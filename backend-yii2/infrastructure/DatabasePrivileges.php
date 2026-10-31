<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Versioned application object manifest. Unknown objects remain DBA-managed. */
final class DatabasePrivileges
{
    public const TABLES = [
        'users','categories','products','product_images','addresses','cart_items','cart_metadata',
        'orders','order_items','payments','reviews','device_pins','device_biometrics','biometric_challenges',
        'idempotency_keys','processed_webhooks','avatar_files','rate_limit_hits','login_attempts',
        'session_families','access_sessions','refresh_sessions','password_session_operations',
        'payment_operations','payment_operation_observations','payment_transaction_links','payment_callback_inbox',
        'repository_metadata','local_identity_migration_journal','credential_remediation_audit',
        'schema_migrations','yii2_migration_history',
    ];
    public const SEQUENCES = ['product_images_id_seq','orders_number_seq','order_items_id_seq','payment_operation_observations_id_seq'];
    public const FUNCTIONS = ['local_identity_journal_immutable()'];
    public const ROLES = [
        'owner'=>['shopkmp_owner','POSTGRES_OWNER_PASSWORD'],
        'runtime'=>['shopkmp_runtime','POSTGRES_RUNTIME_PASSWORD'],
        'operator'=>['shopkmp_identity_operator','POSTGRES_OPERATOR_PASSWORD'],
        'search'=>['shopkmp_search','POSTGRES_SEARCH_PASSWORD'],
        'indexer'=>['shopkmp_indexer','POSTGRES_INDEXER_PASSWORD'],
        'maintenance'=>['shopkmp_maintenance','POSTGRES_MAINTENANCE_PASSWORD'],
        'recovery'=>['shopkmp_recovery','POSTGRES_RECOVERY_PASSWORD'],
    ];

    public static function allowedTables(string $kind): array
    {
        return match ($kind) {
            'operator'=>['users','password_session_operations','session_families','access_sessions','refresh_sessions',
                'device_pins','device_biometrics','login_attempts','local_identity_migration_journal','credential_remediation_audit'],
            'search','indexer'=>['categories','products','product_images','reviews'],
            'maintenance'=>['orders','payments','products','order_items','payment_operations','idempotency_keys',
                'processed_webhooks','access_sessions','refresh_sessions','session_families','biometric_challenges','rate_limit_hits','login_attempts'],
            'recovery'=>['orders','payments','products','order_items','payment_operations','payment_operation_observations',
                'payment_transaction_links','payment_callback_inbox','processed_webhooks','password_session_operations'],
            default=>array_values(array_diff(self::TABLES, ['credential_remediation_audit','schema_migrations','yii2_migration_history'])),
        };
    }
}
