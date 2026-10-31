<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Expensive privilege audit is a bounded CLI preflight, never a business query. */
final class DatabaseRole
{
    public static function identity(Database $db, string $kind): void
    {
        $expected = DatabasePrivileges::ROLES[$kind][0] ?? throw new \RuntimeException('DATABASE_ROLE_REQUIRED');
        $role = $db->one('SELECT current_user AS active, session_user AS login, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname=current_user');
        if ($role === null || $role['active'] !== $expected || $role['login'] !== $expected
            || $role['rolsuper'] || $role['rolcreatedb'] || $role['rolcreaterole'] || $role['rolreplication'] || $role['rolbypassrls']) {
            throw new \RuntimeException('DATABASE_ROLE_UNSAFE');
        }
    }

    public static function preflight(Database $db, string $kind): void
    {
        $db->transaction(function () use ($db, $kind) {
            $db->execute("SET LOCAL statement_timeout='3s'");
            $db->execute("SET LOCAL lock_timeout='1s'");
            self::identity($db, $kind);
            // No membership at all: includes NOINHERIT roles reachable by SET ROLE.
            $unsafe = $db->one("SELECT EXISTS(SELECT 1 FROM pg_auth_members WHERE member=(SELECT oid FROM pg_roles WHERE rolname=current_user)) AS membership,
                EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE p.prosecdef AND n.nspname NOT IN ('pg_catalog','information_schema') AND has_function_privilege(current_user,p.oid,'EXECUTE')) AS definer");
            if ($unsafe['membership']) { throw new \RuntimeException('DATABASE_ROLE_UNSAFE'); }
            if ($kind === 'owner') { return; }
            if ($unsafe['definer']) { throw new \RuntimeException('DATABASE_ROLE_UNSAFE'); }
            $unsafe = $db->one("SELECT
                EXISTS(SELECT 1 FROM pg_class WHERE relowner=(SELECT oid FROM pg_roles WHERE rolname=current_user)) OR
                EXISTS(SELECT 1 FROM pg_namespace WHERE nspowner=(SELECT oid FROM pg_roles WHERE rolname=current_user)) OR
                EXISTS(SELECT 1 FROM pg_proc WHERE proowner=(SELECT oid FROM pg_roles WHERE rolname=current_user)) OR
                EXISTS(SELECT 1 FROM pg_type WHERE typowner=(SELECT oid FROM pg_roles WHERE rolname=current_user)) OR
                EXISTS(SELECT 1 FROM pg_database WHERE datdba=(SELECT oid FROM pg_roles WHERE rolname=current_user)) AS owns,
                EXISTS(SELECT 1 FROM pg_namespace WHERE nspname NOT LIKE 'pg_temp_%' AND nspname NOT LIKE 'pg_toast%' AND has_schema_privilege(current_user,oid,'CREATE')) AS ddl,
                has_database_privilege(current_user,current_database(),'CREATE') OR has_database_privilege(current_user,current_database(),'TEMP') AS database_ddl,
                EXISTS(SELECT 1 FROM information_schema.role_table_grants WHERE grantee=current_user AND is_grantable='YES') OR
                EXISTS(SELECT 1 FROM information_schema.role_column_grants WHERE grantee=current_user AND is_grantable='YES') AS can_grant");
            if (in_array(true, $unsafe, true)) { throw new \RuntimeException('DATABASE_ROLE_UNSAFE'); }
            // Owner-rights views/rules can hide journal access behind another
            // schema. Refuse every effective relation capability outside the
            // reviewed manifest, including PUBLIC and column-only grants.
            $allowed = DatabasePrivileges::allowedTables($kind);
            $marks = implode(',', array_fill(0, count($allowed), '?'));
            $unsafe = $db->one("SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%' AND n.nspname NOT LIKE 'pg_temp_%'
                AND c.relkind IN ('r','p','v','m','f') AND (n.nspname<>'public' OR c.relname NOT IN ($marks))
                AND (has_table_privilege(current_user,c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
                    OR has_any_column_privilege(current_user,c.oid,'SELECT,INSERT,UPDATE,REFERENCES'))) AS exposed", $allowed);
            if ($unsafe['exposed']) { throw new \RuntimeException('DATABASE_RELATION_PRIVILEGE_UNSAFE'); }
            $privileges = $kind !== 'operator'
                ? ['SELECT','INSERT','UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER']
                : ['UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER'];
            foreach ($privileges as $privilege) {
                $has = $db->one("SELECT has_table_privilege(current_user,'public.local_identity_migration_journal',?) AS table_right", [$privilege]);
                if ($has['table_right']) { throw new \RuntimeException('DATABASE_JOURNAL_PRIVILEGE_UNSAFE'); }
                if (in_array($privilege, ['SELECT','INSERT','UPDATE','REFERENCES'], true)
                    && $db->one("SELECT has_any_column_privilege(current_user,'public.local_identity_migration_journal',?) AS column_right", [$privilege])['column_right']) {
                    throw new \RuntimeException('DATABASE_JOURNAL_PRIVILEGE_UNSAFE');
                }
            }
            if ($kind === 'operator' && !$db->one("SELECT has_table_privilege(current_user,'public.local_identity_migration_journal','SELECT') AND has_table_privilege(current_user,'public.local_identity_migration_journal','INSERT') AS allowed")['allowed']) {
                throw new \RuntimeException('DATABASE_OPERATOR_CAPABILITY_REQUIRED');
            }
        });
    }
}
