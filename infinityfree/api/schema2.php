<?php
// SignalForge v2 schema migration. Idempotent: safe to run repeatedly.
// Open once: /api/schema2.php?key=<install_key>
require_once __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');

// When install.php embeds this file it has already checked the key, so the
// guard below is skipped and the schema runs as part of the main install.
if (!defined('SF_SCHEMA2_EMBEDDED')) {
    $key = (string) cfg('install_key');
    if ($key === '' || $key === 'CHANGE-ME-install-key' || !hash_equals($key, (string) (isset($_GET['key']) ? $_GET['key'] : ''))) {
        http_response_code(403);
        exit("Forbidden. Pass ?key=<install_key>\n");
    }
}

$statements = [
    // ---------- experiment management ----------
    "CREATE TABLE IF NOT EXISTS experiment_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        experimentId INT NOT NULL,
        userId INT NOT NULL,
        body TEXT NOT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY notes_exp_idx (experimentId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS experiment_tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        experimentId INT NOT NULL,
        tag VARCHAR(48) NOT NULL,
        UNIQUE KEY tags_exp_tag (experimentId, tag)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        experimentId INT NULL,
        action VARCHAR(64) NOT NULL,
        detail VARCHAR(500) NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY activity_user_idx (userId, createdAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS experiment_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tkey VARCHAR(48) NOT NULL,
        name VARCHAR(120) NOT NULL,
        category VARCHAR(48) NOT NULL,
        description VARCHAR(500) NULL,
        payload MEDIUMTEXT NULL,
        isSystem TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY templates_key (tkey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---------- tracking & analytics ----------
    "CREATE TABLE IF NOT EXISTS custom_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        experimentId INT NULL,
        name VARCHAR(120) NOT NULL,
        kind VARCHAR(32) NOT NULL DEFAULT 'click',
        selector VARCHAR(255) NULL,
        pageUrl VARCHAR(500) NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'Waiting',
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY custom_events_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS event_occurrences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        experimentId INT NOT NULL,
        eventName VARCHAR(120) NOT NULL,
        visitorId VARCHAR(64) NOT NULL,
        props MEDIUMTEXT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY occ_lookup (experimentId, eventName, visitorId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS utm_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        experimentId INT NULL,
        source VARCHAR(120) NULL,
        medium VARCHAR(120) NULL,
        campaign VARCHAR(120) NULL,
        term VARCHAR(120) NULL,
        content VARCHAR(120) NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY utm_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---------- integrations ----------
    "CREATE TABLE IF NOT EXISTS integrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        kind VARCHAR(24) NOT NULL,
        name VARCHAR(120) NOT NULL,
        config MEDIUMTEXT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'unverified',
        lastSyncAt TIMESTAMP NULL DEFAULT NULL,
        lastError VARCHAR(500) NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY integrations_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS webhook_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        integrationId INT NOT NULL,
        event VARCHAR(64) NOT NULL,
        payload MEDIUMTEXT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        responseCode INT NULL,
        attempts INT NOT NULL DEFAULT 0,
        lastError VARCHAR(500) NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY wh_int_idx (integrationId, createdAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS crm_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        experimentId INT NULL,
        email VARCHAR(190) NULL,
        payload MEDIUMTEXT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'queued',
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY leads_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---------- AI recommendations ----------
    "CREATE TABLE IF NOT EXISTS recommendations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        experimentId INT NULL,
        source VARCHAR(32) NOT NULL DEFAULT 'crawl',
        title VARCHAR(255) NOT NULL,
        why TEXT NULL,
        impact VARCHAR(24) NOT NULL DEFAULT 'medium',
        effort VARCHAR(24) NOT NULL DEFAULT 'medium',
        priority INT NOT NULL DEFAULT 50,
        status VARCHAR(24) NOT NULL DEFAULT 'suggested',
        payload MEDIUMTEXT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY recs_user_idx (userId, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS variant_copy (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recommendationId INT NULL,
        experimentId INT NULL,
        variantId INT NULL,
        kind VARCHAR(32) NOT NULL,
        content TEXT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'draft',
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY copy_rec_idx (recommendationId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---------- crawler & audits ----------
    "CREATE TABLE IF NOT EXISTS crawls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        siteUrl VARCHAR(500) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'queued',
        pagesCrawled INT NOT NULL DEFAULT 0,
        score INT NULL,
        error VARCHAR(500) NULL,
        startedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finishedAt TIMESTAMP NULL DEFAULT NULL,
        KEY crawls_user_idx (userId, startedAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS crawl_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        crawlId INT NOT NULL,
        url VARCHAR(500) NOT NULL,
        statusCode INT NULL,
        title VARCHAR(255) NULL,
        score INT NULL,
        issues MEDIUMTEXT NULL,
        screenshotUrl VARCHAR(500) NULL,
        KEY pages_crawl_idx (crawlId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS crawl_issues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        crawlId INT NOT NULL,
        pageId INT NULL,
        severity VARCHAR(16) NOT NULL DEFAULT 'medium',
        category VARCHAR(48) NOT NULL,
        title VARCHAR(255) NOT NULL,
        detail TEXT NULL,
        selector VARCHAR(255) NULL,
        KEY issues_crawl_idx (crawlId, severity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---------- visual analysis ----------
    "CREATE TABLE IF NOT EXISTS screenshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        experimentId INT NULL,
        crawlId INT NULL,
        pageUrl VARCHAR(500) NOT NULL,
        viewport VARCHAR(16) NOT NULL DEFAULT 'desktop',
        imageUrl VARCHAR(500) NULL,
        annotations MEDIUMTEXT NULL,
        findings MEDIUMTEXT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY shots_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---------- competitors ----------
    "CREATE TABLE IF NOT EXISTS competitors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        url VARCHAR(500) NOT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY comp_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS competitor_scans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        competitorId INT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'queued',
        snapshot MEDIUMTEXT NULL,
        changes MEDIUMTEXT NULL,
        error VARCHAR(500) NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY scans_comp_idx (competitorId, createdAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---------- ecommerce ----------
    "CREATE TABLE IF NOT EXISTS ecommerce_metrics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        experimentId INT NULL,
        day DATE NOT NULL,
        revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
        orders INT NOT NULL DEFAULT 0,
        sessions INT NOT NULL DEFAULT 0,
        carts INT NOT NULL DEFAULT 0,
        checkouts INT NOT NULL DEFAULT 0,
        productViews INT NOT NULL DEFAULT 0,
        UNIQUE KEY ecom_user_day (userId, experimentId, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---------- security & ops ----------
    "CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NULL,
        actorId INT NULL,
        action VARCHAR(64) NOT NULL,
        target VARCHAR(190) NULL,
        ip VARCHAR(64) NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY audit_user_idx (userId, createdAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        token VARCHAR(64) NOT NULL,
        expiresAt INT NOT NULL,
        usedAt INT NULL,
        UNIQUE KEY reset_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS system_health (
        id INT AUTO_INCREMENT PRIMARY KEY,
        component VARCHAR(48) NOT NULL,
        status VARCHAR(16) NOT NULL,
        detail VARCHAR(500) NULL,
        latencyMs INT NULL,
        checkedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY health_comp_idx (component, checkedAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS backups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId INT NOT NULL,
        filename VARCHAR(190) NOT NULL,
        sizeBytes INT NOT NULL DEFAULT 0,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY backups_user_idx (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS onboarding (
        userId INT NOT NULL PRIMARY KEY,
        steps MEDIUMTEXT NULL,
        updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS scheduled_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        jobKey VARCHAR(64) NOT NULL,
        lastRunAt TIMESTAMP NULL DEFAULT NULL,
        lastStatus VARCHAR(24) NULL,
        lastDetail VARCHAR(500) NULL,
        runCount INT NOT NULL DEFAULT 0,
        UNIQUE KEY jobs_key (jobKey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$done = [];
try {
    foreach ($statements as $sql) {
        db()->exec($sql);
    }
    // Idempotent column upgrades on existing tables.
    $upgrades = [
        'experiments' => [
            'scheduledAt' => 'TIMESTAMP NULL DEFAULT NULL',
            'archivedAt'  => 'TIMESTAMP NULL DEFAULT NULL',
            'ownerName'   => 'VARCHAR(120) NULL',
            'templateKey' => 'VARCHAR(48) NULL',
            'winnerVariantId' => 'INT NULL',
            'winnerReason'    => 'VARCHAR(500) NULL',
        ],
        'visitor_events' => [
            'browser'    => 'VARCHAR(32) NULL',
            'country'    => 'VARCHAR(8) NULL',
            'landingPage'=> 'VARCHAR(255) NULL',
            'utmSource'  => 'VARCHAR(120) NULL',
            'utmMedium'  => 'VARCHAR(120) NULL',
            'utmCampaign'=> 'VARCHAR(120) NULL',
            'revenue'    => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
        ],
        'users' => [
            'resetToken' => 'VARCHAR(64) NULL',
        ],
    ];
    foreach ($upgrades as $table => $cols) {
        $existing = db()->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $col => $def) {
            if (!in_array($col, $existing, true)) {
                db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                $done[] = "$table.$col added";
            }
        }
    }
    // Status enum needs the new lifecycle values.
    try {
        db()->exec("ALTER TABLE experiments MODIFY COLUMN status ENUM('Draft','Scheduled','Active','Running','Paused','Completed','Archived') NOT NULL DEFAULT 'Draft'");
        $done[] = 'experiments.status enum widened';
    } catch (Throwable $e) {
        $done[] = 'status enum: ' . $e->getMessage();
    }

    // Seed the system experiment templates.
    $templates = [
        ['headline', 'Headline rewrite', 'Copy', 'Test a clearer, benefit-led headline against the current one.', ['kind' => 'A/B test', 'goal' => 'Trial starts', 'framework' => 'PAS', 'audience' => 'All eligible visitors', 'conversionEvent' => 'trial_start', 'traffic' => 100, 'variantNames' => ['Control', 'Benefit-led headline']]],
        ['cta', 'CTA button', 'Copy', 'Test button wording, colour and placement.', ['kind' => 'A/B test', 'goal' => 'Trial starts', 'framework' => 'Friction', 'audience' => 'All eligible visitors', 'conversionEvent' => 'cta_click', 'traffic' => 100, 'variantNames' => ['Control', 'Action-led CTA']]],
        ['form', 'Form length', 'Forms', 'Test fewer fields against the current form.', ['kind' => 'A/B test', 'goal' => 'Lead captured', 'framework' => 'Friction', 'audience' => 'All eligible visitors', 'conversionEvent' => 'form_submit', 'traffic' => 100, 'variantNames' => ['Control', 'Short form']]],
        ['pricing', 'Pricing presentation', 'Commerce', 'Test plan framing, anchoring and badge placement.', ['kind' => 'A/B test', 'goal' => 'Purchase', 'framework' => 'Anchoring', 'audience' => 'All eligible visitors', 'conversionEvent' => 'purchase', 'traffic' => 100, 'variantNames' => ['Control', 'Anchored pricing']]],
        ['layout', 'Page layout', 'Layout', 'Test section order and above-the-fold density.', ['kind' => 'A/B test', 'goal' => 'Trial starts', 'framework' => 'Hierarchy', 'audience' => 'All eligible visitors', 'conversionEvent' => 'trial_start', 'traffic' => 100, 'variantNames' => ['Control', 'Reordered layout']]],
        ['checkout', 'Checkout flow', 'Commerce', 'Test single-page checkout against the current steps.', ['kind' => 'A/B test', 'goal' => 'Purchase', 'framework' => 'Friction', 'audience' => 'All eligible visitors', 'conversionEvent' => 'purchase', 'traffic' => 100, 'variantNames' => ['Control', 'One-page checkout']]],
    ];
    $ins = db()->prepare('INSERT IGNORE INTO experiment_templates (tkey, name, category, description, payload, isSystem) VALUES (?, ?, ?, ?, ?, 1)');
    foreach ($templates as $t) {
        $ins->execute([$t[0], $t[1], $t[2], $t[3], json_encode($t[4])]);
    }
    $done[] = 'templates seeded';

    $GLOBALS['sf_schema2_ok'] = true;
    echo "OK - v2 schema ready.\n";
    foreach ($done as $d) {
        echo "  - $d\n";
    }
} catch (Throwable $e) {
    $GLOBALS['sf_schema2_ok'] = false;
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
