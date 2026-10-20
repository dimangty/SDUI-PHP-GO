-- Старый глобальный UNIQUE(device_id) нельзя безопасно восстановить, если после
-- 009 одинаковый device_id появился у разных пользователей. Останавливаемся до
-- любых DDL-изменений вместо удаления одного из credentials.
DO $$
BEGIN
    IF EXISTS (
        SELECT device_id
        FROM device_biometrics
        GROUP BY device_id
        HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'cannot roll back migration 009: duplicate device_id values exist across users';
    END IF;
END
$$;

ALTER TABLE device_biometrics
    ADD COLUMN IF NOT EXISTS secure_hardware BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE biometric_challenges
    ADD COLUMN IF NOT EXISTS device_id VARCHAR(255);

UPDATE biometric_challenges AS challenge
SET device_id = biometric.device_id
FROM device_biometrics AS biometric
WHERE challenge.credential_id = biometric.id
  AND challenge.device_id IS NULL;

ALTER TABLE biometric_challenges
    ALTER COLUMN device_id SET NOT NULL;

ALTER TABLE biometric_challenges
    DROP CONSTRAINT IF EXISTS biometric_challenges_credential_id_fkey;

ALTER TABLE device_biometrics
    DROP CONSTRAINT IF EXISTS device_biometrics_user_device_key;

ALTER TABLE device_biometrics
    ADD CONSTRAINT device_biometrics_device_id_key UNIQUE (device_id);

ALTER TABLE biometric_challenges
    ADD CONSTRAINT biometric_challenges_device_id_fkey
    FOREIGN KEY (device_id) REFERENCES device_biometrics(device_id) ON DELETE CASCADE;

ALTER TABLE biometric_challenges
    DROP COLUMN IF EXISTS credential_id;

DROP INDEX IF EXISTS biometric_challenges_credential_idx;

ALTER TABLE device_biometrics
    DROP COLUMN IF EXISTS lock_count;
