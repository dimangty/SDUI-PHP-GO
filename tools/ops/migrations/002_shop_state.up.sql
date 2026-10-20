-- Исторический pgx/sqlc repository сохраняет здесь compatibility-state.
-- Domain mutation сериализуются advisory lock PostgreSQL и фиксируются одной
-- транзакцией; нормализованные таблицы из 001 остаются доступны для отчётов.
CREATE TABLE shop_state (
    id SMALLINT PRIMARY KEY CHECK (id = 1),
    payload JSONB NOT NULL,
    version BIGINT NOT NULL DEFAULT 1,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
