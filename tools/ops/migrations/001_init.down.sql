-- Полностью удаляет исходную схему в порядке, безопасном для внешних ключей.
DROP TABLE IF EXISTS processed_webhooks, idempotency_keys, device_pins, reviews, order_items CASCADE;
ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_fk;
DROP TABLE IF EXISTS payments, orders, cart_items CASCADE;
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_default_address_fk;
DROP TABLE IF EXISTS addresses, product_images, products, categories, users CASCADE;
