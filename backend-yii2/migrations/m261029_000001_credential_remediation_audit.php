<?php
declare(strict_types=1);

/** Durable operator evidence commits with addressed credential/session revocation. */
final class m261029_000001_credential_remediation_audit extends \yii\db\Migration
{
    public function safeUp(): void
    {
        $this->execute("CREATE TABLE credential_remediation_audit (
            id UUID PRIMARY KEY,
            user_id UUID NOT NULL REFERENCES users(id),
            credential_kind TEXT NOT NULL CHECK (credential_kind IN ('pin','biometric')),
            credential_id UUID NOT NULL,
            device_id VARCHAR(255) NOT NULL CHECK (device_id IN ('.','..')),
            operator_evidence_ref VARCHAR(64) NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
        )");
    }
    public function safeDown(): bool
    {
        $this->stdout("Operator audit history is retained; this migration is irreversible.\n");return false;
    }
}
