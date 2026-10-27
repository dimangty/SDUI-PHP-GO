-- Removing retry/fencing metadata while jobs remain is unsafe; roll forward.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM password_session_operations WHERE state<>'COMPLETE') THEN
        RAISE EXCEPTION 'pending password session recovery; roll forward instead';
    END IF;
END $$;
DROP INDEX password_session_operations_due_idx;
ALTER TABLE password_session_operations
    DROP COLUMN recovery_attempts, DROP COLUMN next_retry_at, DROP COLUMN recovery_claim_until,
    DROP COLUMN operator_evidence_ref, DROP COLUMN operator_confirmed_at;
