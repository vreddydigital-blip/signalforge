<?php
// SignalForge v2 API — dashboard, experiments, tracking, integrations, AI,
// crawler, visual analysis, competitors, ecommerce, security, ops.
// Loaded by index.php; every route here is namespaced v2.* so the v1 API is untouched.

// ---------------------------------------------------------------- helpers

function v2_owned_experiment($userId, $id)
{
    $st = db()->prepare('SELECT * FROM experiments WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $id, (int) $userId]);
    $row = $st->fetch();
    if (!$row) {
        fail('Experiment not found.', 'NOT_FOUND', 404);
    }
    return $row;
}

function v2_log($userId, $action, $detail = null, $experimentId = null)
{
    try {
        db()->prepare('INSERT INTO activity_log (userId, experimentId, action, detail) VALUES (?, ?, ?, ?)')
            ->execute([(int) $userId, $experimentId !== null ? (int) $experimentId : null, $action, $detail !== null ? mb_substr((string) $detail, 0, 500) : null]);
    } catch (Throwable $e) {
        // activity logging must never break a request
    }
}

function v2_audit($userId, $action, $target = null)
{
    try {
        db()->prepare('INSERT INTO audit_log (userId, actorId, action, target, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int) $userId, (int) $userId, $action, $target !== null ? mb_substr((string) $target, 0, 190) : null, client_ip()]);
    } catch (Throwable $e) {
    }
}

function v2_int($in, $key, $default = 0)
{
    return isset($in[$key]) ? (int) $in[$key] : $default;
}

function v2_str($in, $key, $max = 255, $default = '')
{
    $v = isset($in[$key]) && is_string($in[$key]) ? trim($in[$key]) : $default;
    return mb_substr($v, 0, $max);
}

function v2_json($in, $key)
{
    if (!isset($in[$key])) {
        return null;
    }
    if (is_string($in[$key])) {
        $d = json_decode($in[$key], true);
        return is_array($d) ? $d : null;
    }
    return is_array($in[$key]) ? $in[$key] : null;
}

// Date-range filter -> [fromTs, toTs] in UTC.
function v2_range($in)
{
    $range = v2_str($in, 'range', 16, '30d');
    $now = time();
    switch ($range) {
        case '7d':  return [$now - 7 * 86400, $now, '7d'];
        case '30d': return [$now - 30 * 86400, $now, '30d'];
        case '90d': return [$now - 90 * 86400, $now, '90d'];
        case 'all': return [0, $now, 'all'];
        case 'custom':
            $from = v2_str($in, 'from', 32);
            $to = v2_str($in, 'to', 32);
            $f = $from !== '' ? strtotime($from . ' 00:00:00') : $now - 30 * 86400;
            $t = $to !== '' ? strtotime($to . ' 23:59:59') : $now;
            return [$f ?: 0, $t ?: $now, 'custom'];
        default: return [$now - 30 * 86400, $now, '30d'];
    }
}

// ---------------------------------------------------------------- dashboard

function v2_dashboard($user, $in)
{
    list($from, $to, $label) = v2_range($in);
    $pdo = db();
    $uid = (int) $user['id'];

    $st = $pdo->prepare('SELECT * FROM experiments WHERE userId = ?');
    $st->execute([$uid]);
    $experiments = $st->fetchAll();

    $ids = array_map(function ($e) { return (int) $e['id']; }, $experiments);
    $total = count($experiments);
    $active = 0;
    $completed = 0;
    $draft = 0;
    foreach ($experiments as $e) {
        if (in_array($e['status'], ['Active', 'Running'], true)) { $active++; }
        elseif ($e['status'] === 'Completed') { $completed++; }
        elseif ($e['status'] === 'Draft') { $draft++; }
    }

    $exposures = 0; $conversions = 0; $revenue = 0.0;
    $byDevice = []; $byBrowser = []; $byCountry = []; $byLanding = [];
    $daily = [];
    $bestUplift = null; $bestConfidence = 0;

    if ($ids) {
        $in1 = in_placeholders($ids);
        $q = $pdo->prepare("SELECT type, device, browser, country, landingPage, revenue, DATE_FORMAT(createdAt,'%Y-%m-%d') AS d, COUNT(*) AS n, SUM(revenue) AS rev
            FROM visitor_events WHERE experimentId IN ($in1) AND UNIX_TIMESTAMP(createdAt) BETWEEN ? AND ?
            GROUP BY type, device, browser, country, landingPage, d");
        $q->execute(array_merge($ids, [$from, $to]));
        foreach ($q->fetchAll() as $r) {
            $n = (int) $r['n'];
            if ($r['type'] === 'exposure') {
                $exposures += $n;
                $dev = $r['device'] ?: 'Unknown';
                $byDevice[$dev] = (isset($byDevice[$dev]) ? $byDevice[$dev] : 0) + $n;
                $br = $r['browser'] ?: 'Unknown';
                $byBrowser[$br] = (isset($byBrowser[$br]) ? $byBrowser[$br] : 0) + $n;
                $co = $r['country'] ?: 'Unknown';
                $byCountry[$co] = (isset($byCountry[$co]) ? $byCountry[$co] : 0) + $n;
                $lp = $r['landingPage'] ?: '/';
                $byLanding[$lp] = (isset($byLanding[$lp]) ? $byLanding[$lp] : 0) + $n;
            } else {
                $conversions += $n;
                $revenue += (float) $r['rev'];
            }
            $d = $r['d'];
            if (!isset($daily[$d])) { $daily[$d] = ['exposures' => 0, 'conversions' => 0]; }
            if ($r['type'] === 'exposure') { $daily[$d]['exposures'] += $n; } else { $daily[$d]['conversions'] += $n; }
        }

        // Best uplift + confidence across experiments.
        foreach ($experiments as $e) {
            $vs = $pdo->prepare('SELECT * FROM variants WHERE experimentId = ? ORDER BY position ASC');
            $vs->execute([(int) $e['id']]);
            $variants = $vs->fetchAll();
            if (count($variants) < 2) { continue; }
            $control = $variants[0];
            foreach ($variants as $v) { if ((int) $v['isControl'] === 1) { $control = $v; break; } }
            $cnt = $pdo->prepare("SELECT variantId, type, COUNT(*) AS n FROM visitor_events WHERE experimentId = ? AND UNIX_TIMESTAMP(createdAt) BETWEEN ? AND ? GROUP BY variantId, type");
            $cnt->execute([(int) $e['id'], $from, $to]);
            $c = [];
            foreach ($cnt->fetchAll() as $r) { $c[$r['variantId'] . ':' . $r['type']] = (int) $r['n']; }
            $cv = isset($c[$control['id'] . ':exposure']) ? $c[$control['id'] . ':exposure'] : 0;
            $cx = isset($c[$control['id'] . ':conversion']) ? $c[$control['id'] . ':conversion'] : 0;
            foreach ($variants as $v) {
                if ((int) $v['id'] === (int) $control['id']) { continue; }
                $vv = isset($c[$v['id'] . ':exposure']) ? $c[$v['id'] . ':exposure'] : 0;
                $vx = isset($c[$v['id'] . ':conversion']) ? $c[$v['id'] . ':conversion'] : 0;
                if ($cv < 1 || $vv < 1) { continue; }
                $cr = $cx / $cv; $vr = $vx / $vv;
                $uplift = $cr > 0 ? (($vr - $cr) / $cr) * 100 : 0;
                $conf = prob_to_beat($vv, $vx, $cv, $cx);
                if ($bestUplift === null || $uplift > $bestUplift) {
                    $bestUplift = round($uplift, 1);
                    $bestConfidence = (int) round($conf);
                }
            }
        }
    }

    ksort($daily);
    $series = [];
    foreach ($daily as $d => $v) {
        $series[] = ['day' => $d, 'exposures' => $v['exposures'], 'conversions' => $v['conversions'],
            'rate' => $v['exposures'] > 0 ? round(($v['conversions'] / $v['exposures']) * 100, 2) : 0];
    }

    $act = $pdo->prepare('SELECT action, detail, experimentId, UNIX_TIMESTAMP(createdAt) AS ts FROM activity_log WHERE userId = ? ORDER BY id DESC LIMIT 12');
    $act->execute([$uid]);
    $activity = [];
    foreach ($act->fetchAll() as $a) {
        $activity[] = ['action' => $a['action'], 'detail' => $a['detail'], 'experimentId' => $a['experimentId'] !== null ? (int) $a['experimentId'] : null, 'ago' => time_ago((int) $a['ts'])];
    }

    $sortDesc = function ($arr) {
        arsort($arr);
        $out = [];
        foreach (array_slice($arr, 0, 8, true) as $k => $v) { $out[] = ['label' => (string) $k, 'value' => (int) $v]; }
        return $out;
    };

    return [
        'range' => $label,
        'totals' => [
            'experiments' => $total,
            'active' => $active,
            'completed' => $completed,
            'draft' => $draft,
            'exposures' => $exposures,
            'conversions' => $conversions,
            'conversionRate' => $exposures > 0 ? round(($conversions / $exposures) * 100, 2) : 0,
            'revenue' => round($revenue, 2),
            'bestUplift' => $bestUplift,
            'confidence' => $bestConfidence,
        ],
        'series' => $series,
        'breakdowns' => [
            'device' => $sortDesc($byDevice),
            'browser' => $sortDesc($byBrowser),
            'country' => $sortDesc($byCountry),
            'landingPage' => $sortDesc($byLanding),
        ],
        'activity' => $activity,
    ];
}

// CSV export of the dashboard.
function v2_export_csv($user, $in)
{
    $d = v2_dashboard($user, $in);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="signalforge-report-' . gmdate('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SignalForge report', 'range=' . $d['range'], 'generated=' . gmdate('c')]);
    fputcsv($out, []);
    fputcsv($out, ['Metric', 'Value']);
    foreach ($d['totals'] as $k => $v) { fputcsv($out, [$k, $v]); }
    fputcsv($out, []);
    fputcsv($out, ['Day', 'Exposures', 'Conversions', 'Rate %']);
    foreach ($d['series'] as $s) { fputcsv($out, [$s['day'], $s['exposures'], $s['conversions'], $s['rate']]); }
    foreach (['device', 'browser', 'country', 'landingPage'] as $dim) {
        fputcsv($out, []);
        fputcsv($out, [ucfirst($dim), 'Exposures']);
        foreach ($d['breakdowns'][$dim] as $r) { fputcsv($out, [$r['label'], $r['value']]); }
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------- experiments v2

function v2_experiment_detail($user, $id)
{
    $row = v2_owned_experiment($user['id'], $id);
    $pdo = db();
    $vs = $pdo->prepare('SELECT * FROM variants WHERE experimentId = ? ORDER BY position ASC');
    $vs->execute([(int) $row['id']]);
    $variants = $vs->fetchAll();

    $ns = $pdo->prepare('SELECT n.*, UNIX_TIMESTAMP(n.createdAt) AS ts FROM experiment_notes n WHERE n.experimentId = ? ORDER BY n.id DESC');
    $ns->execute([(int) $row['id']]);
    $notes = [];
    foreach ($ns->fetchAll() as $n) { $notes[] = ['id' => (int) $n['id'], 'body' => $n['body'], 'ago' => time_ago((int) $n['ts'])]; }

    $ts = $pdo->prepare('SELECT tag FROM experiment_tags WHERE experimentId = ?');
    $ts->execute([(int) $row['id']]);
    $tags = array_map(function ($t) { return $t['tag']; }, $ts->fetchAll());

    $as = $pdo->prepare('SELECT action, detail, UNIX_TIMESTAMP(createdAt) AS ts FROM activity_log WHERE experimentId = ? ORDER BY id DESC LIMIT 40');
    $as->execute([(int) $row['id']]);
    $history = [];
    foreach ($as->fetchAll() as $a) { $history[] = ['action' => $a['action'], 'detail' => $a['detail'], 'ago' => time_ago((int) $a['ts'])]; }

    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'kind' => $row['kind'],
        'status' => $row['status'],
        'goal' => $row['goal'],
        'framework' => $row['framework'],
        'pageUrl' => (string) $row['pageUrl'],
        'hypothesis' => (string) $row['hypothesis'],
        'audience' => (string) $row['audience'],
        'conversionEvent' => (string) $row['conversionEvent'],
        'ownerName' => (string) $row['ownerName'],
        'templateKey' => (string) $row['templateKey'],
        'scheduledAt' => $row['scheduledAt'],
        'winnerVariantId' => $row['winnerVariantId'] !== null ? (int) $row['winnerVariantId'] : null,
        'winnerReason' => (string) $row['winnerReason'],
        'variants' => array_map(function ($v) {
            return ['id' => (int) $v['id'], 'name' => $v['name'], 'isControl' => (int) $v['isControl'] === 1, 'color' => $v['color']];
        }, $variants),
        'notes' => $notes,
        'tags' => $tags,
        'history' => $history,
    ];
}

// Automatic winner detection with a plain recommendation.
function v2_winner($user, $id)
{
    $row = v2_owned_experiment($user['id'], $id);
    $pdo = db();
    $vs = $pdo->prepare('SELECT * FROM variants WHERE experimentId = ? ORDER BY position ASC');
    $vs->execute([(int) $row['id']]);
    $variants = $vs->fetchAll();
    if (count($variants) < 2) {
        return ['decision' => 'collect_more', 'reason' => 'This experiment needs at least two variants before a winner can be chosen.', 'confidence' => 0, 'winnerVariantId' => null, 'variants' => []];
    }
    $control = $variants[0];
    foreach ($variants as $v) { if ((int) $v['isControl'] === 1) { $control = $v; break; } }

    $cnt = $pdo->prepare("SELECT variantId, type, COUNT(*) AS n FROM visitor_events WHERE experimentId = ? GROUP BY variantId, type");
    $cnt->execute([(int) $row['id']]);
    $c = [];
    foreach ($cnt->fetchAll() as $r) { $c[$r['variantId'] . ':' . $r['type']] = (int) $r['n']; }

    $cv = isset($c[$control['id'] . ':exposure']) ? $c[$control['id'] . ':exposure'] : 0;
    $cx = isset($c[$control['id'] . ':conversion']) ? $c[$control['id'] . ':conversion'] : 0;
    $cRate = $cv > 0 ? $cx / $cv : 0;

    $rows = [];
    $best = null;
    foreach ($variants as $v) {
        $vv = isset($c[$v['id'] . ':exposure']) ? $c[$v['id'] . ':exposure'] : 0;
        $vx = isset($c[$v['id'] . ':conversion']) ? $c[$v['id'] . ':conversion'] : 0;
        $rate = $vv > 0 ? $vx / $vv : 0;
        $isControl = (int) $v['id'] === (int) $control['id'];
        $uplift = (!$isControl && $cRate > 0) ? (($rate - $cRate) / $cRate) * 100 : 0;
        $conf = $isControl ? 50 : prob_to_beat($vv, $vx, $cv, $cx);
        $rows[] = ['id' => (int) $v['id'], 'name' => $v['name'], 'isControl' => $isControl,
            'visitors' => $vv, 'conversions' => $vx, 'rate' => round($rate * 100, 2),
            'uplift' => round($uplift, 1), 'confidence' => (int) round($conf)];
        if (!$isControl && ($best === null || $conf > $best['confidence'])) {
            $best = ['id' => (int) $v['id'], 'name' => $v['name'], 'confidence' => (int) round($conf), 'uplift' => round($uplift, 1), 'visitors' => $vv];
        }
    }

    $minVisitors = 100;
    if ($cv < $minVisitors) {
        return ['decision' => 'collect_more', 'reason' => "Control has only $cv visitors. Collect at least $minVisitors per variant before deciding.", 'confidence' => $best ? $best['confidence'] : 0, 'winnerVariantId' => null, 'variants' => $rows];
    }
    if ($best === null) {
        return ['decision' => 'keep_control', 'reason' => 'No challenger has recorded traffic yet.', 'confidence' => 0, 'winnerVariantId' => (int) $control['id'], 'variants' => $rows];
    }
    if ($best['confidence'] >= 95 && $best['uplift'] > 0) {
        return ['decision' => 'choose_variant', 'reason' => sprintf('%s is ahead by %.1f%% with %d%% confidence. Safe to promote.', $best['name'], $best['uplift'], $best['confidence']), 'confidence' => $best['confidence'], 'winnerVariantId' => $best['id'], 'variants' => $rows];
    }
    if ($best['confidence'] <= 20 && $best['uplift'] < 0) {
        return ['decision' => 'keep_control', 'reason' => sprintf('The challenger is behind by %.1f%%. Keep the control.', abs($best['uplift'])), 'confidence' => 100 - $best['confidence'], 'winnerVariantId' => (int) $control['id'], 'variants' => $rows];
    }
    return ['decision' => 'collect_more', 'reason' => sprintf('Best challenger is at %d%% confidence. Keep running until it reaches 95%%.', $best['confidence']), 'confidence' => $best['confidence'], 'winnerVariantId' => null, 'variants' => $rows];
}

// ---------------------------------------------------------------- tracking v2

function v2_tracking_diagnostics($user)
{
    $pdo = db();
    $ids = user_experiment_ids($user['id']);
    if (!$ids) {
        return ['duplicates' => [], 'health' => [], 'score' => 0, 'summary' => 'No experiments yet.'];
    }
    $in1 = in_placeholders($ids);

    // Duplicate detection: same visitor + same event more than once.
    $dup = $pdo->prepare("SELECT experimentId, eventName, visitorId, COUNT(*) AS n FROM event_occurrences WHERE experimentId IN ($in1) GROUP BY experimentId, eventName, visitorId HAVING n > 1 ORDER BY n DESC LIMIT 25");
    $dup->execute($ids);
    $duplicates = [];
    foreach ($dup->fetchAll() as $d) {
        $duplicates[] = ['experimentId' => (int) $d['experimentId'], 'event' => $d['eventName'], 'visitorId' => $d['visitorId'], 'count' => (int) $d['n']];
    }

    $checks = [];
    $agg = $pdo->prepare("SELECT COUNT(*) AS total, SUM(type='exposure') AS exp, SUM(type='conversion') AS conv, UNIX_TIMESTAMP(MAX(createdAt)) AS lastTs FROM visitor_events WHERE experimentId IN ($in1)");
    $agg->execute($ids);
    $a = $agg->fetch();
    $lastTs = $a['lastTs'] !== null ? (int) $a['lastTs'] : null;

    $checks[] = ['name' => 'Tracker script', 'status' => $lastTs !== null ? 'ok' : 'warn', 'detail' => $lastTs !== null ? 'Events received ' . time_ago($lastTs) : 'No events received yet'];
    $checks[] = ['name' => 'Exposures', 'status' => ((int) $a['exp']) > 0 ? 'ok' : 'warn', 'detail' => (int) $a['exp'] . ' exposures recorded'];
    $checks[] = ['name' => 'Conversions', 'status' => ((int) $a['conv']) > 0 ? 'ok' : 'warn', 'detail' => (int) $a['conv'] . ' conversions recorded'];
    $checks[] = ['name' => 'Duplicate events', 'status' => count($duplicates) === 0 ? 'ok' : 'warn', 'detail' => count($duplicates) . ' duplicate groups found'];

    $okCount = 0;
    foreach ($checks as $c) { if ($c['status'] === 'ok') { $okCount++; } }
    $score = (int) round(($okCount / max(1, count($checks))) * 100);

    return ['duplicates' => $duplicates, 'health' => $checks, 'score' => $score,
        'summary' => $score >= 75 ? 'Tracking looks healthy.' : 'Tracking needs attention.'];
}

// ---------------------------------------------------------------- integrations

function v2_integration_test($row)
{
    $cfg = json_decode((string) $row['config'], true) ?: [];
    $kind = $row['kind'];
    $started = microtime(true);
    $result = ['ok' => false, 'detail' => 'Not configured', 'latencyMs' => 0];

    if ($kind === 'webhook') {
        $url = isset($cfg['url']) ? (string) $cfg['url'] : '';
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'detail' => 'Webhook URL is missing or invalid.', 'latencyMs' => 0];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['event' => 'test', 'source' => 'signalforge', 'at' => gmdate('c')]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $result = ['ok' => $code >= 200 && $code < 300, 'detail' => $err !== '' ? $err : ('HTTP ' . $code), 'latencyMs' => (int) round((microtime(true) - $started) * 1000)];
        return $result;
    }

    if ($kind === 'ga4') {
        $mid = isset($cfg['measurementId']) ? (string) $cfg['measurementId'] : '';
        $secret = isset($cfg['apiSecret']) ? (string) $cfg['apiSecret'] : '';
        if ($mid === '' || $secret === '') {
            return ['ok' => false, 'detail' => 'Measurement ID and API secret are both required.', 'latencyMs' => 0];
        }
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($mid) . '&api_secret=' . rawurlencode($secret);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['client_id' => 'sf-test-' . bin2hex(random_bytes(4)), 'events' => [['name' => 'signalforge_test', 'params' => ['source' => 'signalforge']]]]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        // GA4 Measurement Protocol returns 204 on success.
        return ['ok' => ($code === 204 || ($code >= 200 && $code < 300)) && $err === '', 'detail' => $err !== '' ? $err : ('HTTP ' . $code . ' (204 = accepted)'), 'latencyMs' => (int) round((microtime(true) - $started) * 1000)];
    }

    if ($kind === 'gtm') {
        $cid = isset($cfg['containerId']) ? (string) $cfg['containerId'] : '';
        if ($cid === '') {
            return ['ok' => false, 'detail' => 'Container ID is required (GTM-XXXXXX).', 'latencyMs' => 0];
        }
        $url = 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode($cid);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_NOBODY => true]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $code === 200, 'detail' => 'HTTP ' . $code . ($code === 200 ? ' — container found' : ' — container not found'), 'latencyMs' => (int) round((microtime(true) - $started) * 1000)];
    }

    if ($kind === 'crm') {
        $url = isset($cfg['url']) ? (string) $cfg['url'] : '';
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'detail' => 'CRM endpoint URL is missing or invalid.', 'latencyMs' => 0];
        }
        $headers = ['Content-Type: application/json'];
        if (!empty($cfg['token'])) { $headers[] = 'Authorization: Bearer ' . $cfg['token']; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['email' => 'test@signalforge.local', 'source' => 'signalforge-test', 'event' => 'connection_test']),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => $code >= 200 && $code < 300, 'detail' => $err !== '' ? $err : ('HTTP ' . $code), 'latencyMs' => (int) round((microtime(true) - $started) * 1000)];
    }

    return $result;
}

// Fire every webhook subscribed to an event.
function v2_fire_webhooks($userId, $event, $payload)
{
    $st = db()->prepare("SELECT * FROM integrations WHERE userId = ? AND kind = 'webhook' AND status = 'connected'");
    $st->execute([(int) $userId]);
    $sent = 0;
    foreach ($st->fetchAll() as $row) {
        $cfg = json_decode((string) $row['config'], true) ?: [];
        $events = isset($cfg['events']) && is_array($cfg['events']) ? $cfg['events'] : ['*'];
        if (!in_array('*', $events, true) && !in_array($event, $events, true)) { continue; }
        $url = isset($cfg['url']) ? (string) $cfg['url'] : '';
        if ($url === '') { continue; }
        $body = json_encode(['event' => $event, 'at' => gmdate('c'), 'data' => $payload]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        db()->prepare('INSERT INTO webhook_deliveries (integrationId, event, payload, status, responseCode, attempts) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([(int) $row['id'], $event, mb_substr($body, 0, 60000), ($code >= 200 && $code < 300) ? 'delivered' : 'failed', $code]);
        $sent++;
    }
    return $sent;
}

// ---------------------------------------------------------------- AI

function v2_ai_call($prompt, $system = null)
{
    $url = (string) cfg('orchestrator_url');
    $token = (string) cfg('orchestrator_token');
    if ($url === '') {
        return ['ok' => false, 'error' => 'AI service is not configured.'];
    }
    // The orchestrator exposes a generic completion endpoint for the app's AI features.
    $endpoint = rtrim($url, '/') . '/v1/complete';
    $payload = json_encode(['prompt' => $prompt, 'system' => $system]);
    $ch = curl_init($endpoint);
    $headers = ['Content-Type: application/json'];
    if ($token !== '') { $headers[] = 'Authorization: Bearer ' . $token; }
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) cfg('orchestrator_timeout'),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') {
        return ['ok' => false, 'error' => 'AI service unreachable: ' . $err];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'AI service returned HTTP ' . $code];
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'AI service returned invalid JSON.'];
    }
    return ['ok' => true, 'text' => isset($decoded['text']) ? (string) $decoded['text'] : (string) $body, 'raw' => $decoded];
}

// Deterministic fallback so AI features still return something useful when the
// service is asleep. Clearly labelled as heuristic, never presented as AI output.
function v2_heuristic_recommendations($user, $experimentId = null)
{
    $pdo = db();
    $ids = $experimentId ? [(int) $experimentId] : user_experiment_ids($user['id']);
    $out = [];
    if (!$ids) {
        return [[
            'title' => 'Create your first experiment',
            'why' => 'Without a running test there is no data to optimise against.',
            'impact' => 'high', 'effort' => 'low', 'priority' => 90, 'source' => 'heuristic',
        ]];
    }
    $in1 = in_placeholders($ids);
    $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(type='conversion') AS conv FROM visitor_events WHERE experimentId IN ($in1)");
    $q->execute($ids);
    $a = $q->fetch();
    if ((int) $a['n'] < 100) {
        $out[] = ['title' => 'Collect more traffic before deciding', 'why' => 'Fewer than 100 events means any uplift reading is noise.', 'impact' => 'high', 'effort' => 'low', 'priority' => 85, 'source' => 'heuristic'];
    }
    if ((int) $a['conv'] === 0) {
        $out[] = ['title' => 'Verify the conversion event fires', 'why' => 'No conversions recorded — the tracker may not be wired to your goal.', 'impact' => 'high', 'effort' => 'medium', 'priority' => 95, 'source' => 'heuristic'];
    }
    $out[] = ['title' => 'Test a benefit-led headline', 'why' => 'Headline tests are the highest-leverage change on most landing pages.', 'impact' => 'medium', 'effort' => 'low', 'priority' => 60, 'source' => 'heuristic'];
    $out[] = ['title' => 'Shorten the primary form', 'why' => 'Each removed field typically lifts completion by a few percent.', 'impact' => 'medium', 'effort' => 'medium', 'priority' => 55, 'source' => 'heuristic'];
    return $out;
}

// ---------------------------------------------------------------- crawler

function v2_fetch_page($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SignalForgeAudit/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['ok' => $err === '' && $code > 0, 'status' => $code, 'body' => (string) $body, 'error' => $err, 'url' => $finalUrl];
}

// Real, deterministic audit checks against fetched HTML.
function v2_audit_html($html, $url)
{
    $issues = [];
    $add = function ($severity, $category, $title, $detail) use (&$issues) {
        $issues[] = ['severity' => $severity, 'category' => $category, 'title' => $title, 'detail' => $detail];
    };

    $len = strlen($html);
    if ($len < 500) {
        $add('critical', 'performance', 'Page returned almost no content', "Only $len bytes were returned. The page may be blocked, broken, or behind a bot check.");
    }

    // Title
    if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $add('high', 'seo', 'Missing page title', 'Search engines and browser tabs have nothing to show.');
    } else {
        $title = trim(html_entity_decode(strip_tags($m[1])));
        if (mb_strlen($title) < 15) { $add('medium', 'seo', 'Page title is very short', 'Title is "' . $title . '" (' . mb_strlen($title) . ' chars). Aim for 30-60.'); }
        if (mb_strlen($title) > 65) { $add('low', 'seo', 'Page title is long', 'Title is ' . mb_strlen($title) . ' chars; it will be truncated in search results.'); }
    }

    // Meta description
    if (!preg_match('/<meta[^>]+name=["\']description["\'][^>]*>/i', $html)) {
        $add('medium', 'seo', 'Missing meta description', 'Search results will use scraped text instead of your message.');
    }

    // H1
    $h1 = preg_match_all('/<h1[^>]*>/i', $html);
    if ($h1 === 0) { $add('high', 'seo', 'No H1 heading', 'The page has no primary heading, which weakens both SEO and visual hierarchy.'); }
    elseif ($h1 > 1) { $add('medium', 'seo', 'Multiple H1 headings', "Found $h1 H1 tags. Use one primary heading per page."); }

    // Viewport / mobile
    if (!preg_match('/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $html)) {
        $add('critical', 'mobile', 'No viewport meta tag', 'The page will render zoomed-out on phones. This is the single biggest mobile usability failure.');
    }

    // Images without alt
    $imgs = preg_match_all('/<img\b[^>]*>/i', $html, $imgTags);
    $noAlt = 0;
    if ($imgs > 0) {
        foreach ($imgTags[0] as $tag) {
            if (!preg_match('/\balt\s*=/i', $tag)) { $noAlt++; }
        }
        if ($noAlt > 0) {
            $add($noAlt > 3 ? 'high' : 'medium', 'accessibility', 'Images missing alt text', "$noAlt of $imgs images have no alt attribute. Screen readers cannot describe them.");
        }
    }

    // Language attribute
    if (!preg_match('/<html[^>]+lang\s*=/i', $html)) {
        $add('medium', 'accessibility', 'Missing lang attribute', 'Screen readers cannot pick the right pronunciation without it.');
    }

    // Forms
    $forms = preg_match_all('/<form\b[^>]*>/i', $html);
    if ($forms > 0) {
        $inputs = preg_match_all('/<input\b[^>]*>/i', $html, $inputTags);
        $unlabelled = 0;
        foreach ($inputTags[0] as $tag) {
            if (preg_match('/type=["\'](hidden|submit|button)["\']/i', $tag)) { continue; }
            if (!preg_match('/\b(aria-label|id)\s*=/i', $tag)) { $unlabelled++; }
        }
        if ($unlabelled > 0) {
            $add('high', 'forms', 'Form fields without labels', "$unlabelled input(s) have no id or aria-label, so they are unlabelled for assistive tech.");
        }
        if ($inputs > 8) {
            $add('medium', 'conversion', 'Long form may hurt conversion', "This page has $inputs inputs. Every extra field costs completions.");
        }
    }

    // CTAs
    $ctaPattern = '/<(a|button)\b[^>]*>(.*?)<\/\1>/is';
    $ctaCount = 0;
    if (preg_match_all($ctaPattern, $html, $ctas)) {
        foreach ($ctas[2] as $text) {
            $t = strtolower(trim(strip_tags($text)));
            if (preg_match('/\b(get started|start|try|buy|sign up|subscribe|book|request|download|contact|add to cart|checkout|free)\b/', $t)) { $ctaCount++; }
        }
    }
    if ($ctaCount === 0) {
        $add('critical', 'conversion', 'No clear call to action', 'No button or link uses action language. Visitors have no obvious next step.');
    } elseif ($ctaCount > 6) {
        $add('medium', 'conversion', 'Too many competing CTAs', "$ctaCount action links compete for attention. Pick one primary action.");
    }

    // Trust signals
    if (!preg_match('/\b(testimonial|review|trusted by|rating|guarantee|secure|ssl)\b/i', $html)) {
        $add('low', 'conversion', 'No visible trust signals', 'Adding reviews, guarantees or security badges reduces hesitation.');
    }

    // Performance heuristics
    if (preg_match_all('/<script\b[^>]*src=/i', $html) > 12) {
        $add('medium', 'performance', 'Many external scripts', 'More than 12 script tags will slow first paint on mobile.');
    }
    if ($len > 500000) {
        $add('medium', 'performance', 'Very large HTML payload', 'The document is ' . round($len / 1024) . ' KB before images and scripts.');
    }

    // Broken links (internal anchors with empty href)
    if (preg_match_all('/<a\b[^>]*href=["\']\s*["\']/i', $html) > 0) {
        $add('low', 'links', 'Links with empty href', 'Some anchors point nowhere, which looks broken to visitors.');
    }

    $weights = ['critical' => 25, 'high' => 12, 'medium' => 6, 'low' => 2];
    $penalty = 0;
    foreach ($issues as $i) { $penalty += isset($weights[$i['severity']]) ? $weights[$i['severity']] : 5; }
    $score = max(0, 100 - $penalty);

    return ['issues' => $issues, 'score' => $score];
}

// ---------------------------------------------------------------- competitors

function v2_competitor_snapshot($url)
{
    $page = v2_fetch_page($url);
    if (!$page['ok']) {
        return ['ok' => false, 'error' => $page['error'] !== '' ? $page['error'] : ('HTTP ' . $page['status'])];
    }
    $html = $page['body'];
    $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html))));

    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) { $title = trim(html_entity_decode(strip_tags($m[1]))); }
    $h1 = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) { $h1 = trim(html_entity_decode(strip_tags($m[1]))); }
    $desc = '';
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)/i', $html, $m)) { $desc = trim(html_entity_decode($m[1])); }

    $ctas = [];
    if (preg_match_all('/<(a|button)\b[^>]*>(.*?)<\/\1>/is', $html, $mm)) {
        foreach ($mm[2] as $t) {
            $t = trim(preg_replace('/\s+/', ' ', strip_tags($t)));
            if ($t !== '' && mb_strlen($t) < 40 && preg_match('/\b(get|start|try|buy|sign|book|request|download|contact|free|shop|order)\b/i', $t)) {
                $ctas[] = $t;
            }
        }
    }
    $ctas = array_values(array_unique(array_slice($ctas, 0, 12)));

    $prices = [];
    if (preg_match_all('/(?:[$£€]\s?\d[\d,]*(?:\.\d{2})?)/', $text, $pm)) {
        $prices = array_values(array_unique(array_slice($pm[0], 0, 12)));
    }

    $trust = [];
    foreach (['testimonial', 'review', 'trusted by', 'rating', 'guarantee', 'money back', 'secure', 'ssl', 'certified'] as $kw) {
        if (stripos($html, $kw) !== false) { $trust[] = $kw; }
    }

    $headings = [];
    if (preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', $html, $hm)) {
        foreach (array_slice($hm[1], 0, 10) as $h) {
            $h = trim(preg_replace('/\s+/', ' ', strip_tags($h)));
            if ($h !== '') { $headings[] = $h; }
        }
    }

    return ['ok' => true, 'snapshot' => [
        'url' => $page['url'], 'status' => $page['status'], 'title' => $title, 'h1' => $h1,
        'description' => $desc, 'ctas' => $ctas, 'prices' => $prices, 'trustSignals' => $trust,
        'headings' => $headings, 'wordCount' => str_word_count($text),
        'hasViewport' => (bool) preg_match('/<meta[^>]+name=["\']viewport["\']/i', $html),
        'capturedAt' => gmdate('c'),
    ]];
}

function v2_compare_snapshots($old, $new)
{
    $changes = [];
    foreach (['title', 'h1', 'description'] as $f) {
        $a = isset($old[$f]) ? (string) $old[$f] : '';
        $b = isset($new[$f]) ? (string) $new[$f] : '';
        if ($a !== $b) { $changes[] = ['field' => $f, 'from' => $a, 'to' => $b]; }
    }
    foreach (['ctas', 'prices', 'trustSignals'] as $f) {
        $a = isset($old[$f]) && is_array($old[$f]) ? $old[$f] : [];
        $b = isset($new[$f]) && is_array($new[$f]) ? $new[$f] : [];
        $added = array_values(array_diff($b, $a));
        $removed = array_values(array_diff($a, $b));
        if ($added || $removed) { $changes[] = ['field' => $f, 'added' => $added, 'removed' => $removed]; }
    }
    return $changes;
}

// ---------------------------------------------------------------- ecommerce

function v2_ecommerce($user, $in)
{
    list($from, $to, $label) = v2_range($in);
    $pdo = db();
    $uid = (int) $user['id'];
    $st = $pdo->prepare('SELECT * FROM ecommerce_metrics WHERE userId = ? AND day BETWEEN ? AND ? ORDER BY day ASC');
    $st->execute([$uid, gmdate('Y-m-d', $from), gmdate('Y-m-d', $to)]);
    $rows = $st->fetchAll();

    $revenue = 0.0; $orders = 0; $sessions = 0; $carts = 0; $checkouts = 0; $views = 0;
    $series = [];
    foreach ($rows as $r) {
        $revenue += (float) $r['revenue'];
        $orders += (int) $r['orders'];
        $sessions += (int) $r['sessions'];
        $carts += (int) $r['carts'];
        $checkouts += (int) $r['checkouts'];
        $views += (int) $r['productViews'];
        $series[] = ['day' => $r['day'], 'revenue' => (float) $r['revenue'], 'orders' => (int) $r['orders'], 'sessions' => (int) $r['sessions']];
    }

    return [
        'range' => $label,
        'totals' => [
            'revenue' => round($revenue, 2),
            'orders' => $orders,
            'sessions' => $sessions,
            'revenuePerVisitor' => $sessions > 0 ? round($revenue / $sessions, 2) : 0,
            'averageOrderValue' => $orders > 0 ? round($revenue / $orders, 2) : 0,
            'cartAbandonment' => $carts > 0 ? round((($carts - $orders) / $carts) * 100, 1) : 0,
            'checkoutCompletion' => $checkouts > 0 ? round(($orders / $checkouts) * 100, 1) : 0,
            'productConversionRate' => $views > 0 ? round(($orders / $views) * 100, 2) : 0,
        ],
        'series' => $series,
    ];
}

// ---------------------------------------------------------------- system health

function v2_system_health($user)
{
    $checks = [];

    // Database
    $t0 = microtime(true);
    try {
        db()->query('SELECT 1');
        $checks[] = ['component' => 'Database', 'status' => 'ok', 'detail' => 'MySQL reachable', 'latencyMs' => (int) round((microtime(true) - $t0) * 1000)];
    } catch (Throwable $e) {
        $checks[] = ['component' => 'Database', 'status' => 'down', 'detail' => $e->getMessage(), 'latencyMs' => null];
    }

    // Tracker
    $ids = user_experiment_ids($user['id']);
    $lastTs = null;
    if ($ids) {
        $in1 = in_placeholders($ids);
        $q = db()->prepare("SELECT UNIX_TIMESTAMP(MAX(createdAt)) AS ts FROM visitor_events WHERE experimentId IN ($in1)");
        $q->execute($ids);
        $r = $q->fetch();
        $lastTs = $r['ts'] !== null ? (int) $r['ts'] : null;
    }
    $checks[] = ['component' => 'Tracker', 'status' => $lastTs !== null ? 'ok' : 'warn',
        'detail' => $lastTs !== null ? 'Last event ' . time_ago($lastTs) : 'No events yet', 'latencyMs' => null];

    // AI service
    $t0 = microtime(true);
    $url = (string) cfg('orchestrator_url');
    if ($url === '') {
        $checks[] = ['component' => 'AI service', 'status' => 'warn', 'detail' => 'Not configured', 'latencyMs' => null];
    } else {
        $ch = curl_init(rtrim($url, '/') . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int) cfg('orchestrator_health_timeout')]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $body, true);
        $checks[] = ['component' => 'AI service', 'status' => $code === 200 ? 'ok' : 'warn',
            'detail' => $code === 200 ? ('Provider: ' . (isset($decoded['provider']) ? $decoded['provider'] : 'unknown')) : ('HTTP ' . $code . ' (may be asleep)'),
            'latencyMs' => (int) round((microtime(true) - $t0) * 1000)];
    }

    // Storage
    $free = @disk_free_space(__DIR__);
    $checks[] = ['component' => 'Storage', 'status' => $free === false ? 'warn' : ($free > 50 * 1024 * 1024 ? 'ok' : 'warn'),
        'detail' => $free === false ? 'Cannot read disk space' : round($free / 1024 / 1024) . ' MB free', 'latencyMs' => null];

    // Scheduled jobs
    $jobs = db()->query('SELECT jobKey, lastRunAt, lastStatus, runCount FROM scheduled_jobs ORDER BY jobKey')->fetchAll();
    $jobList = [];
    foreach ($jobs as $j) {
        $jobList[] = ['key' => $j['jobKey'], 'lastRun' => $j['lastRunAt'], 'status' => $j['lastStatus'], 'runs' => (int) $j['runCount']];
    }
    $checks[] = ['component' => 'Scheduled jobs', 'status' => count($jobList) > 0 ? 'ok' : 'warn',
        'detail' => count($jobList) . ' job(s) registered', 'latencyMs' => null];

    $ok = 0;
    foreach ($checks as $c) { if ($c['status'] === 'ok') { $ok++; } }

    return ['checks' => $checks, 'jobs' => $jobList, 'score' => (int) round(($ok / max(1, count($checks))) * 100),
        'overall' => $ok === count($checks) ? 'healthy' : ($ok >= count($checks) - 1 ? 'degraded' : 'unhealthy')];
}

// ---------------------------------------------------------------- onboarding

function v2_onboarding($user)
{
    $st = db()->prepare('SELECT steps FROM onboarding WHERE userId = ? LIMIT 1');
    $st->execute([(int) $user['id']]);
    $row = $st->fetch();
    $steps = $row ? (json_decode((string) $row['steps'], true) ?: []) : [];

    $ids = user_experiment_ids($user['id']);
    $hasExperiment = count($ids) > 0;
    $hasEvents = false;
    if ($ids) {
        $in1 = in_placeholders($ids);
        $q = db()->prepare("SELECT COUNT(*) AS n FROM visitor_events WHERE experimentId IN ($in1)");
        $q->execute($ids);
        $hasEvents = (int) $q->fetch()['n'] > 0;
    }

    $defaults = [
        ['key' => 'account', 'label' => 'Create your account', 'done' => true],
        ['key' => 'experiment', 'label' => 'Create your first experiment', 'done' => $hasExperiment],
        ['key' => 'tracker', 'label' => 'Install the tracking snippet', 'done' => $hasEvents],
        ['key' => 'traffic', 'label' => 'Collect your first visitors', 'done' => $hasEvents],
        ['key' => 'decide', 'label' => 'Review a winner recommendation', 'done' => false],
    ];
    foreach ($defaults as $i => $d) {
        if (isset($steps[$d['key']])) { $defaults[$i]['done'] = (bool) $steps[$d['key']]; }
    }
    $doneCount = 0;
    foreach ($defaults as $d) { if ($d['done']) { $doneCount++; } }

    return ['steps' => $defaults, 'completed' => $doneCount, 'total' => count($defaults),
        'percent' => (int) round(($doneCount / count($defaults)) * 100)];
}

// ---------------------------------------------------------------- router

function v2_handle($route, $method, $user)
{
    $in = $method === 'POST' ? body_json() : query_input();

    switch ($route) {
        // ---- dashboard ----
        case 'v2.dashboard':
            respond(v2_dashboard($user, $in));
            break;
        case 'v2.export.csv':
            v2_export_csv($user, $in);
            break;

        // ---- experiments ----
        case 'v2.experiments.detail':
            respond(v2_experiment_detail($user, v2_int($in, 'id')));
            break;
        case 'v2.experiments.winner':
            respond(v2_winner($user, v2_int($in, 'id')));
            break;
        case 'v2.experiments.templates':
            $rows = db()->query('SELECT tkey, name, category, description, payload FROM experiment_templates ORDER BY category, name')->fetchAll();
            respond(array_map(function ($r) {
                return ['key' => $r['tkey'], 'name' => $r['name'], 'category' => $r['category'],
                    'description' => $r['description'], 'payload' => json_decode((string) $r['payload'], true)];
            }, $rows));
            break;
        case 'v2.experiments.addNote':
            require_post();
            $row = v2_owned_experiment($user['id'], v2_int($in, 'id'));
            $body = v2_str($in, 'body', 4000);
            if ($body === '') { fail('Note cannot be empty.'); }
            db()->prepare('INSERT INTO experiment_notes (experimentId, userId, body) VALUES (?, ?, ?)')->execute([(int) $row['id'], (int) $user['id'], $body]);
            v2_log($user['id'], 'note_added', mb_substr($body, 0, 120), (int) $row['id']);
            respond(['ok' => true]);
            break;
        case 'v2.experiments.setTags':
            require_post();
            $row = v2_owned_experiment($user['id'], v2_int($in, 'id'));
            $tags = v2_json($in, 'tags');
            if (!is_array($tags)) { $tags = []; }
            db()->prepare('DELETE FROM experiment_tags WHERE experimentId = ?')->execute([(int) $row['id']]);
            $ins = db()->prepare('INSERT IGNORE INTO experiment_tags (experimentId, tag) VALUES (?, ?)');
            foreach (array_slice($tags, 0, 12) as $t) {
                if (is_string($t) && trim($t) !== '') { $ins->execute([(int) $row['id'], mb_substr(trim($t), 0, 48)]); }
            }
            v2_log($user['id'], 'tags_updated', implode(', ', array_slice($tags, 0, 12)), (int) $row['id']);
            respond(['ok' => true]);
            break;
        case 'v2.experiments.setOwner':
            require_post();
            $row = v2_owned_experiment($user['id'], v2_int($in, 'id'));
            $owner = v2_str($in, 'owner', 120);
            db()->prepare('UPDATE experiments SET ownerName = ? WHERE id = ?')->execute([$owner, (int) $row['id']]);
            v2_log($user['id'], 'owner_changed', $owner, (int) $row['id']);
            respond(['ok' => true]);
            break;
        case 'v2.experiments.setStatus2':
            require_post();
            $row = v2_owned_experiment($user['id'], v2_int($in, 'id'));
            $status = v2_str($in, 'status', 24);
            $allowed = ['Draft', 'Scheduled', 'Active', 'Running', 'Paused', 'Completed', 'Archived'];
            if (!in_array($status, $allowed, true)) { fail('Invalid status.'); }
            $sched = null;
            if ($status === 'Scheduled') {
                $s = v2_str($in, 'scheduledAt', 32);
                $ts = $s !== '' ? strtotime($s) : false;
                if (!$ts) { fail('Scheduled experiments need a valid date.'); }
                $sched = gmdate('Y-m-d H:i:s', $ts);
            }
            db()->prepare('UPDATE experiments SET status = ?, scheduledAt = ?, updatedAt = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$status, $sched, (int) $row['id']]);
            v2_log($user['id'], 'status_changed', $status, (int) $row['id']);
            if ($status === 'Active' || $status === 'Running') {
                v2_fire_webhooks($user['id'], 'experiment.started', ['experimentId' => (int) $row['id'], 'name' => $row['name']]);
            }
            respond(['ok' => true, 'status' => $status]);
            break;
        case 'v2.experiments.promoteWinner':
            require_post();
            $row = v2_owned_experiment($user['id'], v2_int($in, 'id'));
            $w = v2_winner($user, (int) $row['id']);
            if ($w['decision'] !== 'choose_variant' || $w['winnerVariantId'] === null) {
                fail('No statistically clear winner yet. ' . $w['reason'], 'CONFLICT', 409);
            }
            db()->prepare('UPDATE experiments SET winnerVariantId = ?, winnerReason = ?, status = \'Completed\', updatedAt = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([(int) $w['winnerVariantId'], mb_substr($w['reason'], 0, 500), (int) $row['id']]);
            v2_log($user['id'], 'winner_selected', $w['reason'], (int) $row['id']);
            v2_fire_webhooks($user['id'], 'winner.selected', ['experimentId' => (int) $row['id'], 'variantId' => (int) $w['winnerVariantId'], 'reason' => $w['reason']]);
            respond(['ok' => true, 'winner' => $w]);
            break;

        // ---- tracking ----
        case 'v2.tracking.diagnostics':
            respond(v2_tracking_diagnostics($user));
            break;
        case 'v2.tracking.events':
            $st = db()->prepare('SELECT * FROM custom_events WHERE userId = ? ORDER BY id DESC');
            $st->execute([(int) $user['id']]);
            respond(array_map(function ($r) {
                return ['id' => (int) $r['id'], 'name' => $r['name'], 'kind' => $r['kind'],
                    'selector' => $r['selector'], 'pageUrl' => $r['pageUrl'], 'status' => $r['status']];
            }, $st->fetchAll()));
            break;
        case 'v2.tracking.addEvent':
            require_post();
            $name = v2_str($in, 'name', 120);
            if ($name === '') { fail('Event name is required.'); }
            db()->prepare('INSERT INTO custom_events (userId, experimentId, name, kind, selector, pageUrl) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([(int) $user['id'], v2_int($in, 'experimentId') ?: null, $name, v2_str($in, 'kind', 32, 'click'), v2_str($in, 'selector', 255), v2_str($in, 'pageUrl', 500)]);
            v2_log($user['id'], 'event_created', $name);
            respond(['ok' => true, 'id' => (int) db()->lastInsertId()]);
            break;
        case 'v2.tracking.removeEvent':
            require_post();
            db()->prepare('DELETE FROM custom_events WHERE id = ? AND userId = ?')->execute([v2_int($in, 'id'), (int) $user['id']]);
            respond(['ok' => true]);
            break;
        case 'v2.tracking.utm':
            $st = db()->prepare("SELECT utmSource, utmMedium, utmCampaign, COUNT(*) AS n, SUM(type='conversion') AS conv FROM visitor_events WHERE experimentId IN (SELECT id FROM experiments WHERE userId = ?) AND utmSource IS NOT NULL GROUP BY utmSource, utmMedium, utmCampaign ORDER BY n DESC LIMIT 50");
            $st->execute([(int) $user['id']]);
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $n = (int) $r['n']; $c = (int) $r['conv'];
                $out[] = ['source' => $r['utmSource'], 'medium' => $r['utmMedium'], 'campaign' => $r['utmCampaign'],
                    'visitors' => $n, 'conversions' => $c, 'rate' => $n > 0 ? round(($c / $n) * 100, 2) : 0];
            }
            respond($out);
            break;

        // ---- integrations ----
        case 'v2.integrations.list':
            $st = db()->prepare('SELECT * FROM integrations WHERE userId = ? ORDER BY id DESC');
            $st->execute([(int) $user['id']]);
            respond(array_map(function ($r) {
                $cfg = json_decode((string) $r['config'], true) ?: [];
                // Never return secrets to the browser.
                foreach (['token', 'apiSecret', 'password', 'key'] as $k) {
                    if (isset($cfg[$k])) { $cfg[$k] = '••••••'; }
                }
                return ['id' => (int) $r['id'], 'kind' => $r['kind'], 'name' => $r['name'],
                    'config' => $cfg, 'status' => $r['status'], 'lastSyncAt' => $r['lastSyncAt'], 'lastError' => $r['lastError']];
            }, $st->fetchAll()));
            break;
        case 'v2.integrations.save':
            require_post();
            $kind = v2_str($in, 'kind', 24);
            if (!in_array($kind, ['ga4', 'gtm', 'crm', 'webhook'], true)) { fail('Unknown integration type.'); }
            $name = v2_str($in, 'name', 120, ucfirst($kind));
            $config = v2_json($in, 'config');
            if (!is_array($config)) { $config = []; }
            $id = v2_int($in, 'id');
            if ($id > 0) {
                $st = db()->prepare('SELECT * FROM integrations WHERE id = ? AND userId = ?');
                $st->execute([$id, (int) $user['id']]);
                $existing = $st->fetch();
                if (!$existing) { fail('Integration not found.', 'NOT_FOUND', 404); }
                // Keep stored secrets when the browser sends the mask back.
                $old = json_decode((string) $existing['config'], true) ?: [];
                foreach (['token', 'apiSecret', 'password', 'key'] as $k) {
                    if (isset($config[$k]) && $config[$k] === '••••••' && isset($old[$k])) { $config[$k] = $old[$k]; }
                }
                db()->prepare('UPDATE integrations SET name = ?, config = ?, status = \'unverified\' WHERE id = ?')
                    ->execute([$name, json_encode($config), $id]);
            } else {
                db()->prepare('INSERT INTO integrations (userId, kind, name, config) VALUES (?, ?, ?, ?)')
                    ->execute([(int) $user['id'], $kind, $name, json_encode($config)]);
                $id = (int) db()->lastInsertId();
            }
            v2_log($user['id'], 'integration_saved', $kind . ': ' . $name);
            respond(['ok' => true, 'id' => $id]);
            break;
        case 'v2.integrations.test':
            require_post();
            $st = db()->prepare('SELECT * FROM integrations WHERE id = ? AND userId = ?');
            $st->execute([v2_int($in, 'id'), (int) $user['id']]);
            $row = $st->fetch();
            if (!$row) { fail('Integration not found.', 'NOT_FOUND', 404); }
            $res = v2_integration_test($row);
            db()->prepare('UPDATE integrations SET status = ?, lastError = ?, lastSyncAt = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$res['ok'] ? 'connected' : 'error', $res['ok'] ? null : mb_substr($res['detail'], 0, 500), (int) $row['id']]);
            respond($res);
            break;
        case 'v2.integrations.remove':
            require_post();
            db()->prepare('DELETE FROM integrations WHERE id = ? AND userId = ?')->execute([v2_int($in, 'id'), (int) $user['id']]);
            respond(['ok' => true]);
            break;
        case 'v2.integrations.deliveries':
            $st = db()->prepare('SELECT d.*, UNIX_TIMESTAMP(d.createdAt) AS ts FROM webhook_deliveries d INNER JOIN integrations i ON i.id = d.integrationId WHERE i.userId = ? ORDER BY d.id DESC LIMIT 50');
            $st->execute([(int) $user['id']]);
            respond(array_map(function ($r) {
                return ['id' => (int) $r['id'], 'event' => $r['event'], 'status' => $r['status'],
                    'responseCode' => $r['responseCode'] !== null ? (int) $r['responseCode'] : null,
                    'attempts' => (int) $r['attempts'], 'ago' => time_ago((int) $r['ts'])];
            }, $st->fetchAll()));
            break;

        // ---- AI ----
        case 'v2.ai.recommendations':
            $st = db()->prepare('SELECT * FROM recommendations WHERE userId = ? ORDER BY priority DESC, id DESC LIMIT 60');
            $st->execute([(int) $user['id']]);
            respond(array_map(function ($r) {
                return ['id' => (int) $r['id'], 'title' => $r['title'], 'why' => $r['why'],
                    'impact' => $r['impact'], 'effort' => $r['effort'], 'priority' => (int) $r['priority'],
                    'status' => $r['status'], 'source' => $r['source'], 'experimentId' => $r['experimentId'] !== null ? (int) $r['experimentId'] : null];
            }, $st->fetchAll()));
            break;
        case 'v2.ai.generateRecommendations':
            require_post();
            $experimentId = v2_int($in, 'experimentId') ?: null;
            $ai = v2_ai_call(
                "You are a CRO consultant. Based on this workspace data, return a JSON array of 5 recommendations. " .
                "Each item: {\"title\":string,\"why\":string,\"impact\":\"high|medium|low\",\"effort\":\"high|medium|low\",\"priority\":1-100}. " .
                "Data: " . json_encode(v2_dashboard($user, ['range' => '30d'])['totals']) . ". Return ONLY the JSON array.",
                'You return strict JSON only, no prose.'
            );
            $items = null;
            $source = 'ai';
            if ($ai['ok']) {
                $txt = trim($ai['text']);
                $txt = preg_replace('/^```(?:json)?|```$/m', '', $txt);
                $decoded = json_decode(trim($txt), true);
                if (is_array($decoded)) { $items = $decoded; }
            }
            if ($items === null) {
                $items = v2_heuristic_recommendations($user, $experimentId);
                $source = 'heuristic';
            }
            $ins = db()->prepare('INSERT INTO recommendations (userId, experimentId, source, title, why, impact, effort, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $created = 0;
            foreach (array_slice($items, 0, 10) as $it) {
                if (!is_array($it) || !isset($it['title'])) { continue; }
                $ins->execute([
                    (int) $user['id'], $experimentId, $source,
                    mb_substr((string) $it['title'], 0, 255),
                    mb_substr(isset($it['why']) ? (string) $it['why'] : '', 0, 4000),
                    in_array(isset($it['impact']) ? $it['impact'] : '', ['high', 'medium', 'low'], true) ? $it['impact'] : 'medium',
                    in_array(isset($it['effort']) ? $it['effort'] : '', ['high', 'medium', 'low'], true) ? $it['effort'] : 'medium',
                    isset($it['priority']) ? max(1, min(100, (int) $it['priority'])) : 50,
                ]);
                $created++;
            }
            v2_log($user['id'], 'recommendations_generated', $created . ' via ' . $source);
            respond(['ok' => true, 'created' => $created, 'source' => $source]);
            break;
        case 'v2.ai.setRecommendationStatus':
            require_post();
            $status = v2_str($in, 'status', 24);
            if (!in_array($status, ['suggested', 'approved', 'rejected', 'done'], true)) { fail('Invalid status.'); }
            db()->prepare('UPDATE recommendations SET status = ? WHERE id = ? AND userId = ?')->execute([$status, v2_int($in, 'id'), (int) $user['id']]);
            v2_log($user['id'], 'recommendation_' . $status, 'id ' . v2_int($in, 'id'));
            respond(['ok' => true]);
            break;
        case 'v2.ai.generateCopy':
            require_post();
            $kind = v2_str($in, 'kind', 32, 'headline');
            $context = v2_str($in, 'context', 2000);
            $ai = v2_ai_call(
                "Write 3 alternative $kind options for a CRO test. Context: $context. " .
                "Return a JSON array of 3 strings only.",
                'You return strict JSON only, no prose.'
            );
            $options = null;
            $source = 'ai';
            if ($ai['ok']) {
                $txt = preg_replace('/^```(?:json)?|```$/m', '', trim($ai['text']));
                $d = json_decode(trim($txt), true);
                if (is_array($d)) { $options = array_values(array_filter($d, 'is_string')); }
            }
            if (!$options) {
                $source = 'heuristic';
                $options = [
                    'Start free — no card required',
                    'See results in your first week',
                    'Join thousands already improving their site',
                ];
            }
            $ins = db()->prepare('INSERT INTO variant_copy (recommendationId, experimentId, kind, content, status) VALUES (?, ?, ?, ?, \'draft\')');
            foreach (array_slice($options, 0, 5) as $o) {
                $ins->execute([v2_int($in, 'recommendationId') ?: null, v2_int($in, 'experimentId') ?: null, $kind, mb_substr($o, 0, 2000)]);
            }
            respond(['ok' => true, 'options' => array_slice($options, 0, 5), 'source' => $source]);
            break;
        case 'v2.ai.copy':
            $st = db()->prepare('SELECT * FROM variant_copy WHERE experimentId IN (SELECT id FROM experiments WHERE userId = ?) OR recommendationId IN (SELECT id FROM recommendations WHERE userId = ?) ORDER BY id DESC LIMIT 50');
            $st->execute([(int) $user['id'], (int) $user['id']]);
            respond(array_map(function ($r) {
                return ['id' => (int) $r['id'], 'kind' => $r['kind'], 'content' => $r['content'], 'status' => $r['status']];
            }, $st->fetchAll()));
            break;
        case 'v2.ai.setCopyStatus':
            require_post();
            $status = v2_str($in, 'status', 24);
            if (!in_array($status, ['draft', 'approved', 'published', 'rejected'], true)) { fail('Invalid status.'); }
            db()->prepare('UPDATE variant_copy SET status = ? WHERE id = ?')->execute([$status, v2_int($in, 'id')]);
            respond(['ok' => true]);
            break;

        // ---- crawler ----
        case 'v2.crawl.list':
            $st = db()->prepare('SELECT c.*, UNIX_TIMESTAMP(c.startedAt) AS ts FROM crawls c WHERE c.userId = ? ORDER BY c.id DESC LIMIT 30');
            $st->execute([(int) $user['id']]);
            respond(array_map(function ($r) {
                return ['id' => (int) $r['id'], 'siteUrl' => $r['siteUrl'], 'status' => $r['status'],
                    'pagesCrawled' => (int) $r['pagesCrawled'], 'score' => $r['score'] !== null ? (int) $r['score'] : null,
                    'error' => $r['error'], 'ago' => time_ago((int) $r['ts'])];
            }, $st->fetchAll()));
            break;
        case 'v2.crawl.start':
            require_post();
            $siteUrl = v2_str($in, 'siteUrl', 500);
            if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) { fail('Enter a valid URL including https://'); }
            if (too_many('crawl:' . $user['id'], 10, 3600)) { fail('Crawl limit reached (10 per hour). Try again later.', 'TOO_MANY_REQUESTS', 429); }
            db()->prepare('INSERT INTO crawls (userId, siteUrl, status) VALUES (?, ?, \'running\')')->execute([(int) $user['id'], $siteUrl]);
            $crawlId = (int) db()->lastInsertId();

            $page = v2_fetch_page($siteUrl);
            if (!$page['ok']) {
                db()->prepare('UPDATE crawls SET status = \'failed\', error = ?, finishedAt = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([mb_substr($page['error'] !== '' ? $page['error'] : ('HTTP ' . $page['status']), 0, 500), $crawlId]);
                respond(['ok' => false, 'id' => $crawlId, 'error' => 'Could not fetch the page: ' . ($page['error'] !== '' ? $page['error'] : 'HTTP ' . $page['status'])]);
            }

            $audit = v2_audit_html($page['body'], $page['url']);
            db()->prepare('INSERT INTO crawl_pages (crawlId, url, statusCode, title, score, issues) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$crawlId, mb_substr($page['url'], 0, 500), $page['status'],
                    mb_substr(preg_match('/<title[^>]*>(.*?)<\/title>/is', $page['body'], $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '', 0, 255),
                    $audit['score'], json_encode($audit['issues'])]);
            $pageId = (int) db()->lastInsertId();
            $ins = db()->prepare('INSERT INTO crawl_issues (crawlId, pageId, severity, category, title, detail) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($audit['issues'] as $i) {
                $ins->execute([$crawlId, $pageId, $i['severity'], $i['category'], mb_substr($i['title'], 0, 255), $i['detail']]);
            }
            db()->prepare('UPDATE crawls SET status = \'completed\', pagesCrawled = 1, score = ?, finishedAt = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$audit['score'], $crawlId]);
            v2_log($user['id'], 'crawl_completed', $siteUrl . ' — score ' . $audit['score']);
            respond(['ok' => true, 'id' => $crawlId, 'score' => $audit['score'], 'issues' => count($audit['issues'])]);
            break;
        case 'v2.crawl.detail':
            $st = db()->prepare('SELECT * FROM crawls WHERE id = ? AND userId = ?');
            $st->execute([v2_int($in, 'id'), (int) $user['id']]);
            $crawl = $st->fetch();
            if (!$crawl) { fail('Crawl not found.', 'NOT_FOUND', 404); }
            $ps = db()->prepare('SELECT * FROM crawl_pages WHERE crawlId = ?');
            $ps->execute([(int) $crawl['id']]);
            $pages = [];
            foreach ($ps->fetchAll() as $p) {
                $pages[] = ['id' => (int) $p['id'], 'url' => $p['url'], 'statusCode' => $p['statusCode'] !== null ? (int) $p['statusCode'] : null,
                    'title' => $p['title'], 'score' => $p['score'] !== null ? (int) $p['score'] : null,
                    'issues' => json_decode((string) $p['issues'], true) ?: []];
            }
            $is = db()->prepare('SELECT * FROM crawl_issues WHERE crawlId = ? ORDER BY FIELD(severity,\'critical\',\'high\',\'medium\',\'low\'), id');
            $is->execute([(int) $crawl['id']]);
            $issues = [];
            foreach ($is->fetchAll() as $i) {
                $issues[] = ['id' => (int) $i['id'], 'severity' => $i['severity'], 'category' => $i['category'],
                    'title' => $i['title'], 'detail' => $i['detail'], 'pageId' => $i['pageId'] !== null ? (int) $i['pageId'] : null];
            }
            $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
            foreach ($issues as $i) { if (isset($counts[$i['severity']])) { $counts[$i['severity']]++; } }
            respond(['id' => (int) $crawl['id'], 'siteUrl' => $crawl['siteUrl'], 'status' => $crawl['status'],
                'score' => $crawl['score'] !== null ? (int) $crawl['score'] : null, 'error' => $crawl['error'],
                'pages' => $pages, 'issues' => $issues, 'counts' => $counts]);
            break;
        case 'v2.crawl.compare':
            $st = db()->prepare('SELECT * FROM crawls WHERE id = ? AND userId = ?');
            $st->execute([v2_int($in, 'id'), (int) $user['id']]);
            $cur = $st->fetch();
            if (!$cur) { fail('Crawl not found.', 'NOT_FOUND', 404); }
            $prev = db()->prepare('SELECT * FROM crawls WHERE userId = ? AND siteUrl = ? AND id < ? AND status = \'completed\' ORDER BY id DESC LIMIT 1');
            $prev->execute([(int) $user['id'], $cur['siteUrl'], (int) $cur['id']]);
            $p = $prev->fetch();
            if (!$p) { respond(['hasPrevious' => false, 'message' => 'This is the first completed crawl for this site.']); }
            $ci = db()->prepare('SELECT title FROM crawl_issues WHERE crawlId = ?');
            $ci->execute([(int) $cur['id']]);
            $curTitles = array_map(function ($r) { return $r['title']; }, $ci->fetchAll());
            $pi = db()->prepare('SELECT title FROM crawl_issues WHERE crawlId = ?');
            $pi->execute([(int) $p['id']]);
            $prevTitles = array_map(function ($r) { return $r['title']; }, $pi->fetchAll());
            respond([
                'hasPrevious' => true,
                'previousId' => (int) $p['id'],
                'scoreBefore' => $p['score'] !== null ? (int) $p['score'] : null,
                'scoreAfter' => $cur['score'] !== null ? (int) $cur['score'] : null,
                'fixed' => array_values(array_diff($prevTitles, $curTitles)),
                'new' => array_values(array_diff($curTitles, $prevTitles)),
            ]);
            break;

        // ---- visual analysis ----
        case 'v2.visual.list':
            $st = db()->prepare('SELECT * FROM screenshots WHERE userId = ? ORDER BY id DESC LIMIT 30');
            $st->execute([(int) $user['id']]);
            respond(array_map(function ($r) {
                return ['id' => (int) $r['id'], 'pageUrl' => $r['pageUrl'], 'viewport' => $r['viewport'],
                    'imageUrl' => $r['imageUrl'], 'annotations' => json_decode((string) $r['annotations'], true) ?: [],
                    'findings' => json_decode((string) $r['findings'], true) ?: []];
            }, $st->fetchAll()));
            break;
        case 'v2.visual.capture':
            require_post();
            $pageUrl = v2_str($in, 'pageUrl', 500);
            if (!filter_var($pageUrl, FILTER_VALIDATE_URL)) { fail('Enter a valid URL including https://'); }
            $viewport = v2_str($in, 'viewport', 16, 'desktop');
            if (!in_array($viewport, ['desktop', 'mobile'], true)) { fail('Viewport must be desktop or mobile.'); }
            if (too_many('shot:' . $user['id'], 20, 3600)) { fail('Screenshot limit reached (20 per hour).', 'TOO_MANY_REQUESTS', 429); }

            // Render via the screenshot service if configured, otherwise record the
            // request and return the deterministic layout findings from the HTML.
            $page = v2_fetch_page($pageUrl);
            $findings = [];
            if ($page['ok']) {
                $html = $page['body'];
                if (!preg_match('/<meta[^>]+name=["\']viewport["\']/i', $html)) {
                    $findings[] = ['severity' => 'critical', 'title' => 'No viewport tag', 'detail' => 'Layout will break on mobile.'];
                }
                if (preg_match_all('/<h1[^>]*>/i', $html) === 0) {
                    $findings[] = ['severity' => 'high', 'title' => 'No H1', 'detail' => 'Visual hierarchy has no anchor.'];
                }
                if (preg_match_all('/<h1[^>]*>/i', $html) > 1) {
                    $findings[] = ['severity' => 'medium', 'title' => 'Multiple H1s', 'detail' => 'Competing hierarchy.'];
                }
                if (!preg_match('/\b(get started|start|try|buy|sign up|book|request|add to cart)\b/i', $html)) {
                    $findings[] = ['severity' => 'critical', 'title' => 'No visible CTA', 'detail' => 'No action language found on the page.'];
                }
                if (preg_match_all('/<img\b[^>]*>/i', $html, $im) > 0) {
                    $noAlt = 0;
                    foreach ($im[0] as $t) { if (!preg_match('/\balt\s*=/i', $t)) { $noAlt++; } }
                    if ($noAlt > 0) { $findings[] = ['severity' => 'medium', 'title' => $noAlt . ' images without alt', 'detail' => 'Accessibility and layout risk.' ]; }
                }
            } else {
                $findings[] = ['severity' => 'high', 'title' => 'Page could not be fetched', 'detail' => $page['error'] !== '' ? $page['error'] : 'HTTP ' . $page['status']];
            }

            db()->prepare('INSERT INTO screenshots (userId, experimentId, pageUrl, viewport, imageUrl, findings) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([(int) $user['id'], v2_int($in, 'experimentId') ?: null, $pageUrl, $viewport, null, json_encode($findings)]);
            $id = (int) db()->lastInsertId();
            v2_log($user['id'], 'screenshot_captured', $pageUrl . ' (' . $viewport . ')');
            respond(['ok' => true, 'id' => $id, 'findings' => $findings,
                'note' => 'Layout findings are derived from the live HTML. Pixel screenshots need a rendering service, which this host does not provide.']);
            break;
        case 'v2.visual.annotate':
            require_post();
            $st = db()->prepare('SELECT * FROM screenshots WHERE id = ? AND userId = ?');
            $st->execute([v2_int($in, 'id'), (int) $user['id']]);
            $row = $st->fetch();
            if (!$row) { fail('Screenshot not found.', 'NOT_FOUND', 404); }
            $ann = v2_json($in, 'annotations');
            if (!is_array($ann)) { $ann = []; }
            db()->prepare('UPDATE screenshots SET annotations = ? WHERE id = ?')->execute([json_encode($ann), (int) $row['id']]);
            respond(['ok' => true]);
            break;

        // ---- competitors ----
        case 'v2.competitors.list':
            $st = db()->prepare('SELECT * FROM competitors WHERE userId = ? ORDER BY id DESC');
            $st->execute([(int) $user['id']]);
            $out = [];
            foreach ($st->fetchAll() as $c) {
                $ls = db()->prepare('SELECT * FROM competitor_scans WHERE competitorId = ? ORDER BY id DESC LIMIT 1');
                $ls->execute([(int) $c['id']]);
                $last = $ls->fetch();
                $out[] = ['id' => (int) $c['id'], 'name' => $c['name'], 'url' => $c['url'],
                    'lastScan' => $last ? ['id' => (int) $last['id'], 'status' => $last['status'], 'changes' => json_decode((string) $last['changes'], true) ?: []] : null];
            }
            respond($out);
            break;
        case 'v2.competitors.add':
            require_post();
            $name = v2_str($in, 'name', 120);
            $url = v2_str($in, 'url', 500);
            if ($name === '' || !filter_var($url, FILTER_VALIDATE_URL)) { fail('A name and a valid URL are required.'); }
            db()->prepare('INSERT INTO competitors (userId, name, url) VALUES (?, ?, ?)')->execute([(int) $user['id'], $name, $url]);
            respond(['ok' => true, 'id' => (int) db()->lastInsertId()]);
            break;
        case 'v2.competitors.remove':
            require_post();
            $st = db()->prepare('SELECT id FROM competitors WHERE id = ? AND userId = ?');
            $st->execute([v2_int($in, 'id'), (int) $user['id']]);
            if (!$st->fetch()) { fail('Competitor not found.', 'NOT_FOUND', 404); }
            db()->prepare('DELETE FROM competitor_scans WHERE competitorId = ?')->execute([v2_int($in, 'id')]);
            db()->prepare('DELETE FROM competitors WHERE id = ?')->execute([v2_int($in, 'id')]);
            respond(['ok' => true]);
            break;
        case 'v2.competitors.scan':
            require_post();
            $st = db()->prepare('SELECT * FROM competitors WHERE id = ? AND userId = ?');
            $st->execute([v2_int($in, 'id'), (int) $user['id']]);
            $comp = $st->fetch();
            if (!$comp) { fail('Competitor not found.', 'NOT_FOUND', 404); }
            if (too_many('comp:' . $user['id'], 20, 3600)) { fail('Scan limit reached (20 per hour).', 'TOO_MANY_REQUESTS', 429); }

            $res = v2_competitor_snapshot($comp['url']);
            if (!$res['ok']) {
                db()->prepare('INSERT INTO competitor_scans (competitorId, status, error) VALUES (?, \'failed\', ?)')
                    ->execute([(int) $comp['id'], mb_substr($res['error'], 0, 500)]);
                respond(['ok' => false, 'error' => $res['error']]);
            }
            $prev = db()->prepare('SELECT snapshot FROM competitor_scans WHERE competitorId = ? AND status = \'completed\' ORDER BY id DESC LIMIT 1');
            $prev->execute([(int) $comp['id']]);
            $p = $prev->fetch();
            $changes = [];
            if ($p) {
                $old = json_decode((string) $p['snapshot'], true) ?: [];
                $changes = v2_compare_snapshots($old, $res['snapshot']);
            }
            db()->prepare('INSERT INTO competitor_scans (competitorId, status, snapshot, changes) VALUES (?, \'completed\', ?, ?)')
                ->execute([(int) $comp['id'], json_encode($res['snapshot']), json_encode($changes)]);
            v2_log($user['id'], 'competitor_scanned', $comp['name']);
            respond(['ok' => true, 'snapshot' => $res['snapshot'], 'changes' => $changes, 'isFirst' => !$p]);
            break;

        // ---- ecommerce ----
        case 'v2.ecommerce.metrics':
            respond(v2_ecommerce($user, $in));
            break;
        case 'v2.ecommerce.record':
            require_post();
            $day = v2_str($in, 'day', 10, gmdate('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { fail('Day must be YYYY-MM-DD.'); }
            db()->prepare('REPLACE INTO ecommerce_metrics (userId, experimentId, day, revenue, orders, sessions, carts, checkouts, productViews) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $user['id'], v2_int($in, 'experimentId') ?: null, $day,
                    (float) (isset($in['revenue']) ? $in['revenue'] : 0), v2_int($in, 'orders'), v2_int($in, 'sessions'),
                    v2_int($in, 'carts'), v2_int($in, 'checkouts'), v2_int($in, 'productViews')]);
            respond(['ok' => true]);
            break;

        // ---- security & ops ----
        case 'v2.onboarding.get':
            respond(v2_onboarding($user));
            break;
        case 'v2.onboarding.set':
            require_post();
            $steps = v2_json($in, 'steps');
            if (!is_array($steps)) { $steps = []; }
            db()->prepare('REPLACE INTO onboarding (userId, steps) VALUES (?, ?)')->execute([(int) $user['id'], json_encode($steps)]);
            respond(v2_onboarding($user));
            break;
        case 'v2.audit.list':
            $st = db()->prepare('SELECT action, target, ip, UNIX_TIMESTAMP(createdAt) AS ts FROM audit_log WHERE userId = ? ORDER BY id DESC LIMIT 100');
            $st->execute([(int) $user['id']]);
            respond(array_map(function ($r) {
                return ['action' => $r['action'], 'target' => $r['target'], 'ip' => $r['ip'], 'ago' => time_ago((int) $r['ts'])];
            }, $st->fetchAll()));
            break;
        case 'v2.health.system':
            respond(v2_system_health($user));
            break;
        case 'v2.jobs.run':
            require_post();
            $job = v2_str($in, 'job', 64, 'all');
            $ran = [];
            if ($job === 'all' || $job === 'scheduled_experiments') {
                $n = db()->prepare("UPDATE experiments SET status = 'Active' WHERE status = 'Scheduled' AND scheduledAt IS NOT NULL AND scheduledAt <= NOW()");
                $n->execute();
                $ran[] = 'scheduled_experiments: ' . $n->rowCount() . ' activated';
            }
            if ($job === 'all' || $job === 'retry_webhooks') {
                $st = db()->prepare("SELECT d.* FROM webhook_deliveries d INNER JOIN integrations i ON i.id = d.integrationId WHERE i.userId = ? AND d.status = 'failed' AND d.attempts < 3 ORDER BY d.id DESC LIMIT 10");
                $st->execute([(int) $user['id']]);
                $retried = 0;
                foreach ($st->fetchAll() as $d) {
                    db()->prepare('UPDATE webhook_deliveries SET attempts = attempts + 1, status = \'pending\' WHERE id = ?')->execute([(int) $d['id']]);
                    $retried++;
                }
                $ran[] = 'retry_webhooks: ' . $retried . ' queued';
            }
            if ($job === 'all' || $job === 'health_snapshot') {
                $h = v2_system_health($user);
                $ins = db()->prepare('INSERT INTO system_health (component, status, detail, latencyMs) VALUES (?, ?, ?, ?)');
                foreach ($h['checks'] as $c) {
                    $ins->execute([$c['component'], $c['status'], mb_substr((string) $c['detail'], 0, 500), $c['latencyMs']]);
                }
                $ran[] = 'health_snapshot: ' . count($h['checks']) . ' components';
            }
            foreach ($ran as $r) {
                $key = explode(':', $r)[0];
                db()->prepare('INSERT INTO scheduled_jobs (jobKey, lastRunAt, lastStatus, lastDetail, runCount) VALUES (?, NOW(), \'ok\', ?, 1) ON DUPLICATE KEY UPDATE lastRunAt = NOW(), lastStatus = \'ok\', lastDetail = VALUES(lastDetail), runCount = runCount + 1')
                    ->execute([$key, mb_substr($r, 0, 500)]);
            }
            v2_log($user['id'], 'jobs_run', implode('; ', $ran));
            respond(['ok' => true, 'ran' => $ran]);
            break;
        case 'v2.backup.create':
            require_post();
            $pdo = db();
            $tables = ['users', 'experiments', 'variants', 'visitor_events', 'workspace_settings', 'experiment_notes', 'experiment_tags', 'recommendations', 'integrations', 'competitors'];
            $dump = ['generatedAt' => gmdate('c'), 'tables' => []];
            foreach ($tables as $t) {
                try {
                    $rows = $pdo->query("SELECT * FROM `$t` LIMIT 5000")->fetchAll();
                    if ($t === 'users') {
                        foreach ($rows as &$r) { unset($r['passwordHash']); }
                        unset($r);
                    }
                    $dump['tables'][$t] = $rows;
                } catch (Throwable $e) {
                    $dump['tables'][$t] = ['error' => $e->getMessage()];
                }
            }
            $json = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $dir = __DIR__ . '/../backups';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $filename = 'backup-' . gmdate('Ymd-His') . '.json';
            $written = @file_put_contents($dir . '/' . $filename, $json);
            if ($written === false) {
                respond(['ok' => false, 'error' => 'The host did not allow writing to the backups folder. Download the JSON below and store it yourself.', 'json' => $json]);
            }
            db()->prepare('INSERT INTO backups (userId, filename, sizeBytes) VALUES (?, ?, ?)')->execute([(int) $user['id'], $filename, $written]);
            v2_log($user['id'], 'backup_created', $filename);
            respond(['ok' => true, 'filename' => $filename, 'sizeBytes' => $written]);
            break;
        case 'v2.backup.list':
            $st = db()->prepare('SELECT filename, sizeBytes, UNIX_TIMESTAMP(createdAt) AS ts FROM backups WHERE userId = ? ORDER BY id DESC LIMIT 20');
            $st->execute([(int) $user['id']]);
            respond(array_map(function ($r) {
                return ['filename' => $r['filename'], 'sizeBytes' => (int) $r['sizeBytes'], 'ago' => time_ago((int) $r['ts'])];
            }, $st->fetchAll()));
            break;
        case 'v2.system.version':
            $pdo = db();
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $expected = ['users', 'experiments', 'variants', 'visitor_events', 'recommendations', 'crawls', 'competitors', 'integrations', 'screenshots', 'ecommerce_metrics', 'audit_log', 'scheduled_jobs'];
            $missing = array_values(array_diff($expected, $tables));
            respond([
                'appVersion' => '2.0.0',
                'schemaVersion' => count($missing) === 0 ? '2.0.0' : '1.x',
                'phpVersion' => PHP_VERSION,
                'tables' => count($tables),
                'missingTables' => $missing,
                'upToDate' => count($missing) === 0,
                'checkedAt' => gmdate('c'),
            ]);
            break;

        default:
            return false; // not a v2 route
    }
    return true;
}
