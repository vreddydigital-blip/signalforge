export type Counts = { visitors: number; conversions: number };

/** Abramowitz-Stegun approximation of the standard normal CDF. */
export function normalCdf(value: number): number {
  const sign = value < 0 ? -1 : 1;
  const x = Math.abs(value) / Math.sqrt(2);
  const t = 1 / (1 + 0.3275911 * x);
  const polynomial =
    (((((1.061405429 * t - 1.453152027) * t) + 1.421413741) * t - 0.284496736) * t + 0.254829592) * t;
  return 0.5 * (1 + sign * (1 - polynomial * Math.exp(-x * x)));
}

function posterior(item: Counts) {
  // Beta(1,1) prior, Beta-Binomial posterior.
  const alpha = item.conversions + 1;
  const beta = Math.max(item.visitors - item.conversions, 0) + 1;
  const total = alpha + beta;
  return { mean: alpha / total, variance: (alpha * beta) / (total * total * (total + 1)) };
}

/** Probability (0-100) that `variant` has a higher conversion rate than `control`. */
export function probabilityToBeatControl(variant: Counts, control: Counts): number {
  const a = posterior(variant);
  const b = posterior(control);
  const se = Math.sqrt(a.variance + b.variance);
  const z = se > 0 ? (a.mean - b.mean) / se : 0;
  return Math.max(1, Math.min(99, normalCdf(z) * 100));
}
