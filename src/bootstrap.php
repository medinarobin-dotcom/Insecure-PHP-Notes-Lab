<?php
declare(strict_types=1);

/*
 * Shared start-up for index.php and api.php.
 * - Errors are logged, never displayed (Issue 8).
 * - Session cookie is hardened before the session starts (Issue 6).
 * - Loads the other responsibilities (db, auth, csrf, notes, view helpers).
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/notes.php';
require __DIR__ . '/view.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');   // reject unknown (attacker-chosen) session IDs
    ini_set('session.use_only_cookies', '1');  // no session ID in URLs
    ini_set('session.use_trans_sid', '0');
    session_name('CAMPUSNOTESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),               // true automatically once served over HTTPS
        'httponly' => true,                     // JavaScript cannot read the cookie
        'samesite' => 'Lax',                    // cookie not sent on cross-site POST/fetch
    ]);
    session_start();
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** Log a sanitized diagnostic: exception class, file and line only (no SQL, values or credentials). */
function log_exception(Throwable $e): void
{
    error_log(sprintf('[campus-notes] %s in %s:%d', get_class($e), basename($e->getFile()), $e->getLine()));
}
