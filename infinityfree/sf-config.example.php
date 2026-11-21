<?php
// ---------------------------------------------------------------------------
// SignalForge configuration - EXAMPLE.
//
// Copy this file to ONE of these locations and fill in real values:
//
//   <parent of htdocs>/sf-config.php     <- preferred, outside the web root
//   /home/uploads/sf-config.php          <- InfinityFree-style host
//
// Or skip this file entirely and set the environment variables instead
// (see api/config.php for the list). Environment variables win over this file.
//
// Never commit a filled-in copy of this file to git.
// ---------------------------------------------------------------------------

return [
    // ---- Database (MySQL / MariaDB) -------------------------------------
    // Either give a single connection string...
    // 'database_url' => 'mysql://user:pass@host:3306/dbname',

    // ...or the individual fields.
    'db_host' => 'localhost',
    'db_name' => 'signalforge',
    'db_user' => 'CHANGE-ME',
    'db_pass' => 'CHANGE-ME',
    'db_port' => 3306,

    // Managed MySQL (TiDB Cloud, Aiven, PlanetScale) needs TLS.
    // Set to true and point db_ssl_ca at the provider's CA bundle.
    'db_ssl'      => false,
    'db_ssl_ca'   => null,
    'db_ssl_verify' => true,

    // ---- Security -------------------------------------------------------
    // Signs the login cookie. Generate with: openssl rand -hex 32
    // If this changes, every existing session is invalidated.
    'secret' => 'CHANGE-ME-openssl-rand-hex-32',

    // Required to run api/install.php. Any long random string.
    'install_key' => 'CHANGE-ME-install-key',

    // ---- Orchestration service (optional) -------------------------------
    // The app falls back to its built-in engine if this is unreachable.
    'orchestrator_url'            => 'https://signalforge-orchestrator.onrender.com',
    'orchestrator_token'          => '',
    'orchestrator_mode'           => 'auto',   // auto | remote | local
    'orchestrator_timeout'        => 60,
    'orchestrator_health_timeout' => 8,
];
