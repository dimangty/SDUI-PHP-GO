-- Сначала удаляет дочерние challenge, затем credentials устройств, чтобы
-- откат не нарушал внешний ключ biometric_challenges.device_id.
DROP TABLE IF EXISTS biometric_challenges;
DROP TABLE IF EXISTS device_biometrics;
