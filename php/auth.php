<?php
// Player accounts — one login per player (table di_accounts, see db_migrate.php)
//
// Public:
//   GET  ?action=invite&token=T        -> { player_name, team, activated, email } for a valid invite link
//   POST {action:'activate', token, email, password}
//                                      -> sets email + password from an invite link, returns a session
//   POST {action:'login', email, password} -> returns a session
//
// Logged in (Authorization: Bearer <session token>):
//   GET  ?action=me                    -> the logged-in account + player
//   POST {action:'logout'}             -> ends this session
//   POST {action:'change_password', current_password, new_password}
//
// Admin only (account with is_admin = 1):
//   GET  ?action=accounts              -> every player's account status + invite link token
//   POST {action:'reset_invite', player_id}
//                                      -> new invite token (old link stops working); the player
//                                         uses it to set a new password. Existing password keeps
//                                         working until the new link is used.
// v1.0
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

const SESSION_DAYS     = 180;  // stay logged in through the season
const MIN_PASSWORD_LEN = 8;
const MAX_FAILED       = 5;    // failed logins before a short lock
const LOCK_MINUTES     = 15;

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

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

function create_session($pdo, $account_id) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO di_sessions (token_hash, account_id, expires_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . SESSION_DAYS . " DAY))")
        ->execute([hash('sha256', $token), $account_id]);
    $pdo->prepare("UPDATE di_accounts SET last_login_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = ?")
        ->execute([$account_id]);
    // Opportunistic cleanup of expired sessions
    $pdo->exec("DELETE FROM di_sessions WHERE expires_at < NOW()");
    return $token;
}

function account_payload($pdo, $account_id) {
    $stmt = $pdo->prepare("
        SELECT a.id AS account_id, a.player_id, a.email, a.is_admin,
               p.name AS player_name, p.team
        FROM di_accounts a JOIN players p ON p.id = a.player_id
        WHERE a.id = ?
    ");
    $stmt->execute([$account_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['account_id'] = (int)$row['account_id'];
    $row['player_id']  = (int)$row['player_id'];
    $row['is_admin']   = (bool)$row['is_admin'];
    return $row;
}

// Returns the logged-in account row, or exits 401.
function require_account($pdo) {
    $token = bearer_token();
    if (!$token) out(['error' => 'Not logged in'], 401);
    $stmt = $pdo->prepare("
        SELECT a.* FROM di_sessions s
        JOIN di_accounts a ON a.id = s.account_id
        WHERE s.token_hash = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([hash('sha256', $token)]);
    $acc = $stmt->fetch();
    if (!$acc) out(['error' => 'Session expired — please log in again'], 401);
    return $acc;
}

function require_admin($pdo) {
    $acc = require_account($pdo);
    if (!(int)$acc['is_admin']) out(['error' => 'Admin only'], 403);
    return $acc;
}

function clean_email($email) {
    $email = strtolower(trim((string)$email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function find_invite($pdo, $token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare("
        SELECT a.*, p.name AS player_name, p.team
        FROM di_accounts a JOIN players p ON p.id = a.player_id
        WHERE a.invite_token = ?
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'invite') {
        $inv = find_invite($pdo, $_GET['token'] ?? '');
        if (!$inv) out(['error' => 'This invite link is not valid (it may have been replaced by a newer one).'], 404);
        out([
            'player_name' => $inv['player_name'],
            'team'        => $inv['team'],
            'activated'   => $inv['activated_at'] !== null,
            'email'       => $inv['email'],
        ]);
    }

    if ($action === 'me') {
        $acc = require_account($pdo);
        out(account_payload($pdo, (int)$acc['id']));
    }

    if ($action === 'accounts') {
        require_admin($pdo);
        $rows = $pdo->query("
            SELECT p.id AS player_id, p.name AS player_name, p.team, p.active,
                   a.email, a.invite_token, a.is_admin,
                   a.activated_at, a.last_login_at,
                   EXISTS(SELECT 1 FROM roster_assignments ra
                          WHERE ra.player_id = p.id
                            AND ra.year = (SELECT MAX(year) FROM roster_assignments)) AS on_latest_roster
            FROM players p
            JOIN di_accounts a ON a.player_id = p.id
            ORDER BY p.name
        ")->fetchAll();
        foreach ($rows as &$r) {
            $r['player_id']        = (int)$r['player_id'];
            $r['active']           = (bool)$r['active'];
            $r['is_admin']         = (bool)$r['is_admin'];
            $r['on_latest_roster'] = (bool)$r['on_latest_roster'];
        }
        $latest = $pdo->query("SELECT MAX(year) FROM roster_assignments")->fetchColumn();
        out(['roster_year' => $latest ? (int)$latest : null, 'accounts' => $rows]);
    }

    out(['error' => 'Unknown action'], 400);
}

// ── POST ─────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';

    // Set email + password from an invite link (first time, or a reset link)
    if ($action === 'activate') {
        $inv = find_invite($pdo, $data['token'] ?? '');
        if (!$inv) out(['error' => 'This invite link is not valid (it may have been replaced by a newer one).'], 404);

        $email    = clean_email($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        if (!$email) out(['error' => 'Please enter a valid email address'], 400);
        if (strlen($password) < MIN_PASSWORD_LEN) out(['error' => 'Password must be at least ' . MIN_PASSWORD_LEN . ' characters'], 400);

        $stmt = $pdo->prepare("SELECT id FROM di_accounts WHERE email = ? AND id <> ?");
        $stmt->execute([$email, $inv['id']]);
        if ($stmt->fetch()) out(['error' => 'That email is already used by another player'], 409);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE di_accounts
                SET email = ?, password_hash = ?, invite_token = NULL,
                    activated_at = COALESCE(activated_at, NOW()),
                    failed_logins = 0, locked_until = NULL
                WHERE id = ?
            ")->execute([$email, password_hash($password, PASSWORD_DEFAULT), $inv['id']]);
            // A reset link logs out everywhere else
            $pdo->prepare("DELETE FROM di_sessions WHERE account_id = ?")->execute([$inv['id']]);
            $token = create_session($pdo, (int)$inv['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            out(['error' => 'Could not save account'], 500);
        }
        out(['token' => $token, 'account' => account_payload($pdo, (int)$inv['id'])]);
    }

    if ($action === 'login') {
        $email    = clean_email($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        $generic  = ['error' => 'Wrong email or password'];
        if (!$email || $password === '') out($generic, 401);

        $stmt = $pdo->prepare("SELECT * FROM di_accounts WHERE email = ?");
        $stmt->execute([$email]);
        $acc = $stmt->fetch();
        if (!$acc || !$acc['password_hash']) {
            password_hash($password, PASSWORD_DEFAULT); // even out timing vs. a real check
            out($generic, 401);
        }
        if ($acc['locked_until'] && strtotime($acc['locked_until']) > time()) {
            out(['error' => 'Too many attempts — try again in a few minutes'], 429);
        }
        if (!password_verify($password, $acc['password_hash'])) {
            $fails = (int)$acc['failed_logins'] + 1;
            if ($fails >= MAX_FAILED) {
                $pdo->prepare("UPDATE di_accounts SET failed_logins = 0,
                               locked_until = DATE_ADD(NOW(), INTERVAL " . LOCK_MINUTES . " MINUTE) WHERE id = ?")
                    ->execute([$acc['id']]);
            } else {
                $pdo->prepare("UPDATE di_accounts SET failed_logins = ? WHERE id = ?")
                    ->execute([$fails, $acc['id']]);
            }
            out($generic, 401);
        }
        if (password_needs_rehash($acc['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare("UPDATE di_accounts SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $acc['id']]);
        }
        $token = create_session($pdo, (int)$acc['id']);
        out(['token' => $token, 'account' => account_payload($pdo, (int)$acc['id'])]);
    }

    if ($action === 'logout') {
        $token = bearer_token();
        if ($token) {
            $pdo->prepare("DELETE FROM di_sessions WHERE token_hash = ?")->execute([hash('sha256', $token)]);
        }
        out(['ok' => true]);
    }

    if ($action === 'change_password') {
        $acc = require_account($pdo);
        $current = (string)($data['current_password'] ?? '');
        $new     = (string)($data['new_password'] ?? '');
        if (!password_verify($current, (string)$acc['password_hash'])) out(['error' => 'Current password is wrong'], 400);
        if (strlen($new) < MIN_PASSWORD_LEN) out(['error' => 'Password must be at least ' . MIN_PASSWORD_LEN . ' characters'], 400);
        $pdo->prepare("UPDATE di_accounts SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $acc['id']]);
        // Keep this session, end all others
        $pdo->prepare("DELETE FROM di_sessions WHERE account_id = ? AND token_hash <> ?")
            ->execute([$acc['id'], hash('sha256', bearer_token())]);
        out(['ok' => true]);
    }

    if ($action === 'reset_invite') {
        require_admin($pdo);
        $player_id = (int)($data['player_id'] ?? 0);
        if (!$player_id) out(['error' => 'player_id required'], 400);
        ensure_player_accounts($pdo);
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("UPDATE di_accounts SET invite_token = ? WHERE player_id = ?");
        $stmt->execute([$token, $player_id]);
        if ($stmt->rowCount() === 0) out(['error' => 'Player not found'], 404);
        out(['ok' => true, 'invite_token' => $token]);
    }

    out(['error' => 'Unknown action'], 400);
}

out(['error' => 'Method not allowed'], 405);
