-- Удаляет версию compatibility-state.
ALTER TABLE shop_state DROP COLUMN IF EXISTS version;
