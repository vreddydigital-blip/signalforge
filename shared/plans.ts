export type Plan = "Free" | "Growth" | "Scale";

export const planLimits: Record<Plan, { experiments: number; variants: number; copy: string }> = {
  Free: { experiments: 3, variants: 2, copy: "Core A/B testing" },
  Growth: { experiments: 10, variants: 5, copy: "Multi-variant insights" },
  Scale: { experiments: 50, variants: 8, copy: "Advanced experimentation" },
};
