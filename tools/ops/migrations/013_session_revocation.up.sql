-- Legacy opaque tokens cannot be linked to a credential safely. Force reauth;
-- never derive a device/credential identity from the user ID.
ALTER TABLE users ADD COLUMN session_security_version BIGINT NOT NULL DEFAULT 0 CHECK (session_security_version >= 0);
ALTER TABLE users ADD COLUMN sessions_blocked BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE session_families (
    id UUID PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    security_version BIGINT NOT NULL,
    credential_kind TEXT NOT NULL CHECK (credential_kind IN ('internal','pin','biometric')),
    credential_id UUID,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at TIMESTAMPTZ,
    CHECK ((credential_kind = 'internal') = (credential_id IS NULL)),
    UNIQUE (id,user_id)
);
CREATE INDEX session_families_credential_idx ON session_families(user_id,credential_kind,credential_id) WHERE revoked_at IS NULL;

DELETE FROM access_sessions;
DELETE FROM refresh_sessions;
ALTER TABLE access_sessions ADD COLUMN family_id UUID NOT NULL;
ALTER TABLE refresh_sessions ADD COLUMN family_id UUID NOT NULL;
ALTER TABLE access_sessions ADD CONSTRAINT access_session_family_fk FOREIGN KEY (family_id,user_id) REFERENCES session_families(id,user_id) ON DELETE CASCADE;
ALTER TABLE refresh_sessions ADD CONSTRAINT refresh_session_family_fk FOREIGN KEY (family_id,user_id) REFERENCES session_families(id,user_id) ON DELETE CASCADE;
CREATE INDEX access_sessions_family_idx ON access_sessions(family_id);
CREATE INDEX refresh_sessions_family_idx ON refresh_sessions(family_id);

-- No password or provider refresh token is persisted. RESET_PENDING also means
-- the remote reset outcome may be unknown after a crash or transport failure.
CREATE TABLE password_session_operations (
    id UUID PRIMARY KEY,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    security_version BIGINT NOT NULL,
    state TEXT NOT NULL CHECK (state IN ('RESET_PENDING','REVOKE_PENDING','COMPLETE')),
    last_error TEXT CHECK (last_error IN ('IDENTITY_PROVIDER_ERROR','IDENTITY_PROVIDER_UNAVAILABLE','LOCAL_COMPLETION_FAILED')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX password_session_operations_active_idx ON password_session_operations(user_id) WHERE state <> 'COMPLETE';
