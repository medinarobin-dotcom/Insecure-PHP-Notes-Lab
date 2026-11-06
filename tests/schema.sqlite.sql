-- SQLite copy of schema.sql, used only by tests/run_checks.sh when no MySQL server is available.
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'student'
);
CREATE TABLE notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL
);
INSERT INTO users (id, email, password_hash, role) VALUES
(1, 'alex@example.test', '$2y$10$BRpym9ghUfCJzmVMZAYuEOZaK7EQnfim7i99xmj8AqwY2epoC7rAq', 'student'),
(2, 'sam@example.test',  '$2y$10$JGzqXi4VhQTn28zThVh7uOs41tzwE/SFc1RascUlqDTn6vhGNw0vS', 'student');
INSERT INTO notes (user_id, title, body) VALUES
(1, 'Alex: course reminder', 'Review the security reading before class.'),
(1, 'Alex: project idea', 'Build a small student directory.'),
(2, 'Sam: study plan', 'Compare authentication and authorization.'),
(2, 'Sam: meeting', 'Prepare questions for the next group discussion.');
