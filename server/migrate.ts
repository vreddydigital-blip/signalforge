import { sql } from "drizzle-orm";
import { getDb } from "./db";

// Idempotent schema bootstrap so free hosts (no shell access) need no manual migration step.
// Keep in sync with drizzle/schema.ts.
const statements = [
  `CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    openId VARCHAR(64) NOT NULL,
    name TEXT NULL,
    email VARCHAR(320) NULL,
    passwordHash TEXT NULL,
    loginMethod VARCHAR(64) NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    plan ENUM('Free','Growth','Scale') NOT NULL DEFAULT 'Growth',
    projectKey VARCHAR(32) NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    lastSignedIn TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY users_openId_unique (openId),
    UNIQUE KEY users_email_unique (email),
    UNIQUE KEY users_projectKey_unique (projectKey)
  )`,
  `CREATE TABLE IF NOT EXISTS experiments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    kind ENUM('A/B test','Multivariate') NOT NULL DEFAULT 'A/B test',
    status ENUM('Draft','Active','Paused','Completed') NOT NULL DEFAULT 'Draft',
    goal VARCHAR(255) NOT NULL,
    framework VARCHAR(128) NOT NULL,
    pageUrl TEXT NULL,
    objective TEXT NULL,
    hypothesis TEXT NULL,
    observedInsight TEXT NULL,
    experienceChange TEXT NULL,
    behaviorReason TEXT NULL,
    audience VARCHAR(128) NULL,
    conversionEvent VARCHAR(255) NULL,
    traffic INT NOT NULL DEFAULT 100,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY experiments_user_idx (userId)
  )`,
  `CREATE TABLE IF NOT EXISTS variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    experimentId INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    isControl BOOLEAN NOT NULL DEFAULT FALSE,
    position INT NOT NULL DEFAULT 0,
    color VARCHAR(16) NOT NULL,
    customCss TEXT NULL,
    customJs TEXT NULL,
    KEY variants_experiment_idx (experimentId)
  )`,
  `CREATE TABLE IF NOT EXISTS visitor_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    experimentId INT NOT NULL,
    variantId INT NOT NULL,
    visitorId VARCHAR(64) NOT NULL,
    type ENUM('exposure','conversion') NOT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY visitor_events_unique (experimentId, visitorId, type),
    KEY visitor_events_lookup (experimentId, variantId, type)
  )`,
];

export async function ensureSchema() {
  const db = await getDb();
  if (!db) {
    console.warn("[Database] DATABASE_URL is not set; skipping schema setup. Login and experiments will not work.");
    return;
  }
  for (const statement of statements) {
    await db.execute(sql.raw(statement));
  }
  console.log("[Database] Schema ready");
}
