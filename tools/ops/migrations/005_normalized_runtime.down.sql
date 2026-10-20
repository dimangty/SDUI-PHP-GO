-- Откатывает дополнительные поля нормализованного runtime.
DROP TABLE IF EXISTS cart_metadata;
ALTER TABLE IF EXISTS payments DROP COLUMN IF EXISTS gateway_url;
ALTER TABLE IF EXISTS users DROP COLUMN IF EXISTS date_of_birth;
