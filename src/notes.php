<?php
declare(strict_types=1);

/*
 * Note data access. Every query is a prepared statement (Issue 1) and every
 * read or write is scoped to the owner taken from the session (Issues 3, 7, 9).
 * No function accepts an owner ID from the request.
 */

const NOTE_TITLE_MAX = 120;   // matches notes.title VARCHAR(120)
const NOTE_BODY_MAX  = 10000;

/** @return array<int, array{id:int,title:string,body:string}> */
function notes_for_user(int $userId): array
{
    $stmt = db()->prepare('SELECT id, title, body FROM notes WHERE user_id = ? ORDER BY id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function find_note_for_user(int $noteId, int $userId): ?array
{
    $stmt = db()->prepare('SELECT id, title, body FROM notes WHERE id = ? AND user_id = ?');
    $stmt->execute([$noteId, $userId]);
    $note = $stmt->fetch();
    return $note ?: null;
}

function create_note(int $userId, string $title, string $body): void
{
    $stmt = db()->prepare('INSERT INTO notes (user_id, title, body) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $title, $body]);
}

/** Returns false when the note does not exist or belongs to someone else. */
function update_note(int $noteId, int $userId, string $title, string $body): bool
{
    // Check ownership first: MySQL rowCount() is 0 when values are unchanged, so it cannot signal "not yours".
    if (find_note_for_user($noteId, $userId) === null) {
        return false;
    }
    $stmt = db()->prepare('UPDATE notes SET title = ?, body = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$title, $body, $noteId, $userId]);
    return true;
}

function delete_note(int $noteId, int $userId): bool
{
    $stmt = db()->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
    $stmt->execute([$noteId, $userId]);
    return $stmt->rowCount() === 1;
}

/** Positive integer ID or null. */
function parse_id(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (!is_string($value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

/**
 * Validate title/body (Issue 10). Returns [title, body, null] or [null, null, error].
 * @return array{0:?string,1:?string,2:?string}
 */
function validate_note(mixed $title, mixed $body): array
{
    if (!is_string($title) || !is_string($body)) {
        return [null, null, 'Title and body must be text.'];
    }
    $title = trim($title);
    if ($title === '' || trim($body) === '') {
        return [null, null, 'Title and body are required.'];
    }
    if (!mb_check_encoding($title, 'UTF-8') || !mb_check_encoding($body, 'UTF-8')) {
        return [null, null, 'Text must be valid UTF-8.'];
    }
    if (mb_strlen($title) > NOTE_TITLE_MAX) {
        return [null, null, 'Title must be at most ' . NOTE_TITLE_MAX . ' characters.'];
    }
    if (mb_strlen($body) > NOTE_BODY_MAX) {
        return [null, null, 'Body must be at most ' . NOTE_BODY_MAX . ' characters.'];
    }
    return [$title, $body, null];
}
