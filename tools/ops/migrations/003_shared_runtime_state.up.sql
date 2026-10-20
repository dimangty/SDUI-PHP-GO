-- Выносит файлы аватаров и распределённые окна rate limit в PostgreSQL, чтобы
-- они переживали рестарт и были общими для нескольких backend-реплик.
CREATE TABLE avatar_files (
    path TEXT PRIMARY KEY,
    content_type TEXT NOT NULL,
    data BYTEA NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE rate_limit_hits (
    bucket_key TEXT NOT NULL,
    occurred_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX rate_limit_hits_key_time_idx ON rate_limit_hits(bucket_key, occurred_at);
CREATE INDEX rate_limit_hits_time_idx ON rate_limit_hits(occurred_at);
