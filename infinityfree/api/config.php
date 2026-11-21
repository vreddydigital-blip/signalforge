<?php
// ---------------------------------------------------------------------------
// This file is a STUB. It holds no credentials, and it is denied by the
// .htaccess rule in the web root so it is never served as plain text.
//
// api/lib.php resolves configuration in this order (first hit wins):
//
//   1. ENVIRONMENT VARIABLES - the preferred route on a container host
//      (Render, Railway, Fly, Docker). Set DATABASE_URL, or the SF_DB_* set:
//          DATABASE_URL=mysql://user:pass@host:3306/dbname
//          SF_SECRET=<openssl rand -hex 32>
//          SF_INSTALL_KEY=<random>
//      Optional: SF_DB_SSL=true, SF_DB_SSL_CA=/path/to/ca.pem
//
//   2. A PRIVATE FILE ABOVE THE WEB ROOT. Copy sf-config.example.php to
//      <parent of htdocs>/sf-config.php and fill it in. On InfinityFree that
//      is /home/uploads/sf-config.php. This keeps credentials out of htdocs.
//
//   3. THIS FILE. If you have no way to write above the web root, you can
//      return an array here instead of []. It is the least safe option.
//
// To change database or orchestrator settings, use route 1 or 2 - not this file.
// ---------------------------------------------------------------------------
return [];
