-- Закрепляет нормализованные orders/payments как источник истины и добавляет
-- служебные метаданные завершённого bootstrap.
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();

CREATE INDEX IF NOT EXISTS payments_status_updated_idx
    ON payments(status, updated_at);

CREATE TABLE IF NOT EXISTS repository_metadata (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
