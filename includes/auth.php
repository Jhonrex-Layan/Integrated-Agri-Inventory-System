<?php
declare(strict_types=1);

function permissions_map(): array
{
    static $m = null;
    return $m ??= require __DIR__ . '/permissions_map.php';
}

/** The logged-in user, re-read from the database on every request so role changes and deactivation apply at once. */
function current_user(bool $refresh = false): ?array
{
    static $cache = null;
    static $loaded = false;
    if ($refresh) {
        $loaded = false;
        $cache = null;
    }
    if ($loaded) {
        return $cache;
    }
    $loaded = true;
    if (empty($_SESSION['uid'])) {
        return $cache = null;
    }
    $life = (int)(app_config()['session_lifetime'] ?? 3600);
    if (time() - (int)($_SESSION['last_activity'] ?? 0) > $life) {
        logout_user();
        return $cache = null;
    }
    $u = db_row('SELECT id, username, full_name, email, role, is_active FROM users WHERE id = ?', [(int)$_SESSION['uid']]);
    if (!$u || !(int)$u['is_active']) {
        logout_user();
        return $cache = null;
    }
    $_SESSION['last_activity'] = time();
    return $cache = $u;
}

function can(string $permission): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    $map = permissions_map();
    return isset($map[$permission]) && in_array($u['role'], $map[$permission], true);
}

function require_login(): void
{
    if (!current_user()) {
        flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }
}

function require_permission(string $permission): void
{
    require_login();
    if (!can($permission)) {
        audit('access_denied', 'permission', null, $permission);
        http_response_code(403);
        $pageTitle = 'Access denied';
        $activeMenu = '';
        require __DIR__ . '/layout_header.php';
        echo '<div class="alert alert-danger"><i class="bi bi-shield-lock"></i> You do not have permission to perform this action.</div>';
        require __DIR__ . '/layout_footer.php';
        exit;
    }
}

/** @return array{0: bool, 1: string} */
function attempt_login(string $username, string $password): array
{
    static $dummy = null;
    $cfg = app_config();
    $generic = 'Invalid username or password, or the account is temporarily locked.';

    $u = db_row('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
    if (!$u) {
        $dummy ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
        password_verify($password, $dummy);   // keeps timing similar for unknown usernames
        audit('login_failed', 'user', null, 'unknown username');
        return [false, $generic];
    }
    $uid = (int)$u['id'];
    if (!empty($u['locked_until']) && strtotime($u['locked_until']) > time()) {
        audit('login_blocked', 'user', $uid, 'account locked', $uid);
        return [false, $generic];
    }
    if (!(int)$u['is_active'] || !password_verify($password, $u['password_hash'])) {
        $n = (int)$u['failed_logins'] + 1;
        if ($n >= (int)($cfg['max_failed_logins'] ?? 5)) {
            $until = date('Y-m-d H:i:s', time() + 60 * (int)($cfg['lockout_minutes'] ?? 15));
            db()->prepare('UPDATE users SET failed_logins = 0, locked_until = ? WHERE id = ?')->execute([$until, $uid]);
            audit('account_locked', 'user', $uid, 'too many failed logins', $uid);
        } else {
            db()->prepare('UPDATE users SET failed_logins = ? WHERE id = ?')->execute([$n, $uid]);
        }
        audit('login_failed', 'user', $uid, null, $uid);
        return [false, $generic];
    }

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
    }
    db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $uid]);

    session_regenerate_id(true);               // prevents session fixation
    $_SESSION['uid'] = $uid;
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf']);                  // fresh CSRF token for the new session
    current_user(true);
    audit('login', 'user', $uid, null, $uid);
    return [true, ''];
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);   // old session id becomes invalid; session stays usable for flash messages
    }
}
