-- Дополняет нормализованную runtime-схему датой рождения, URL провайдера
-- платежа и метаданными времени изменения корзины.
ALTER TABLE users ADD COLUMN IF NOT EXISTS date_of_birth DATE;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS gateway_url TEXT;

CREATE TABLE IF NOT EXISTS cart_metadata (
    user_id UUID PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    updated_at TIMESTAMPTZ NOT NULL
);
