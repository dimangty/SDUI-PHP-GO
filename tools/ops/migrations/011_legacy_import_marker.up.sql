-- Фиксирует наличие исторических данных, пока старая таблица доступна миграциям.
-- Рабочий код читает только repository_metadata.
INSERT INTO repository_metadata(key, value)
SELECT 'legacy_import_status', 'required'
WHERE EXISTS (SELECT 1 FROM shop_state WHERE id = 1)
  AND NOT EXISTS (
      SELECT 1 FROM repository_metadata WHERE key = 'normalized_bootstrapped'
  )
ON CONFLICT(key) DO UPDATE
SET value = EXCLUDED.value, updated_at = now();
