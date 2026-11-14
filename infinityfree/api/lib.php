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

function cfg($key)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return isset($config[$key]) ? $config[$key] : null;
}

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
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
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
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
