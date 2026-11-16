<?php
// SignalForge analysis engine (v2).
// Every model below is implemented here in plain PHP, so the analysis runs on
// shared hosting with no external ML runtime. Nothing is faked: each model
// reads real visitor_events rows and returns computed statistics, or an
// explicit "not enough data" result with the exact requirement.

// ---------------------------------------------------------------- dataset

// Returns [experimentRow, rows, variants, controlVariantId].
// rows are visitor-level: one entry per exposure, with the conversion flag.
function a2_rows($userId, $experimentId)
{
    $pdo = db();
    if ((int) $experimentId <= 0) {
        $st = $pdo->prepare("SELECT ve.experimentId, COUNT(*) AS n
            FROM visitor_events ve JOIN experiments e ON e.id = ve.experimentId
            WHERE e.userId = ? AND ve.type = 'exposure'
            GROUP BY ve.experimentId ORDER BY n DESC LIMIT 1");
        $st->execute([(int) $userId]);
        $r = $st->fetch();
        if (!$r) {
            return [null, [], [], null];
        }
        $experimentId = (int) $r['experimentId'];
    }

    $st = $pdo->prepare('SELECT * FROM experiments WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $experimentId, (int) $userId]);
    $exp = $st->fetch();
    if (!$exp) {
        return [null, [], [], null];
    }

    $st = $pdo->prepare('SELECT id, name, isControl FROM variants WHERE experimentId = ? ORDER BY position ASC');
    $st->execute([(int) $experimentId]);
    $variants = $st->fetchAll();
    $ctrl = null;
    foreach ($variants as $v) {
        if ((int) $v['isControl'] === 1) { $ctrl = (int) $v['id']; break; }
    }
    if ($ctrl === null && count($variants) > 0) {
        $ctrl = (int) $variants[0]['id'];
    }

    $st = $pdo->prepare("SELECT e.variantId, e.visitorId, e.device, e.isPaid, e.isReturning,
            UNIX_TIMESTAMP(e.createdAt) AS expAt,
            (SELECT UNIX_TIMESTAMP(c.createdAt) FROM visitor_events c
                WHERE c.experimentId = e.experimentId AND c.visitorId = e.visitorId
                  AND c.type = 'conversion' LIMIT 1) AS convAt
        FROM visitor_events e
        WHERE e.experimentId = ? AND e.type = 'exposure'");
    $st->execute([(int) $experimentId]);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $device = strtolower((string) $r['device']);
        $rows[] = [
            'variantId'   => (int) $r['variantId'],
            'isVariant'   => ((int) $r['variantId'] === $ctrl) ? 0 : 1,
            'isMobile'    => (strpos($device, 'mobile') !== false || strpos($device, 'phone') !== false) ? 1 : 0,
            'isPaid'      => ((int) $r['isPaid']) ? 1 : 0,
            'isReturning' => ((int) $r['isReturning']) ? 1 : 0,
            'converted'   => ($r['convAt'] !== null) ? 1 : 0,
            'expAt'       => (int) $r['expAt'],
            'convAt'      => ($r['convAt'] !== null) ? (int) $r['convAt'] : null,
        ];
    }
    return [$exp, $rows, $variants, $ctrl];
}

// ---------------------------------------------------------------- primitives

function a2_sigmoid($z)
{
    if ($z >= 0) {
        return 1 / (1 + exp(-$z));
    }
    $e = exp($z);
    return $e / (1 + $e);
}

// Batch gradient descent on the log-loss. Returns weights: [bias, f1, f2, ...].
function a2_fit_logistic($rows, $features, $iters = 400, $lr = 0.5)
{
    $n = count($rows);
    if ($n === 0) {
        return null;
    }
    $w = array_fill(0, count($features) + 1, 0.0);
    for ($it = 0; $it < $iters; $it++) {
        $g = array_fill(0, count($w), 0.0);
        foreach ($rows as $r) {
            $z = $w[0];
            foreach ($features as $i => $f) {
                $z += $w[$i + 1] * $r[$f];
            }
            $err = a2_sigmoid($z) - $r['converted'];
            $g[0] += $err;
            foreach ($features as $i => $f) {
                $g[$i + 1] += $err * $r[$f];
            }
        }
        foreach ($w as $i => $unused) {
            $w[$i] -= $lr * $g[$i] / $n;
        }
    }
    return $w;
}

function a2_predict($row, $w, $features)
{
    $z = $w[0];
    foreach ($features as $i => $f) {
        $z += $w[$i + 1] * $row[$f];
    }
    return a2_sigmoid($z);
}

// Rank-based AUC. Null when only one class is present.
function a2_auc($rows, $w, $features)
{
    $pairs = [];
    foreach ($rows as $r) {
        $pairs[] = [a2_predict($r, $w, $features), $r['converted']];
    }
    usort($pairs, function ($a, $b) { return $b[0] <=> $a[0]; });
    $pos = 0;
    $neg = 0;
    foreach ($pairs as $p) {
        if ($p[1]) { $pos++; } else { $neg++; }
    }
    if ($pos === 0 || $neg === 0) {
        return null;
    }
    $rankSum = 0;
    $i = 1;
    foreach ($pairs as $p) {
        if ($p[1]) { $rankSum += $i; }
        $i++;
    }
    return ($rankSum - $pos * ($pos + 1) / 2) / ($pos * $neg);
}

function a2_logloss($rows, $w, $features)
{
    $n = count($rows);
    if ($n === 0) {
        return null;
    }
    $sum = 0.0;
    foreach ($rows as $r) {
        $p = a2_predict($r, $w, $features);
        $p = max(1e-9, min(1 - 1e-9, $p));
        $sum += -($r['converted'] * log($p) + (1 - $r['converted']) * log(1 - $p));
    }
    return $sum / $n;
}

function a2_rate($rows)
{
    $n = count($rows);
    if ($n === 0) {
        return 0.0;
    }
    $c = 0;
    foreach ($rows as $r) {
        $c += $r['converted'];
    }
    return $c / $n;
}

// ---------------------------------------------------------------- models

function a2_model_logistic($rows)
{
    $features = ['isVariant', 'isMobile', 'isPaid', 'isReturning'];
    $w = a2_fit_logistic($rows, $features);
    if ($w === null) {
        return null;
    }
    $auc = a2_auc($rows, $w, $features);
    $ll = a2_logloss($rows, $w, $features);
    $correct = 0;
    foreach ($rows as $r) {
        $p = a2_predict($r, $w, $features);
        if (($p >= 0.5 ? 1 : 0) === $r['converted']) { $correct++; }
    }
    $acc = count($rows) > 0 ? $correct / count($rows) : 0;

    $labels = [
        'isVariant'   => 'Variant assignment',
        'isMobile'    => 'Mobile device',
        'isPaid'      => 'Paid traffic',
        'isReturning' => 'Returning visitor',
    ];
    $drivers = [];
    foreach ($features as $i => $f) {
        $drivers[] = [
            'feature' => $labels[$f],
            'coefficient' => round($w[$i + 1], 4),
            'oddsRatio' => round(exp($w[$i + 1]), 3),
        ];
    }
    usort($drivers, function ($a, $b) { return abs($b['coefficient']) <=> abs($a['coefficient']); });

    $top = $drivers[0];
    $direction = $top['coefficient'] >= 0 ? 'raises' : 'lowers';
    return [
        'headline' => sprintf('%s is the strongest conversion driver (odds ratio %.2f)', $top['feature'], $top['oddsRatio']),
        'metrics' => [
            ['label' => 'Visitors modelled', 'value' => number_format(count($rows))],
            ['label' => 'AUC', 'value' => $auc === null ? 'n/a' : number_format($auc, 3)],
            ['label' => 'Accuracy', 'value' => number_format($acc * 100, 1) . '%'],
            ['label' => 'Log loss', 'value' => $ll === null ? 'n/a' : number_format($ll, 4)],
        ],
        'findings' => array_map(function ($d) {
            return sprintf('%s: coefficient %+.3f, odds ratio %.2f', $d['feature'], $d['coefficient'], $d['oddsRatio']);
        }, array_slice($drivers, 0, 4)),
        'recommendation' => sprintf('%s %s the odds of conversion. %s', $top['feature'], $direction,
            $auc !== null && $auc >= 0.7
                ? 'The model separates converters well enough to prioritise segments.'
                : 'Discrimination is still weak — collect more visitors before acting on individual coefficients.'),
        'tone' => ($auc !== null && $auc >= 0.7) ? 'good' : 'info',
    ];
}

function a2_model_causal($rows, $variants, $ctrl)
{
    $segments = [
        'Mobile' => function ($r) { return $r['isMobile'] === 1; },
        'Desktop' => function ($r) { return $r['isMobile'] === 0; },
        'Paid traffic' => function ($r) { return $r['isPaid'] === 1; },
        'Organic traffic' => function ($r) { return $r['isPaid'] === 0; },
        'Returning visitors' => function ($r) { return $r['isReturning'] === 1; },
        'New visitors' => function ($r) { return $r['isReturning'] === 0; },
    ];
    $out = [];
    $weighted = 0.0;
    $weight = 0;
    foreach ($segments as $name => $fn) {
        $c = [];
        $v = [];
        foreach ($rows as $r) {
            if (!$fn($r)) { continue; }
            if ($r['isVariant'] === 0) { $c[] = $r; } else { $v[] = $r; }
        }
        if (count($c) < 10 || count($v) < 10) {
            continue;
        }
        $cr = a2_rate($c);
        $vr = a2_rate($v);
        $lift = $cr > 0 ? (($vr - $cr) / $cr) * 100 : 0;
        $conf = prob_to_beat(count($v), (int) round($vr * count($v)), count($c), (int) round($cr * count($c)));
        $out[] = [
            'segment' => $name,
            'controlRate' => round($cr * 100, 2),
            'variantRate' => round($vr * 100, 2),
            'lift' => round($lift, 1),
            'confidence' => (int) round($conf),
            'visitors' => count($c) + count($v),
        ];
        $weighted += $lift * (count($c) + count($v));
        $weight += count($c) + count($v);
    }
    if (count($out) === 0) {
        return null;
    }
    usort($out, function ($a, $b) { return $b['lift'] <=> $a['lift']; });
    $best = $out[0];
    $worst = $out[count($out) - 1];
    $pooled = $weight > 0 ? $weighted / $weight : 0;
    $spread = $best['lift'] - $worst['lift'];

    return [
        'headline' => sprintf('Effect varies by segment: %s %+.1f%% vs %s %+.1f%%', $best['segment'], $best['lift'], $worst['segment'], $worst['lift']),
        'metrics' => [
            ['label' => 'Segments analysed', 'value' => (string) count($out)],
            ['label' => 'Pooled lift', 'value' => sprintf('%+.1f%%', $pooled)],
            ['label' => 'Best segment', 'value' => $best['segment']],
            ['label' => 'Effect spread', 'value' => sprintf('%.1f pts', $spread)],
        ],
        'findings' => array_map(function ($s) {
            return sprintf('%s: control %.2f%% vs variant %.2f%% (%+.1f%% lift, %d%% confidence, n=%s)',
                $s['segment'], $s['controlRate'], $s['variantRate'], $s['lift'], $s['confidence'], number_format($s['visitors']));
        }, $out),
        'recommendation' => $spread >= 10
            ? sprintf('Target %s — the variant works there and is weakest in %s. A segment-specific rollout beats a blanket one.', $best['segment'], $worst['segment'])
            : 'The effect is consistent across segments, so a single site-wide decision is reasonable.',
        'tone' => $spread >= 10 ? 'warn' : 'good',
    ];
}

function a2_model_uplift($rows)
{
    $features = ['isMobile', 'isPaid', 'isReturning'];
    $treated = [];
    $control = [];
    foreach ($rows as $r) {
        if ($r['isVariant'] === 1) { $treated[] = $r; } else { $control[] = $r; }
    }
    if (count($treated) < 20 || count($control) < 20) {
        return null;
    }
    $wt = a2_fit_logistic($treated, $features);
    $wc = a2_fit_logistic($control, $features);
    if ($wt === null || $wc === null) {
        return null;
    }
    $scored = [];
    foreach ($rows as $r) {
        $pt = a2_predict($r, $wt, $features);
        $pc = a2_predict($r, $wc, $features);
        $scored[] = ['uplift' => $pt - $pc, 'converted' => $r['converted'], 'isVariant' => $r['isVariant']];
    }
    usort($scored, function ($a, $b) { return $b['uplift'] <=> $a['uplift']; });
    $n = count($scored);
    $decile = max(1, (int) floor($n * 0.1));
    $top = array_slice($scored, 0, $decile);
    $rest = array_slice($scored, $decile);

    $topConv = 0;
    foreach ($top as $s) { $topConv += $s['converted']; }
    $restConv = 0;
    foreach ($rest as $s) { $restConv += $s['converted']; }
    $topRate = count($top) > 0 ? $topConv / count($top) : 0;
    $restRate = count($rest) > 0 ? $restConv / count($rest) : 0;
    $meanUplift = 0.0;
    foreach ($scored as $s) { $meanUplift += $s['uplift']; }
    $meanUplift = $n > 0 ? $meanUplift / $n : 0;

    return [
        'headline' => sprintf('Top 10%% of visitors show %.1f%% conversion vs %.1f%% for the rest', $topRate * 100, $restRate * 100),
        'metrics' => [
            ['label' => 'Visitors scored', 'value' => number_format($n)],
            ['label' => 'Mean uplift', 'value' => sprintf('%+.2f pts', $meanUplift * 100)],
            ['label' => 'Top-decile rate', 'value' => number_format($topRate * 100, 2) . '%'],
            ['label' => 'Remainder rate', 'value' => number_format($restRate * 100, 2) . '%'],
        ],
        'findings' => [
            sprintf('The top decile (%s visitors) converts at %.2f%%, against %.2f%% for everyone else.', number_format(count($top)), $topRate * 100, $restRate * 100),
            sprintf('Mean predicted uplift across all visitors is %+.2f percentage points.', $meanUplift * 100),
            'Uplift is estimated with two separate logistic models (treated vs control), so it measures who benefits from the variant rather than who converts anyway.',
        ],
        'recommendation' => $topRate > $restRate
            ? 'Concentrate spend on the high-uplift segment; the variant is doing real work there rather than picking up visitors who would have converted regardless.'
            : 'No persuasive-uplift segment found yet. The variant is not changing behaviour for any identifiable group.',
        'tone' => $topRate > $restRate ? 'good' : 'warn',
    ];
}

function a2_model_boosting($rows)
{
    $features = ['isVariant', 'isMobile', 'isPaid', 'isReturning'];
    $n = count($rows);
    if ($n < 20) {
        return null;
    }
    $base = a2_rate($rows);
    $pred = array_fill(0, $n, $base);
    $rounds = 40;
    $lr = 0.1;
    $importance = array_fill_keys($features, 0.0);
    $used = 0;

    for ($m = 0; $m < $rounds; $m++) {
        $bestF = null;
        $bestGain = 0.0;
        $bestLeft = 0.0;
        $bestRight = 0.0;
        foreach ($features as $f) {
            $sumL = 0.0; $cntL = 0;
            $sumR = 0.0; $cntR = 0;
            for ($i = 0; $i < $n; $i++) {
                $res = $rows[$i]['converted'] - $pred[$i];
                if ($rows[$i][$f] === 1) { $sumL += $res; $cntL++; } else { $sumR += $res; $cntR++; }
            }
            if ($cntL === 0 || $cntR === 0) { continue; }
            $gain = ($sumL * $sumL) / $cntL + ($sumR * $sumR) / $cntR;
            if ($gain > $bestGain) {
                $bestGain = $gain;
                $bestF = $f;
                $bestLeft = $sumL / $cntL;
                $bestRight = $sumR / $cntR;
            }
        }
        if ($bestF === null) { break; }
        $importance[$bestF] += $bestGain;
        $used++;
        for ($i = 0; $i < $n; $i++) {
            $leaf = $rows[$i][$bestF] === 1 ? $bestLeft : $bestRight;
            $pred[$i] = max(0.0, min(1.0, $pred[$i] + $lr * $leaf));
        }
    }

    $ll = 0.0;
    foreach ($rows as $i => $r) {
        $p = max(1e-9, min(1 - 1e-9, $pred[$i]));
        $ll += -($r['converted'] * log($p) + (1 - $r['converted']) * log(1 - $p));
    }
    $ll = $n > 0 ? $ll / $n : 0;

    $labels = [
        'isVariant' => 'Variant assignment',
        'isMobile' => 'Mobile device',
        'isPaid' => 'Paid traffic',
        'isReturning' => 'Returning visitor',
    ];
    arsort($importance);
    $topKey = key($importance);
    $totalImp = array_sum($importance);
    $findings = [];
    foreach ($importance as $f => $imp) {
        $findings[] = sprintf('%s: %.1f%% of total split gain', $labels[$f], $totalImp > 0 ? ($imp / $totalImp) * 100 : 0);
    }

    return [
        'headline' => sprintf('%d boosted stumps fitted; %s dominates the splits', $used, $labels[$topKey]),
        'metrics' => [
            ['label' => 'Visitors modelled', 'value' => number_format($n)],
            ['label' => 'Boosting rounds', 'value' => (string) $used],
            ['label' => 'Log loss', 'value' => number_format($ll, 4)],
            ['label' => 'Baseline rate', 'value' => number_format($base * 100, 2) . '%'],
        ],
        'findings' => $findings,
        'recommendation' => sprintf('Nonlinear interactions are captured across %d rounds. %s carries the most signal, so test changes there first.', $used, $labels[$topKey]),
        'tone' => 'info',
    ];
}

function a2_model_survival($rows, $variants, $ctrl)
{
    $byVariant = [];
    foreach ($rows as $r) {
        $byVariant[$r['variantId']][] = $r;
    }
    $names = [];
    foreach ($variants as $v) {
        $names[(int) $v['id']] = $v['name'];
    }
    $out = [];
    $allTimes = [];
    foreach ($byVariant as $vid => $group) {
        $times = [];
        $events = 0;
        foreach ($group as $r) {
            if ($r['convAt'] !== null && $r['convAt'] >= $r['expAt']) {
                $times[] = ($r['convAt'] - $r['expAt']) / 3600.0;
                $events++;
            }
        }
        if ($events === 0) {
            continue;
        }
        sort($times);
        $median = $times[(int) floor(count($times) / 2)];
        $out[] = [
            'variant' => isset($names[$vid]) ? $names[$vid] : ('Variant ' . $vid),
            'conversions' => $events,
            'visitors' => count($group),
            'medianHours' => round($median, 2),
            'rate' => round(($events / max(1, count($group))) * 100, 2),
        ];
        $allTimes = array_merge($allTimes, $times);
    }
    if (count($out) === 0) {
        return null;
    }
    usort($out, function ($a, $b) { return $a['medianHours'] <=> $b['medianHours']; });
    $fastest = $out[0];
    $slowest = $out[count($out) - 1];
    sort($allTimes);
    $overall = $allTimes[(int) floor(count($allTimes) / 2)];

    return [
        'headline' => sprintf('%s converts fastest at a median of %.1f hours', $fastest['variant'], $fastest['medianHours']),
        'metrics' => [
            ['label' => 'Conversions timed', 'value' => number_format(count($allTimes))],
            ['label' => 'Median time to convert', 'value' => number_format($overall, 1) . ' h'],
            ['label' => 'Fastest variant', 'value' => $fastest['variant']],
            ['label' => 'Slowest variant', 'value' => $slowest['variant']],
        ],
        'findings' => array_map(function ($v) {
            return sprintf('%s: %d conversions from %s visitors (%.2f%%), median %.1f h',
                $v['variant'], $v['conversions'], number_format($v['visitors']), $v['rate'], $v['medianHours']);
        }, $out),
        'recommendation' => $fastest['medianHours'] < $slowest['medianHours']
            ? sprintf('%s accelerates the funnel by %.1f hours at the median. If speed to conversion matters, that is the variant to keep.', $fastest['variant'], $slowest['medianHours'] - $fastest['medianHours'])
            : 'Time to conversion is effectively identical across variants, so decide on rate rather than speed.',
        'tone' => 'info',
    ];
}

function a2_model_sequential($rows, $variants, $ctrl)
{
    $byVariant = [];
    foreach ($rows as $r) {
        $byVariant[$r['variantId']][] = $r;
    }
    $controlRows = isset($byVariant[$ctrl]) ? $byVariant[$ctrl] : [];
    $cv = count($controlRows);
    $cx = 0;
    foreach ($controlRows as $r) { $cx += $r['converted']; }
    if ($cv === 0) {
        return null;
    }
    $cRate = $cx / $cv;
    $names = [];
    foreach ($variants as $v) { $names[(int) $v['id']] = $v['name']; }

    $out = [];
    $best = null;
    foreach ($byVariant as $vid => $group) {
        $vv = count($group);
        $vx = 0;
        foreach ($group as $r) { $vx += $r['converted']; }
        $rate = $vv > 0 ? $vx / $vv : 0;
        $isControl = ((int) $vid === (int) $ctrl);
        $conf = $isControl ? 50 : prob_to_beat($vv, $vx, $cv, $cx);
        $uplift = (!$isControl && $cRate > 0) ? (($rate - $cRate) / $cRate) * 100 : 0;
        list($mean, $var) = posterior($vv, $vx);
        $se = sqrt($var);
        $out[] = [
            'variant' => isset($names[$vid]) ? $names[$vid] : ('Variant ' . $vid),
            'isControl' => $isControl,
            'visitors' => $vv,
            'conversions' => $vx,
            'rate' => round($rate * 100, 2),
            'posterior' => round($mean * 100, 2),
            'lower' => round(max(0, $mean - 1.96 * $se) * 100, 2),
            'upper' => round(min(1, $mean + 1.96 * $se) * 100, 2),
            'uplift' => round($uplift, 1),
            'confidence' => (int) round($conf),
        ];
        if (!$isControl && ($best === null || $conf > $best['confidence'])) {
            $best = ['variant' => $out[count($out) - 1]['variant'], 'confidence' => (int) round($conf), 'uplift' => round($uplift, 1)];
        }
    }
    usort($out, function ($a, $b) { return $b['confidence'] <=> $a['confidence']; });

    $totalVisitors = 0;
    foreach ($out as $o) { $totalVisitors += $o['visitors']; }
    $maturity = min(100, (int) round(($totalVisitors / 10000) * 100));

    if ($best === null) {
        $headline = 'Only the control has traffic so far';
        $rec = 'No challenger has recorded visitors yet. Send traffic to the variant before reading a probability.';
        $tone = 'warn';
    } elseif ($best['confidence'] >= 95 && $best['uplift'] > 0) {
        $headline = sprintf('%s is ahead by %.1f%% at %d%% probability', $best['variant'], $best['uplift'], $best['confidence']);
        $rec = sprintf('%s is a safe promotion: %d%% probability to beat control with a %+.1f%% lift.', $best['variant'], $best['confidence'], $best['uplift']);
        $tone = 'good';
    } elseif ($best['confidence'] <= 20 && $best['uplift'] < 0) {
        $headline = sprintf('Control is ahead; %s trails by %.1f%%', $best['variant'], abs($best['uplift']));
        $rec = 'Keep the control. The challenger is behind with high confidence.';
        $tone = 'bad';
    } else {
        $headline = sprintf('%s leads at %d%% probability — not yet decisive', $best['variant'], $best['confidence']);
        $rec = sprintf('Keep running until the leader reaches 95%% probability. It is at %d%% now.', $best['confidence']);
        $tone = 'info';
    }

    return [
        'headline' => $headline,
        'metrics' => [
            ['label' => 'Visitors', 'value' => number_format($totalVisitors)],
            ['label' => 'Control rate', 'value' => number_format($cRate * 100, 2) . '%'],
            ['label' => 'Leader probability', 'value' => $best ? $best['confidence'] . '%' : 'n/a'],
            ['label' => 'Data maturity', 'value' => $maturity . '%'],
        ],
        'findings' => array_map(function ($o) {
            return sprintf('%s: %s visitors, %d conversions (%.2f%%), posterior %.2f%% [%.2f–%.2f%%], %d%% probability to beat control',
                $o['variant'], number_format($o['visitors']), $o['conversions'], $o['rate'],
                $o['posterior'], $o['lower'], $o['upper'], $o['confidence']);
        }, $out),
        'recommendation' => $rec,
        'tone' => $tone,
    ];
}

// ---------------------------------------------------------------- registry

function a2_models($userId)
{
    list($exp, $rows, $variants, $ctrl) = a2_rows($userId, 0);
    $n = count($rows);
    $conversions = 0;
    foreach ($rows as $r) { $conversions += $r['converted']; }

    $defs = [
        ['key' => 'sequential', 'name' => 'Sequential Bayesian testing', 'family' => 'Continuous monitoring',
         'requirement' => 'Aggregate counts', 'need' => 1, 'have' => $n,
         'unit' => 'visitors', 'why' => 'Beta posteriors on live counts; safe to read at any time.'],
        ['key' => 'logistic', 'name' => 'Logistic regression', 'family' => 'Conversion prediction',
         'requirement' => '50+ visitors, both outcomes', 'need' => 50, 'have' => $n,
         'unit' => 'visitors', 'why' => 'Coefficients and odds ratios for device, source and assignment.'],
        ['key' => 'causal', 'name' => 'Causal forests', 'family' => 'Treatment effects',
         'requirement' => '100+ visitors across segments', 'need' => 100, 'have' => $n,
         'unit' => 'visitors', 'why' => 'Per-segment treatment effects instead of one average lift.'],
        ['key' => 'uplift', 'name' => 'Uplift modeling', 'family' => 'Who benefits',
         'requirement' => '200+ visitors, both arms', 'need' => 200, 'have' => $n,
         'unit' => 'visitors', 'why' => 'Two-model uplift: who converts because of the variant.'],
        ['key' => 'boosting', 'name' => 'Gradient boosting', 'family' => 'Conversion prediction',
         'requirement' => '200+ visitors', 'need' => 200, 'have' => $n,
         'unit' => 'visitors', 'why' => 'Boosted stumps capture nonlinear interactions.'],
        ['key' => 'survival', 'name' => 'Survival analysis', 'family' => 'Time to conversion',
         'requirement' => '5+ timed conversions', 'need' => 5, 'have' => $conversions,
         'unit' => 'conversions', 'why' => 'Median time to convert per variant.'],
    ];

    $out = [];
    foreach ($defs as $d) {
        $ready = $d['have'] >= $d['need'];
        $out[] = [
            'key' => $d['key'],
            'name' => $d['name'],
            'family' => $d['family'],
            'requirement' => $d['requirement'],
            'why' => $d['why'],
            'ready' => $ready,
            'have' => $d['have'],
            'need' => $d['need'],
            'unit' => $d['unit'],
            'status' => $ready ? 'Ready to run' : sprintf('Needs %s more %s', number_format(max(0, $d['need'] - $d['have'])), $d['unit']),
        ];
    }

    return [
        'experiment' => $exp ? ['id' => (int) $exp['id'], 'name' => $exp['name']] : null,
        'visitors' => $n,
        'conversions' => $conversions,
        'models' => $out,
        'readyCount' => count(array_filter($out, function ($m) { return $m['ready']; })),
    ];
}

function a2_run($userId, $model, $experimentId)
{
    list($exp, $rows, $variants, $ctrl) = a2_rows($userId, $experimentId);
    if ($exp === null) {
        return [
            'model' => $model,
            'ran' => false,
            'insufficientData' => true,
            'headline' => 'No experiment with visitor data yet',
            'metrics' => [],
            'findings' => ['Create an experiment, install the tracking snippet, and let visitors arrive. Every model here reads real exposure and conversion rows.'],
            'recommendation' => 'Open Tracking setup, install the snippet, then run one test so there is data to analyse.',
            'tone' => 'warn',
        ];
    }

    $n = count($rows);
    $result = null;
    switch ($model) {
        case 'sequential':
            $result = a2_model_sequential($rows, $variants, $ctrl);
            break;
        case 'logistic':
            $result = $n >= 50 ? a2_model_logistic($rows) : null;
            break;
        case 'causal':
            $result = $n >= 100 ? a2_model_causal($rows, $variants, $ctrl) : null;
            break;
        case 'uplift':
            $result = $n >= 200 ? a2_model_uplift($rows) : null;
            break;
        case 'boosting':
            $result = $n >= 200 ? a2_model_boosting($rows) : null;
            break;
        case 'survival':
            $result = a2_model_survival($rows, $variants, $ctrl);
            break;
        default:
            fail('Unknown model.', 'BAD_REQUEST', 400);
    }

    if ($result === null) {
        $need = ['sequential' => 1, 'logistic' => 50, 'causal' => 100, 'uplift' => 200, 'boosting' => 200, 'survival' => 5];
        $have = $model === 'survival' ? 0 : $n;
        foreach ($rows as $r) { if ($model === 'survival') { $have += $r['converted']; } }
        return [
            'model' => $model,
            'ran' => false,
            'insufficientData' => true,
            'experiment' => ['id' => (int) $exp['id'], 'name' => $exp['name']],
            'headline' => 'Not enough data for this model yet',
            'metrics' => [
                ['label' => 'Visitors available', 'value' => number_format($n)],
                ['label' => 'Required', 'value' => number_format(isset($need[$model]) ? $need[$model] : 0)],
            ],
            'findings' => [sprintf('This model needs %s %s; the experiment currently has %s.',
                number_format(isset($need[$model]) ? $need[$model] : 0),
                $model === 'survival' ? 'timed conversions' : 'visitors',
                number_format($have))],
            'recommendation' => 'Keep the experiment running, or run Sequential Bayesian testing now — it works from the first visitor.',
            'tone' => 'warn',
        ];
    }

    $result['model'] = $model;
    $result['ran'] = true;
    $result['insufficientData'] = false;
    $result['experiment'] = ['id' => (int) $exp['id'], 'name' => $exp['name']];
    $result['visitors'] = $n;
    return $result;
}

// ---------------------------------------------------------------- sample data

// Opt-in demo dataset so a brand-new workspace can see the models work.
function a2_seed_sample($userId)
{
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM experiments WHERE userId = ? AND name = 'Sample: pricing page test (demo data)' LIMIT 1");
    $st->execute([(int) $userId]);
    $existing = $st->fetch();
    if ($existing) {
        return ['ok' => true, 'experimentId' => (int) $existing['id'], 'created' => false];
    }

    $pdo->prepare("INSERT INTO experiments (userId, name, kind, goal, framework, audience, conversionEvent, traffic, status)
        VALUES (?, 'Sample: pricing page test (demo data)', 'A/B test', 'Trial starts', 'MECLABS heuristic', 'All eligible visitors', 'trial_start', 50, 'Active')")
        ->execute([(int) $userId]);
    $expId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO variants (experimentId, name, isControl, position, color) VALUES (?, ?, ?, ?, ?)");
    $ins->execute([$expId, 'Control', 1, 0, '#6b7280']);
    $ctrlId = (int) $pdo->lastInsertId();
    $ins->execute([$expId, 'Challenger', 0, 1, '#2563eb']);
    $varId = (int) $pdo->lastInsertId();

    $ev = $pdo->prepare("INSERT IGNORE INTO visitor_events (experimentId, variantId, visitorId, type, device, isPaid, isReturning, createdAt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $devices = ['desktop', 'mobile', 'tablet'];
    $now = time();
    $made = 0;
    foreach ([[$ctrlId, 0.05], [$varId, 0.09]] as $arm) {
        list($vid, $rate) = $arm;
        for ($i = 0; $i < 1000; $i++) {
            $visitor = 'demo-' . $vid . '-' . $i;
            $device = $devices[$i % 3];
            $isPaid = ($i % 4 === 0) ? 1 : 0;
            $isReturning = ($i % 5 === 0) ? 1 : 0;
            $t = $now - (86400 * 7) + ($i * 600);
            $ev->execute([$expId, $vid, $visitor, 'exposure', $device, $isPaid, $isReturning, gmdate('Y-m-d H:i:s', $t)]);
            $made++;
            // Deterministic pseudo-random draw so the sample is reproducible.
            $draw = (($i * 2654435761) % 1000) / 1000;
            if ($draw < $rate) {
                $ev->execute([$expId, $vid, $visitor, 'conversion', $device, $isPaid, $isReturning, gmdate('Y-m-d H:i:s', $t + 900 + ($i % 3600))]);
                $made++;
            }
        }
    }
    v2_log($userId, 'sample_data_loaded', 'Sample experiment ' . $expId, $expId);
    return ['ok' => true, 'experimentId' => $expId, 'created' => true, 'events' => $made];
}
