import type { CookieOptions, Request } from "express";

export function getSessionCookieOptions(req: Request): CookieOptions {
  // First-party cookie: Lax is enough and safer than SameSite=None.
  // `secure` follows the real protocol (Express "trust proxy" is enabled for Render/Netlify-style proxies).
  const secure = req.protocol === "https" || req.headers["x-forwarded-proto"] === "https";
  return { httpOnly: true, path: "/", sameSite: "lax", secure };
}
