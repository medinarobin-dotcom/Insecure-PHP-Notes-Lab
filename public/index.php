<?php
declare(strict_types=1);

/*
 * Web front controller: handles POST actions, then renders templates/home.php.
 * All state changes are POST + CSRF token (Issue 5). Owner is always the session user (Issues 3, 7).
 */

require __DIR__ . '/../src/bootstrap.php';

send_html_security_headers();

try {
    start_secure_session();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><title>Request rejected</title>'
                . '<p>Your form expired or was not sent from this site. <a href="/">Go back</a> and try again.</p>';
            exit;
        }
        handle_post((string)($_POST['action'] ?? ''));
        redirect_home();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }

    $userId = current_user_id();
    $editing = null;
    if ($userId !== null && isset($_GET['edit'])) {
        $noteId = parse_id($_GET['edit']);
        $editing = $noteId === null ? null : find_note_for_user($noteId, $userId);
        if ($editing === null) {
            flash('Note not found.');
        }
    }

    render('home', [
        'userId'  => $userId,
        'role'    => current_role(),
        'notes'   => $userId === null ? [] : notes_for_user($userId),
        'editing' => $editing,
        'message' => take_flash(),
        'csrf'    => csrf_token(),
    ]);
} catch (Throwable $e) {
    log_exception($e);
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>Something went wrong. Please try again later.</p>';
}

function handle_post(string $action): void
{
    switch ($action) {
        case 'register':
            $error = register_user($_POST['email'] ?? null, $_POST['password'] ?? null);
            flash($error ?? 'Account created. You can sign in now.');
            return;

        case 'login':
            if (!attempt_login($_POST['email'] ?? null, $_POST['password'] ?? null)) {
                flash('Invalid email or password.');   // generic; does not echo the email back
            }
            return;
    }

    $userId = current_user_id();
    if ($userId === null) {
        http_response_code(401);
        flash('Please sign in.');
        return;
    }

    switch ($action) {
        case 'logout':
            logout();
            start_secure_session();
            flash('You have been logged out.');
            return;

        case 'save':
            [$title, $body, $error] = validate_note($_POST['title'] ?? null, $_POST['body'] ?? null);
            if ($error !== null) {
                flash($error);
                return;
            }
            create_note($userId, $title, $body);      // owner = session user; any posted user_id is ignored
            flash('Note saved.');
            return;

        case 'update':
            $noteId = parse_id($_POST['id'] ?? null);
            [$title, $body, $error] = validate_note($_POST['title'] ?? null, $_POST['body'] ?? null);
            if ($noteId === null || $error !== null) {
                flash($error ?? 'Note not found.');
                return;
            }
            flash(update_note($noteId, $userId, $title, $body) ? 'Note updated.' : 'Note not found.');
            return;

        case 'delete':
            $noteId = parse_id($_POST['id'] ?? null);
            flash($noteId !== null && delete_note($noteId, $userId) ? 'Note deleted.' : 'Note not found.');
            return;

        default:
            http_response_code(400);
            flash('Unknown action.');
    }
}
