<?php
declare(strict_types=1);

/*
 * JSON API (Issues 9, 10, 11).
 *
 *   GET  /api.php            -> the signed-in user's notes
 *   GET  /api.php?id=N       -> one of the signed-in user's notes
 *   POST /api.php            -> update one of the signed-in user's notes
 *        Content-Type: application/json
 *        X-CSRF-Token: <token from the page's <meta name="csrf-token">>
 *        {"id": 1, "title": "...", "body": "..."}
 *
 * Requests from an Origin outside the allowlist are refused, ACAO is never reflected,
 * and POST needs a CSRF header (a custom header also forces a CORS preflight cross-origin).
 */

require __DIR__ . '/../src/bootstrap.php';

const API_MAX_BODY_BYTES = 16384;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Vary: Origin');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // ---- Cross-origin policy (Issue 11) ----
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null) {
        if (!in_array(rtrim($origin, '/'), allowed_origins(), true)) {
            respond(403, ['error' => 'Origin not allowed']);
        }
        header('Access-Control-Allow-Origin: ' . rtrim($origin, '/'));   // exact allowlisted origin only
        header('Access-Control-Allow-Credentials: true');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        exit;
    }

    start_secure_session();

    // ---- Authentication (Issue 9) ----
    $userId = current_user_id();
    if ($userId === null) {
        respond(401, ['error' => 'Authentication required']);
    }

    if ($method === 'GET') {
        // The owner is the session user. A user_id parameter for anyone else is refused.
        if (isset($_GET['user_id']) && parse_id($_GET['user_id']) !== $userId) {
            respond(403, ['error' => 'Forbidden']);
        }
        if (isset($_GET['id'])) {
            $noteId = parse_id($_GET['id']);
            $note = $noteId === null ? null : find_note_for_user($noteId, $userId);
            $note === null ? respond(404, ['error' => 'Note not found']) : respond(200, ['note' => $note]);
        }
        respond(200, ['notes' => notes_for_user($userId)]);
    }

    if ($method === 'POST') {
        // ---- CSRF (Issue 11) ----
        if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            respond(403, ['error' => 'Invalid or missing CSRF token']);
        }

        // ---- Request validation (Issue 10) ----
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            respond(415, ['error' => 'Content-Type must be application/json']);
        }
        $raw = file_get_contents('php://input', false, null, 0, API_MAX_BODY_BYTES + 1);
        if ($raw === false || strlen($raw) > API_MAX_BODY_BYTES) {
            respond(413, ['error' => 'Request body too large']);
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            respond(400, ['error' => 'Malformed JSON']);
        }
        if (!is_array($data) || array_is_list($data)) {
            respond(400, ['error' => 'Expected a JSON object']);
        }
        $unknown = array_diff(array_keys($data), ['id', 'title', 'body']);
        if ($unknown) {
            respond(400, ['error' => 'Unexpected fields']);
        }
        $noteId = parse_id($data['id'] ?? null);
        if ($noteId === null) {
            respond(400, ['error' => 'id must be a positive integer']);
        }
        [$title, $body, $error] = validate_note($data['title'] ?? null, $data['body'] ?? null);
        if ($error !== null) {
            respond(422, ['error' => $error]);
        }

        // ---- Per-record authorization (Issue 9) ----
        if (!update_note($noteId, $userId, $title, $body)) {
            respond(404, ['error' => 'Note not found']);   // same answer for "missing" and "not yours"
        }
        respond(200, ['updated' => true, 'id' => $noteId]);
    }

    header('Allow: GET, POST, OPTIONS');
    respond(405, ['error' => 'Method not supported']);
} catch (Throwable $e) {
    log_exception($e);
    respond(500, ['error' => 'Internal server error']);
}
