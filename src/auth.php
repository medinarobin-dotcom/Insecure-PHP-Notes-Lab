<?php
declare(strict_types=1);

/*
 * Accounts and login sessions (Issues 1, 2, 6, 7).
 */

const DEFAULT_ROLE = 'student';
const PASSWORD_MIN_LENGTH = 12;
const PASSWORD_MAX_BYTES = 72; // bcrypt (PASSWORD_DEFAULT) ignores bytes past 72, so reject longer input

function current_user_id(): ?int
{
    $id = $_SESSION['user_id'] ?? null;
    return is_int($id) && $id > 0 ? $id : null;
}

function current_role(): string
{
    return is_string($_SESSION['role'] ?? null) ? $_SESSION['role'] : DEFAULT_ROLE;
}

/** @return string|null error message, or null on success */
function register_user(mixed $email, mixed $password): ?string
{
    if (!is_string($email) || !is_string($password)) {
        return 'Please provide an email and password.';
    }
    $email = trim($email);
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return 'Please enter a valid email address.';
    }
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH || strlen($password) > PASSWORD_MAX_BYTES) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters and at most ' . PASSWORD_MAX_BYTES . ' bytes.';
    }

    // Role is never taken from the request: every new account is a student (Issue 7).
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = db()->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
        $stmt->execute([$email, $hash, DEFAULT_ROLE]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {           // duplicate email (unique constraint)
            return 'Could not create that account.';
        }
        throw $e;
    }
    return null;
}

/** Returns true when the credentials are valid and a fresh session has been started. */
function attempt_login(mixed $email, mixed $password): bool
{
    if (!is_string($email) || !is_string($password) || strlen($password) > PASSWORD_MAX_BYTES) {
        return false;
    }
    $stmt = db()->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();

    // Verify against a dummy hash when the email is unknown so timing does not reveal which emails exist.
    // (hash of a random value nobody knows; it never matches)
    $hash = $user['password_hash'] ?? '$2y$10$naVyY8rYbhB1B.uckeiIPOoPTluvr9BL.O0Mk6JIu9jRu4uolxBlW';
    if (!password_verify($password, $hash) || !$user) {
        return false;
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);                   // new ID on privilege change: blocks session fixation
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = (string)$user['role'];     // role comes from the database, not the request
    unset($_SESSION['csrf_token']);                // fresh token for the authenticated session
    return true;
}

/** Fully end the session: clear data, expire the cookie, destroy server-side state (Issue 6). */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}
