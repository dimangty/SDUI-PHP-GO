-- Maintenance-window trust cutover. Existing identity state requires individual
-- operator review; no credentials, roles or account state are guessed/imported.
ALTER TABLE users ADD COLUMN account_disabled BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN local_identity_ready BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMPTZ;
-- Future local registrations/trusted SQL provisioning are local identities.
-- Old writers MUST be drained before this incompatible trust transition.
ALTER TABLE users ALTER COLUMN local_identity_ready SET DEFAULT TRUE;

-- Conflicts fail this transaction. Never merge/rename users automatically.
CREATE UNIQUE INDEX users_normalized_email_idx ON users(lower(btrim(email)));

ALTER TABLE session_families DROP CONSTRAINT session_families_credential_kind_check;
ALTER TABLE session_families DROP CONSTRAINT session_families_check;
ALTER TABLE session_families ADD CONSTRAINT session_families_credential_kind_check
    CHECK (credential_kind IN ('internal','password','pin','biometric'));
ALTER TABLE session_families ADD CONSTRAINT session_families_credential_link_check
    CHECK ((credential_kind IN ('internal','password')) = (credential_id IS NULL));
ALTER TABLE session_families ADD COLUMN expires_at TIMESTAMPTZ;
UPDATE session_families SET expires_at=created_at+interval '30 days';
ALTER TABLE session_families ALTER COLUMN expires_at SET NOT NULL;
ALTER TABLE session_families ALTER COLUMN expires_at SET DEFAULT (now()+interval '30 days');
CREATE INDEX session_families_expiry_idx ON session_families(expires_at,id);
-- Existing opaque sessions lose trust at cutover, without deleting evidence.
UPDATE session_families SET revoked_at=clock_timestamp() WHERE revoked_at IS NULL;
UPDATE users SET session_security_version=session_security_version+1;
