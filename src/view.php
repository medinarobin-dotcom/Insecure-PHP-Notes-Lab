<?php
declare(strict_types=1);

/** HTML-escape for element content and quoted attributes (Issue 4). */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function take_flash(): string
{
    $message = is_string($_SESSION['flash'] ?? null) ? $_SESSION['flash'] : '';
    unset($_SESSION['flash']);
    return $message;
}

function redirect_home(): never
{
    header('Location: /', true, 303);   // 303: browser follows with GET (post/redirect/get)
    exit;
}

/** Render a template from /templates with only the variables passed in. */
function render(string $template, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../templates/' . $template . '.php';
}

function send_html_security_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
}
