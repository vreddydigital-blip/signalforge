<?php
// Multi-agent orchestration engine for SignalForge.
//
// Shared PHP hosting has no background workers and no process forking, so a run is
// modelled as a durable task queue instead of a thread pool:
//   * orchestrator.start  creates one run row plus one task row per agent
//   * orchestrator.tick   claims a small batch of queued tasks, executes them against
//                         the workspace's real experiment data, and persists the output
//   * the dashboard polls tick, so several agents visibly progress side by side
// Every agent below computes from live rows in experiments / variants / visitor_events.
// Nothing is simulated: an agent with no data reports that it has no data.

function orch_agents()
{
    return [
        [
            'key'    => 'signal-analyst',
            'name'   => 'Signal Analyst',
            'role'   => 'Scores every experiment for the probability that a challenger beats control.',
            'icon'   => 'activity',
            'accent' => 'coral',
        ],
        [
            'key'    => 'segment-scout',
            'name'   => 'Segment Scout',
            'role'   => 'Splits exposures by device, paid traffic and repeat visits to find who benefits.',
            'icon'   => 'users',
            'accent' => 'blue',
        ],
        [
            'key'    => 'tracking-auditor',
            'name'   => 'Tracking Auditor',
            'role'   => 'Checks event freshness, conversion coverage and traffic split integrity.',
            'icon'   => 'shield',
            'accent' => 'green',
        ],
        [
            'key'    => 'sample-planner',
            'name'   => 'Sample Planner',
            'role'   => 'Estimates the visitors and days each active test still needs before a decision.',
            'icon'   => 'gauge',
            'accent' => 'amber',
        ],
        [
            'key'    => 'brief-writer',
            'name'   => 'Brief Writer',
            'role'   => 'Turns the weakest result into a framework-grounded brief for the next test.',
            'icon'   => 'sparkles',
            'accent' => 'purple',
        ],
    ];
}

function orch_agent_meta($key)
{
    foreach (orch_agents() as $agent) {
        if ($agent['key'] === $key) {
            return $agent;
        }
    }
    return ['key' => $key, 'name' => $key, 'role' => '', 'icon' => 'activity', 'accent' => 'coral'];
}

/**
 * Loads one compact, already-enriched snapshot of the workspace so every agent in a
 * tick reads the same numbers. Variants carry visitors / conversions / rate /
 * probability / uplift, exactly like the dashboard readout.
 */
function orch_dataset($userId)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM experiments WHERE userId = ? ORDER BY updatedAt DESC, id DESC');
    $st->execute([(int) $userId]);
    $experiments = $st->fetchAll();

    $empty = [
        'experiments' => [],
        'variants'    => [],
        'segments'    => [],
        'lastEventTs' => null,
        'events24h'   => 0,
        'events7d'    => 0,
        'eventTotal'  => 0,
        'exposures7d' => 0,
    ];
    if (!$experiments) {
        return $empty;
    }

    $ids = [];
    foreach ($experiments as $row) {
        $ids[] = (int) $row['id'];
    }
    $in = in_placeholders($ids);

    $vst = $pdo->prepare("SELECT * FROM variants WHERE experimentId IN ($in) ORDER BY position ASC, id ASC");
    $vst->execute($ids);
    $variantRows = $vst->fetchAll();

    $cst = $pdo->prepare("SELECT variantId, type, COUNT(*) AS n FROM visitor_events WHERE experimentId IN ($in) GROUP BY variantId, type");
    $cst->execute($ids);
    $counts = [];
    foreach ($cst->fetchAll() as $c) {
        $counts[$c['variantId'] . ':' . $c['type']] = (int) $c['n'];
    }

    $agg = $pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(createdAt)) AS lastTs,
                                 SUM(createdAt >= (NOW() - INTERVAL 1 DAY)) AS e24,
                                 SUM(createdAt >= (NOW() - INTERVAL 7 DAY)) AS e7,
                                 COUNT(*) AS total
                          FROM visitor_events WHERE experimentId IN ($in)");
    $agg->execute($ids);
    $aggRow = $agg->fetch();

    // Conversions are written without device/paid/returning flags, so attribute each
    // event to the segment recorded on that visitor's exposure row. Without the join
    // every segment would show a 0% lift because conversions land in a NULL bucket.
    $sst = $pdo->prepare("SELECT e.experimentId, e.variantId, e.type,
                                 x.device AS device, x.isPaid AS isPaid, x.isReturning AS isReturning,
                                 COUNT(*) AS n
                          FROM visitor_events e
                          LEFT JOIN visitor_events x
                            ON x.experimentId = e.experimentId
                           AND x.visitorId = e.visitorId
                           AND x.type = 'exposure'
                          WHERE e.experimentId IN ($in)
                          GROUP BY e.experimentId, e.variantId, e.type, x.device, x.isPaid, x.isReturning");
    $sst->execute($ids);
    $segmentRows = $sst->fetchAll();

    // Daily exposure rate, used by the sample planner. Conversions are excluded so the
    // runway is measured in visitors, not in events.
    $rate = $pdo->prepare("SELECT COUNT(*) AS n FROM visitor_events
                           WHERE experimentId IN ($in) AND type = 'exposure'
                             AND createdAt >= (NOW() - INTERVAL 7 DAY)");
    $rate->execute($ids);
    $exposures7d = (int) $rate->fetch()['n'];

    $byExperiment = [];
    foreach ($variantRows as $v) {
        $byExperiment[(int) $v['experimentId']][] = $v;
    }

    $variants = [];
    foreach ($byExperiment as $experimentId => $rows) {
        $raw = [];
        foreach ($rows as $v) {
            $visitors = isset($counts[$v['id'] . ':exposure']) ? $counts[$v['id'] . ':exposure'] : 0;
            $conversions = isset($counts[$v['id'] . ':conversion']) ? $counts[$v['id'] . ':conversion'] : 0;
            $raw[] = [
                'id'          => (int) $v['id'],
                'name'        => $v['name'],
                'isControl'   => (int) $v['isControl'] === 1,
                'color'       => $v['color'],
                'visitors'    => $visitors,
                'conversions' => $conversions,
                'rate'        => $visitors > 0 ? ($conversions / $visitors) * 100 : 0.0,
            ];
        }
        $control = null;
        foreach ($raw as $item) {
            if ($item['isControl']) {
                $control = $item;
                break;
            }
        }
        if ($control === null && count($raw) > 0) {
            $control = $raw[0];
        }
        foreach ($raw as $index => $item) {
            $uplift = 0.0;
            $probability = 50;
            if ($control !== null && !$item['isControl']) {
                if ($control['rate'] > 0) {
                    $uplift = (($item['rate'] - $control['rate']) / $control['rate']) * 100;
                }
                $probability = (int) round(prob_to_beat($item['visitors'], $item['conversions'], $control['visitors'], $control['conversions']));
            }
            $raw[$index]['uplift'] = round($uplift, 1);
            $raw[$index]['probability'] = $probability;
        }
        $variants[$experimentId] = $raw;
    }

    return [
        'experiments' => $experiments,
        'variants'    => $variants,
        'segments'    => $segmentRows,
        'lastEventTs' => $aggRow['lastTs'] !== null ? (int) $aggRow['lastTs'] : null,
        'events24h'   => (int) $aggRow['e24'],
        'events7d'    => (int) $aggRow['e7'],
        'eventTotal'  => (int) $aggRow['total'],
        'exposures7d' => $exposures7d,
    ];
}

function orch_control_of($variants)
{
    foreach ($variants as $variant) {
        if ($variant['isControl']) {
            return $variant;
        }
    }
    return count($variants) > 0 ? $variants[0] : null;
}

function orch_lead_of($variants)
{
    $control = orch_control_of($variants);
    $lead = null;
    foreach ($variants as $variant) {
        if ($control !== null && $variant['id'] === $control['id']) {
            continue;
        }
        if ($lead === null || $variant['probability'] > $lead['probability']) {
            $lead = $variant;
        }
    }
    return $lead;
}

function orch_total_visitors($variants)
{
    $total = 0;
    foreach ($variants as $variant) {
        $total += $variant['visitors'];
    }
    return $total;
}

// ---------------------------------------------------------------- agents

function orch_agent_signal($data)
{
    $analysed = 0;
    $decisive = 0;
    $contested = 0;
    $best = null;
    $findings = [];

    foreach ($data['experiments'] as $experiment) {
        $variants = isset($data['variants'][(int) $experiment['id']]) ? $data['variants'][(int) $experiment['id']] : [];
        if (!$variants) {
            continue;
        }
        $control = orch_control_of($variants);
        if ($control === null || $control['visitors'] <= 0) {
            continue;
        }
        $lead = orch_lead_of($variants);
        if ($lead === null) {
            continue;
        }
        $analysed++;
        if ($lead['probability'] >= 90) {
            $decisive++;
        } elseif ($lead['probability'] >= 60) {
            $contested++;
        }
        if ($best === null || $lead['uplift'] > $best['uplift']) {
            $best = ['name' => $experiment['name'], 'variant' => $lead['name'], 'uplift' => $lead['uplift'], 'probability' => $lead['probability']];
        }
        $findings[] = $experiment['name'] . ': ' . $lead['name'] . ' at ' . $lead['probability'] . '% probability, '
            . ($lead['uplift'] >= 0 ? '+' : '') . $lead['uplift'] . '% uplift on ' . number_format(orch_total_visitors($variants)) . ' visitors.';
    }

    if ($analysed === 0) {
        return [
            'headline'       => 'No experiment has enough traffic to score yet',
            'metrics'        => [
                ['label' => 'Experiments scored', 'value' => '0'],
                ['label' => 'Decisive signals', 'value' => '0'],
                ['label' => 'Best uplift', 'value' => '—'],
            ],
            'findings'       => ['Activate an experiment and let the tracker collect exposures before this agent can score a signal.'],
            'recommendation' => 'Install the tracking snippet, then launch one A/B test so the analyst has exposures to read.',
            'tone'           => 'info',
        ];
    }

    return [
        'headline'       => $decisive > 0
            ? $decisive . ' experiment' . ($decisive === 1 ? ' carries' : 's carry') . ' a decisive signal'
            : ($contested > 0 ? $contested . ' experiment' . ($contested === 1 ? ' is' : 's are') . ' still contested' : 'Every scored experiment is still inconclusive'),
        'metrics'        => [
            ['label' => 'Experiments scored', 'value' => (string) $analysed],
            ['label' => 'Decisive signals', 'value' => (string) $decisive],
            ['label' => 'Best uplift', 'value' => $best !== null ? (($best['uplift'] >= 0 ? '+' : '') . $best['uplift'] . '%') : '—'],
        ],
        'findings'       => $findings,
        'recommendation' => $best !== null
            ? 'Strongest read: ' . $best['variant'] . ' in "' . $best['name'] . '" at ' . $best['probability'] . '% probability. '
                . ($best['probability'] >= 90 ? 'Roll it out and start the next test.' : 'Keep collecting before you commit.')
            : 'Keep the current tests running.',
        'tone'           => $decisive > 0 ? 'good' : ($contested > 0 ? 'warn' : 'info'),
    ];
}

function orch_agent_segments($data)
{
    $buckets = [
        'All visitors'       => ['v' => 0, 'x' => 0, 'lv' => 0, 'lx' => 0],
        'Mobile visitors'    => ['v' => 0, 'x' => 0, 'lv' => 0, 'lx' => 0],
        'Desktop visitors'   => ['v' => 0, 'x' => 0, 'lv' => 0, 'lx' => 0],
        'Paid traffic'       => ['v' => 0, 'x' => 0, 'lv' => 0, 'lx' => 0],
        'Returning visitors' => ['v' => 0, 'x' => 0, 'lv' => 0, 'lx' => 0],
    ];

    foreach ($data['experiments'] as $experiment) {
        $experimentId = (int) $experiment['id'];
        $variants = isset($data['variants'][$experimentId]) ? $data['variants'][$experimentId] : [];
        if (!$variants) {
            continue;
        }
        $control = orch_control_of($variants);
        $lead = orch_lead_of($variants);
        if ($control === null || $lead === null) {
            continue;
        }
        foreach ($data['segments'] as $row) {
            if ((int) $row['experimentId'] !== $experimentId) {
                continue;
            }
            $variantId = (int) $row['variantId'];
            $isControl = $variantId === $control['id'];
            $isLead = $variantId === $lead['id'];
            if (!$isControl && !$isLead) {
                continue;
            }
            $n = (int) $row['n'];
            $isConversion = $row['type'] === 'conversion';
            $matches = ['All visitors'];
            if ($row['device'] === 'Mobile') {
                $matches[] = 'Mobile visitors';
            }
            if ($row['device'] === 'Desktop') {
                $matches[] = 'Desktop visitors';
            }
            if ((int) $row['isPaid'] === 1) {
                $matches[] = 'Paid traffic';
            }
            if ((int) $row['isReturning'] === 1) {
                $matches[] = 'Returning visitors';
            }
            foreach ($matches as $label) {
                if ($isControl) {
                    if ($isConversion) {
                        $buckets[$label]['x'] += $n;
                    } else {
                        $buckets[$label]['v'] += $n;
                    }
                } else {
                    if ($isConversion) {
                        $buckets[$label]['lx'] += $n;
                    } else {
                        $buckets[$label]['lv'] += $n;
                    }
                }
            }
        }
    }

    $rows = [];
    $best = null;
    foreach ($buckets as $label => $b) {
        $controlRate = $b['v'] > 0 ? $b['x'] / $b['v'] : 0.0;
        $leadRate = $b['lv'] > 0 ? $b['lx'] / $b['lv'] : 0.0;
        $lift = $controlRate > 0 ? (($leadRate - $controlRate) / $controlRate) * 100 : 0.0;
        $visitors = $b['v'] + $b['lv'];
        $signal = $visitors >= 200 ? 'Strong' : ($visitors >= 50 ? 'Moderate' : ($visitors >= 10 ? 'Early' : 'Inconclusive'));
        $rows[] = ['segment' => $label, 'lift' => round($lift, 1), 'signal' => $signal, 'visitors' => $visitors, 'controlRate' => $controlRate];
        // A segment only counts as a finding when the control actually converted there;
        // otherwise the lift is a division by zero dressed up as a number.
        if ($label !== 'All visitors' && $visitors >= 10 && $controlRate > 0 && ($best === null || $lift > $best['lift'])) {
            $best = ['segment' => $label, 'lift' => round($lift, 1), 'visitors' => $visitors, 'signal' => $signal];
        }
    }

    $findings = [];
    foreach ($rows as $row) {
        if ($row['segment'] === 'All visitors') {
            continue;
        }
        if ($row['controlRate'] <= 0) {
            $findings[] = $row['segment'] . ': ' . number_format($row['visitors']) . ' visitors, but the control has no conversions here yet — no lift to report.';
            continue;
        }
        $findings[] = $row['segment'] . ': ' . ($row['lift'] >= 0 ? '+' : '') . $row['lift'] . '% lift across '
            . number_format($row['visitors']) . ' visitors (' . strtolower($row['signal']) . ' signal).';
    }

    if ($best === null) {
        return [
            'headline'       => 'Not enough segment traffic to compare audiences',
            'metrics'        => [
                ['label' => 'Segments read', 'value' => '0'],
                ['label' => 'Strongest segment', 'value' => '—'],
                ['label' => 'Segment lift', 'value' => '—'],
            ],
            'findings'       => ['Each segment needs at least 10 visitors and at least one control conversion before a lift figure means anything.'],
            'recommendation' => 'Let the active test collect more exposures, then re-run this agent.',
            'tone'           => 'info',
        ];
    }

    return [
        'headline'       => $best['segment'] . ' shows the strongest response at ' . ($best['lift'] >= 0 ? '+' : '') . $best['lift'] . '%',
        'metrics'        => [
            ['label' => 'Segments read', 'value' => (string) count($rows)],
            ['label' => 'Strongest segment', 'value' => $best['segment']],
            ['label' => 'Segment lift', 'value' => ($best['lift'] >= 0 ? '+' : '') . $best['lift'] . '%'],
        ],
        'findings'       => $findings,
        'recommendation' => 'Target ' . strtolower($best['segment']) . ' first: the variant moves them '
            . ($best['lift'] >= 0 ? 'up' : 'down') . ' by ' . abs($best['lift']) . '% on ' . number_format($best['visitors']) . ' visitors.',
        'tone'           => $best['lift'] > 0 ? 'good' : 'warn',
    ];
}

function orch_agent_tracking($data)
{
    $issues = [];
    $score = 100;

    if ($data['lastEventTs'] === null) {
        $issues[] = 'No tracking event has ever been received for this workspace.';
        $score -= 60;
    } else {
        $ageHours = (time() - $data['lastEventTs']) / 3600;
        if ($ageHours > 48) {
            $issues[] = 'Last event arrived ' . time_ago($data['lastEventTs']) . ' — the snippet may have been removed.';
            $score -= 35;
        } elseif ($ageHours > 24) {
            $issues[] = 'No event in the last 24 hours (last seen ' . time_ago($data['lastEventTs']) . ').';
            $score -= 15;
        }
    }

    $active = 0;
    $starved = 0;
    $mismatched = 0;
    foreach ($data['experiments'] as $experiment) {
        if ($experiment['status'] !== 'Active') {
            continue;
        }
        $active++;
        $variants = isset($data['variants'][(int) $experiment['id']]) ? $data['variants'][(int) $experiment['id']] : [];
        if (!$variants) {
            continue;
        }
        $visitors = orch_total_visitors($variants);
        $conversions = 0;
        $counts = [];
        foreach ($variants as $variant) {
            $conversions += $variant['conversions'];
            $counts[] = $variant['visitors'];
        }
        if ($visitors >= 50 && $conversions === 0) {
            $starved++;
            $issues[] = '"' . $experiment['name'] . '" has ' . number_format($visitors) . ' visitors but zero conversions — check the conversion event.';
            $score -= 12;
        }
        $max = max($counts);
        $min = min($counts);
        if ($min > 0 && $max / $min > 1.5) {
            $mismatched++;
            $issues[] = '"' . $experiment['name'] . '" traffic split is uneven (' . $max . ' vs ' . $min . ' exposures).';
            $score -= 10;
        }
    }

    if ($active === 0) {
        $issues[] = 'No experiment is currently active, so no new data is being collected.';
        $score -= 10;
    }
    if (!$issues) {
        $issues[] = 'Event flow, conversion coverage and traffic split all look healthy.';
    }
    $score = max(0, min(100, $score));

    return [
        'headline'       => $score >= 85 ? 'Tracking pipeline is healthy' : ($score >= 60 ? 'Tracking needs attention' : 'Tracking is broken or idle'),
        'metrics'        => [
            ['label' => 'Health score', 'value' => $score . '/100'],
            ['label' => 'Events (24h)', 'value' => number_format($data['events24h'])],
            ['label' => 'Active tests', 'value' => (string) $active],
        ],
        'findings'       => $issues,
        'recommendation' => $score >= 85
            ? 'Leave instrumentation alone and keep collecting. Re-audit after the next experiment launches.'
            : ($starved > 0
                ? 'Fix the conversion event on the starved experiment before reading any probability score.'
                : 'Re-check the snippet install and confirm events are still arriving.'),
        'tone'           => $score >= 85 ? 'good' : ($score >= 60 ? 'warn' : 'bad'),
    ];
}

function orch_agent_sample($data)
{
    $rows = [];
    $findings = [];
    $totalNeeded = 0;
    $slowest = null;

    foreach ($data['experiments'] as $experiment) {
        if ($experiment['status'] !== 'Active') {
            continue;
        }
        $variants = isset($data['variants'][(int) $experiment['id']]) ? $data['variants'][(int) $experiment['id']] : [];
        if (!$variants) {
            continue;
        }
        $control = orch_control_of($variants);
        if ($control === null) {
            continue;
        }
        $baseline = $control['visitors'] > 0 ? $control['conversions'] / $control['visitors'] : 0.0;
        if ($baseline <= 0) {
            $baseline = 0.05; // assume a 5% baseline until the control converts
        }
        $delta = $baseline * 0.15; // detect a 15% relative lift
        $neededPerVariant = (int) ceil(16 * $baseline * (1 - $baseline) / ($delta * $delta));
        $neededPerVariant = max(200, min(200000, $neededPerVariant));
        $neededTotal = $neededPerVariant * count($variants);
        $have = orch_total_visitors($variants);
        $remaining = max(0, $neededTotal - $have);
        $totalNeeded += $remaining;

        $daily = $data['exposures7d'] > 0 ? $data['exposures7d'] / 7 : 0;
        $days = $daily > 0 ? (int) ceil($remaining / $daily) : null;

        $rows[] = [
            'experiment' => $experiment['name'],
            'have'       => $have,
            'needed'     => $neededTotal,
            'remaining'  => $remaining,
            'days'       => $days,
        ];
        $findings[] = $experiment['name'] . ': ' . number_format($have) . ' of ' . number_format($neededTotal) . ' visitors'
            . ($remaining > 0 ? ' — ' . number_format($remaining) . ' to go' . ($days !== null ? ' (~' . $days . ' days at the current rate)' : '') : ' — sample target met') . '.';
        if ($slowest === null || ($days !== null && ($slowest['days'] === null || $days > $slowest['days']))) {
            $slowest = ['name' => $experiment['name'], 'days' => $days];
        }
    }

    if (!$rows) {
        return [
            'headline'       => 'No active experiment to plan a sample for',
            'metrics'        => [
                ['label' => 'Active tests', 'value' => '0'],
                ['label' => 'Visitors still needed', 'value' => '—'],
                ['label' => 'Longest runway', 'value' => '—'],
            ],
            'findings'       => ['Sample planning only covers experiments in the Active state.'],
            'recommendation' => 'Activate a test, then re-run the planner to get a visitor target and a day estimate.',
            'tone'           => 'info',
        ];
    }

    return [
        'headline'       => $totalNeeded > 0
            ? number_format($totalNeeded) . ' more visitors needed across ' . count($rows) . ' active test' . (count($rows) === 1 ? '' : 's')
            : 'Every active test has met its sample target',
        'metrics'        => [
            ['label' => 'Active tests', 'value' => (string) count($rows)],
            ['label' => 'Visitors still needed', 'value' => number_format($totalNeeded)],
            ['label' => 'Longest runway', 'value' => $slowest !== null && $slowest['days'] !== null ? $slowest['days'] . ' days' : '—'],
        ],
        'findings'       => $findings,
        'recommendation' => $totalNeeded > 0
            ? 'Hold decisions until the sample target is met; the slowest test is "' . ($slowest !== null ? $slowest['name'] : 'unknown') . '".'
            : 'Samples are mature — move to a decision on the leading variant.',
        'tone'           => $totalNeeded > 0 ? 'warn' : 'good',
    ];
}

function orch_agent_brief($data)
{
    $candidate = null;
    $candidateScore = null;

    foreach ($data['experiments'] as $experiment) {
        $variants = isset($data['variants'][(int) $experiment['id']]) ? $data['variants'][(int) $experiment['id']] : [];
        $visitors = orch_total_visitors($variants);
        $lead = $variants ? orch_lead_of($variants) : null;
        $probability = $lead !== null ? $lead['probability'] : 50;
        // Prefer a test that is running but not yet decisive, then the least mature one.
        $score = ($experiment['status'] === 'Active' ? 1000 : 0) + (100 - abs($probability - 50)) - min($visitors, 500) / 10;
        if ($candidateScore === null || $score > $candidateScore) {
            $candidate = $experiment;
            $candidateScore = $score;
        }
    }

    if ($candidate === null) {
        return [
            'headline'       => 'No experiment to build a brief from',
            'metrics'        => [
                ['label' => 'Briefs drafted', 'value' => '0'],
                ['label' => 'Framework', 'value' => '—'],
                ['label' => 'Target page', 'value' => '—'],
            ],
            'findings'       => ['A brief needs at least one experiment with a hypothesis and a target page.'],
            'recommendation' => 'Create an experiment first; the writer will then turn its weakest signal into the next test.',
            'tone'           => 'info',
        ];
    }

    $variants = isset($data['variants'][(int) $candidate['id']]) ? $data['variants'][(int) $candidate['id']] : [];
    $lead = $variants ? orch_lead_of($variants) : null;
    $framework = $candidate['framework'] !== '' ? $candidate['framework'] : 'MECLABS heuristic';
    $page = $candidate['pageUrl'] !== '' ? $candidate['pageUrl'] : 'the target page';
    $goal = $candidate['goal'] !== '' ? $candidate['goal'] : 'the primary conversion';
    $audience = $candidate['audience'] !== '' ? $candidate['audience'] : 'all eligible visitors';
    $insight = $candidate['observedInsight'] !== '' ? $candidate['observedInsight'] : 'visitors hesitate before ' . $goal;
    $change = $candidate['experienceChange'] !== '' ? $candidate['experienceChange'] : 'clarify the value proposition above the fold';
    $reason = $candidate['behaviorReason'] !== '' ? $candidate['behaviorReason'] : 'clearer value reduces the effort of deciding';

    $hypothesis = 'Because ' . lcfirst($insight) . ', changing the experience to ' . lcfirst($change) . ' for ' . strtolower($audience)
        . ' should improve ' . strtolower($goal) . ' because ' . lcfirst($reason) . ', using the ' . $framework . ' lens.';

    return [
        'headline'       => 'Next brief drafted for "' . $candidate['name'] . '"',
        'metrics'        => [
            ['label' => 'Framework', 'value' => $framework],
            ['label' => 'Target page', 'value' => $page],
            ['label' => 'Current probability', 'value' => ($lead !== null ? $lead['probability'] : 50) . '%'],
        ],
        'findings'       => [
            'Hypothesis: ' . $hypothesis,
            'Primary metric: ' . $goal . ' on ' . $page . '.',
            'Audience: ' . $audience . '.',
        ],
        'recommendation' => 'Run this as the next test once "' . $candidate['name'] . '" reaches its sample target.',
        'tone'           => 'info',
    ];
}

function orch_execute($key, $data)
{
    switch ($key) {
        case 'signal-analyst':
            return orch_agent_signal($data);
        case 'segment-scout':
            return orch_agent_segments($data);
        case 'tracking-auditor':
            return orch_agent_tracking($data);
        case 'sample-planner':
            return orch_agent_sample($data);
        case 'brief-writer':
            return orch_agent_brief($data);
    }
    return [
        'headline'       => 'Unknown agent',
        'metrics'        => [],
        'findings'       => ['This agent key is not registered in the orchestrator.'],
        'recommendation' => 'Remove the task and start a new run.',
        'tone'           => 'bad',
    ];
}

// ---------------------------------------------------------------- runs

function orch_task_row($row)
{
    $meta = orch_agent_meta($row['agentKey']);
    $output = null;
    if ($row['output'] !== null && $row['output'] !== '') {
        $decoded = json_decode($row['output'], true);
        if (is_array($decoded)) {
            $output = $decoded;
        }
    }
    $started = $row['startedAt'] !== null ? strtotime($row['startedAt']) : null;
    $finished = $row['finishedAt'] !== null ? strtotime($row['finishedAt']) : null;
    return [
        'id'         => (int) $row['id'],
        'agentKey'   => $row['agentKey'],
        'agentName'  => $meta['name'],
        'agentRole'  => $meta['role'],
        'icon'       => $meta['icon'],
        'accent'     => $meta['accent'],
        'title'      => $row['title'],
        'status'     => $row['status'],
        'durationMs' => ($started !== null && $finished !== null) ? max(0, ($finished - $started) * 1000) : null,
        'output'     => $output,
        'error'      => $row['error'],
    ];
}

function orch_summary($tasks)
{
    $done = 0;
    $failed = 0;
    $metrics = [];
    $findings = [];
    $recommendations = [];
    $tone = 'info';

    foreach ($tasks as $task) {
        if ($task['status'] === 'done') {
            $done++;
        }
        if ($task['status'] === 'failed') {
            $failed++;
        }
        if (!$task['output']) {
            continue;
        }
        $output = $task['output'];
        foreach ($output['metrics'] as $metric) {
            $metrics[] = ['label' => $metric['label'], 'value' => $metric['value'], 'agent' => $task['agentName']];
        }
        foreach ($output['findings'] as $finding) {
            $findings[] = ['text' => $finding, 'agent' => $task['agentName'], 'accent' => $task['accent']];
        }
        if ($output['recommendation'] !== '') {
            $recommendations[] = ['text' => $output['recommendation'], 'agent' => $task['agentName'], 'tone' => $output['tone']];
        }
        if ($output['tone'] === 'bad') {
            $tone = 'bad';
        } elseif ($output['tone'] === 'warn' && $tone !== 'bad') {
            $tone = 'warn';
        } elseif ($output['tone'] === 'good' && $tone === 'info') {
            $tone = 'good';
        }
    }

    // The lead recommendation is the most urgent one: bad, then warn, then good.
    $lead = null;
    $rank = ['bad' => 3, 'warn' => 2, 'good' => 1, 'info' => 0];
    foreach ($recommendations as $item) {
        if ($lead === null || $rank[$item['tone']] > $rank[$lead['tone']]) {
            $lead = $item;
        }
    }

    return [
        'agentsDone'      => $done,
        'agentsFailed'    => $failed,
        'metrics'         => $metrics,
        'findings'        => $findings,
        'recommendations' => $recommendations,
        'lead'            => $lead,
        'tone'            => $tone,
    ];
}

function orch_run_state($userId, $runId)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM agent_runs WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $runId, (int) $userId]);
    $run = $st->fetch();
    if (!$run) {
        fail('Run not found.', 'NOT_FOUND', 404);
    }
    $tst = $pdo->prepare('SELECT * FROM agent_tasks WHERE runId = ? ORDER BY position ASC, id ASC');
    $tst->execute([(int) $run['id']]);
    $tasks = [];
    foreach ($tst->fetchAll() as $row) {
        $tasks[] = orch_task_row($row);
    }
    $total = count($tasks);
    $finished = 0;
    foreach ($tasks as $task) {
        if ($task['status'] === 'done' || $task['status'] === 'failed') {
            $finished++;
        }
    }
    return [
        'id'        => (int) $run['id'],
        'objective' => $run['objective'],
        'status'    => $run['status'],
        'createdAt' => $run['createdAt'],
        'engine'    => isset($run['engine']) ? (string) $run['engine'] : 'local',
        'progress'  => $total > 0 ? (int) round(($finished / $total) * 100) : 0,
        'tasks'     => $tasks,
        'summary'   => orch_summary($tasks),
    ];
}

function orch_runs($userId)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM agent_runs WHERE userId = ? ORDER BY id DESC LIMIT 12');
    $st->execute([(int) $userId]);
    $runs = [];
    foreach ($st->fetchAll() as $row) {
        $runs[] = [
            'id'        => (int) $row['id'],
            'objective' => $row['objective'],
            'status'    => $row['status'],
            'engine'    => isset($row['engine']) ? (string) $row['engine'] : 'local',
            'createdAt' => $row['createdAt'],
            'updatedAt' => $row['updatedAt'],
        ];
    }
    return $runs;
}

function orch_start($user, $input)
{
    $pdo = db();
    $objective = isset($input['objective']) && is_string($input['objective']) ? trim($input['objective']) : '';
    if ($objective === '') {
        $objective = 'Full workspace sweep';
    }
    $objective = mb_substr($objective, 0, 200);

    // Keep the queue bounded: one live run per workspace at a time.
    $st = $pdo->prepare("SELECT COUNT(*) AS n FROM agent_runs WHERE userId = ? AND status = 'running'");
    $st->execute([(int) $user['id']]);
    if ((int) $st->fetch()['n'] > 0) {
        fail('A run is already in progress. Wait for it to finish or cancel it.', 'CONFLICT', 409);
    }

    // Prefer the LangGraph service when it is configured and reachable.
    $mode = orch_remote_mode();
    if ($mode !== 'local' && orch_remote_configured()) {
        list($state, $error) = orch_remote_start($user, $objective);
        if ($state !== null) {
            return $state;
        }
        if ($mode === 'remote') {
            fail(
                'The orchestration service is unreachable: ' . $error
                . '. Check that the Render service is awake and that orchestrator_url in api/config.php is correct.',
                'ORCHESTRATOR_UNAVAILABLE',
                503
            );
        }
        // mode 'auto': fall through to the local engine and note why.
        error_log('[signalforge:orchestrator] remote unavailable, using local engine: ' . $error);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO agent_runs (userId, objective, status, engine) VALUES (?, ?, 'running', 'local')")
            ->execute([(int) $user['id'], $objective]);
        $runId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO agent_tasks (runId, agentKey, title, status, position) VALUES (?, ?, ?, 'queued', ?)");
        $titles = [
            'signal-analyst'   => 'Score every experiment for a decisive signal',
            'segment-scout'    => 'Find which audience segment responds best',
            'tracking-auditor' => 'Audit event flow and traffic split integrity',
            'sample-planner'   => 'Estimate remaining sample and days to decision',
            'brief-writer'     => 'Draft the next test brief from the weakest result',
        ];
        $position = 0;
        foreach (orch_agents() as $agent) {
            $ins->execute([$runId, $agent['key'], $titles[$agent['key']], $position]);
            $position++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return orch_run_state($user['id'], $runId);
}

function orch_tick($user, $runId)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM agent_runs WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $runId, (int) $user['id']]);
    $run = $st->fetch();
    if (!$run) {
        fail('Run not found.', 'NOT_FOUND', 404);
    }
    // A run started on the LangGraph service is advanced by polling that service.
    if (isset($run['engine']) && $run['engine'] === 'langgraph') {
        return orch_remote_sync($user, $runId);
    }
    if ($run['status'] !== 'running') {
        return orch_run_state($user['id'], $runId);
    }

    // Claim a batch of queued tasks. Two per tick keeps the board visibly parallel
    // without letting one request run long on shared hosting.
    $qst = $pdo->prepare("SELECT * FROM agent_tasks WHERE runId = ? AND status = 'queued' ORDER BY position ASC LIMIT 2");
    $qst->execute([(int) $run['id']]);
    $batch = $qst->fetchAll();

    if ($batch) {
        $data = orch_dataset($user['id']);
        $markRunning = $pdo->prepare("UPDATE agent_tasks SET status = 'running', startedAt = CURRENT_TIMESTAMP WHERE id = ?");
        $markDone = $pdo->prepare("UPDATE agent_tasks SET status = 'done', output = ?, finishedAt = CURRENT_TIMESTAMP WHERE id = ?");
        $markFailed = $pdo->prepare("UPDATE agent_tasks SET status = 'failed', error = ?, finishedAt = CURRENT_TIMESTAMP WHERE id = ?");
        foreach ($batch as $task) {
            $markRunning->execute([(int) $task['id']]);
            try {
                $output = orch_execute($task['agentKey'], $data);
                $markDone->execute([json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $task['id']]);
            } catch (Throwable $e) {
                error_log('[signalforge:agent] ' . $task['agentKey'] . ' ' . $e->getMessage());
                $markFailed->execute([mb_substr($e->getMessage(), 0, 500), (int) $task['id']]);
            }
        }
    }

    // Close the run once nothing is left queued or running.
    $pst = $pdo->prepare("SELECT SUM(status = 'queued') AS q, SUM(status = 'running') AS r, SUM(status = 'failed') AS f FROM agent_tasks WHERE runId = ?");
    $pst->execute([(int) $run['id']]);
    $counts = $pst->fetch();
    if ((int) $counts['q'] === 0 && (int) $counts['r'] === 0) {
        $status = (int) $counts['f'] > 0 ? 'failed' : 'completed';
        $pdo->prepare('UPDATE agent_runs SET status = ?, updatedAt = CURRENT_TIMESTAMP WHERE id = ?')->execute([$status, (int) $run['id']]);
    } else {
        $pdo->prepare('UPDATE agent_runs SET updatedAt = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $run['id']]);
    }

    return orch_run_state($user['id'], $runId);
}

function orch_cancel($user, $runId)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM agent_runs WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $runId, (int) $user['id']]);
    $run = $st->fetch();
    if (!$run) {
        fail('Run not found.', 'NOT_FOUND', 404);
    }
    if (isset($run['engine']) && $run['engine'] === 'langgraph') {
        return orch_remote_cancel($user, $runId);
    }
    if ($run['status'] === 'running') {
        $pdo->prepare("UPDATE agent_tasks SET status = 'failed', error = 'Cancelled by user', finishedAt = CURRENT_TIMESTAMP WHERE runId = ? AND status IN ('queued','running')")
            ->execute([(int) $run['id']]);
        $pdo->prepare("UPDATE agent_runs SET status = 'cancelled', updatedAt = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int) $run['id']]);
    }
    return orch_run_state($user['id'], $runId);
}
