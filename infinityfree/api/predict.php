<?php
// SignalForge prediction engine (v3).
// Everything here is computed in plain PHP from the app's own visitor_events
// rows. No external ML service, no call to the orchestrator: the numbers on the
// Insights page are produced on this host, from this workspace's data.
//
// Five capabilities:
//   1. Win probability  - Beta posteriors per variant, P(variant > control),
//                         with a credible band on the lift.
//   2. Time to significance - how many days until the leader reaches a verdict
//                         at the current traffic rate.
//   3. Projected lift   - expected conversion gain if the leader ships.
//   4. Power calculator - sample size needed before launching a test.
//   5. Anomaly flags    - daily traffic / conversion drops and sample-ratio
//                         mismatch.
//
// Small-sample and zero-conversion cases return an explicit, honest result
// instead of a confident-looking guess.

// ------------------------------------------------------------ special functions

// Lanczos log-gamma. Needed for the Beta density and incomplete Beta.
function p3_lgamma($x)
{
    static $c = [
        76.18009172947146, -86.50532032941677, 24.01409824083091,
        -1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5,
    ];
    if ($x <= 0) {
        return 0.0;
    }
    $y = $x;
    $tmp = $x + 5.5;
    $tmp -= ($x + 0.5) * log($tmp);
    $ser = 1.000000000190015;
    for ($j = 0; $j < 6; $j++) {
        $y += 1.0;
        $ser += $c[$j] / $y;
    }
    return -$tmp + log(2.5066282746310005 * $ser / $x);
}

// Continued fraction for the regularized incomplete Beta.
function p3_betacf($a, $b, $x)
{
    $MAXIT = 220;
    $EPS = 3e-12;
    $FPMIN = 1e-300;
    $qab = $a + $b;
    $qap = $a + 1.0;
    $qam = $a - 1.0;
    $c = 1.0;
    $d = 1.0 - $qab * $x / $qap;
    if (abs($d) < $FPMIN) { $d = $FPMIN; }
    $d = 1.0 / $d;
    $h = $d;
    for ($m = 1; $m <= $MAXIT; $m++) {
        $m2 = 2 * $m;
        $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
        $d = 1.0 + $aa * $d;
        if (abs($d) < $FPMIN) { $d = $FPMIN; }
        $c = 1.0 + $aa / $c;
        if (abs($c) < $FPMIN) { $c = $FPMIN; }
        $d = 1.0 / $d;
        $h *= $d * $c;
        $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
        $d = 1.0 + $aa * $d;
        if (abs($d) < $FPMIN) { $d = $FPMIN; }
        $c = 1.0 + $aa / $c;
        if (abs($c) < $FPMIN) { $c = $FPMIN; }
        $d = 1.0 / $d;
        $del = $d * $c;
        $h *= $del;
        if (abs($del - 1.0) < $EPS) {
            break;
        }
    }
    return $h;
}

// Regularized incomplete Beta I_x(a,b).
function p3_betai($a, $b, $x)
{
    if ($x <= 0.0) { return 0.0; }
    if ($x >= 1.0) { return 1.0; }
    $lb = p3_lgamma($a + $b) - p3_lgamma($a) - p3_lgamma($b)
        + $a * log($x) + $b * log(1.0 - $x);
    $bt = exp($lb);
    if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
        return $bt * p3_betacf($a, $b, $x) / $a;
    }
    return 1.0 - $bt * p3_betacf($b, $a, 1.0 - $x) / $b;
}

// Beta(alpha,beta) density at t.
function p3_beta_pdf($t, $a, $b, $logB)
{
    $t = max(1e-12, min(1.0 - 1e-12, $t));
    return exp(($a - 1.0) * log($t) + ($b - 1.0) * log(1.0 - $t) - $logB);
}

// P(X > Y) where X ~ Beta(aA,bA), Y ~ Beta(aB,bB).
// Numeric integration of pdf_X(t) * CDF_Y(t) over the mass of X.
function p3_prob_beats($aA, $bA, $aB, $bB)
{
    $mean = $aA / ($aA + $bA);
    $var = ($aA * $bA) / (($aA + $bA) * ($aA + $bA) * ($aA + $bA + 1.0));
    $sd = sqrt(max($var, 1e-18));
    $lo = max(0.0, $mean - 8.0 * $sd);
    $hi = min(1.0, $mean + 8.0 * $sd);
    if ($hi <= $lo + 1e-12) {
        return 0.5;
    }
    $steps = 600;
    $dt = ($hi - $lo) / $steps;
    $logB = p3_lgamma($aA) + p3_lgamma($bA) - p3_lgamma($aA + $bA);
    $sum = 0.0;
    for ($i = 0; $i < $steps; $i++) {
        $t = $lo + ($i + 0.5) * $dt;
        $sum += p3_beta_pdf($t, $aA, $bA, $logB) * p3_betai($aB, $bB, $t);
    }
    $sum *= $dt;
    return max(0.0, min(1.0, $sum));
}

// Inverse standard normal (Acklam). Used by the power calculator.
function p3_z($p)
{
    if ($p <= 0.0) { return -6.0; }
    if ($p >= 1.0) { return 6.0; }
    $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
          1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
    $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
          6.680131188771972e+01, -1.328068155288572e+01];
    $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
          -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
    $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
          3.754408661907416e+00];
    $plow = 0.02425;
    $phigh = 1.0 - $plow;
    if ($p < $plow) {
        $q = sqrt(-2.0 * log($p));
        return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
            / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
    }
    if ($p > $phigh) {
        $q = sqrt(-2.0 * log(1.0 - $p));
        return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
            / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
    }
    $q = $p - 0.5;
    $r = $q * $q;
    return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
        / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
}

// Chi-square upper tail, so sample-ratio mismatch gets a real p-value.
function p3_chi2_sf($chi2, $df)
{
    if ($df <= 0) { return 1.0; }
    if ($chi2 <= 0) { return 1.0; }
    if ($df === 1) {
        $z = sqrt($chi2);
        return max(0.0, min(1.0, 2.0 * (1.0 - normal_cdf($z))));
    }
    $h = 2.0 / (9.0 * $df);
    $z = (pow($chi2 / $df, 1.0 / 3.0) - (1.0 - $h)) / sqrt($h);
    return max(0.0, min(1.0, 1.0 - normal_cdf($z)));
}

// ------------------------------------------------------------ dataset

// Per-variant counts plus the daily series, straight from visitor_events.
function p3_dataset($userId, $experimentId)
{
    $pdo = db();
    $uid = (int) $userId;

    if ((int) $experimentId <= 0) {
        $st = $pdo->prepare("SELECT ve.experimentId, COUNT(*) AS n
            FROM visitor_events ve JOIN experiments e ON e.id = ve.experimentId
            WHERE e.userId = ? AND ve.type = 'exposure'
            GROUP BY ve.experimentId ORDER BY n DESC LIMIT 1");
        $st->execute([$uid]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $experimentId = (int) $r['experimentId'];
    }

    $st = $pdo->prepare('SELECT * FROM experiments WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $experimentId, $uid]);
    $exp = $st->fetch();
    if (!$exp) {
        return null;
    }

    $st = $pdo->prepare('SELECT id, name, isControl, position, color FROM variants WHERE experimentId = ? ORDER BY position ASC');
    $st->execute([(int) $experimentId]);
    $variants = $st->fetchAll();
    if (!$variants) {
        return ['experiment' => $exp, 'variants' => [], 'arms' => [], 'controlId' => null,
                'daily' => [], 'totals' => ['exposures' => 0, 'conversions' => 0]];
    }

    $controlId = (int) $variants[0]['id'];
    foreach ($variants as $v) {
        if ((int) $v['isControl'] === 1) { $controlId = (int) $v['id']; break; }
    }

    // Per-variant exposure and conversion counts.
    $st = $pdo->prepare("SELECT variantId, type, COUNT(*) AS n
        FROM visitor_events
        WHERE experimentId = ? AND type IN ('exposure','conversion')
        GROUP BY variantId, type");
    $st->execute([(int) $experimentId]);
    $counts = [];
    foreach ($st->fetchAll() as $r) {
        $counts[(int) $r['variantId']][$r['type']] = (int) $r['n'];
    }

    $arms = [];
    $totExp = 0;
    $totConv = 0;
    foreach ($variants as $v) {
        $vid = (int) $v['id'];
        $n = isset($counts[$vid]['exposure']) ? $counts[$vid]['exposure'] : 0;
        $x = isset($counts[$vid]['conversion']) ? $counts[$vid]['conversion'] : 0;
        // Conversions can never exceed exposures; clamp so a mis-tagged event
        // does not produce a >100% rate.
        if ($n > 0 && $x > $n) { $x = $n; }
        $totExp += $n;
        $totConv += $x;
        $arms[] = [
            'id' => $vid,
            'name' => $v['name'],
            'isControl' => ($vid === $controlId),
            'visitors' => $n,
            'conversions' => $x,
            'rate' => $n > 0 ? $x / $n : 0.0,
            'color' => $v['color'],
        ];
    }

    // Daily series for the anomaly scan and the traffic rate.
    $st = $pdo->prepare("SELECT DATE(createdAt) AS d, variantId, type, COUNT(*) AS n
        FROM visitor_events
        WHERE experimentId = ? AND type IN ('exposure','conversion')
        GROUP BY d, variantId, type
        ORDER BY d ASC");
    $st->execute([(int) $experimentId]);
    $daily = [];
    foreach ($st->fetchAll() as $r) {
        $d = (string) $r['d'];
        if (!isset($daily[$d])) {
            $daily[$d] = ['date' => $d, 'exposures' => 0, 'conversions' => 0, 'byVariant' => []];
        }
        $n = (int) $r['n'];
        $vid = (int) $r['variantId'];
        if ($r['type'] === 'exposure') {
            $daily[$d]['exposures'] += $n;
            $daily[$d]['byVariant'][$vid] = (isset($daily[$d]['byVariant'][$vid]) ? $daily[$d]['byVariant'][$vid] : 0) + $n;
        } else {
            $daily[$d]['conversions'] += $n;
        }
    }
    ksort($daily);

    return [
        'experiment' => $exp,
        'variants' => $variants,
        'controlId' => $controlId,
        'arms' => $arms,
        'daily' => array_values($daily),
        'totals' => ['exposures' => $totExp, 'conversions' => $totConv],
    ];
}

// Visitors per day: recent 7-day mean when there is history, else overall.
function p3_traffic_rate($data)
{
    $daily = $data['daily'];
    $n = count($daily);
    if ($n === 0) {
        return ['recent' => 0.0, 'overall' => 0.0, 'days' => 0, 'source' => 'none'];
    }
    $total = 0;
    foreach ($daily as $d) { $total += $d['exposures']; }
    $overall = $total / max(1, $n);

    $tail = array_slice($daily, -7);
    $tailSum = 0;
    foreach ($tail as $d) { $tailSum += $d['exposures']; }
    $recent = $tailSum / max(1, count($tail));

    return [
        'recent' => round($recent, 2),
        'overall' => round($overall, 2),
        'days' => $n,
        'source' => count($tail) >= 2 ? 'last 7 days' : 'whole period',
    ];
}

// ------------------------------------------------------------ 1. win probability

// Format a probability so 99.9 never prints as a flat "100".
function p3_pct($p)
{
    if ($p >= 99.9) { return '99.9'; }
    if ($p <= 0.1) { return '0.1'; }
    return number_format($p, 1);
}

function p3_win_probability($data)
{
    $arms = $data['arms'];
    $control = null;
    foreach ($arms as $a) { if ($a['isControl']) { $control = $a; break; } }
    if ($control === null || count($arms) < 2) {
        return ['ready' => false, 'variants' => [],
                'headline' => 'Not enough variants to compare',
                'detail' => 'This experiment needs a control and at least one challenger.',
                'reason' => 'This experiment needs a control and at least one challenger.'];
    }

    $cA = $control['conversions'] + 1.0;
    $cB = max($control['visitors'] - $control['conversions'], 0) + 1.0;
    $cRate = $control['rate'];

    $out = [];
    foreach ($arms as $a) {
        if ($a['isControl']) {
            $out[] = [
                'id' => $a['id'], 'name' => $a['name'], 'isControl' => true,
                'visitors' => $a['visitors'], 'conversions' => $a['conversions'],
                'rate' => round($a['rate'] * 100, 2),
                'probability' => 50.0, 'lift' => 0.0, 'liftLow' => null, 'liftHigh' => null,
                'verdict' => 'baseline',
            ];
            continue;
        }
        $aA = $a['conversions'] + 1.0;
        $bA = max($a['visitors'] - $a['conversions'], 0) + 1.0;

        $prob = p3_prob_beats($aA, $bA, $cA, $cB) * 100.0;
        if ($a['visitors'] === 0) { $prob = 50.0; }
        // Never show a flat 0 or 100: the posterior is never certain.
        $prob = max(0.1, min(99.9, $prob));

        list($m1, $v1) = posterior($a['visitors'], $a['conversions']);
        list($m0, $v0) = posterior($control['visitors'], $control['conversions']);
        $se = sqrt($v1 + $v0);
        $diff = $m1 - $m0;
        $lo = $diff - 1.959964 * $se;
        $hi = $diff + 1.959964 * $se;
        $relLift = $cRate > 0 ? (($a['rate'] - $cRate) / $cRate) * 100.0 : 0.0;
        $relLow = $cRate > 0 ? ($lo / $cRate) * 100.0 : null;
        $relHigh = $cRate > 0 ? ($hi / $cRate) * 100.0 : null;

        $verdict = 'inconclusive';
        if ($a['visitors'] === 0) {
            $verdict = 'no_traffic';
        } elseif ($prob >= 95.0) {
            $verdict = 'likely_winner';
        } elseif ($prob <= 5.0) {
            $verdict = 'likely_loser';
        }

        $out[] = [
            'id' => $a['id'], 'name' => $a['name'], 'isControl' => false,
            'visitors' => $a['visitors'], 'conversions' => $a['conversions'],
            'rate' => round($a['rate'] * 100, 2),
            'probability' => round($prob, 1),
            'lift' => round($relLift, 1),
            'liftLow' => $relLow === null ? null : round($relLow, 1),
            'liftHigh' => $relHigh === null ? null : round($relHigh, 1),
            'verdict' => $verdict,
        ];
    }

    usort($out, function ($a, $b) { return $b['probability'] <=> $a['probability']; });

    $leader = null;
    foreach ($out as $o) {
        if (!$o['isControl']) { $leader = $o; break; }
    }
    $best = $out[0];

    if ($data['totals']['exposures'] === 0) {
        $headline = 'No traffic recorded yet';
        $detail = 'Win probability needs at least one exposure. Install the snippet and let visitors arrive.';
    } elseif ($leader === null || $leader['visitors'] === 0) {
        $headline = 'Only the control has traffic';
        $detail = 'No challenger has recorded a visitor yet, so there is nothing to compare against.';
    } elseif ($best['probability'] >= 95.0) {
        $headline = sprintf('%s leads with %s%% probability', $best['name'], p3_pct($best['probability']));
        $detail = sprintf('Its lift is %+.1f%% (95%% credible band %+.1f%% to %+.1f%%).', $best['lift'], $best['liftLow'], $best['liftHigh']);
    } elseif ($best['probability'] <= 5.0) {
        $headline = 'Control is ahead with high confidence';
        $detail = sprintf('The best challenger sits at %s%% probability to beat control.', p3_pct($best['probability']));
    } elseif ($best['isControl']) {
        $headline = sprintf('Control leads at %s%% — not yet decisive', p3_pct($best['probability']));
        $detail = 'No challenger is ahead yet. Between 5% and 95% the evidence is still open.';
    } else {
        $headline = sprintf('%s leads at %s%% — not yet decisive', $best['name'], p3_pct($best['probability']));
        $detail = 'Between 5% and 95% the evidence is still open. Keep collecting.';
    }

    return [
        'ready' => true,
        'headline' => $headline,
        'detail' => $detail,
        'control' => ['id' => $control['id'], 'name' => $control['name'],
                      'rate' => round($control['rate'] * 100, 2), 'visitors' => $control['visitors']],
        'variants' => $out,
        'decisionReady' => $best['probability'] >= 95.0 || $best['probability'] <= 5.0,
    ];
}

// ------------------------------------------------------------ 2. time to significance

function p3_time_to_significance($data, $traffic, $target = 0.95)
{
    $arms = $data['arms'];
    $control = null;
    $leader = null;
    foreach ($arms as $a) {
        if ($a['isControl']) { $control = $a; }
    }
    if ($control === null) {
        return ['ready' => false, 'reason' => 'No control variant to compare against.'];
    }
    foreach ($arms as $a) {
        if ($a['isControl'] || $a['visitors'] === 0) { continue; }
        if ($leader === null || $a['rate'] > $leader['rate']) { $leader = $a; }
    }
    if ($leader === null) {
        return ['ready' => false, 'reason' => 'No challenger has traffic yet.',
                'days' => null, 'visitorsNeeded' => null];
    }

    $p0 = $control['rate'];
    $p1 = $leader['rate'];
    $gap = $p1 - $p0;
    $daily = max(0.01, (float) $traffic['recent']);

    $cA = $control['conversions'] + 1.0;
    $cB = max($control['visitors'] - $control['conversions'], 0) + 1.0;
    $aA = $leader['conversions'] + 1.0;
    $bA = max($leader['visitors'] - $leader['conversions'], 0) + 1.0;

    $current = p3_prob_beats($aA, $bA, $cA, $cB) * 100.0;
    if ($current >= $target * 100.0) {
        return [
            'ready' => true, 'decided' => true, 'days' => 0,
            'visitorsNeeded' => 0, 'currentProbability' => round($current, 1),
            'target' => (int) round($target * 100), 'leaderName' => $leader['name'],
            'headline' => 'The verdict has already landed',
            'detail' => sprintf('%s is at %.0f%% probability — above the %.0f%% threshold.', $leader['name'], $current, $target * 100),
            'atCurrentRate' => true,
        ];
    }

    // A leader that is not actually ahead never reaches a winning verdict by
    // simply adding visitors at the same rates.
    if ($gap <= 0) {
        return [
            'ready' => true, 'decided' => false, 'days' => null,
            'visitorsNeeded' => null, 'currentProbability' => round($current, 1),
            'target' => (int) round($target * 100), 'leaderName' => $leader['name'],
            'headline' => 'No verdict will arrive at this gap',
            'detail' => sprintf('%s is not ahead of control. More visitors will not flip the result unless the rate changes — iterate on the variant instead.', $leader['name']),
            'atCurrentRate' => false,
        ];
    }

    // Scale both arms by the same factor and find where P(beat) crosses target.
    // This keeps the observed rates fixed and only adds sample size.
    $lo = 1.0;
    $hi = 4000.0;
    $found = null;
    for ($i = 0; $i < 26; $i++) {
        $mid = ($lo + $hi) / 2.0;
        $p = p3_prob_beats($aA * $mid, $bA * $mid, $cA * $mid, $cB * $mid) * 100.0;
        if ($p >= $target * 100.0) {
            $found = $mid;
            $hi = $mid;
        } else {
            $lo = $mid;
        }
        if (abs($hi - $lo) < 0.01) { break; }
    }

    if ($found === null) {
        return [
            'ready' => true, 'decided' => false, 'days' => null,
            'visitorsNeeded' => null, 'currentProbability' => round($current, 1),
            'target' => (int) round($target * 100), 'leaderName' => $leader['name'],
            'headline' => 'Too far from significance to project',
            'detail' => sprintf('Even at 4,000× the current traffic the gap does not reach %.0f%%. The effect is too small for this test size.', $target * 100),
            'atCurrentRate' => false,
        ];
    }

    $neededArm = max($control['visitors'], 1) * $found;
    $neededTotal = 2.0 * $neededArm;
    $have = $control['visitors'] + $leader['visitors'];
    $additional = max(0.0, $neededTotal - $have);
    $days = $additional / $daily;

    // A classic two-proportion cross-check, for readers who want the textbook number.
    $pbar = ($p0 + $p1) / 2.0;
    $zAlpha = 1.959964;
    $zPower = 0.8416212;
    $classic = ($p0 > 0 && $p1 > 0 && $gap > 0)
        ? pow($zAlpha + $zPower, 2) * (2.0 * $pbar * (1.0 - $pbar)) / ($gap * $gap)
        : null;

    return [
        'ready' => true,
        'decided' => false,
        'days' => (int) ceil($days),
        'visitorsNeeded' => (int) ceil($additional),
        'neededPerArm' => (int) ceil($neededArm),
        'currentProbability' => round($current, 1),
        'target' => (int) round($target * 100),
        'dailyTraffic' => round($daily, 1),
        'leaderName' => $leader['name'],
        'classicSampleSize' => $classic === null ? null : (int) ceil($classic),
        'headline' => sprintf('About %d more days at the current traffic', (int) ceil($days)),
        'detail' => sprintf('At %s visitors/day the leader reaches %.0f%% probability after roughly %s more visitors (about %d per arm).',
            number_format($daily), $target * 100, number_format($additional), (int) ceil($neededArm)),
        'atCurrentRate' => true,
    ];
}

// ------------------------------------------------------------ 3. projected lift

function p3_projected_lift($data, $traffic)
{
    $arms = $data['arms'];
    $control = null;
    $leader = null;
    foreach ($arms as $a) { if ($a['isControl']) { $control = $a; } }
    if ($control === null) {
        return ['ready' => false, 'reason' => 'No control variant.'];
    }
    foreach ($arms as $a) {
        if ($a['isControl'] || $a['visitors'] === 0) { continue; }
        if ($leader === null || $a['rate'] > $leader['rate']) { $leader = $a; }
    }
    if ($leader === null) {
        return ['ready' => false, 'reason' => 'No challenger has traffic yet.'];
    }

    $p0 = $control['rate'];
    $p1 = $leader['rate'];
    if ($p0 <= 0 && $p1 <= 0) {
        return ['ready' => false, 'reason' => 'Neither arm has a conversion yet, so there is no rate to project from.'];
    }

    $absDiff = $p1 - $p0;
    $relLift = $p0 > 0 ? ($absDiff / $p0) * 100.0 : null;

    list($m1, $v1) = posterior($leader['visitors'], $leader['conversions']);
    list($m0, $v0) = posterior($control['visitors'], $control['conversions']);
    $se = sqrt($v1 + $v0);
    $lo = $absDiff - 1.959964 * $se;
    $hi = $absDiff + 1.959964 * $se;

    $monthlyTraffic = max((float) $traffic['recent'], (float) $traffic['overall'] * 0.5) * 30.0;
    $gain = $monthlyTraffic * $absDiff;
    $gainLow = $monthlyTraffic * $lo;
    $gainHigh = $monthlyTraffic * $hi;

    $tone = 'info';
    if ($absDiff > 0 && $lo > 0) { $tone = 'good'; }
    if ($absDiff < 0 && $hi < 0) { $tone = 'bad'; }

    return [
        'ready' => true,
        'tone' => $tone,
        'leaderName' => $leader['name'],
        'controlRate' => round($p0 * 100, 2),
        'leaderRate' => round($p1 * 100, 2),
        'relativeLift' => $relLift === null ? null : round($relLift, 1),
        'absoluteLiftPp' => round($absDiff * 100, 2),
        'bandLowPp' => round($lo * 100, 2),
        'bandHighPp' => round($hi * 100, 2),
        'monthlyTraffic' => (int) round($monthlyTraffic),
        'monthlyGain' => round($gain, 0),
        'monthlyGainLow' => round($gainLow, 0),
        'monthlyGainHigh' => round($gainHigh, 0),
        'gainIsPositive' => $lo > 0,
        'headline' => $relLift === null
            ? sprintf('Ships %+.2f percentage points', $absDiff * 100)
            : sprintf('Shipping %s would lift conversion %+.1f%%', $leader['name'], $relLift),
        'detail' => sprintf('At %s visitors a month that is %s extra conversions (95%% band %s to %s).',
            number_format($monthlyTraffic),
            ($gain >= 0 ? '+' : '') . number_format($gain, 0),
            number_format($gainLow, 0), number_format($gainHigh, 0)),
    ];
}

// ------------------------------------------------------------ 4. power calculator

// Sample size for a two-proportion test. All inputs are explicit so the
// calculator also works before an experiment exists.
function p3_power($in)
{
    $p0 = isset($in['baselineRate']) ? (float) $in['baselineRate'] / 100.0 : 0.05;
    $mde = isset($in['mde']) ? abs((float) $in['mde']) / 100.0 : 0.10;
    $power = isset($in['power']) ? (float) $in['power'] / 100.0 : 0.80;
    $alpha = isset($in['alpha']) ? (float) $in['alpha'] : 0.05;
    $daily = isset($in['dailyTraffic']) ? max(0.0, (float) $in['dailyTraffic']) : 0.0;

    $p0 = max(1e-6, min(0.999999, $p0));
    $mde = max(1e-6, min(2.0, $mde));
    $power = max(0.5, min(0.999, $power));
    $alpha = max(0.001, min(0.2, $alpha));

    $p1 = min(0.999999, $p0 * (1.0 + $mde));
    $delta = abs($p1 - $p0);
    $zA = p3_z(1.0 - $alpha / 2.0);
    $zB = p3_z($power);

    $nArm = ($delta > 0)
        ? pow($zA + $zB, 2) * ($p0 * (1.0 - $p0) + $p1 * (1.0 - $p1)) / ($delta * $delta)
        : 0.0;
    $nArm = (int) ceil($nArm);
    $total = $nArm * 2;
    $days = $daily > 0 ? (int) ceil($total / $daily) : null;

    // A small sensitivity matrix, so the user sees how MDE drives the answer.
    $matrix = [];
    foreach ([0.05, 0.10, 0.15, 0.20, 0.30, 0.50] as $m) {
        $pm = min(0.999999, $p0 * (1.0 + $m));
        $d = abs($pm - $p0);
        $n = $d > 0 ? pow($zA + $zB, 2) * ($p0 * (1.0 - $p0) + $pm * (1.0 - $pm)) / ($d * $d) : 0.0;
        $n = (int) ceil($n);
        $matrix[] = [
            'mde' => (int) round($m * 100),
            'perArm' => $n,
            'total' => $n * 2,
            'days' => $daily > 0 ? (int) ceil(($n * 2) / $daily) : null,
        ];
    }

    return [
        'baselineRate' => round($p0 * 100, 2),
        'targetRate' => round($p1 * 100, 2),
        'mde' => round($mde * 100, 1),
        'power' => (int) round($power * 100),
        'alpha' => $alpha,
        'perArm' => $nArm,
        'total' => $total,
        'dailyTraffic' => $daily > 0 ? round($daily, 1) : null,
        'days' => $days,
        'trafficKnown' => $daily > 0,
        'matrix' => $matrix,
        'headline' => $nArm > 0
            ? sprintf('%s visitors per arm', number_format($nArm))
            : 'No sample size — the effect is zero',
        'detail' => $days === null
            ? sprintf('To detect a %+.0f%% change on a %.2f%% baseline at %d%% power you need %s visitors in total.',
                $mde * 100, $p0 * 100, (int) round($power * 100), number_format($total))
            : sprintf('That is %s visitors in total, or about %d days at %s visitors/day.',
                number_format($total), $days, number_format($daily)),
    ];
}

// ------------------------------------------------------------ 5. anomalies

function p3_anomalies($data, $traffic)
{
    $daily = $data['daily'];
    $flags = [];

    // Drop today's partial day: a half-finished day always looks like a drop.
    $today = gmdate('Y-m-d');
    if (count($daily) > 1 && isset($daily[count($daily) - 1]['date']) && $daily[count($daily) - 1]['date'] === $today) {
        array_pop($daily);
    }
    $n = count($daily);

    if ($n === 0) {
        return ['ready' => false, 'flags' => [], 'reason' => 'No daily history to scan yet.',
                'headline' => 'No history to scan', 'detail' => 'Anomaly detection needs at least two days of events.'];
    }

    // ---- traffic drops across the daily series
    $exp = [];
    foreach ($daily as $d) { $exp[] = $d['exposures']; }
    $mean = array_sum($exp) / max(1, count($exp));
    $var = 0.0;
    foreach ($exp as $v) { $var += pow($v - $mean, 2); }
    $sd = count($exp) > 1 ? sqrt($var / (count($exp) - 1)) : 0.0;

    foreach ($daily as $i => $d) {
        if ($d['exposures'] < 10 || $sd <= 0) { continue; }
        $z = ($d['exposures'] - $mean) / $sd;
        if ($z <= -2.0) {
            $flags[] = [
                'severity' => $z <= -3.0 ? 'high' : 'medium',
                'kind' => 'traffic',
                'title' => 'Traffic drop on ' . $d['date'],
                'detail' => sprintf('%s exposures, %.1f standard deviations below the %s daily average.',
                    number_format($d['exposures']), abs($z), number_format($mean, 0)),
                'metric' => number_format($d['exposures']),
            ];
        }
    }

    // ---- day-over-day collapse
    for ($i = 1; $i < $n; $i++) {
        $prev = $daily[$i - 1]['exposures'];
        $cur = $daily[$i]['exposures'];
        if ($prev >= 30 && $cur > 0 && ($cur / $prev) <= 0.5) {
            $flags[] = [
                'severity' => ($cur / $prev) <= 0.25 ? 'high' : 'medium',
                'kind' => 'traffic',
                'title' => 'Traffic halved on ' . $daily[$i]['date'],
                'detail' => sprintf('Fell from %s to %s exposures day over day.',
                    number_format($prev), number_format($cur)),
                'metric' => sprintf('%.0f%%', ($cur / $prev) * 100),
            ];
        }
    }

    // ---- conversion-rate collapse
    $overallConv = $data['totals']['conversions'];
    $overallExp = $data['totals']['exposures'];
    $overallRate = $overallExp > 0 ? $overallConv / $overallExp : 0.0;
    if ($overallRate > 0) {
        $convFlags = [];
        foreach ($daily as $d) {
            if ($d['exposures'] < 30) { continue; }
            $r = $d['conversions'] / $d['exposures'];
            if ($r < $overallRate * 0.5) {
                $convFlags[] = [
                    'severity' => $r < $overallRate * 0.25 ? 'high' : 'medium',
                    'kind' => 'conversion',
                    'title' => 'Conversion drop on ' . $d['date'],
                    'detail' => sprintf('%.2f%% conversion rate against a %.2f%% period average, on %s visitors.',
                        $r * 100, $overallRate * 100, number_format($d['exposures'])),
                    'metric' => sprintf('%.2f%%', $r * 100),
                ];
            }
        }
        // Collapse a run of bad days into one flag so the list stays readable.
        if (count($convFlags) > 0) {
            if (count($convFlags) === 1) {
                $flags[] = $convFlags[0];
            } else {
                $worst = $convFlags[0];
                foreach ($convFlags as $f) {
                    if ($f['severity'] === 'high') { $worst = $f; break; }
                }
                $flags[] = [
                    'severity' => $worst['severity'],
                    'kind' => 'conversion',
                    'title' => sprintf('Conversion rate below half average on %d days', count($convFlags)),
                    'detail' => sprintf('The daily rate fell under half the %.2f%% period average on %d of %d days. Worst day: %s.',
                        $overallRate * 100, count($convFlags), $n, $worst['title']),
                    'metric' => $worst['metric'],
                ];
            }
        }
    }

    // ---- sample ratio mismatch: a real chi-square on the variant split
    $arms = $data['arms'];
    $live = [];
    foreach ($arms as $a) { if ($a['visitors'] > 0) { $live[] = $a; } }
    if (count($live) >= 2) {
        $total = 0;
        foreach ($live as $a) { $total += $a['visitors']; }
        $expected = $total / count($live);
        $chi2 = 0.0;
        foreach ($live as $a) {
            $chi2 += pow($a['visitors'] - $expected, 2) / max(1e-9, $expected);
        }
        $df = count($live) - 1;
        $p = p3_chi2_sf($chi2, $df);
        if ($p < 0.001 && $total >= 100) {
            $worst = $live[0];
            foreach ($live as $a) {
                if (abs($a['visitors'] - $expected) > abs($worst['visitors'] - $expected)) { $worst = $a; }
            }
            $flags[] = [
                'severity' => $p < 0.0001 ? 'high' : 'medium',
                'kind' => 'srm',
                'title' => 'Sample ratio mismatch',
                'detail' => sprintf('The split is significantly uneven (χ²=%.2f, p=%.5f). %s has %s of an expected %s. Usually a redirect or caching problem in the test setup.',
                    $chi2, $p, $worst['name'], number_format($worst['visitors']), number_format($expected)),
                'metric' => sprintf('p=%.4f', $p),
            ];
        }
    }

    // ---- stalled traffic: nothing in the last 48h while the test is active
    $lastDate = $daily[$n - 1]['date'];
    $lastTs = strtotime($lastDate . ' 12:00:00');
    $hoursStale = (time() - $lastTs) / 3600.0;
    if ($hoursStale > 48 && $data['totals']['exposures'] > 0) {
        $flags[] = [
            'severity' => 'high',
            'kind' => 'stalled',
            'title' => 'No events for ' . (int) floor($hoursStale / 24) . ' days',
            'detail' => sprintf('The last recorded exposure was %s. The snippet may have been removed, or traffic has stopped.', $lastDate),
            'metric' => (int) floor($hoursStale / 24) . 'd',
        ];
    }

    usort($flags, function ($a, $b) {
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        return $rank[$a['severity']] <=> $rank[$b['severity']];
    });

    $high = 0;
    foreach ($flags as $f) { if ($f['severity'] === 'high') { $high++; } }

    return [
        'ready' => true,
        'flags' => array_slice($flags, 0, 8),
        'count' => count($flags),
        'highCount' => $high,
        'daysScanned' => $n,
        'headline' => count($flags) === 0 ? 'No anomalies in the last ' . $n . ' days' : count($flags) . ' anomal' . (count($flags) === 1 ? 'y' : 'ies') . ' flagged',
        'detail' => count($flags) === 0
            ? 'Daily traffic and conversion rates stay inside their normal range, and the variant split is balanced.'
            : ($high > 0 ? $high . ' of these are high severity and worth checking before you read the results.' : 'All flagged items are medium severity — worth watching, not yet a reason to stop.'),
        'tone' => $high > 0 ? 'bad' : (count($flags) > 0 ? 'warn' : 'good'),
    ];
}

// ------------------------------------------------------------ entry points

// One payload for the Insights page: everything the five cards need.
function p3_overview($user, $in)
{
    $experimentId = isset($in['experimentId']) ? (int) $in['experimentId'] : 0;
    $data = p3_dataset($user['id'], $experimentId);
    if ($data === null) {
        return [
            'ready' => false,
            'reason' => 'No experiment with visitor data yet.',
            'headline' => 'No experiments to analyse',
            'detail' => 'Create an experiment and let visitors arrive, or load the sample data on the Insights page to see every prediction work.',
            'power' => p3_power($in),
        ];
    }

    $traffic = p3_traffic_rate($data);
    $win = p3_win_probability($data);
    $timing = p3_time_to_significance($data, $traffic);
    $lift = p3_projected_lift($data, $traffic);
    $anomalies = p3_anomalies($data, $traffic);

    // The calculator defaults to the control's real rate and real traffic.
    $controlRate = 0.0;
    foreach ($data['arms'] as $a) {
        if ($a['isControl'] && $a['rate'] > 0) { $controlRate = $a['rate']; }
    }
    if ($controlRate <= 0) {
        $controlRate = $data['totals']['exposures'] > 0
            ? $data['totals']['conversions'] / max(1, $data['totals']['exposures'])
            : 0.0;
    }
    $powerIn = [
        'baselineRate' => isset($in['baselineRate']) ? (float) $in['baselineRate'] : $controlRate * 100.0,
        'mde' => isset($in['mde']) ? (float) $in['mde'] : 10.0,
        'power' => isset($in['power']) ? (float) $in['power'] : 80.0,
        'alpha' => isset($in['alpha']) ? (float) $in['alpha'] : 0.05,
        'dailyTraffic' => $traffic['recent'] > 0 ? $traffic['recent'] : $traffic['overall'],
    ];

    return [
        'ready' => true,
        'experiment' => ['id' => (int) $data['experiment']['id'], 'name' => $data['experiment']['name'],
                         'status' => $data['experiment']['status']],
        'visitors' => $data['totals']['exposures'],
        'conversions' => $data['totals']['conversions'],
        'traffic' => $traffic,
        'win' => $win,
        'timing' => $timing,
        'lift' => $lift,
        'power' => p3_power($powerIn),
        'anomalies' => $anomalies,
        'computedAt' => gmdate('c'),
        'engine' => 'php',
    ];
}

// Standalone calculator route, so the page can recompute on input change
// without re-reading the experiment.
function p3_power_route($user, $in)
{
    $data = p3_dataset($user['id'], isset($in['experimentId']) ? (int) $in['experimentId'] : 0);
    $traffic = $data === null ? ['recent' => 0, 'overall' => 0, 'days' => 0, 'source' => 'none']
                              : p3_traffic_rate($data);
    $in['dailyTraffic'] = $traffic['recent'] > 0 ? $traffic['recent'] : $traffic['overall'];
    if (!isset($in['baselineRate']) || (float) $in['baselineRate'] <= 0) {
        $rate = 5.0;
        if ($data !== null && $data['totals']['exposures'] > 0) {
            $rate = ($data['totals']['conversions'] / max(1, $data['totals']['exposures'])) * 100.0;
            if ($rate <= 0) { $rate = 5.0; }
        }
        $in['baselineRate'] = $rate;
    }
    return p3_power($in);
}
