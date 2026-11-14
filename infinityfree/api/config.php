<?php
// Edit these values, save, and keep this file private.
// Find the database values in InfinityFree: Control Panel -> MySQL Databases.
return [
    'db_host'     => 'sqlXXX.infinityfree.com',   // "MySQL Hostname" from the control panel
    'db_name'     => 'if0_12345678_signalforge',  // full database name, including the if0_ prefix
    'db_user'     => 'if0_12345678',              // "MySQL Username"
    'db_pass'     => 'YOUR_DATABASE_PASSWORD',    // your vPanel / MySQL password

    // Any long random text. It signs login cookies. Changing it logs everyone out.
    'secret'      => 'CHANGE-ME-to-60-random-characters',

    // Needed once to open install.php (creates the tables). Delete install.php afterwards.
    'install_key' => 'CHANGE-ME-install-key',

    // ---- Multi-agent orchestration (LangGraph service on Render) ----
    // The service URL Render gives you, e.g. https://signalforge-orchestrator.onrender.com
    // Leave empty to use the built-in PHP engine only.
    'orchestrator_url'   => '',

    // The SIGNALFORGE_API_TOKEN you set on Render. Must match exactly.
    'orchestrator_token' => '',

    // 'remote'  = always use the LangGraph service (clear error if it is down)
    // 'local'   = always use the built-in PHP engine
    // 'auto'    = try the service, fall back to PHP when it is unreachable
    'orchestrator_mode'  => 'auto',

    // Seconds to wait for the service. Render's free tier can take ~50s to wake,
    // so keep this generous; the dashboard retries on the next poll.
    'orchestrator_timeout' => 25,
];
