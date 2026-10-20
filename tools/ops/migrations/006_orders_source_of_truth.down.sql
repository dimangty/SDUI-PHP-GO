-- Удаляет метаданные bootstrap и время обновления платежа.
DROP INDEX IF EXISTS payments_status_updated_idx;
ALTER TABLE IF EXISTS payments DROP COLUMN IF EXISTS updated_at;
DROP TABLE IF EXISTS repository_metadata;
