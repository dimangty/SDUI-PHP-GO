-- Блокировка входа хранится в нормализованном агрегате каждого пользователя.
-- shop_state остаётся только источником импорта старых данных и не используется
-- при обработке запросов.
CREATE TABLE IF NOT EXISTS login_attempts (
    user_id UUID PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    failed_attempts INT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
    locked_until TIMESTAMPTZ,
    last_attempt TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS login_attempts_locked_idx
    ON login_attempts(locked_until)
    WHERE locked_until IS NOT NULL;

INSERT INTO repository_metadata(key,value)
VALUES('runtime_storage','normalized-v1')
ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,updated_at=now();
