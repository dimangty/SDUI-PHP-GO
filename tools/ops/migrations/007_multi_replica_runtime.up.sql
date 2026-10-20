-- Хранит только SHA-256 digest access/refresh токенов в общих таблицах,
-- обеспечивая отзыв сессий между несколькими backend-репликами.
CREATE TABLE IF NOT EXISTS access_sessions (
    token_hash BYTEA PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS refresh_sessions (
    token_hash BYTEA PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS access_sessions_user_idx ON access_sessions(user_id);
CREATE INDEX IF NOT EXISTS access_sessions_expiry_idx ON access_sessions(expires_at);
CREATE INDEX IF NOT EXISTS refresh_sessions_user_idx ON refresh_sessions(user_id);
CREATE INDEX IF NOT EXISTS refresh_sessions_expiry_idx ON refresh_sessions(expires_at);
