import { COOKIE_NAME } from "@shared/const";
import { TRPCError } from "@trpc/server";
import { eq } from "drizzle-orm";
import { z } from "zod";
import { users } from "../drizzle/schema";
import { getSessionCookieOptions } from "./_core/cookies";
import { systemRouter } from "./_core/systemRouter";
import { protectedProcedure, publicProcedure, router } from "./_core/trpc";
import { SESSION_MS, createSessionToken, hashPassword, toPublicUser, tooManyAttempts, verifyPassword } from "./auth";
import * as db from "./db";
import { experimentsRouter } from "./experiments";

const emailSchema = z.string().trim().toLowerCase().email().max(320);

export const appRouter = router({
  system: systemRouter,
  auth: router({
    me: publicProcedure.query(opts => (opts.ctx.user ? toPublicUser(opts.ctx.user) : null)),
    register: publicProcedure
      .input(z.object({ name: z.string().trim().min(1).max(80), email: emailSchema, password: z.string().min(8).max(128) }))
      .mutation(async ({ ctx, input }) => {
        if (tooManyAttempts(`register:${ctx.req.ip}`, 10)) {
          throw new TRPCError({ code: "TOO_MANY_REQUESTS", message: "Too many attempts. Try again later." });
        }
        if (await db.getUserByEmail(input.email)) {
          throw new TRPCError({ code: "CONFLICT", message: "An account with this email already exists." });
        }
        let user;
        try {
          user = await db.createLocalUser({ email: input.email, name: input.name, passwordHash: await hashPassword(input.password) });
        } catch (error) {
          // Unique-constraint race or DB problem.
          if (await db.getUserByEmail(input.email)) {
            throw new TRPCError({ code: "CONFLICT", message: "An account with this email already exists." });
          }
          throw error;
        }
        if (!user) throw new TRPCError({ code: "INTERNAL_SERVER_ERROR", message: "Could not create account." });
        const token = await createSessionToken(user.id);
        ctx.res.cookie(COOKIE_NAME, token, { ...getSessionCookieOptions(ctx.req), maxAge: SESSION_MS });
        return toPublicUser(user);
      }),
    login: publicProcedure
      .input(z.object({ email: emailSchema, password: z.string().min(1).max(128) }))
      .mutation(async ({ ctx, input }) => {
        if (tooManyAttempts(`login:${ctx.req.ip}:${input.email}`, 10)) {
          throw new TRPCError({ code: "TOO_MANY_REQUESTS", message: "Too many attempts. Try again later." });
        }
        const user = await db.getUserByEmail(input.email);
        if (!user || !(await verifyPassword(input.password, user.passwordHash))) {
          throw new TRPCError({ code: "UNAUTHORIZED", message: "Incorrect email or password." });
        }
        await db.upsertUser({ openId: user.openId, lastSignedIn: new Date() });
        const token = await createSessionToken(user.id);
        ctx.res.cookie(COOKIE_NAME, token, { ...getSessionCookieOptions(ctx.req), maxAge: SESSION_MS });
        return toPublicUser(user);
      }),
    logout: publicProcedure.mutation(({ ctx }) => {
      const cookieOptions = getSessionCookieOptions(ctx.req);
      ctx.res.clearCookie(COOKIE_NAME, { ...cookieOptions, maxAge: -1 });
      return {
        success: true,
      } as const;
    }),
  }),
  account: router({
    setPlan: protectedProcedure
      .input(z.object({ plan: z.enum(["Free", "Growth", "Scale"]) }))
      .mutation(async ({ ctx, input }) => {
        const database = await db.requireDb();
        await database.update(users).set({ plan: input.plan }).where(eq(users.id, ctx.user.id));
        return { plan: input.plan };
      }),
  }),
  experiments: experimentsRouter,
});

export type AppRouter = typeof appRouter;
