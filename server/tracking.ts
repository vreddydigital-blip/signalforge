import { createHash } from "node:crypto";
import { and, eq, inArray, sql } from "drizzle-orm";
import express, { type Express, type Request, type Response } from "express";
import { experiments, users, variants, visitorEvents } from "../drizzle/schema";
import { getDb } from "./db";
import { trackerScript } from "./trackerScript";

const VISITOR_RE = /^[A-Za-z0-9_-]{8,64}$/;
const PROJECT_RE = /^[a-f0-9]{8,32}$/;

function cors(res: Response) {
  res.set({
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Methods": "POST, GET, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type",
    "Access-Control-Max-Age": "86400",
  });
}

// Per-IP throttle for the public endpoints (in-memory; one free instance).
const hits = new Map<string, { count: number; resetAt: number }>();
function limited(ip: string): boolean {
  const now = Date.now();
  const entry = hits.get(ip);
  if (!entry || entry.resetAt < now) {
    hits.set(ip, { count: 1, resetAt: now + 60_000 });
    return false;
  }
  entry.count += 1;
  return entry.count > 300;
}

function readBody(req: Request): Record<string, unknown> {
  if (req.body && typeof req.body === "object") return req.body as Record<string, unknown>;
  if (typeof req.body === "string" && req.body.length > 0) {
    try {
      const parsed = JSON.parse(req.body);
      if (parsed && typeof parsed === "object") return parsed as Record<string, unknown>;
    } catch {
      /* fall through */
    }
  }
  return {};
}

function normalizeUrl(value: string): string | null {
  try {
    const url = new URL(value);
    return url.hostname.replace(/^www\./, "").toLowerCase() + url.pathname.replace(/\/+$/, "").toLowerCase();
  } catch {
    return null;
  }
}

function pageMatches(experimentUrl: string | null, currentUrl: string): boolean {
  if (!experimentUrl) return true;
  const expected = normalizeUrl(experimentUrl);
  if (!expected) return true;
  const actual = normalizeUrl(currentUrl);
  return actual !== null && actual === expected;
}

function bucketFor(visitorId: string, experimentId: number) {
  const digest = createHash("sha256").update(`${experimentId}:${visitorId}`).digest();
  return { traffic: digest.readUInt32BE(0) % 10000, variant: digest.readUInt32BE(4) };
}

async function findProject(projectKey: unknown) {
  if (typeof projectKey !== "string" || !PROJECT_RE.test(projectKey)) return null;
  const db = await getDb();
  if (!db) return null;
  const [user] = await db.select({ id: users.id }).from(users).where(eq(users.projectKey, projectKey)).limit(1);
  return user ? { db, userId: user.id } : null;
}

export function registerTrackingRoutes(app: Express) {
  app.get("/tracker.js", (_req, res) => {
    cors(res);
    res.set("Cache-Control", "public, max-age=300").type("application/javascript").send(trackerScript);
  });

  app.options("/api/t/*", (_req, res) => {
    cors(res);
    res.sendStatus(204);
  });

  const parser = express.text({ type: "*/*", limit: "16kb" });

  // Which variant (if any) should this visitor see on this page? Also records the exposure.
  app.post("/api/t/decide", parser, async (req, res) => {
    cors(res);
    try {
      if (limited(req.ip ?? "unknown")) return res.status(429).json({ error: "rate_limited" });
      const body = readBody(req);
      const visitorId = typeof body.visitorId === "string" ? body.visitorId : "";
      const url = typeof body.url === "string" ? body.url : "";
      if (!VISITOR_RE.test(visitorId)) return res.status(400).json({ error: "bad_visitor" });
      const project = await findProject(body.project);
      if (!project) return res.status(404).json({ error: "unknown_project" });
      const { db, userId } = project;

      const active = (await db
        .select()
        .from(experiments)
        .where(and(eq(experiments.userId, userId), eq(experiments.status, "Active")))
      ).filter(experiment => pageMatches(experiment.pageUrl, url));
      if (active.length === 0) return res.json({ assignments: [] });

      const ids = active.map(experiment => experiment.id);
      const variantRows = await db.select().from(variants).where(inArray(variants.experimentId, ids)).orderBy(variants.position);
      const existing = await db
        .select()
        .from(visitorEvents)
        .where(and(eq(visitorEvents.visitorId, visitorId), eq(visitorEvents.type, "exposure"), inArray(visitorEvents.experimentId, ids)));

      const assignments = [];
      for (const experiment of active) {
        const options = variantRows.filter(variant => variant.experimentId === experiment.id);
        if (options.length === 0) continue;
        let chosen = options.find(variant => variant.id === existing.find(event => event.experimentId === experiment.id)?.variantId);
        if (!chosen) {
          const bucket = bucketFor(visitorId, experiment.id);
          // Traffic allocation: only `traffic`% of visitors enter the experiment.
          if (bucket.traffic >= experiment.traffic * 100) continue;
          chosen = options[bucket.variant % options.length];
          await db.execute(
            sql`INSERT IGNORE INTO visitor_events (experimentId, variantId, visitorId, type) VALUES (${experiment.id}, ${chosen.id}, ${visitorId}, 'exposure')`
          );
        }
        assignments.push({
          experimentId: experiment.id,
          variantId: chosen.id,
          variantName: chosen.name,
          isControl: chosen.isControl,
          css: chosen.customCss ?? "",
          js: chosen.customJs ?? "",
        });
      }
      return res.json({ assignments });
    } catch (error) {
      console.error("[tracking] decide failed", error);
      return res.status(500).json({ error: "server_error" });
    }
  });

  // Record a conversion for every active experiment this visitor was exposed to.
  app.post("/api/t/convert", parser, async (req, res) => {
    cors(res);
    try {
      if (limited(req.ip ?? "unknown")) return res.status(429).json({ error: "rate_limited" });
      const body = readBody(req);
      const visitorId = typeof body.visitorId === "string" ? body.visitorId : "";
      const event = typeof body.event === "string" ? body.event.trim().toLowerCase().slice(0, 255) : "conversion";
      if (!VISITOR_RE.test(visitorId)) return res.status(400).json({ error: "bad_visitor" });
      const project = await findProject(body.project);
      if (!project) return res.status(404).json({ error: "unknown_project" });
      const { db, userId } = project;

      const active = await db
        .select()
        .from(experiments)
        .where(and(eq(experiments.userId, userId), eq(experiments.status, "Active")));
      const matching = active.filter(experiment => event === "conversion" || (experiment.conversionEvent ?? "").trim().toLowerCase() === event);
      if (matching.length === 0) return res.json({ ok: true, recorded: 0 });

      const exposures = await db
        .select()
        .from(visitorEvents)
        .where(
          and(
            eq(visitorEvents.visitorId, visitorId),
            eq(visitorEvents.type, "exposure"),
            inArray(visitorEvents.experimentId, matching.map(experiment => experiment.id))
          )
        );
      for (const exposure of exposures) {
        await db.execute(
          sql`INSERT IGNORE INTO visitor_events (experimentId, variantId, visitorId, type) VALUES (${exposure.experimentId}, ${exposure.variantId}, ${visitorId}, 'conversion')`
        );
      }
      return res.json({ ok: true, recorded: exposures.length });
    } catch (error) {
      console.error("[tracking] convert failed", error);
      return res.status(500).json({ error: "server_error" });
    }
  });
}
