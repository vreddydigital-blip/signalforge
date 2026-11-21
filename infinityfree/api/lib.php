<?php
// Shared helpers for SignalForge (PHP 7.4+).
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

// Some shared hosts ship PHP without mbstring. Fall back to the plain string
// functions so the API never fatals on a missing extension.
if (!function_exists('mb_strlen')) {
    function mb_strlen($value, $encoding = null) { return strlen((string) $value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($value, $start, $length = null, $encoding = null) {
        return $length === null ? substr((string) $value, $start) : substr((string) $value, $start, $length);
    }
}

const SF_COOKIE = 'sf_session';
const SF_SESSION_SECONDS = 2592000; // 30 days
const SF_COLORS = ['#8d96a8', '#e5643c', '#f2aa4f', '#5676e8', '#6eaf75', '#9a70cf', '#4a9a9a', '#d477a0'];
const SF_PLAN_LIMITS = [
    'Free'   => ['experiments' => 3,  'variants' => 2],
    'Growth' => ['experiments' => 10, 'variants' => 5],
    'Scale'  => ['experiments' => 50, 'variants' => 8],
];

// ---------------------------------------------------------------------------
// CONFIGURATION
//
// Resolution order (first hit wins, so environment beats files):
//   1. Environment variables - DATABASE_URL, SF_DB_HOST, SF_DB_NAME, ...
//      This is what a container host (Render, Railway, Fly) uses.
//   2. A private config file above the web root. Candidates, in order:
//        - $SF_CONFIG_PATH if set
//        - <parent of htdocs>/sf-config.php   (portable, no account id baked in)
//        - /home/uploads/sf_43438998/config.php  (legacy InfinityFree path)
//   3. api/config.php inside the web root (denied by .htaccess).
//
// The old code hardcoded the InfinityFree path as the ONLY private location.
// That account no longer exists, so the path is now just one candidate among
// several and the app boots from env vars or a portable file instead.
// ---------------------------------------------------------------------------

if (!defined('SF_CONFIG_CANDIDATES')) {
    $sfCandidates = [];
    $sfEnvPath = getenv('SF_CONFIG_PATH');
    if ($sfEnvPath !== false && $sfEnvPath !== '') {
        $sfCandidates[] = $sfEnvPath;
    }
    // dirname(__DIR__, 2) from api/ is the directory ABOVE the web root.
    $sfCandidates[] = dirname(__DIR__, 2) . '/sf-config.php';
    $sfCandidates[] = '/home/uploads/sf_43438998/config.php';
    define('SF_CONFIG_CANDIDATES', $sfCandidates);
    define('SF_PRIVATE_CONFIG', $sfCandidates[0]);
}

// Environment variable -> config key. Env always wins over a file.
function sf_env_map()
{
    return [
        'database_url'                => 'DATABASE_URL',
        'db_host'                     => 'SF_DB_HOST',
        'db_name'                     => 'SF_DB_NAME',
        'db_user'                     => 'SF_DB_USER',
        'db_pass'                     => 'SF_DB_PASS',
        'db_port'                     => 'SF_DB_PORT',
        'db_ssl'                      => 'SF_DB_SSL',
        'db_ssl_ca'                   => 'SF_DB_SSL_CA',
        'db_ssl_verify'               => 'SF_DB_SSL_VERIFY',
        'secret'                      => 'SF_SECRET',
        'install_key'                 => 'SF_INSTALL_KEY',
        'orchestrator_url'            => 'SF_ORCHESTRATOR_URL',
        'orchestrator_token'          => 'SF_ORCHESTRATOR_TOKEN',
        'orchestrator_mode'           => 'SF_ORCHESTRATOR_MODE',
        'orchestrator_timeout'        => 'SF_ORCHESTRATOR_TIMEOUT',
        'orchestrator_health_timeout' => 'SF_ORCHESTRATOR_HEALTH_TIMEOUT',
    ];
}

function cfg($key)
{
    static $config = null;
    if ($config === null) {
        $config = [];

        // 1. Environment variables.
        foreach (sf_env_map() as $k => $envName) {
            $value = getenv($envName);
            if ($value !== false && $value !== '') {
                $config[$k] = $value;
            }
        }

        // 2. Private file(s) above the web root, then 3. the in-web-root copy.
        $files = SF_CONFIG_CANDIDATES;
        $files[] = __DIR__ . '/config.php';
        foreach ($files as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $maybe = require $path;
            if (is_array($maybe)) {
                foreach ($maybe as $k => $v) {
                    // Do not let a file overwrite a value the environment set.
                    if (!array_key_exists($k, $config)) {
                        $config[$k] = $v;
                    }
                }
            }
        }
    }
    return isset($config[$key]) ? $config[$key] : null;
}

// Parse a mysql:// connection string into its parts.
function sf_parse_database_url($url)
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme'])) {
        return null;
    }
    $out = [
        'scheme' => strtolower($parts['scheme']),
        'host'   => isset($parts['host']) ? $parts['host'] : '',
        'port'   => isset($parts['port']) ? (int) $parts['port'] : null,
        'user'   => isset($parts['user']) ? urldecode($parts['user']) : '',
        'pass'   => isset($parts['pass']) ? urldecode($parts['pass']) : '',
        'name'   => isset($parts['path']) ? ltrim($parts['path'], '/') : '',
        'ssl'    => null,
    ];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $q);
        if (isset($q['ssl'])) {
            $out['ssl'] = $q['ssl'];
        }
    }
    return $out;
}

// Turn a PDO connection failure into something a human can act on.
// Never includes the password.
function sf_db_hint($host, $e)
{
    $msg  = $e->getMessage();
    $code = (int) $e->getCode();
    $hint = 'Database connection failed (host: ' . $host . '). ';

    if (stripos($msg, 'getaddrinfo') !== false
        || stripos($msg, 'Name or service not known') !== false
        || stripos($msg, 'nodename nor servname') !== false
        || stripos($msg, 'php_network_getaddresses') !== false
        || stripos($msg, 'Temporary failure in name resolution') !== false) {
        $hint .= 'The hostname does not resolve in DNS, so that database server no longer exists. '
               . 'Create a new database and point DATABASE_URL (or db_host) at it.';
    } elseif ($code === 1045 || stripos($msg, 'Access denied') !== false) {
        $hint .= 'The username or password was rejected. Check db_user / db_pass.';
    } elseif ($code === 1049 || stripos($msg, 'Unknown database') !== false) {
        $hint .= 'That database name does not exist on the server. Create it first.';
    } elseif (stripos($msg, 'timed out') !== false || stripos($msg, 'Connection refused') !== false) {
        $hint .= 'The server refused the connection. Check the port, and that the host allows remote connections.';
    } elseif (stripos($msg, 'SSL') !== false || stripos($msg, 'certificate') !== false) {
        $hint .= 'TLS negotiation failed. Set db_ssl_ca to the provider CA bundle, or db_ssl=false for a local server.';
    } else {
        $hint .= 'Underlying error: ' . $msg;
    }
    return $hint;
}

function db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = cfg('db_host');
    $name = cfg('db_name');
    $user = cfg('db_user');
    $pass = cfg('db_pass');
    $port = cfg('db_port');
    $ssl  = cfg('db_ssl');

    // A single connection string takes precedence over the individual fields.
    $url = cfg('database_url');
    if ($url) {
        $p = sf_parse_database_url($url);
        if ($p === null) {
            throw new RuntimeException('DATABASE_URL is not a valid connection string.');
        }
        if ($p['scheme'] !== 'mysql') {
            throw new RuntimeException(
                'DATABASE_URL uses "' . $p['scheme'] . '", but this app\'s SQL is MySQL-specific '
                . '(REPLACE INTO, ON DUPLICATE KEY UPDATE, ENUM columns, SHOW COLUMNS, backticks). '
                . 'Use a MySQL-compatible database: mysql://user:pass@host:port/dbname'
            );
        }
        $host = $p['host'];
        $name = $p['name'];
        $user = $p['user'];
        $pass = $p['pass'];
        if ($p['port']) {
            $port = $p['port'];
        }
        if ($p['ssl'] !== null) {
            $ssl = $p['ssl'];
        }
    }

    if (!$host || !$name) {
        throw new RuntimeException(
            'No database is configured. Set DATABASE_URL, or db_host/db_name/db_user/db_pass '
            . 'in the config file, or the SF_DB_* environment variables.'
        );
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
    if ($port) {
        $dsn = 'mysql:host=' . $host . ';port=' . (int) $port . ';dbname=' . $name . ';charset=utf8mb4';
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ];

    // Managed MySQL (TiDB Cloud, Aiven, PlanetScale) requires TLS.
    $sslOn = $ssl !== null && $ssl !== '' && $ssl !== 'false' && $ssl !== '0' && $ssl !== false;
    if ($sslOn && defined('PDO::MYSQL_ATTR_SSL_CA')) {
        $ca = cfg('db_ssl_ca');
        if ($ca && is_readable($ca)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        }
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $verify = cfg('db_ssl_verify');
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = ($verify === null || $verify === '' || $verify === 'true' || $verify === '1');
        }
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        throw new RuntimeException(sf_db_hint($host, $e), 0, $e);
    }

    // Offset form works even when the server has no timezone tables loaded.
    try {
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (Throwable $e) {
        // Not fatal: some managed servers restrict session variables.
    }

    return $pdo;
}

// Non-throwing connectivity probe, used by api/health.php.
function db_ping()
{
    $started = microtime(true);
    try {
        $pdo = db();
        $pdo->query('SELECT 1')->fetchColumn();
        return [
            'ok'      => true,
            'ms'      => (int) round((microtime(true) - $started) * 1000),
            'error'   => null,
        ];
    } catch (Throwable $e) {
        return [
            'ok'    => false,
            'ms'    => (int) round((microtime(true) - $started) * 1000),
            'error' => $e->getMessage(),
        ];
    }
}

// Which of the app's tables actually exist. Used by health + install.
function db_missing_tables(array $expected)
{
    try {
        $rows = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return $expected;
    }
    $have = array_map('strtolower', $rows);
    $missing = [];
    foreach ($expected as $t) {
        if (!in_array(strtolower($t), $have, true)) {
            $missing[] = $t;
        }
    }
    return $missing;
}

function respond($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail($message, $code = 'BAD_REQUEST', $status = 400)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['message' => $message, 'code' => $code]], JSON_UNESCAPED_UNICODE);
    exit;
}

function body_json()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

function client_ip()
{
    // Cloudflare's header, when the site sits behind Cloudflare.
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    // Container hosts (Railway, Render, Fly) terminate TLS at their own edge,
    // so REMOTE_ADDR is the edge proxy - and on Railway that address is not
    // stable across requests. Keying the rate limiter on it meant every
    // request looked like a brand-new client, so the counter never accumulated
    // and brute-force protection was completely inert in production.
    // The edge appends the real peer address to X-Forwarded-For; earlier
    // entries are client-supplied and must not be trusted, so read the last.
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $last = trim((string) end($parts));
        if (filter_var($last, FILTER_VALIDATE_IP)) {
            return $last;
        }
    }

    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
}

function str_field($input, $key, $min, $max, $label = null)
{
    $label = $label ?: $key;
    $value = isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
    $length = mb_strlen($value);
    if ($length < $min || $length > $max) {
        fail("Please check \"$label\".");
    }
    return $value;
}

function opt_field($input, $key, $max)
{
    $value = isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
    return mb_substr($value, 0, $max);
}

// ---------- sessions (signed cookie, no PHP sessions needed) ----------

function session_signature($uid, $exp)
{
    return hash_hmac('sha256', $uid . '.' . $exp, (string) cfg('secret'));
}

function start_session($uid)
{
    $exp = time() + SF_SESSION_SECONDS;
    $token = $uid . '.' . $exp . '.' . session_signature($uid, $exp);
    setcookie(SF_COOKIE, $token, [
        'expires'  => $exp,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function end_session()
{
    setcookie(SF_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function current_user()
{
    if (empty($_COOKIE[SF_COOKIE]) || !is_string($_COOKIE[SF_COOKIE])) {
        return null;
    }
    $parts = explode('.', $_COOKIE[SF_COOKIE]);
    if (count($parts) !== 3) {
        return null;
    }
    list($uid, $exp, $sig) = $parts;
    if (!ctype_digit($uid) || !ctype_digit($exp) || (int) $exp < time()) {
        return null;
    }
    if (!hash_equals(session_signature($uid, $exp), $sig)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int) $uid]);
    $user = $st->fetch();
    return $user ? $user : null;
}

function require_user()
{
    $user = current_user();
    if (!$user) {
        fail('Please login (10001)', 'UNAUTHORIZED', 401);
    }
    return $user;
}

function public_user($user)
{
    return [
        'id'         => (int) $user['id'],
        'name'       => $user['name'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'plan'       => $user['plan'],
        'projectKey' => $user['projectKey'],
    ];
}

// Simple DB-backed throttle (hits per window).
function too_many($key, $max, $windowSeconds)
{
    $pdo = db();
    $k = md5($key);
    $now = time();
    $st = $pdo->prepare('SELECT hits, reset_at FROM rate_limits WHERE k = ?');
    $st->execute([$k]);
    $row = $st->fetch();
    if (!$row || (int) $row['reset_at'] < $now) {
        $pdo->prepare('REPLACE INTO rate_limits (k, hits, reset_at) VALUES (?, 1, ?)')->execute([$k, $now + $windowSeconds]);
        return false;
    }
    $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?')->execute([$k]);
    return ((int) $row['hits'] + 1) > $max;
}

// ---------- statistics (same maths as the dashboard) ----------

function normal_cdf($value)
{
    $sign = $value < 0 ? -1 : 1;
    $x = abs($value) / sqrt(2);
    $t = 1 / (1 + 0.3275911 * $x);
    $poly = (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t;
    return 0.5 * (1 + $sign * (1 - $poly * exp(-$x * $x)));
}

function posterior($visitors, $conversions)
{
    $alpha = $conversions + 1;
    $beta = max($visitors - $conversions, 0) + 1;
    $total = $alpha + $beta;
    return [$alpha / $total, ($alpha * $beta) / ($total * $total * ($total + 1))];
}

// Probability (1-99) that the variant beats the control.
function prob_to_beat($vVisitors, $vConversions, $cVisitors, $cConversions)
{
    list($meanA, $varA) = posterior($vVisitors, $vConversions);
    list($meanB, $varB) = posterior($cVisitors, $cConversions);
    $se = sqrt($varA + $varB);
    $z = $se > 0 ? ($meanA - $meanB) / $se : 0;
    return max(1.0, min(99.0, normal_cdf($z) * 100));
}

function time_ago($timestamp)
{
    $minutes = max(0, (int) floor((time() - $timestamp) / 60));
    if ($minutes < 1) {
        return 'Just now';
    }
    if ($minutes < 60) {
        return $minutes . ' min ago';
    }
    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours . ' hr ago';
    }
    if ($hours < 48) {
        return 'Yesterday';
    }
    return date('M j', $timestamp);
}

function in_placeholders(array $values)
{
    return implode(',', array_fill(0, count($values), '?'));
}

// ---------- workspace settings ----------

function settings_defaults($user)
{
    $name = isset($user['name']) && $user['name'] !== null && $user['name'] !== '' ? $user['name'] . "'s workspace" : 'My workspace';
    return [
        'workspaceName'        => $name,
        'timezone'             => 'UTC',
        'defaultGoal'          => 'Trial starts',
        'emailUpdates'         => true,
        'weeklyDigest'         => true,
        'revenuePerConversion' => 0.0,
        'monthlyVisitors'      => 0,
    ];
}

function get_settings($user)
{
    $st = db()->prepare('SELECT * FROM workspace_settings WHERE userId = ? LIMIT 1');
    $st->execute([(int) $user['id']]);
    $row = $st->fetch();
    $defaults = settings_defaults($user);
    if (!$row) {
        return $defaults;
    }
    return [
        'workspaceName'        => (string) $row['workspaceName'],
        'timezone'             => (string) $row['timezone'],
        'defaultGoal'          => (string) $row['defaultGoal'],
        'emailUpdates'         => (bool) $row['emailUpdates'],
        'weeklyDigest'         => (bool) $row['weeklyDigest'],
        'revenuePerConversion' => (float) $row['revenuePerConversion'],
        'monthlyVisitors'      => (int) $row['monthlyVisitors'],
    ];
}

function user_experiment_ids($userId)
{
    $st = db()->prepare('SELECT id FROM experiments WHERE userId = ?');
    $st->execute([(int) $userId]);
    return array_map(function ($r) { return (int) $r['id']; }, $st->fetchAll());
}
