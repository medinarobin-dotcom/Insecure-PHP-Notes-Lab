<?php
declare(strict_types=1);

/** One shared PDO connection with safe defaults. Fails closed when credentials are missing (Issue 8). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn  = (string)env_string('LAB_DB_DSN', 'mysql:host=127.0.0.1;dbname=notes_lab;charset=utf8mb4');
    $user = env_string('LAB_DB_USER');
    $pass = env_string('LAB_DB_PASSWORD');

    if (str_starts_with($dsn, 'mysql:') && ($user === null || $pass === null)) {
        throw new RuntimeException('Database credentials are not configured.');
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // real server-side prepared statements (Issue 1)
    ]);
    return $pdo;
}
