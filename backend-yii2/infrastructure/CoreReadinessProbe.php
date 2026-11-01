<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Configuration and mandatory schema/identity, with no optional service I/O. */
final class CoreReadinessProbe
{
    public function __construct(private readonly string $databaseUrl,private readonly ReadinessBudget $budget=new ReadinessBudget()) {}

    /** @param callable(): float $clock @param callable(): bool $cancel */
    public function __invoke(float $deadline,callable $clock,callable $cancel): void
    {
        Network::cidrs(Env::get('PAYMENT_WEBHOOK_ALLOWED_CIDRS'));
        if (Env::production()) {
            foreach (['PAYMENT_GATEWAY_URL','PAYMENT_GATEWAY_API_KEY','PAYMENT_WEBHOOK_SECRET','PAYMENT_WEBHOOK_ALLOWED_CIDRS'] as $key) {
                if (Env::get($key)==='') { throw new \RuntimeException('Readiness runtime configuration missing'); }
            }
        }
        // LIMIT 0 validates both schema and SELECT privilege without reading
        // business rows. One async batch shares connect/TLS/statement deadline.
        $sql=<<<'SQL'
SELECT 1 FROM users LIMIT 0;
SELECT 1 FROM biometric_challenges LIMIT 0;
SELECT id,stock_restored_at FROM orders LIMIT 0;
SELECT id,cancel_requested_at,refund_requested_at FROM payments LIMIT 0;
SELECT id,payment_id,kind,operation_key,state,lease_token,lease_until,sent_at FROM payment_operations LIMIT 0;
SELECT operation_id,lease_token,observed_at,result FROM payment_operation_observations LIMIT 0;
SELECT transaction_id,payment_id FROM payment_transaction_links LIMIT 0;
SELECT operation_id,payment_id,transaction_id,status,resolved_at FROM payment_callback_inbox LIMIT 0;
SELECT session_security_version,sessions_blocked,account_disabled,local_identity_ready,password_changed_at FROM users LIMIT 0;
SELECT id,user_id,security_version,credential_kind,credential_id,revoked_at,expires_at FROM session_families LIMIT 0;
SELECT family_id FROM access_sessions LIMIT 0;
SELECT family_id FROM refresh_sessions LIMIT 0;
SELECT id,user_id,state,recovery_attempts,next_retry_at,recovery_claim_until,operator_evidence_ref,operator_confirmed_at FROM password_session_operations LIMIT 0;
SELECT current_user AS active,session_user AS login,rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls,
    (SELECT value FROM repository_metadata WHERE key='legacy_import_status') AS legacy
FROM pg_roles WHERE rolname=current_user;
SQL;
        $rows=(new ReadinessSql($this->databaseUrl,$this->budget))->run($sql,$deadline,$clock,$cancel);
        $role=$rows[0]??null;
        if ($role===null || ($role['legacy']??'')==='required') { throw new \RuntimeException('Readiness schema unavailable'); }
        if (Env::production()) {
            $expected=DatabasePrivileges::ROLES['runtime'][0];
            if ($role['active']!==$expected || $role['login']!==$expected) { throw new \RuntimeException('Readiness runtime role unsafe'); }
            foreach (['rolsuper','rolcreatedb','rolcreaterole','rolreplication','rolbypassrls'] as $flag) {
                if (($role[$flag]??null)!=='f') { throw new \RuntimeException('Readiness runtime role unsafe'); }
            }
        }
    }
}
