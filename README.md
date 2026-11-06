# Campus Notes: security review project (repaired)

This started as intentionally vulnerable educational material. The repaired version fixes the
eleven issues in the laboratory handout, but it is still a lab project: run it only on an isolated
machine you control, with synthetic data and a disposable local database. Never expose it to the
internet or connect it to real accounts.

## Layout

```
Insecure-PHP-Notes-Lab/
├── public/                 web root (the only folder the server exposes)
│   ├── index.php           web front controller: POST actions + page render
│   ├── api.php             JSON API (auth, per-record checks, CORS allowlist, CSRF, validation)
│   └── assets/style.css    styles (moved out of the page so CSP can forbid inline code)
├── src/
│   ├── bootstrap.php       error settings, hardened session start, loads the files below
│   ├── config.php          reads settings from environment variables
│   ├── db.php              PDO connection, fails closed without credentials
│   ├── auth.php            register / login / logout with password_hash and session rotation
│   ├── csrf.php            per-session CSRF token helpers
│   ├── notes.php           owner-scoped note queries + input validation
│   └── view.php            escaping, flash messages, redirects, security headers, render()
├── templates/home.php      HTML template; every value escaped with e()
├── tests/
│   ├── run_checks.sh       black-box security checks with curl
│   └── schema.sqlite.sql   SQLite copy of the schema for running the checks without MySQL
├── schema.sql              MySQL schema + synthetic seed data (bcrypt hashes)
└── README.md
```

No Composer packages are used.

## Local setup

1. Install a supported PHP 8 release (8.1+) with `pdo_mysql` and a local MySQL 8 server.
   Confirm `pdo_mysql` appears in `php -m`.
2. As a local MySQL administrator, import `schema.sql` into a fresh disposable server/database.
   If you imported the old starter schema, drop `notes_lab` first: the seed passwords now use
   `password_hash()` (bcrypt) instead of MD5.
3. Create a dedicated local MySQL account with SELECT, INSERT, UPDATE and DELETE only on `notes_lab`:
   ```sql
   CREATE USER 'notes_app'@'localhost' IDENTIFIED BY '<choose a local throwaway password>';
   GRANT SELECT, INSERT, UPDATE, DELETE ON notes_lab.* TO 'notes_app'@'localhost';
   ```
   Set the credentials in your shell (never commit them). PowerShell:
   ```powershell
   $env:LAB_DB_USER = 'notes_app'
   $env:LAB_DB_PASSWORD = '<your local password>'
   ```
   bash: `export LAB_DB_USER=notes_app LAB_DB_PASSWORD='<your local password>'`.
   The app refuses to start a MySQL connection if these are missing (no `root` / empty-password fallback).
4. From this folder, serve only the `public` folder:
   ```
   php -S 127.0.0.1:8000 -t public
   ```
   Visit `http://127.0.0.1:8000`. If you use a different host or port, set `LAB_APP_ORIGIN`
   (for example `http://localhost:8080`) so the API accepts same-origin requests.
5. Synthetic accounts (public, throwaway, lab only):
   - `alex@example.test` / `LabOnly-Alex-123`
   - `sam@example.test` / `LabOnly-Sam-456`

   Use separate browser profiles for separate sessions. New accounts are always `student` and need a
   password of at least 12 characters.

## API

All requests need a signed-in session cookie.

- `GET /api.php` lists the signed-in user's notes. `GET /api.php?id=N` returns one of them.
  `?user_id=` for any other user is refused with 403.
- `POST /api.php` updates one of the signed-in user's notes. Requirements:
  `Content-Type: application/json`, header `X-CSRF-Token` with the value of the page's
  `<meta name="csrf-token">`, and a body of exactly `{"id": <int>, "title": "...", "body": "..."}`.
- Requests whose `Origin` is not `LAB_APP_ORIGIN` (or listed in `LAB_ALLOWED_ORIGINS`,
  comma-separated) are refused with 403.

## Running the checks

With the server running against a freshly imported database:

```
BASE=http://127.0.0.1:8000 bash tests/run_checks.sh
```

To also test error handling, start a second instance without credentials on another port
(for example `env -u LAB_DB_USER -u LAB_DB_PASSWORD php -S 127.0.0.1:8001 -t public`) and add
`ERR_BASE=http://127.0.0.1:8001`.

To run without MySQL, create a SQLite file from `tests/schema.sqlite.sql` and start the server with
`LAB_DB_DSN=sqlite:/path/to/lab.sqlite`. The checks were run this way; see the report for what that
does and does not cover.

## Production notes

Local HTTP and PHP's built-in server are for development only. A real deployment needs HTTPS (the
session cookie becomes `Secure` automatically), a real web server pointed at `public/`, secrets in a
managed store, `log_errors` to a protected file, and login rate limiting (not implemented here).
