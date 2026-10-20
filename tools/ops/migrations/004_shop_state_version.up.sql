-- Добавляет монотонную версию compatibility-state для контроля изменений.
ALTER TABLE shop_state
    ADD COLUMN IF NOT EXISTS version BIGINT NOT NULL DEFAULT 1;
