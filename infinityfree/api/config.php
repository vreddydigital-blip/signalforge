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
];
