<?php
/**
 * Admin auth: session, current user, CSRF token.
 * Sessions start only when the admin cookie is present, so anonymous visitors
 * never get a session (and pages stay cacheable for them).
 */

const VE_ADMIN_SESSION = 've_admin';

function ve_admin_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Start the admin session. Without $force it only resumes an existing one. */
function ve_admin_session(bool $force = false): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    if (!$force && empty($_COOKIE[VE_ADMIN_SESSION])) {
        return false;
    }
    session_name(VE_ADMIN_SESSION);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => ve_base_url() . '/',
        'httponly' => true,
        'secure'   => ve_admin_is_https(),
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
    return true;
}

function ve_admin_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (ve_admin_session() && !empty($_SESSION['ve_uid'])) {
        $row = q('SELECT id, email FROM users WHERE id = ?', [(int) $_SESSION['ve_uid']])->fetch();
        $user = $row ?: null;
    }
    return $user;
}

function ve_admin_login(string $email, string $password): ?array
{
    $row = q('SELECT id, email, password_hash FROM users WHERE email = ?', [trim($email)])->fetch();
    // Verify against a dummy hash when the user is unknown, so timing does not reveal accounts.
    $hash = $row['password_hash'] ?? password_hash(random_bytes(16), PASSWORD_DEFAULT);
    if (!password_verify($password, $hash) || !$row) {
        return null;
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    ve_admin_session(true);
    session_regenerate_id(true);
    $_SESSION = ['ve_uid' => (int) $row['id']];
    q('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?', [$row['id']]);
    return ['id' => (int) $row['id'], 'email' => $row['email']];
}

function ve_admin_logout(): void
{
    if (!ve_admin_session()) {
        return;
    }
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_destroy();
}

function ve_admin_csrf(): string
{
    ve_admin_session(true);
    if (empty($_SESSION['ve_csrf'])) {
        $_SESSION['ve_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['ve_csrf'];
}

function ve_admin_csrf_ok(?string $token): bool
{
    return ve_admin_session() && !empty($_SESSION['ve_csrf']) && is_string($token)
        && hash_equals($_SESSION['ve_csrf'], $token);
}
