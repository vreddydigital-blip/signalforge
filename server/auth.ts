import { COOKIE_NAME } from "@shared/const";
import { randomBytes, scrypt as scryptCb, timingSafeEqual } from "node:crypto";
import { promisify } from "node:util";
import { parse as parseCookieHeader } from "cookie";
import type { Request } from "express";
import { SignJWT, jwtVerify } from "jose";
import type { User } from "../drizzle/schema";
import { ENV } from "./_core/env";
import * as db from "./db";

const scrypt = promisify(scryptCb) as (password: string, salt: Buffer, keylen: number) => Promise<Buffer>;

export const SESSION_MS = 1000 * 60 * 60 * 24 * 30;

export async function hashPassword(password: string): Promise<string> {
  const salt = randomBytes(16);
  const hash = await scrypt(password, salt, 64);
  return `${salt.toString("hex")}:${hash.toString("hex")}`;
}

export async function verifyPassword(password: string, stored: string | null | undefined): Promise<boolean> {
  if (!stored) return false;
  const [saltHex, hashHex] = stored.split(":");
  if (!saltHex || !hashHex) return false;
  const expected = Buffer.from(hashHex, "hex");
  const actual = await scrypt(password, Buffer.from(saltHex, "hex"), expected.length);
  return actual.length === expected.length && timingSafeEqual(actual, expected);
}

function secretKey() {
  let secret = ENV.cookieSecret;
  if (!secret) {
    if (ENV.isProduction) throw new Error("JWT_SECRET is not set");
    secret = "dev-only-secret-change-me";
  }
  return new TextEncoder().encode(secret);
}

export async function createSessionToken(userId: number): Promise<string> {
  return new SignJWT({ uid: userId })
    .setProtectedHeader({ alg: "HS256", typ: "JWT" })
    .setExpirationTime(Math.floor((Date.now() + SESSION_MS) / 1000))
    .sign(secretKey());
}

export async function getUserFromRequest(req: Request): Promise<User | null> {
  const header = req.headers.cookie;
  if (!header) return null;
  const token = parseCookieHeader(header)[COOKIE_NAME];
  if (!token) return null;
  try {
    const { payload } = await jwtVerify(token, secretKey(), { algorithms: ["HS256"] });
    const uid = Number(payload.uid);
    if (!Number.isInteger(uid)) return null;
    return (await db.getUserById(uid)) ?? null;
  } catch {
    return null;
  }
}

/** Never send the password hash to the browser. */
export function toPublicUser(user: User) {
  return {
    id: user.id,
    name: user.name,
    email: user.email,
    role: user.role,
    plan: user.plan,
    projectKey: user.projectKey,
  };
}

// Tiny in-memory throttle for login/register (per process; fine for a single free instance).
const attempts = new Map<string, { count: number; resetAt: number }>();
export function tooManyAttempts(key: string, max = 10, windowMs = 15 * 60 * 1000): boolean {
  const now = Date.now();
  const entry = attempts.get(key);
  if (!entry || entry.resetAt < now) {
    attempts.set(key, { count: 1, resetAt: now + windowMs });
    return false;
  }
  entry.count += 1;
  return entry.count > max;
}
