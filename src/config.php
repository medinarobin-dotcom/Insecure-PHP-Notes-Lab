<?php
declare(strict_types=1);

/*
 * Configuration comes only from environment variables. Nothing secret is stored in the repository,
 * and there is no fallback to root / empty password (Issue 8).
 *
 *   LAB_DB_USER, LAB_DB_PASSWORD  required for MySQL
 *   LAB_DB_DSN                    optional, default mysql:host=127.0.0.1;dbname=notes_lab;charset=utf8mb4
 *   LAB_APP_ORIGIN                optional, the origin the app is served from (default http://127.0.0.1:8000)
 *   LAB_ALLOWED_ORIGINS           optional, comma-separated extra origins trusted by the API (default none)
 */

function env_string(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function app_origin(): string
{
    return rtrim((string)env_string('LAB_APP_ORIGIN', 'http://127.0.0.1:8000'), '/');
}

/** @return string[] origins allowed to call the API with credentials */
function allowed_origins(): array
{
    $extra = array_filter(array_map('trim', explode(',', (string)env_string('LAB_ALLOWED_ORIGINS', ''))));
    return array_values(array_unique(array_merge([app_origin()], array_map(fn($o) => rtrim($o, '/'), $extra))));
}
