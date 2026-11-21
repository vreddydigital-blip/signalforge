<?php
// Public tracking endpoint used by /tracker.js. Actions: ?a=decide | ?a=convert
require __DIR__ . '/lib.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Cache-Control: no-store');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function norm_url($url)
{
    $p = parse_url((string) $url);
    if (!$p || empty($p['host'])) {
        return null;
    }
    $host = strtolower(preg_replace('/^www\./i', '', $p['host']));
    $path = strtolower(rtrim(isset($p['path']) ? $p['path'] : '', '/'));
    return $host . $path;
}

function page_matches($experimentUrl, $currentUrl)
{
    $experimentUrl = trim((string) $experimentUrl);
    if ($experimentUrl === '') {
        return true;
    }
    $expected = norm_url($experimentUrl);
    if ($expected === null) {
        return true;
    }
    $actual = norm_url($currentUrl);
    return $actual !== null && $actual === $expected;
}

function buckets($visitorId, $experimentId)
{
    $digest = hash('sha256', $experimentId . ':' . $visitorId, true);
    $a = unpack('N', substr($digest, 0, 4));
    $b = unpack('N', substr($digest, 4, 4));
    return [$a[1] % 10000, $b[1]];
}

try {
    $action = isset($_GET['a']) ? (string) $_GET['a'] : '';
    $in = body_json();
    $visitorId = isset($in['visitorId']) && is_string($in['visitorId']) ? $in['visitorId'] : '';
    $project = isset($in['project']) && is_string($in['project']) ? $in['project'] : '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $visitorId)) {
        fail('bad_visitor', 'BAD_REQUEST', 400);
    }
    if (!preg_match('/^[a-f0-9]{8,32}$/', $project)) {
        fail('unknown_project', 'NOT_FOUND', 404);
    }
    $device = isset($in['device']) && $in['device'] === 'Mobile' ? 'Mobile' : 'Desktop';
    $isPaid = !empty($in['paid']) ? 1 : 0;
    $isReturning = !empty($in['returning']) ? 1 : 0;
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM users WHERE projectKey = ? LIMIT 1');
    $st->execute([$project]);
    $user = $st->fetch();
    if (!$user) {
        fail('unknown_project', 'NOT_FOUND', 404);
    }

    $est = $pdo->prepare("SELECT * FROM experiments WHERE userId = ? AND status = 'Active'");
    $est->execute([(int) $user['id']]);
    $active = $est->fetchAll();

    if ($action === 'decide') {
        $url = isset($in['url']) && is_string($in['url']) ? $in['url'] : '';
        $active = array_values(array_filter($active, function ($e) use ($url) {
            return page_matches($e['pageUrl'], $url);
        }));
        if (!$active) {
            respond(['assignments' => []]);
        }
        $ids = array_map(function ($e) { return (int) $e['id']; }, $active);
        $in1 = in_placeholders($ids);

        $vst = $pdo->prepare("SELECT * FROM variants WHERE experimentId IN ($in1) ORDER BY position ASC, id ASC");
        $vst->execute($ids);
        $variantRows = $vst->fetchAll();

        $xst = $pdo->prepare("SELECT experimentId, variantId FROM visitor_events WHERE visitorId = ? AND type = 'exposure' AND experimentId IN ($in1)");
        $xst->execute(array_merge([$visitorId], $ids));
        $existing = [];
        foreach ($xst->fetchAll() as $x) {
            $existing[(int) $x['experimentId']] = (int) $x['variantId'];
        }

        $assignments = [];
        foreach ($active as $e) {
            $options = array_values(array_filter($variantRows, function ($v) use ($e) {
                return (int) $v['experimentId'] === (int) $e['id'];
            }));
            if (!$options) {
                continue;
            }
            $chosen = null;
            if (isset($existing[(int) $e['id']])) {
                foreach ($options as $o) {
                    if ((int) $o['id'] === $existing[(int) $e['id']]) {
                        $chosen = $o;
                    }
                }
            }
            if ($chosen === null) {
                list($trafficBucket, $variantBucket) = buckets($visitorId, (int) $e['id']);
                if ($trafficBucket >= ((int) $e['traffic']) * 100) {
                    continue; // visitor is outside the traffic allocation
                }
                $chosen = $options[$variantBucket % count($options)];
                $pdo->prepare("INSERT IGNORE INTO visitor_events (experimentId, variantId, visitorId, type, device, isPaid, isReturning) VALUES (?, ?, ?, 'exposure', ?, ?, ?)")
                    ->execute([(int) $e['id'], (int) $chosen['id'], $visitorId, $device, $isPaid, $isReturning]);
            }
            $assignments[] = [
                'experimentId' => (int) $e['id'],
                'variantId' => (int) $chosen['id'],
                'variantName' => $chosen['name'],
                'isControl' => (int) $chosen['isControl'] === 1,
                'css' => (string) $chosen['customCss'],
                'js' => (string) $chosen['customJs'],
            ];
        }
        respond(['assignments' => $assignments]);
    }

    if ($action === 'convert') {
        $event = isset($in['event']) && is_string($in['event']) ? strtolower(trim(mb_substr($in['event'], 0, 255))) : 'conversion';
        $matching = array_values(array_filter($active, function ($e) use ($event) {
            return $event === 'conversion' || strtolower(trim((string) $e['conversionEvent'])) === $event;
        }));
        if (!$matching) {
            respond(['ok' => true, 'recorded' => 0]);
        }
        $ids = array_map(function ($e) { return (int) $e['id']; }, $matching);
        $in1 = in_placeholders($ids);
        $xst = $pdo->prepare("SELECT experimentId, variantId FROM visitor_events WHERE visitorId = ? AND type = 'exposure' AND experimentId IN ($in1)");
        $xst->execute(array_merge([$visitorId], $ids));
        $recorded = 0;
        // Revenue was accepted in the request body but never written, so every
        // conversion stored revenue = 0 and the dashboard's revenue total was
        // always 0 no matter what the caller sent. Persist it now.
        $revenue = isset($in['revenue']) && is_numeric($in['revenue']) ? (float) $in['revenue'] : 0.0;
        if ($revenue < 0) { $revenue = 0.0; }
        $ins = $pdo->prepare("INSERT IGNORE INTO visitor_events (experimentId, variantId, visitorId, type, revenue) VALUES (?, ?, ?, 'conversion', ?)");
        foreach ($xst->fetchAll() as $x) {
            $ins->execute([(int) $x['experimentId'], (int) $x['variantId'], $visitorId, $revenue]);
            // INSERT IGNORE reports 0 rows when this visitor already converted for
            // that experiment, so only count rows that were really written.
            $recorded += $ins->rowCount();
        }
        respond(['ok' => true, 'recorded' => $recorded]);
    }

    fail('unknown_action', 'NOT_FOUND', 404);
} catch (Throwable $e) {
    error_log('[signalforge track] ' . $e->getMessage());
    fail('server_error', 'INTERNAL_SERVER_ERROR', 500);
}
