-- Публичный biometric login адресуется по серверному UUID credential, а не по
-- выбираемому клиентом device_id. Это позволяет одному device_id безопасно
-- существовать у разных пользователей и исключает глобальный namespace squatting.
ALTER TABLE device_biometrics
    ADD COLUMN IF NOT EXISTS lock_count INT NOT NULL DEFAULT 0 CHECK (lock_count >= 0);

ALTER TABLE biometric_challenges
    ADD COLUMN IF NOT EXISTS credential_id UUID;

UPDATE biometric_challenges AS challenge
SET credential_id = biometric.id
FROM device_biometrics AS biometric
WHERE challenge.device_id = biometric.device_id
  AND challenge.credential_id IS NULL;

ALTER TABLE biometric_challenges
    ALTER COLUMN credential_id SET NOT NULL;

ALTER TABLE biometric_challenges
    DROP CONSTRAINT IF EXISTS biometric_challenges_device_id_fkey;

ALTER TABLE biometric_challenges
    ADD CONSTRAINT biometric_challenges_credential_id_fkey
    FOREIGN KEY (credential_id) REFERENCES device_biometrics(id) ON DELETE CASCADE;

ALTER TABLE biometric_challenges
    DROP COLUMN IF EXISTS device_id;

ALTER TABLE device_biometrics
    DROP CONSTRAINT IF EXISTS device_biometrics_device_id_key;

ALTER TABLE device_biometrics
    ADD CONSTRAINT device_biometrics_user_device_key UNIQUE (user_id, device_id);

-- Сервер не выполняет Android/iOS attestation, поэтому не публикует и не
-- хранит недостоверный признак аппаратной защиты.
ALTER TABLE device_biometrics
    DROP COLUMN IF EXISTS secure_hardware;

CREATE INDEX IF NOT EXISTS biometric_challenges_credential_idx
    ON biometric_challenges(credential_id);
