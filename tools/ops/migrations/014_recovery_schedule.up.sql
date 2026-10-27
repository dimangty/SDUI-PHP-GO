-- Recovery metadata contains only scheduling state and an operator ticket ID.
ALTER TABLE password_session_operations
    ADD COLUMN recovery_attempts INTEGER NOT NULL DEFAULT 0 CHECK (recovery_attempts BETWEEN 0 AND 5),
    ADD COLUMN next_retry_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    ADD COLUMN recovery_claim_until TIMESTAMPTZ,
    ADD COLUMN operator_evidence_ref TEXT CHECK (operator_evidence_ref ~ '^[A-Z][A-Z0-9_]{1,31}-[1-9][0-9]{0,11}$'),
    ADD COLUMN operator_confirmed_at TIMESTAMPTZ;
CREATE INDEX password_session_operations_due_idx ON password_session_operations(next_retry_at,id)
    WHERE state='REVOKE_PENDING' AND recovery_attempts<5;
