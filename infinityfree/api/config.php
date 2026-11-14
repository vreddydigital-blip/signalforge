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
    // The live service. Leave empty to use the built-in PHP engine only.
    'orchestrator_url'   => 'https://signalforge-orchestrator.onrender.com',

    // The SIGNALFORGE_API_TOKEN you set on Render. Must match exactly.
    // NEVER commit a real token to a public repo - paste it here on the server only.
    'orchestrator_token' => '',

    // 'remote'  = always use the LangGraph service (clear error if it is down, NO fallback)
    // 'local'   = always use the built-in PHP engine
    // 'auto'    = try the service first, fall back to PHP when it is asleep/unreachable
    // 'auto' is used here so the site keeps working through Render's free-tier sleep.
    // Switch to 'remote' if you would rather see a hard error than a local fallback.
    'orchestrator_mode'  => 'auto',

    // Seconds to wait for the service. Render's free tier sleeps after 15 min idle
    // and can take up to ~60s to wake, so this must be generous or the first
    // request after a sleep will time out.
    'orchestrator_timeout' => 60,

    // Seconds for the quick health probe the dashboard polls. Kept short so a
    // sleeping service shows as "asleep" immediately instead of freezing the UI.
    'orchestrator_health_timeout' => 8,
];
