<?php
// Open once: https://YOUR-SITE/api/install.php?key=YOUR_INSTALL_KEY  -- then DELETE this file.
require __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');

$key = (string) cfg('install_key');
if ($key === '' || $key === 'CHANGE-ME-install-key') {
    http_response_code(403);
    exit(
        "Forbidden. No install_key is configured.\n"
        . "Set SF_INSTALL_KEY in the environment, or install_key in your config file,\n"
        . "then call this page again as ?key=THAT_VALUE\n"
    );
}
if (!hash_equals($key, (string) (isset($_GET['key']) ? $_GET['key'] : ''))) {
    http_response_code(403);
    exit("Forbidden. The ?key= value does not match the configured install_key.\n");
}

$statements = [
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NULL,
        email VARCHAR(190) NOT NULL,
        passwordHash VARCHAR(255) NOT NULL,
        role ENUM('user','admin') NOT NULL DEFAULT 'user',
        plan ENUM('Free','Growth','Scale') NOT NULL DEFAULT 'Growth',
        projectKey VARCHAR(32) NOT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        lastSignedIn TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY users_email_unique (email),
        UNIQUE KEY users_projectKey_unique (projectKey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS experiments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        kind ENUM('A/B test','Multivariate') NOT NULL DEFAULT 'A/B test',
        status ENUM('Draft','Active','Paused','Completed') NOT NULL DEFAULT 'Draft',
        goal VARCHAR(255) NOT NULL,
        framework VARCHAR(128) NOT NULL,
        pageUrl TEXT NULL,
        objective TEXT NULL,
        hypothesis TEXT NULL,
        observedInsight TEXT NULL,
        experienceChange TEXT NULL,
        behaviorReason TEXT NULL,
        audience VARCHAR(128) NULL,
        conversionEvent VARCHAR(255) NULL,
        traffic INT NOT NULL DEFAULT 100,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY experiments_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS variants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        experimentId INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        isControl TINYINT(1) NOT NULL DEFAULT 0,
        position INT NOT NULL DEFAULT 0,
        color VARCHAR(16) NOT NULL,
        customCss TEXT NULL,
        customJs TEXT NULL,
        KEY variants_experiment_idx (experimentId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS visitor_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        experimentId INT NOT NULL,
        variantId INT NOT NULL,
        visitorId VARCHAR(64) NOT NULL,
        type ENUM('exposure','conversion') NOT NULL,
        device VARCHAR(16) NULL,
        isPaid TINYINT(1) NOT NULL DEFAULT 0,
        isReturning TINYINT(1) NOT NULL DEFAULT 0,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY visitor_events_unique (experimentId, visitorId, type),
        KEY visitor_events_lookup (experimentId, variantId, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS rate_limits (
        k VARCHAR(32) NOT NULL PRIMARY KEY,
        hits INT NOT NULL DEFAULT 0,
        reset_at INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS workspace_settings (
        userId INT NOT NULL PRIMARY KEY,
        workspaceName VARCHAR(120) NOT NULL DEFAULT 'My workspace',
        timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
        defaultGoal VARCHAR(120) NOT NULL DEFAULT 'Trial starts',
        emailUpdates TINYINT(1) NOT NULL DEFAULT 1,
        weeklyDigest TINYINT(1) NOT NULL DEFAULT 1,
        revenuePerConversion DECIMAL(12,2) NOT NULL DEFAULT 0,
        monthlyVisitors INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // Multi-agent orchestration: one row per run, one row per agent task in that run.
    "CREATE TABLE IF NOT EXISTS agent_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        objective VARCHAR(200) NOT NULL,
        status ENUM('running','completed','failed','cancelled') NOT NULL DEFAULT 'running',
        engine VARCHAR(16) NOT NULL DEFAULT 'local',
        remoteId VARCHAR(64) NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY agent_runs_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS agent_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        runId INT NOT NULL,
        agentKey VARCHAR(64) NOT NULL,
        title VARCHAR(255) NOT NULL,
        status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
        position INT NOT NULL DEFAULT 0,
        output MEDIUMTEXT NULL,
        error VARCHAR(500) NULL,
        startedAt TIMESTAMP NULL DEFAULT NULL,
        finishedAt TIMESTAMP NULL DEFAULT NULL,
        KEY agent_tasks_run_idx (runId, position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

try {
    foreach ($statements as $sql) {
        db()->exec($sql);
    }
    // Add the tracking columns if this is an older install (idempotent upgrade).
    $cols = db()->query("SHOW COLUMNS FROM visitor_events")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['device' => "VARCHAR(16) NULL", 'isPaid' => "TINYINT(1) NOT NULL DEFAULT 0", 'isReturning' => "TINYINT(1) NOT NULL DEFAULT 0"] as $col => $def) {
        if (!in_array($col, $cols, true)) {
            db()->exec("ALTER TABLE visitor_events ADD COLUMN $col $def");
        }
    }

    // Orchestration engine columns (added when upgrading from the local-only engine).
    $runCols = db()->query("SHOW COLUMNS FROM agent_runs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('engine', $runCols, true)) {
        db()->exec("ALTER TABLE agent_runs ADD COLUMN engine VARCHAR(16) NOT NULL DEFAULT 'local' AFTER status");
    }
    if (!in_array('remoteId', $runCols, true)) {
        db()->exec("ALTER TABLE agent_runs ADD COLUMN remoteId VARCHAR(64) NULL AFTER engine");
    }

    // The v2 feature set (dashboard, integrations, AI, crawls) needs its own
    // tables. Run that schema here too, so a fresh install is complete in one
    // step instead of leaving half the app answering 500s.
    define('SF_SCHEMA2_EMBEDDED', true);
    require __DIR__ . '/schema2.php';

    if (empty($GLOBALS['sf_schema2_ok'])) {
        echo "\nWARNING: the v2 schema did not complete - see the FAILED line above.\n";
    }

    echo "OK - tables are ready.\n";
    echo "Now DELETE api/install.php from your hosting.\n";
    echo "Then open api/health.php to confirm every check passes.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database error: " . $e->getMessage() . "\n";
    echo "\n";
    echo "This is a connection or permissions problem, not a schema problem.\n";
    echo "Open api/health.php for a step-by-step diagnosis.\n";
}