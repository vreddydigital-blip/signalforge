<?php
// ---------------------------------------------------------------------------
// SignalForge diagnostic endpoint.
//
//   GET /api/health.php            -> JSON summary (safe to expose)
//   GET /api/health.php?verbose=1  -> adds config source + table list
//
// This exists because the app used to answer every failure with
// "Check api/config.php and that api/install.php has been run", which pointed
// at the two things that were usually fine. This endpoint says what is
// actually wrong: DNS, credentials, missing database, missing tables, or a
// missing secret.
//
// It never prints a password, a full connection string, or a stack trace.
// ---------------------------------------------------------------------------

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$verbose = isset($_GET['verbose']) && $_GET['verbose'] === '1';

// The tables the app needs. Kept in one place so install.php and this file
// cannot drift apart. The first group is the core app; the second is the v2
// feature set (dashboard, integrations, AI, crawls) that install.php now
// builds in the same run.
$expectedTables = [
    // core
    'users', 'experiments', 'variants', 'visitor_events', 'rate_limits',
    'workspace_settings', 'agent_runs', 'agent_tasks',
    // v2
    'activity_log', 'experiment_notes', 'experiment_tags', 'experiment_templates',
    'integrations', 'recommendations', 'crawls', 'crawl_pages', 'crawl_issues',
    'custom_events', 'utm_campaigns', 'scheduled_jobs', 'system_health',
];

$report = [
    'ok'       => false,
    'php'      => PHP_VERSION,
    'checks'   => [],
    'nextStep' => null,
];

function check_add(&$report, $name, $ok, $detail)
{
    $report['checks'][] = ['check' => $name, 'ok' => (bool) $ok, 'detail' => $detail];
}

// ---- 1. PHP extensions -----------------------------------------------------
$hasPdo = extension_loaded('pdo');
$hasMysql = extension_loaded('pdo_mysql');
check_add($report, 'pdo', $hasPdo, $hasPdo ? 'loaded' : 'MISSING - enable the PDO extension');
check_add($report, 'pdo_mysql', $hasMysql, $hasMysql ? 'loaded' : 'MISSING - enable pdo_mysql');

// ---- 2. Configuration present ---------------------------------------------
$host = cfg('db_host');
$name = cfg('db_name');
$url  = cfg('database_url');

$configured = (bool) (($url) || ($host && $name));
check_add(
    $report,
    'database_configured',
    $configured,
    $configured
        ? 'connection details found'
        : 'NOT CONFIGURED - set DATABASE_URL, or db_host/db_name/db_user/db_pass'
);

// Which source supplied the config. Useful when a stale file is shadowing env.
if ($verbose) {
    $sources = [];
    foreach (SF_CONFIG_CANDIDATES as $p) {
        if (is_readable($p)) {
            $sources[] = $p;
        }
    }
    if (is_readable(__DIR__ . '/config.php')) {
        $sources[] = __DIR__ . '/config.php';
    }
    $envSet = [];
    foreach (sf_env_map() as $k => $envName) {
        if (getenv($envName) !== false && getenv($envName) !== '') {
            $envSet[] = $envName;
        }
    }
    $report['config'] = [
        'readableFiles'    => $sources,
        'environmentVars'  => $envSet,
        'effectiveHost'    => $host ? $host : null,
        'effectiveDb'      => $name ? $name : null,
        'effectivePort'    => cfg('db_port') ? (int) cfg('db_port') : null,
        'ssl'              => cfg('db_ssl'),
    ];
}

// ---- 3. Secret present (sessions break without it) ------------------------
$secret = (string) cfg('secret');
$secretOk = $secret !== '' && $secret !== 'change-me' && strlen($secret) >= 16;
check_add(
    $report,
    'session_secret',
    $secretOk,
    $secretOk ? 'set' : 'MISSING or too short - set SF_SECRET to a random 32+ char string'
);

// ---- 4. Database reachable -------------------------------------------------
$ping = db_ping();
check_add($report, 'database_connection', $ping['ok'], $ping['ok'] ? ('connected in ' . $ping['ms'] . ' ms') : $ping['error']);

// ---- 5. Tables exist -------------------------------------------------------
if ($ping['ok']) {
    $missing = db_missing_tables($expectedTables);
    check_add(
        $report,
        'tables',
        count($missing) === 0,
        count($missing) === 0
            ? count($expectedTables) . ' tables present'
            : 'MISSING: ' . implode(', ', $missing) . ' - run api/install.php'
    );
    if ($verbose) {
        try {
            $report['tables'] = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $report['tables'] = [];
        }
    }
} else {
    check_add($report, 'tables', false, 'skipped - no database connection');
}

// ---- 6. Orchestrator (informational only, never fatal) --------------------
$orchUrl = (string) cfg('orchestrator_url');
if ($orchUrl !== '') {
    $report['orchestrator'] = [
        'url'  => $orchUrl,
        'mode' => cfg('orchestrator_mode') ? cfg('orchestrator_mode') : 'auto',
        'note' => 'The app falls back to its built-in engine when this is unreachable.',
    ];
}

// ---- Verdict ---------------------------------------------------------------
$allOk = true;
foreach ($report['checks'] as $c) {
    if (!$c['ok']) {
        $allOk = false;
        break;
    }
}
$report['ok'] = $allOk;

if (!$allOk) {
    foreach ($report['checks'] as $c) {
        if (!$c['ok']) {
            $report['nextStep'] = $c['check'] . ': ' . $c['detail'];
            break;
        }
    }
}

http_response_code($allOk ? 200 : 503);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
