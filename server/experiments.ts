import { planLimits } from "@shared/plans";
import { probabilityToBeatControl } from "@shared/stats";
import { TRPCError } from "@trpc/server";
import { and, desc, eq, inArray, sql } from "drizzle-orm";
import { z } from "zod";
import { experiments, variants, visitorEvents } from "../drizzle/schema";
import { protectedProcedure, router } from "./_core/trpc";
import { requireDb } from "./db";

const COLORS = ["#8d96a8", "#e5643c", "#f2aa4f", "#5676e8", "#6eaf75", "#9a70cf", "#4a9a9a", "#d477a0"];

type ClientVariant = {
  id: number;
  name: string;
  visitors: number;
  conversions: number;
  rate: number;
  uplift: number;
  probability: number;
  color: string;
  isControl: boolean;
  customCss: string;
  customJs: string;
};

export type ClientExperiment = {
  id: number;
  name: string;
  kind: "A/B test" | "Multivariate";
  status: "Draft" | "Active" | "Paused" | "Completed";
  owner: string;
  updated: string;
  goal: string;
  framework: string;
  pageUrl: string;
  objective: string;
  hypothesis: string;
  observedInsight: string;
  experienceChange: string;
  behaviorReason: string;
  audience: string;
  conversionEvent: string;
  traffic: number;
  variants: ClientVariant[];
};

function timeAgo(date: Date): string {
  const minutes = Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000));
  if (minutes < 1) return "Just now";
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} hr ago`;
  if (hours < 48) return "Yesterday";
  return date.toLocaleDateString("en-US", { month: "short", day: "numeric" });
}

const round = (value: number, places: number) => {
  const factor = 10 ** places;
  return Math.round(value * factor) / factor;
};

async function loadExperiments(userId: number, ownerName: string): Promise<ClientExperiment[]> {
  const db = await requireDb();
  const rows = await db.select().from(experiments).where(eq(experiments.userId, userId)).orderBy(desc(experiments.updatedAt));
  if (rows.length === 0) return [];
  const ids = rows.map(row => row.id);
  const variantRows = await db.select().from(variants).where(inArray(variants.experimentId, ids)).orderBy(variants.position);
  const countRows = await db
    .select({ variantId: visitorEvents.variantId, type: visitorEvents.type, n: sql<number>`count(*)` })
    .from(visitorEvents)
    .where(inArray(visitorEvents.experimentId, ids))
    .groupBy(visitorEvents.variantId, visitorEvents.type);
  const counts = new Map<string, number>();
  for (const row of countRows) counts.set(`${row.variantId}:${row.type}`, Number(row.n));

  return rows.map(row => {
    const raw = variantRows
      .filter(variant => variant.experimentId === row.id)
      .map(variant => {
        const visitors = counts.get(`${variant.id}:exposure`) ?? 0;
        const conversions = counts.get(`${variant.id}:conversion`) ?? 0;
        return { variant, visitors, conversions, rate: visitors > 0 ? (conversions / visitors) * 100 : 0 };
      });
    const control = raw.find(item => item.variant.isControl) ?? raw[0];
    const mapped: ClientVariant[] = raw.map(item => ({
      id: item.variant.id,
      name: item.variant.name,
      visitors: item.visitors,
      conversions: item.conversions,
      rate: round(item.rate, 2),
      uplift: item.variant.isControl || !control || control.rate <= 0 ? 0 : round(((item.rate - control.rate) / control.rate) * 100, 1),
      probability: item.variant.isControl || !control ? 50 : Math.round(probabilityToBeatControl(item, control)),
      color: item.variant.color,
      isControl: item.variant.isControl,
      customCss: item.variant.customCss ?? "",
      customJs: item.variant.customJs ?? "",
    }));
    return {
      id: row.id,
      name: row.name,
      kind: row.kind,
      status: row.status,
      owner: ownerName,
      updated: timeAgo(row.updatedAt),
      goal: row.goal,
      framework: row.framework,
      pageUrl: row.pageUrl ?? "",
      objective: row.objective ?? "",
      hypothesis: row.hypothesis ?? "",
      observedInsight: row.observedInsight ?? "",
      experienceChange: row.experienceChange ?? "",
      behaviorReason: row.behaviorReason ?? "",
      audience: row.audience ?? "",
      conversionEvent: row.conversionEvent ?? "",
      traffic: row.traffic,
      variants: mapped,
    };
  });
}

async function assertOwner(userId: number, experimentId: number) {
  const db = await requireDb();
  const [row] = await db
    .select()
    .from(experiments)
    .where(and(eq(experiments.id, experimentId), eq(experiments.userId, userId)))
    .limit(1);
  if (!row) throw new TRPCError({ code: "NOT_FOUND", message: "Experiment not found." });
  return row;
}

const text = (max: number) => z.string().max(max).optional();

const createInput = z.object({
  name: z.string().trim().min(1).max(255),
  kind: z.enum(["A/B test", "Multivariate"]),
  goal: z.string().max(255),
  framework: z.string().max(128),
  pageUrl: text(2000),
  objective: text(2000),
  hypothesis: text(2000),
  observedInsight: text(2000),
  experienceChange: text(2000),
  behaviorReason: text(2000),
  audience: z.string().max(128),
  conversionEvent: z.string().max(255),
  traffic: z.number().int().min(1).max(100),
  variantNames: z.array(z.string().trim().min(1).max(255)).min(2).max(8),
});

export const experimentsRouter = router({
  list: protectedProcedure.query(({ ctx }) => loadExperiments(ctx.user.id, ctx.user.name || "You")),

  create: protectedProcedure.input(createInput).mutation(async ({ ctx, input }) => {
    const db = await requireDb();
    const limits = planLimits[ctx.user.plan];
    const [{ total }] = await db
      .select({ total: sql<number>`count(*)` })
      .from(experiments)
      .where(eq(experiments.userId, ctx.user.id));
    if (Number(total) >= limits.experiments) {
      throw new TRPCError({ code: "FORBIDDEN", message: `${ctx.user.plan} plan limit reached (${limits.experiments} experiments).` });
    }
    if (input.kind === "Multivariate" && ctx.user.plan === "Free") {
      throw new TRPCError({ code: "FORBIDDEN", message: "Multivariate tests need the Growth plan or higher." });
    }
    const names = input.kind === "A/B test" ? input.variantNames.slice(0, 2) : input.variantNames;
    if (names.length > limits.variants) {
      throw new TRPCError({ code: "FORBIDDEN", message: `${ctx.user.plan} plan allows up to ${limits.variants} variants.` });
    }

    const [header] = await db.insert(experiments).values({
      userId: ctx.user.id,
      name: input.name,
      kind: input.kind,
      status: "Draft",
      goal: input.goal,
      framework: input.framework,
      pageUrl: input.pageUrl ?? "",
      objective: input.objective ?? "",
      hypothesis: input.hypothesis ?? "",
      observedInsight: input.observedInsight ?? "",
      experienceChange: input.experienceChange ?? "",
      behaviorReason: input.behaviorReason ?? "",
      audience: input.audience,
      conversionEvent: input.conversionEvent,
      traffic: input.traffic,
    });
    const id = Number(header.insertId);
    await db.insert(variants).values(
      names.map((name, index) => ({
        experimentId: id,
        name,
        isControl: index === 0,
        position: index,
        color: COLORS[index % COLORS.length],
      }))
    );
    return { id };
  }),

  setStatus: protectedProcedure
    .input(z.object({ id: z.number().int(), status: z.enum(["Draft", "Active", "Paused", "Completed"]) }))
    .mutation(async ({ ctx, input }) => {
      const row = await assertOwner(ctx.user.id, input.id);
      if (row.status === "Completed") {
        throw new TRPCError({ code: "BAD_REQUEST", message: "Completed experiments are read-only." });
      }
      if (input.status === "Draft") {
        throw new TRPCError({ code: "BAD_REQUEST", message: "An experiment cannot go back to Draft." });
      }
      const db = await requireDb();
      await db.update(experiments).set({ status: input.status, updatedAt: new Date() }).where(eq(experiments.id, row.id));
      return { ok: true };
    }),

  remove: protectedProcedure.input(z.object({ id: z.number().int() })).mutation(async ({ ctx, input }) => {
    const row = await assertOwner(ctx.user.id, input.id);
    const db = await requireDb();
    await db.delete(visitorEvents).where(eq(visitorEvents.experimentId, row.id));
    await db.delete(variants).where(eq(variants.experimentId, row.id));
    await db.delete(experiments).where(eq(experiments.id, row.id));
    return { ok: true };
  }),

  /** Attach CSS / JavaScript that the tracker applies for visitors in a variant. */
  updateVariantCode: protectedProcedure
    .input(z.object({ variantId: z.number().int(), customCss: z.string().max(20000).optional(), customJs: z.string().max(20000).optional() }))
    .mutation(async ({ ctx, input }) => {
      const db = await requireDb();
      const [row] = await db
        .select({ id: variants.id })
        .from(variants)
        .innerJoin(experiments, eq(variants.experimentId, experiments.id))
        .where(and(eq(variants.id, input.variantId), eq(experiments.userId, ctx.user.id)))
        .limit(1);
      if (!row) throw new TRPCError({ code: "NOT_FOUND", message: "Variant not found." });
      const patch: { customCss?: string; customJs?: string } = {};
      if (input.customCss !== undefined) patch.customCss = input.customCss;
      if (input.customJs !== undefined) patch.customJs = input.customJs;
      if (Object.keys(patch).length > 0) await db.update(variants).set(patch).where(eq(variants.id, row.id));
      return { ok: true };
    }),

  /** Cumulative conversion rate per day for the control and the leading variant (last 14 days). */
  trend: protectedProcedure.input(z.object({ id: z.number().int() })).query(async ({ ctx, input }) => {
    const row = await assertOwner(ctx.user.id, input.id);
    const db = await requireDb();
    const variantRows = await db.select().from(variants).where(eq(variants.experimentId, row.id)).orderBy(variants.position);
    const control = variantRows.find(variant => variant.isControl) ?? variantRows[0];
    if (!control) return [];

    const day = sql<string>`DATE_FORMAT(${visitorEvents.createdAt}, '%Y-%m-%d')`;
    const rows = await db
      .select({ day, variantId: visitorEvents.variantId, type: visitorEvents.type, n: sql<number>`count(*)` })
      .from(visitorEvents)
      .where(eq(visitorEvents.experimentId, row.id))
      .groupBy(day, visitorEvents.variantId, visitorEvents.type);

    const totals = new Map<number, number>();
    for (const item of rows) if (item.type === "exposure") totals.set(item.variantId, (totals.get(item.variantId) ?? 0) + Number(item.n));
    const others = variantRows.filter(variant => variant.id !== control.id);
    const lead = others.sort((a, b) => (totals.get(b.id) ?? 0) - (totals.get(a.id) ?? 0))[0];

    const perDay = new Map<string, Record<string, number>>();
    for (const item of rows) {
      const bucket = perDay.get(item.day) ?? {};
      bucket[`${item.variantId}:${item.type}`] = Number(item.n);
      perDay.set(item.day, bucket);
    }
    const days = Array.from(perDay.keys()).sort();
    const cumulative = { c: { v: 0, x: 0 }, l: { v: 0, x: 0 } };
    const series = new Map<string, { control: number; variant: number }>();
    for (const key of days) {
      const bucket = perDay.get(key)!;
      cumulative.c.v += bucket[`${control.id}:exposure`] ?? 0;
      cumulative.c.x += bucket[`${control.id}:conversion`] ?? 0;
      if (lead) {
        cumulative.l.v += bucket[`${lead.id}:exposure`] ?? 0;
        cumulative.l.x += bucket[`${lead.id}:conversion`] ?? 0;
      }
      series.set(key, {
        control: cumulative.c.v > 0 ? round((cumulative.c.x / cumulative.c.v) * 100, 2) : 0,
        variant: cumulative.l.v > 0 ? round((cumulative.l.x / cumulative.l.v) * 100, 2) : 0,
      });
    }

    const out: { day: string; control: number; variant: number }[] = [];
    let last = { control: 0, variant: 0 };
    const today = new Date();
    for (let offset = 13; offset >= 0; offset -= 1) {
      const date = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate() - offset));
      const key = date.toISOString().slice(0, 10);
      const known = series.get(key);
      if (known) last = known;
      else {
        // carry forward the latest cumulative value from days before this one
        const earlier = days.filter(d => d <= key).pop();
        if (earlier) last = series.get(earlier)!;
      }
      out.push({ day: date.toLocaleDateString("en-US", { month: "short", day: "2-digit", timeZone: "UTC" }), ...last });
    }
    return out;
  }),
});
