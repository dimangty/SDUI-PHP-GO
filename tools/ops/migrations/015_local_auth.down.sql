-- Password changes/revocations cannot safely be rolled back to remote identity.
-- Restore data only under a separately approved identity remigration procedure.
DO $$ BEGIN RAISE EXCEPTION 'Local identity cutover is forward-only; preserve passwords and revocations'; END $$;
