// Combines the built website (dist/public) with the PHP backend (infinityfree/) into dist/infinityfree,
// ready to upload to InfinityFree's htdocs folder.
import { cpSync, existsSync, mkdirSync, rmSync } from "node:fs";
import path from "node:path";

const out = "dist/infinityfree";
if (!existsSync("dist/public")) throw new Error("Run `vite build` first (dist/public is missing).");
rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });
// Skip Manus dev leftovers that vite copies from client/public.
const skip = (src) => {
  const rel = path.relative("dist/public", src).split(path.sep);
  return !rel.includes("__manus__") && !rel.includes("manus-routes.json") && !rel.includes(".gitkeep");
};
cpSync("dist/public", out, { recursive: true, filter: skip });
cpSync("infinityfree", out, { recursive: true });
console.log(`Ready: upload the contents of ${out} to htdocs.`);
