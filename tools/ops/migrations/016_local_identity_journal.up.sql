-- Operator evidence only: no identity/credential/business rows are rewritten.
-- A ticket identifies ONE action for ONE UUID, not a reusable batch identifier.
CREATE TABLE local_identity_migration_journal (
    ticket TEXT PRIMARY KEY CHECK (ticket ~ '^[A-Z][A-Z0-9_]{1,31}-[1-9][0-9]{0,11}$'),
    user_id UUID NOT NULL REFERENCES users(id),
    action TEXT NOT NULL CHECK (action IN ('attest','reset-password','set-disabled')),
    request JSONB NOT NULL CHECK (jsonb_typeof(request) = 'object'),
    security_version BIGINT NOT NULL CHECK (security_version >= 0),
    is_admin BOOLEAN NOT NULL,
    account_disabled BOOLEAN NOT NULL,
    local_identity_ready BOOLEAN NOT NULL,
    sessions_blocked BOOLEAN NOT NULL,
    applied_by TEXT NOT NULL DEFAULT session_user,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX local_identity_migration_user_idx ON local_identity_migration_journal(user_id,applied_at);
REVOKE ALL ON local_identity_migration_journal FROM PUBLIC;

CREATE FUNCTION local_identity_journal_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Local identity evidence is append-only; use a new reviewed ticket';
END;
$$;
CREATE TRIGGER local_identity_journal_immutable
    BEFORE UPDATE OR DELETE OR TRUNCATE ON local_identity_migration_journal
    FOR EACH STATEMENT EXECUTE FUNCTION local_identity_journal_immutable();
REVOKE ALL ON FUNCTION local_identity_journal_immutable() FROM PUBLIC;
