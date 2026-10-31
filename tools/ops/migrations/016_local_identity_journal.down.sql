-- Operator decisions, password changes and revocations cannot be undone by DDL.
DO $$
BEGIN
    RAISE EXCEPTION '016 local identity migration is forward-only; retain operator evidence and roll forward';
END;
$$;
