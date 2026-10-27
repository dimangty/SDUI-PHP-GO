-- Downgrade cannot resurrect revoked tokens. Reauthentication remains required.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM password_session_operations WHERE state <> 'COMPLETE') THEN
        RAISE EXCEPTION 'pending password session recovery prevents downgrade';
    END IF;
END $$;
DELETE FROM access_sessions;
DELETE FROM refresh_sessions;
ALTER TABLE access_sessions DROP CONSTRAINT access_session_family_fk, DROP COLUMN family_id;
ALTER TABLE refresh_sessions DROP CONSTRAINT refresh_session_family_fk, DROP COLUMN family_id;
DROP TABLE password_session_operations;
DROP TABLE session_families;
ALTER TABLE users DROP COLUMN sessions_blocked, DROP COLUMN session_security_version;
