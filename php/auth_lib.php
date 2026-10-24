<?php
// Shared login checks for DI endpoints (player accounts live in di_accounts,
// sessions in di_sessions — see auth.php / db_migrate.php).
//
// Usage in a write endpoint, after db_connect.php:
//   require_once __DIR__ . '/auth_lib.php';
//   require_di_admin($pdo);   // exits with 401/403 JSON unless an admin is logged in

// Bearer token from the Authorization header (64 hex chars), or null.
function bearer_token() {
    $h = $_SERVER['HTTP_AUTHORIZATION']
      ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
      ?? '';
    if (!$h && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m) ? strtolower($m[1]) : null;
}

// The di_accounts row for the current session, or null if not logged in / expired.
function di_current_account($pdo) {
    $token = bearer_token();
    if (!$token) return null;
    $stmt = $pdo->prepare("
        SELECT a.* FROM di_sessions s
        JOIN di_accounts a ON a.id = s.account_id
        WHERE s.token_hash = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

function di_auth_fail($msg, $code) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// Logged-in account or exit 401.
function require_di_account($pdo) {
    $acc = di_current_account($pdo);
    if (!$acc) di_auth_fail(bearer_token() ? 'Session expired — please log in again' : 'Not logged in', 401);
    return $acc;
}

// Logged-in admin account or exit 401/403.
function require_di_admin($pdo) {
    $acc = require_di_account($pdo);
    if (!(int)$acc['is_admin']) di_auth_fail('Admin only', 403);
    return $acc;
}
