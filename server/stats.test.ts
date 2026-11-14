import { describe, expect, it } from "vitest";
import { probabilityToBeatControl } from "../shared/stats";

describe("probabilityToBeatControl", () => {
  it("is 50% for identical results", () => {
    const a = { visitors: 1000, conversions: 50 };
    expect(Math.round(probabilityToBeatControl(a, a))).toBe(50);
  });
  it("is high when the variant clearly wins and low when it clearly loses", () => {
    expect(probabilityToBeatControl({ visitors: 5000, conversions: 400 }, { visitors: 5000, conversions: 300 })).toBeGreaterThan(95);
    expect(probabilityToBeatControl({ visitors: 5000, conversions: 300 }, { visitors: 5000, conversions: 400 })).toBeLessThan(5);
  });
});
