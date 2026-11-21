<?php
// Dashboard API. Routes: /api/index.php?route=<group.action>
require __DIR__ . '/lib.php';
require __DIR__ . '/orchestrator.php';
require __DIR__ . '/remote.php';
require __DIR__ . '/v2.php';

header('Cache-Control: no-store');
$route = isset($_GET['route']) ? (string) $_GET['route'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

function query_input()
{
    if (!isset($_GET['input'])) {
        return [];
    }
    $decoded = json_decode((string) $_GET['input'], true);
    return is_array($decoded) ? $decoded : [];
}

function require_post()
{
    global $method;
    if ($method !== 'POST') {
        fail('Method not allowed', 'METHOD_NOT_SUPPORTED', 405);
    }
    // Blocks cross-site form posts: browsers cannot add this header cross-origin without CORS approval.
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'signalforge') {
        fail('Forbidden', 'FORBIDDEN', 403);
    }
}

function owned_experiment($userId, $experimentId)
{
    $st = db()->prepare('SELECT * FROM experiments WHERE id = ? AND userId = ? LIMIT 1');
    $st->execute([(int) $experimentId, (int) $userId]);
    $row = $st->fetch();
    if (!$row) {
        fail('Experiment not found.', 'NOT_FOUND', 404);
    }
    return $row;
}

function load_experiments($user)
{
    $pdo = db();
    $st = $pdo->prepare('SELECT *, UNIX_TIMESTAMP(updatedAt) AS updatedTs FROM experiments WHERE userId = ? ORDER BY updatedAt DESC, id DESC');
    $st->execute([(int) $user['id']]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return [];
    }
    $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
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

    $out = [];
    foreach ($rows as $row) {
        $raw = [];
        foreach ($variantRows as $v) {
            if ((int) $v['experimentId'] !== (int) $row['id']) {
                continue;
            }
            $visitors = isset($counts[$v['id'] . ':exposure']) ? $counts[$v['id'] . ':exposure'] : 0;
            $conversions = isset($counts[$v['id'] . ':conversion']) ? $counts[$v['id'] . ':conversion'] : 0;
            $raw[] = [
                'v' => $v,
                'visitors' => $visitors,
                'conversions' => $conversions,
                'rate' => $visitors > 0 ? ($conversions / $visitors) * 100 : 0,
            ];
        }
        $control = null;
        foreach ($raw as $item) {
            if ((int) $item['v']['isControl'] === 1) {
                $control = $item;
                break;
            }
        }
        if ($control === null && count($raw) > 0) {
            $control = $raw[0];
        }

        $variants = [];
        foreach ($raw as $item) {
            $isControl = (int) $item['v']['isControl'] === 1;
            $uplift = 0;
            $probability = 50;
            if (!$isControl && $control !== null) {
                if ($control['rate'] > 0) {
                    $uplift = round((($item['rate'] - $control['rate']) / $control['rate']) * 100, 1);
                }
                $probability = (int) round(prob_to_beat($item['visitors'], $item['conversions'], $control['visitors'], $control['conversions']));
            }
            $variants[] = [
                'id' => (int) $item['v']['id'],
                'name' => $item['v']['name'],
                'visitors' => $item['visitors'],
                'conversions' => $item['conversions'],
                'rate' => round($item['rate'], 2),
                'uplift' => $uplift,
                'probability' => $probability,
                'color' => $item['v']['color'],
                'isControl' => $isControl,
                'customCss' => (string) $item['v']['customCss'],
                'customJs' => (string) $item['v']['customJs'],
            ];
        }

        $out[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'status' => $row['status'],
            'owner' => $user['name'] ? $user['name'] : 'You',
            'updated' => time_ago((int) $row['updatedTs']),
            'goal' => $row['goal'],
            'framework' => $row['framework'],
            'pageUrl' => (string) $row['pageUrl'],
            'objective' => (string) $row['objective'],
            'hypothesis' => (string) $row['hypothesis'],
            'observedInsight' => (string) $row['observedInsight'],
            'experienceChange' => (string) $row['experienceChange'],
            'behaviorReason' => (string) $row['behaviorReason'],
            'audience' => (string) $row['audience'],
            'conversionEvent' => (string) $row['conversionEvent'],
            'traffic' => (int) $row['traffic'],
            'variants' => $variants,
        ];
    }
    return $out;
}

try {
    // v2 routes are namespaced and handled before the v1 switch, so the
    // original API surface stays byte-for-byte compatible.
    if (strpos($route, 'v2.') === 0) {
        $v2user = current_user();
        if (!$v2user) {
            fail('Please login (10001)', 'UNAUTHORIZED', 401);
        }
        if (v2_handle($route, $method, $v2user) !== false) {
            exit;
        }
        fail('Unknown route.', 'NOT_FOUND', 404);
    }

    switch ($route) {
        case 'auth.me':
            $user = current_user();
            respond($user ? public_user($user) : null);
            break;

        case 'auth.register':
            require_post();
            $in = body_json();
            $name = str_field($in, 'name', 1, 80, 'name');
            $email = strtolower(str_field($in, 'email', 3, 190, 'email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail('Enter a valid email address.');
            }
            $password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';
            if (strlen($password) < 8 || strlen($password) > 128) {
                fail('Password must be 8 to 128 characters.');
            }
            if (too_many('register:' . client_ip(), 10, 900)) {
                fail('Too many attempts. Try again later.', 'TOO_MANY_REQUESTS', 429);
            }
            $st = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            if ($st->fetch()) {
                fail('An account with this email already exists.', 'CONFLICT', 409);
            }
            try {
                db()->prepare('INSERT INTO users (name, email, passwordHash, projectKey) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), bin2hex(random_bytes(8))]);
            } catch (PDOException $e) {
                fail('An account with this email already exists.', 'CONFLICT', 409);
            }
            $uid = (int) db()->lastInsertId();
            start_session($uid);
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$uid]);
            respond(public_user($st->fetch()));
            break;

        case 'auth.login':
            require_post();
            $in = body_json();
            $email = strtolower(isset($in['email']) && is_string($in['email']) ? trim($in['email']) : '');
            $password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';
            if ($email === '' || $password === '') {
                fail('Enter your email and password.');
            }
            if (too_many('login:' . client_ip() . ':' . $email, 10, 900)) {
                fail('Too many attempts. Try again later.', 'TOO_MANY_REQUESTS', 429);
            }
            $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            $user = $st->fetch();
            if (!$user) {
                // Distinguish "no such account" from "wrong password". Reporting
                // both as "Incorrect email or password" left people retyping a
                // password for an account that had never been created.
                fail(
                    'No account exists for this email. Use "Create an account" to sign up.',
                    'NO_SUCH_ACCOUNT',
                    401
                );
            }
            if (!password_verify($password, $user['passwordHash'])) {
                fail('Incorrect password for this email.', 'UNAUTHORIZED', 401);
            }
            db()->prepare('UPDATE users SET lastSignedIn = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $user['id']]);
            start_session((int) $user['id']);
            respond(public_user($user));
            break;

        case 'auth.logout':
            require_post();
            end_session();
            respond(['success' => true]);
            break;

        case 'account.setPlan':
            require_post();
            $user = require_user();
            $in = body_json();
            $plan = isset($in['plan']) ? $in['plan'] : '';
            if (!is_string($plan) || !isset(SF_PLAN_LIMITS[$plan])) {
                fail('Unknown plan.');
            }
            db()->prepare('UPDATE users SET plan = ? WHERE id = ?')->execute([$plan, (int) $user['id']]);
            respond(['plan' => $plan]);
            break;

        case 'experiments.list':
            $user = require_user();
            respond(load_experiments($user));
            break;

        case 'experiments.create':
            require_post();
            $user = require_user();
            $in = body_json();
            $name = str_field($in, 'name', 1, 255, 'experiment name');
            $kind = isset($in['kind']) ? $in['kind'] : '';
            if ($kind !== 'A/B test' && $kind !== 'Multivariate') {
                fail('Unknown experiment type.');
            }
            $goal = str_field($in, 'goal', 1, 255, 'goal');
            $framework = str_field($in, 'framework', 1, 128, 'framework');
            $audience = str_field($in, 'audience', 1, 128, 'audience');
            $conversionEvent = str_field($in, 'conversionEvent', 1, 255, 'conversion event');
            $traffic = isset($in['traffic']) ? (int) $in['traffic'] : 0;
            if ($traffic < 1 || $traffic > 100) {
                fail('Traffic must be between 1 and 100.');
            }
            $names = [];
            if (isset($in['variantNames']) && is_array($in['variantNames'])) {
                foreach ($in['variantNames'] as $n) {
                    $n = is_string($n) ? trim($n) : '';
                    if ($n !== '') {
                        $names[] = mb_substr($n, 0, 255);
                    }
                }
            }
            if (count($names) < 2 || count($names) > 8) {
                fail('An experiment needs between 2 and 8 variants.');
            }
            if ($kind === 'A/B test') {
                $names = array_slice($names, 0, 2);
            }

            $planName = isset($user['plan']) && isset(SF_PLAN_LIMITS[$user['plan']]) ? $user['plan'] : 'Free';
            $limits = SF_PLAN_LIMITS[$planName];
            $st = db()->prepare('SELECT COUNT(*) AS n FROM experiments WHERE userId = ?');
            $st->execute([(int) $user['id']]);
            $existing = (int) $st->fetch()['n'];
            if ($existing >= $limits['experiments']) {
                fail($planName . ' plan limit reached (' . $limits['experiments'] . ' experiments).', 'FORBIDDEN', 403);
            }
            if ($kind === 'Multivariate' && $planName === 'Free') {
                fail('Multivariate tests need the Growth plan or higher.', 'FORBIDDEN', 403);
            }
            if (count($names) > $limits['variants']) {
                fail($planName . ' plan allows up to ' . $limits['variants'] . ' variants.', 'FORBIDDEN', 403);
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO experiments (userId, name, kind, status, goal, framework, pageUrl, objective, hypothesis, observedInsight, experienceChange, behaviorReason, audience, conversionEvent, traffic) VALUES (?, ?, ?, \'Draft\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        (int) $user['id'], $name, $kind, $goal, $framework,
                        opt_field($in, 'pageUrl', 2000), opt_field($in, 'objective', 2000), opt_field($in, 'hypothesis', 2000),
                        opt_field($in, 'observedInsight', 2000), opt_field($in, 'experienceChange', 2000), opt_field($in, 'behaviorReason', 2000),
                        $audience, $conversionEvent, $traffic,
                    ]);
                $experimentId = (int) $pdo->lastInsertId();
                $vins = $pdo->prepare('INSERT INTO variants (experimentId, name, isControl, position, color) VALUES (?, ?, ?, ?, ?)');
                foreach ($names as $i => $n) {
                    $vins->execute([$experimentId, $n, $i === 0 ? 1 : 0, $i, SF_COLORS[$i % count(SF_COLORS)]]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            respond(['id' => $experimentId]);
            break;

        case 'experiments.setStatus':
            require_post();
            $user = require_user();
            $in = body_json();
            $row = owned_experiment($user['id'], isset($in['id']) ? $in['id'] : 0);
            $status = isset($in['status']) ? $in['status'] : '';
            if (!in_array($status, ['Active', 'Paused', 'Completed'], true)) {
                fail('Invalid status.');
            }
            if ($row['status'] === 'Completed') {
                fail('Completed experiments are read-only.');
            }
            db()->prepare('UPDATE experiments SET status = ?, updatedAt = CURRENT_TIMESTAMP WHERE id = ?')->execute([$status, (int) $row['id']]);
            respond(['ok' => true]);
            break;

        case 'experiments.remove':
            require_post();
            $user = require_user();
            $in = body_json();
            $row = owned_experiment($user['id'], isset($in['id']) ? $in['id'] : 0);
            $pdo = db();
            $pdo->prepare('DELETE FROM visitor_events WHERE experimentId = ?')->execute([(int) $row['id']]);
            $pdo->prepare('DELETE FROM variants WHERE experimentId = ?')->execute([(int) $row['id']]);
            $pdo->prepare('DELETE FROM experiments WHERE id = ?')->execute([(int) $row['id']]);
            respond(['ok' => true]);
            break;

        case 'experiments.updateVariantCode':
            require_post();
            $user = require_user();
            $in = body_json();
            $st = db()->prepare('SELECT v.id FROM variants v INNER JOIN experiments e ON e.id = v.experimentId WHERE v.id = ? AND e.userId = ? LIMIT 1');
            $st->execute([isset($in['variantId']) ? (int) $in['variantId'] : 0, (int) $user['id']]);
            $variant = $st->fetch();
            if (!$variant) {
                fail('Variant not found.', 'NOT_FOUND', 404);
            }
            if (isset($in['customCss']) && is_string($in['customCss'])) {
                db()->prepare('UPDATE variants SET customCss = ? WHERE id = ?')->execute([mb_substr($in['customCss'], 0, 20000), (int) $variant['id']]);
            }
            if (isset($in['customJs']) && is_string($in['customJs'])) {
                db()->prepare('UPDATE variants SET customJs = ? WHERE id = ?')->execute([mb_substr($in['customJs'], 0, 20000), (int) $variant['id']]);
            }
            respond(['ok' => true]);
            break;

        case 'experiments.trend':
            $user = require_user();
            $in = query_input();
            $row = owned_experiment($user['id'], isset($in['id']) ? $in['id'] : 0);
            $pdo = db();
            $vst = $pdo->prepare('SELECT * FROM variants WHERE experimentId = ? ORDER BY position ASC, id ASC');
            $vst->execute([(int) $row['id']]);
            $variantRows = $vst->fetchAll();
            if (!$variantRows) {
                respond([]);
            }
            $control = $variantRows[0];
            foreach ($variantRows as $v) {
                if ((int) $v['isControl'] === 1) {
                    $control = $v;
                    break;
                }
            }
            $est = $pdo->prepare("SELECT DATE_FORMAT(createdAt, '%Y-%m-%d') AS d, variantId, type, COUNT(*) AS n FROM visitor_events WHERE experimentId = ? GROUP BY d, variantId, type");
            $est->execute([(int) $row['id']]);
            $events = $est->fetchAll();

            $totals = [];
            $perDay = [];
            foreach ($events as $e) {
                if ($e['type'] === 'exposure') {
                    $totals[$e['variantId']] = (isset($totals[$e['variantId']]) ? $totals[$e['variantId']] : 0) + (int) $e['n'];
                }
                $perDay[$e['d']][$e['variantId'] . ':' . $e['type']] = (int) $e['n'];
            }
            $lead = null;
            $leadTotal = -1;
            foreach ($variantRows as $v) {
                if ((int) $v['id'] === (int) $control['id']) {
                    continue;
                }
                $t = isset($totals[$v['id']]) ? $totals[$v['id']] : 0;
                if ($t > $leadTotal) {
                    $lead = $v;
                    $leadTotal = $t;
                }
            }

            ksort($perDay);
            $cum = ['cv' => 0, 'cx' => 0, 'lv' => 0, 'lx' => 0];
            $series = [];
            foreach ($perDay as $day => $bucket) {
                $cum['cv'] += isset($bucket[$control['id'] . ':exposure']) ? $bucket[$control['id'] . ':exposure'] : 0;
                $cum['cx'] += isset($bucket[$control['id'] . ':conversion']) ? $bucket[$control['id'] . ':conversion'] : 0;
                if ($lead) {
                    $cum['lv'] += isset($bucket[$lead['id'] . ':exposure']) ? $bucket[$lead['id'] . ':exposure'] : 0;
                    $cum['lx'] += isset($bucket[$lead['id'] . ':conversion']) ? $bucket[$lead['id'] . ':conversion'] : 0;
                }
                $series[$day] = [
                    'control' => $cum['cv'] > 0 ? round(($cum['cx'] / $cum['cv']) * 100, 2) : 0,
                    'variant' => $cum['lv'] > 0 ? round(($cum['lx'] / $cum['lv']) * 100, 2) : 0,
                ];
            }

            $out = [];
            $last = ['control' => 0, 'variant' => 0];
            $today = strtotime(gmdate('Y-m-d'));
            for ($offset = 13; $offset >= 0; $offset--) {
                $ts = $today - $offset * 86400;
                $key = gmdate('Y-m-d', $ts);
                if (isset($series[$key])) {
                    $last = $series[$key];
                } else {
                    $earlier = null;
                    foreach (array_keys($series) as $d) {
                        if ($d <= $key) {
                            $earlier = $d;
                        }
                    }
                    if ($earlier !== null) {
                        $last = $series[$earlier];
                    }
                }
                $out[] = ['day' => gmdate('M d', $ts), 'control' => $last['control'], 'variant' => $last['variant']];
            }
            respond($out);
            break;

        case 'settings.get':
            $user = require_user();
            respond(get_settings($user));
            break;

        case 'settings.save':
            require_post();
            $user = require_user();
            $in = body_json();
            $defaults = get_settings($user);
            $name = isset($in['workspaceName']) && is_string($in['workspaceName']) ? trim($in['workspaceName']) : $defaults['workspaceName'];
            if ($name === '' || mb_strlen($name) > 120) {
                fail('Workspace name must be 1 to 120 characters.');
            }
            $timezone = isset($in['timezone']) && is_string($in['timezone']) ? mb_substr(trim($in['timezone']), 0, 64) : $defaults['timezone'];
            $defaultGoal = isset($in['defaultGoal']) && is_string($in['defaultGoal']) ? mb_substr(trim($in['defaultGoal']), 0, 120) : $defaults['defaultGoal'];
            $emailUpdates = isset($in['emailUpdates']) ? (bool) $in['emailUpdates'] : $defaults['emailUpdates'];
            $weeklyDigest = isset($in['weeklyDigest']) ? (bool) $in['weeklyDigest'] : $defaults['weeklyDigest'];
            $revenue = isset($in['revenuePerConversion']) ? (float) $in['revenuePerConversion'] : $defaults['revenuePerConversion'];
            if ($revenue < 0 || $revenue > 1000000) {
                fail('Revenue per conversion must be between 0 and 1,000,000.');
            }
            $monthlyVisitors = isset($in['monthlyVisitors']) ? (int) $in['monthlyVisitors'] : $defaults['monthlyVisitors'];
            if ($monthlyVisitors < 0 || $monthlyVisitors > 100000000) {
                fail('Monthly visitors must be a positive number.');
            }
            db()->prepare('REPLACE INTO workspace_settings (userId, workspaceName, timezone, defaultGoal, emailUpdates, weeklyDigest, revenuePerConversion, monthlyVisitors) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $user['id'], $name, $timezone, $defaultGoal, $emailUpdates ? 1 : 0, $weeklyDigest ? 1 : 0, $revenue, $monthlyVisitors]);
            respond(get_settings($user));
            break;

        case 'tracking.health':
            $user = require_user();
            $ids = user_experiment_ids($user['id']);
            $empty = ['scriptDetected' => false, 'lastEventTs' => null, 'lastEventAgo' => null, 'eventsLast24h' => 0, 'visitorRows' => 0, 'conversions' => 0, 'mappedOutcomes' => 0, 'events' => []];
            if (!$ids) {
                respond($empty);
            }
            $pdo = db();
            $in1 = in_placeholders($ids);
            $row = $pdo->prepare("SELECT COUNT(*) AS total, SUM(type = 'exposure') AS exposures, SUM(type = 'conversion') AS conversions, SUM(createdAt >= (NOW() - INTERVAL 1 DAY)) AS recent, UNIX_TIMESTAMP(MAX(createdAt)) AS lastTs FROM visitor_events WHERE experimentId IN ($in1)");
            $row->execute($ids);
            $agg = $row->fetch();
            $lastTs = $agg['lastTs'] !== null ? (int) $agg['lastTs'] : null;

            $cst = $pdo->prepare("SELECT experimentId, COUNT(*) AS n FROM visitor_events WHERE type = 'conversion' AND experimentId IN ($in1) GROUP BY experimentId");
            $cst->execute($ids);
            $convByExp = [];
            foreach ($cst->fetchAll() as $c) {
                $convByExp[(int) $c['experimentId']] = (int) $c['n'];
            }
            $est = $pdo->prepare("SELECT id, name, conversionEvent FROM experiments WHERE id IN ($in1)");
            $est->execute($ids);
            $events = [];
            $seen = [];
            foreach ($est->fetchAll() as $e) {
                $eventName = trim((string) $e['conversionEvent']);
                if ($eventName === '' || isset($seen[$eventName])) {
                    continue;
                }
                $seen[$eventName] = true;
                $receiving = isset($convByExp[(int) $e['id']]) && $convByExp[(int) $e['id']] > 0;
                $events[] = ['name' => $eventName, 'type' => 'Conversion', 'selector' => 'data-sf-conversion="' . $eventName . '"', 'status' => $receiving ? 'Receiving' : 'Waiting'];
            }
            respond([
                'scriptDetected'  => $lastTs !== null,
                'lastEventTs'     => $lastTs,
                'lastEventAgo'    => $lastTs !== null ? time_ago($lastTs) : null,
                'eventsLast24h'   => (int) $agg['recent'],
                'visitorRows'     => (int) $agg['exposures'],
                'conversions'     => (int) $agg['conversions'],
                'mappedOutcomes'  => count($events),
                'events'          => $events,
            ]);
            break;

        case 'experiments.segments':
            $user = require_user();
            $in = query_input();
            $row = owned_experiment($user['id'], isset($in['id']) ? $in['id'] : 0);
            $pdo = db();
            $vst = $pdo->prepare('SELECT * FROM variants WHERE experimentId = ? ORDER BY position ASC, id ASC');
            $vst->execute([(int) $row['id']]);
            $variantRows = $vst->fetchAll();
            if (!$variantRows) {
                respond([]);
            }
            $control = $variantRows[0];
            foreach ($variantRows as $v) {
                if ((int) $v['isControl'] === 1) {
                    $control = $v;
                    break;
                }
            }
            $est = $pdo->prepare('SELECT variantId, type, device, isPaid, isReturning FROM visitor_events WHERE experimentId = ?');
            $est->execute([(int) $row['id']]);
            $events = $est->fetchAll();

            // Leading challenger = non-control variant with the most exposures.
            $expCount = [];
            foreach ($events as $e) {
                if ($e['type'] === 'exposure') {
                    $vid = (int) $e['variantId'];
                    $expCount[$vid] = (isset($expCount[$vid]) ? $expCount[$vid] : 0) + 1;
                }
            }
            $lead = null;
            $leadN = -1;
            foreach ($variantRows as $v) {
                if ((int) $v['id'] === (int) $control['id']) {
                    continue;
                }
                $n = isset($expCount[(int) $v['id']]) ? $expCount[(int) $v['id']] : 0;
                if ($n > $leadN) {
                    $lead = $v;
                    $leadN = $n;
                }
            }

            $segments = [
                'All visitors'       => function ($e) { return true; },
                'Mobile visitors'    => function ($e) { return $e['device'] === 'Mobile'; },
                'Desktop visitors'   => function ($e) { return $e['device'] === 'Desktop'; },
                'Paid traffic'       => function ($e) { return (int) $e['isPaid'] === 1; },
                'Returning visitors' => function ($e) { return (int) $e['isReturning'] === 1; },
            ];
            $out = [];
            foreach ($segments as $label => $match) {
                $cv = 0; $cx = 0; $lv = 0; $lx = 0;
                foreach ($events as $e) {
                    if (!$match($e)) {
                        continue;
                    }
                    $vid = (int) $e['variantId'];
                    if ($vid === (int) $control['id']) {
                        if ($e['type'] === 'exposure') { $cv++; } else { $cx++; }
                    } elseif ($lead !== null && $vid === (int) $lead['id']) {
                        if ($e['type'] === 'exposure') { $lv++; } else { $lx++; }
                    }
                }
                $visitors = $cv + $lv;
                $cRate = $cv > 0 ? $cx / $cv : 0;
                $lRate = $lv > 0 ? $lx / $lv : 0;
                $lift = $cRate > 0 ? (($lRate - $cRate) / $cRate) * 100 : 0;
                $signal = $visitors >= 200 ? 'Strong' : ($visitors >= 50 ? 'Moderate' : ($visitors >= 10 ? 'Early' : 'Inconclusive'));
                $out[] = ['segment' => $label, 'lift' => round($lift, 1), 'signal' => $signal, 'visitors' => $visitors];
            }
            respond($out);
            break;

        // ---------- multi-agent orchestration ----------
        case 'orchestrator.agents':
            require_user();
            respond(orch_agents());
            break;

        // Engine status: which engine will run, and whether the service is up.
        case 'orchestrator.engine':
            require_user();
            $mode = orch_remote_mode();
            $configured = orch_remote_configured();
            $health = $configured ? orch_remote_health() : ['reachable' => false, 'degraded' => true, 'provider' => null, 'error' => 'Not configured'];
            respond([
                'mode'       => $mode,
                'configured' => $configured,
                'reachable'  => (bool) $health['reachable'],
                'degraded'   => (bool) $health['degraded'],
                'provider'   => $health['provider'],
                'model'      => isset($health['model']) ? $health['model'] : null,
                'error'      => $health['error'],
                'active'     => $mode === 'local' ? 'local' : ($health['reachable'] ? 'langgraph' : ($mode === 'remote' ? 'unavailable' : 'local')),
            ]);
            break;

        case 'orchestrator.runs':
            $user = require_user();
            respond(orch_runs($user['id']));
            break;

        case 'orchestrator.state':
            $user = require_user();
            $in = query_input();
            respond(orch_run_state($user['id'], isset($in['id']) ? (int) $in['id'] : 0));
            break;

        case 'orchestrator.start':
            require_post();
            $user = require_user();
            respond(orch_start($user, body_json()));
            break;

        case 'orchestrator.tick':
            require_post();
            $user = require_user();
            $in = body_json();
            respond(orch_tick($user, isset($in['id']) ? (int) $in['id'] : 0));
            break;

        case 'orchestrator.cancel':
            require_post();
            $user = require_user();
            $in = body_json();
            respond(orch_cancel($user, isset($in['id']) ? (int) $in['id'] : 0));
            break;

        default:
            fail('Unknown route.', 'NOT_FOUND', 404);
    }
} catch (Throwable $e) {
    error_log('[signalforge] ' . $e->getMessage());

    // A database problem is by far the most common cause of a 500 here, and the
    // old catch-all message sent people to config.php and install.php even when
    // the real fault was a dead database host. Say what actually broke.
    $message = $e->getMessage();
    $isDb = $e instanceof PDOException
        || stripos($message, 'Database connection failed') !== false
        || stripos($message, 'No database is configured') !== false
        || stripos($message, 'DATABASE_URL') !== false
        || stripos($message, 'SQLSTATE') !== false;

    if ($isDb) {
        fail(
            'Database unavailable. ' . $message
            . ' Run api/health.php for a full diagnostic.',
            'DATABASE_UNAVAILABLE',
            503
        );
    }

    fail(
        'Server error. ' . $message . ' Run api/health.php for a full diagnostic.',
        'INTERNAL_SERVER_ERROR',
        500
    );
}
