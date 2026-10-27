-- Roll-forward-only. Old writers can replay initiated PENDING payments even
-- after a nominal drain, and dropped send/callback/stock evidence is irrecoverable.
-- Refuse before any DDL/DML, including for an empty or fully settled database.
DO $$
BEGIN
    RAISE EXCEPTION USING
        ERRCODE = '0A000',
        MESSAGE = '012 payment operations is roll-forward-only; downgrade refused to preserve payment audit and send fences';
END
$$;
