-- Хранит только публичные ключи устройств и короткоживущие одноразовые
-- challenge. Биометрические шаблоны и приватные ключи остаются на устройстве.
CREATE TABLE IF NOT EXISTS device_biometrics (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    device_id VARCHAR(255) NOT NULL UNIQUE,
    device_name VARCHAR(255) NOT NULL,
    public_key BYTEA NOT NULL,
    key_algorithm VARCHAR(16) NOT NULL CHECK (key_algorithm IN ('ES256', 'EdDSA')),
    secure_hardware BOOLEAN NOT NULL DEFAULT FALSE,
    failed_attempts INT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
    locked_until TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS biometric_challenges (
    challenge VARCHAR(128) PRIMARY KEY,
    device_id VARCHAR(255) NOT NULL REFERENCES device_biometrics(device_id) ON DELETE CASCADE,
    expires_at TIMESTAMPTZ NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS device_biometrics_user_last_used_idx
    ON device_biometrics(user_id, last_used_at DESC);
CREATE INDEX IF NOT EXISTS biometric_challenges_expiry_idx
    ON biometric_challenges(expires_at);
