-- Создаёт исходную нормализованную схему магазина, ограничения целостности
-- и индексы основных пользовательских запросов.
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), email VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL, full_name VARCHAR(255) NOT NULL, phone VARCHAR(32) NOT NULL,
    avatar_url TEXT, default_address_id UUID, is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE categories (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), name VARCHAR(255) NOT NULL,
    parent_id UUID REFERENCES categories(id) ON DELETE SET NULL, icon_url TEXT NOT NULL DEFAULT '', sort_order INT NOT NULL DEFAULT 0
);
CREATE TABLE products (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), category_id UUID NOT NULL REFERENCES categories(id),
    name VARCHAR(255) NOT NULL, description TEXT NOT NULL DEFAULT '', price NUMERIC(12,2) NOT NULL CHECK(price > 0),
    discount_price NUMERIC(12,2), stock INT NOT NULL DEFAULT 0 CHECK(stock >= 0), rating DOUBLE PRECISION,
    is_active BOOLEAN NOT NULL DEFAULT TRUE, sold_last_30_days INT NOT NULL DEFAULT 0, created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE product_images (id BIGSERIAL PRIMARY KEY, product_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE, url TEXT NOT NULL, sort_order INT NOT NULL DEFAULT 0);
CREATE TABLE addresses (id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE, city VARCHAR(255) NOT NULL, street VARCHAR(255) NOT NULL, house VARCHAR(64) NOT NULL, apartment VARCHAR(64) NOT NULL DEFAULT '', comment TEXT);
ALTER TABLE users ADD CONSTRAINT users_default_address_fk FOREIGN KEY(default_address_id) REFERENCES addresses(id) ON DELETE SET NULL;
CREATE TABLE cart_items (user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE, product_id UUID NOT NULL REFERENCES products(id), quantity INT NOT NULL CHECK(quantity > 0), price_snapshot NUMERIC(12,2) NOT NULL, PRIMARY KEY(user_id, product_id));
CREATE TABLE orders (id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), number BIGSERIAL UNIQUE, user_id UUID NOT NULL REFERENCES users(id), status VARCHAR(32) NOT NULL, total_amount NUMERIC(12,2) NOT NULL, delivery_address_id UUID NOT NULL REFERENCES addresses(id), delivery_address TEXT NOT NULL, payment_id UUID, payment_method VARCHAR(32) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), comment TEXT);
CREATE TABLE order_items (id BIGSERIAL PRIMARY KEY, order_id UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE, product_id UUID NOT NULL, product_name VARCHAR(255) NOT NULL, quantity INT NOT NULL, price_snapshot NUMERIC(12,2) NOT NULL);
CREATE TABLE payments (id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), order_id UUID NOT NULL UNIQUE REFERENCES orders(id), user_id UUID NOT NULL REFERENCES users(id), method VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, amount NUMERIC(12,2) NOT NULL, transaction_id VARCHAR(255));
ALTER TABLE orders ADD CONSTRAINT orders_payment_fk FOREIGN KEY(payment_id) REFERENCES payments(id) ON DELETE SET NULL;
CREATE TABLE reviews (id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), user_id UUID NOT NULL REFERENCES users(id), product_id UUID NOT NULL REFERENCES products(id), rating INT NOT NULL CHECK(rating BETWEEN 1 AND 5), text VARCHAR(2000) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), UNIQUE(user_id, product_id));
CREATE TABLE device_pins (id UUID PRIMARY KEY DEFAULT uuid_generate_v4(), user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE, device_id VARCHAR(255) NOT NULL UNIQUE, device_name VARCHAR(255) NOT NULL, pin_hash TEXT NOT NULL, failed_attempts INT NOT NULL DEFAULT 0, lock_count INT NOT NULL DEFAULT 0, locked_until TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), last_used_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE idempotency_keys (user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE, endpoint VARCHAR(100) NOT NULL, key VARCHAR(255) NOT NULL, response JSONB NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT now(), PRIMARY KEY(user_id, endpoint, key));
CREATE TABLE processed_webhooks (id VARCHAR(255) PRIMARY KEY, processed_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX products_category_idx ON products(category_id) WHERE is_active;
CREATE INDEX orders_user_created_idx ON orders(user_id, created_at DESC);
CREATE INDEX reviews_product_created_idx ON reviews(product_id, created_at DESC);
