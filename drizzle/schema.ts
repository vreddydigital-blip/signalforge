import {
  boolean,
  index,
  int,
  mysqlEnum,
  mysqlTable,
  text,
  timestamp,
  uniqueIndex,
  varchar,
} from "drizzle-orm/mysql-core";

/**
 * NOTE: tables are created automatically at server start by server/migrate.ts
 * (idempotent CREATE TABLE IF NOT EXISTS). Keep that file in sync with this one.
 */
export const users = mysqlTable("users", {
  id: int("id").autoincrement().primaryKey(),
  /** Stable unique id. For email/password accounts this is "local:<uuid>". */
  openId: varchar("openId", { length: 64 }).notNull().unique(),
  name: text("name"),
  email: varchar("email", { length: 320 }).unique(),
  passwordHash: text("passwordHash"),
  loginMethod: varchar("loginMethod", { length: 64 }),
  role: mysqlEnum("role", ["user", "admin"]).default("user").notNull(),
  plan: mysqlEnum("plan", ["Free", "Growth", "Scale"]).default("Growth").notNull(),
  /** Public key embedded in the tracking snippet (data-project). */
  projectKey: varchar("projectKey", { length: 32 }).unique(),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
  lastSignedIn: timestamp("lastSignedIn").defaultNow().notNull(),
});

export type User = typeof users.$inferSelect;
export type InsertUser = typeof users.$inferInsert;

export const experiments = mysqlTable("experiments", {
  id: int("id").autoincrement().primaryKey(),
  userId: int("userId").notNull(),
  name: varchar("name", { length: 255 }).notNull(),
  kind: mysqlEnum("kind", ["A/B test", "Multivariate"]).default("A/B test").notNull(),
  status: mysqlEnum("status", ["Draft", "Active", "Paused", "Completed"]).default("Draft").notNull(),
  goal: varchar("goal", { length: 255 }).notNull(),
  framework: varchar("framework", { length: 128 }).notNull(),
  pageUrl: text("pageUrl"),
  objective: text("objective"),
  hypothesis: text("hypothesis"),
  observedInsight: text("observedInsight"),
  experienceChange: text("experienceChange"),
  behaviorReason: text("behaviorReason"),
  audience: varchar("audience", { length: 128 }),
  conversionEvent: varchar("conversionEvent", { length: 255 }),
  traffic: int("traffic").default(100).notNull(),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
  updatedAt: timestamp("updatedAt").defaultNow().onUpdateNow().notNull(),
}, t => [index("experiments_user_idx").on(t.userId)]);

export type Experiment = typeof experiments.$inferSelect;

export const variants = mysqlTable("variants", {
  id: int("id").autoincrement().primaryKey(),
  experimentId: int("experimentId").notNull(),
  name: varchar("name", { length: 255 }).notNull(),
  isControl: boolean("isControl").default(false).notNull(),
  position: int("position").default(0).notNull(),
  color: varchar("color", { length: 16 }).notNull(),
  /** CSS injected on the page for visitors in this variant. */
  customCss: text("customCss"),
  /** JavaScript run on the page for visitors in this variant. */
  customJs: text("customJs"),
}, t => [index("variants_experiment_idx").on(t.experimentId)]);

export type Variant = typeof variants.$inferSelect;

/** One exposure and at most one conversion per visitor per experiment. */
export const visitorEvents = mysqlTable("visitor_events", {
  id: int("id").autoincrement().primaryKey(),
  experimentId: int("experimentId").notNull(),
  variantId: int("variantId").notNull(),
  visitorId: varchar("visitorId", { length: 64 }).notNull(),
  type: mysqlEnum("type", ["exposure", "conversion"]).notNull(),
  createdAt: timestamp("createdAt").defaultNow().notNull(),
}, t => [
  uniqueIndex("visitor_events_unique").on(t.experimentId, t.visitorId, t.type),
  index("visitor_events_lookup").on(t.experimentId, t.variantId, t.type),
]);
