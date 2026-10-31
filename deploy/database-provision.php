<?php
declare(strict_types=1);
// Explicit DBA-only provisioning/upgrade. Not an initdb script or an HTTP route.
require '/app/vendor/autoload.php';

use Shop\infrastructure\{Database,DatabasePrivileges,Env};

ini_set('zend.exception_ignore_args', '1');
try {
    if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['roles', 'grants'], true)) {
        throw new RuntimeException('Explicit roles or grants phase required');
    }
    $db = new Database(Env::get('DATABASE_URL'));
    $db->transaction(function () use ($db, $argv) {
        $db->execute("SET LOCAL statement_timeout='30s'");
        $db->execute("SET LOCAL lock_timeout='3s'");
        $db->advisory('shopkmp:database-role-provisioning');
        $quote = static fn(string $name): string => '"'.str_replace('"', '""', $name).'"';
        $roles = array_column(DatabasePrivileges::ROLES, 1, 0);
        $limitedRoles = implode(',', array_keys(array_diff_key($roles, ['shopkmp_owner'=>true])));
        $revokedRoles = 'PUBLIC,'.$limitedRoles;
        if ($argv[1] === 'roles') {
            foreach ($roles as $role => $key) {
                $password = Env::get($key);
                if (!preg_match('/^[A-Za-z0-9_-]{32,}$/D', $password)) { throw new RuntimeException('Invalid role secret'); }
                // Existing roles must be reviewed rather than silently stripped of
                // memberships/ownership potentially used by other deployments.
                if ($db->one('SELECT 1 FROM pg_roles WHERE rolname=?', [$role]) === null) {
                    $db->execute('CREATE ROLE '.$quote($role).' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
                }
                $r = $db->one('SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS unsafe FROM pg_roles WHERE rolname=?', [$role]);
                if ($r['unsafe'] || $db->one('SELECT 1 FROM pg_auth_members WHERE member=(SELECT oid FROM pg_roles WHERE rolname=?)', [$role])) {
                    throw new RuntimeException('Existing role requires DBA review');
                }
                $db->execute('ALTER ROLE '.$quote($role).' PASSWORD '.$db->pdo()->quote($password));
            }
            $database = $quote($db->one('SELECT current_database() AS name')['name']);
            $db->execute('REVOKE CREATE,TEMPORARY ON DATABASE '.$database.' FROM PUBLIC');
            $db->execute('GRANT CONNECT ON DATABASE '.$database.' TO '.implode(',',array_keys($roles)));
            $db->execute('GRANT CREATE ON DATABASE '.$database.' TO shopkmp_owner');
            $db->execute('ALTER SCHEMA public OWNER TO shopkmp_owner');
            $db->execute('REVOKE ALL ON SCHEMA public FROM PUBLIC');
            $db->execute('GRANT USAGE ON SCHEMA public TO '.$limitedRoles);
            return;
        }
        // Never reassign arbitrary public/audit/extension objects. Validate all
        // managed owners and relation kinds before any ACL/ownership mutation.
        foreach (['TABLE'=>DatabasePrivileges::TABLES,'SEQUENCE'=>DatabasePrivileges::SEQUENCES] as $kind=>$objects) {
            foreach ($objects as $name) {
                $object=$db->one("SELECT c.relkind,pg_get_userbyid(c.relowner) AS owner FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname=?",[$name]);
                if ($object===null) { continue; } // additive upgrade may precede Yii's audit migration
                if (!in_array($object['owner'],['shopkmp','shopkmp_owner'],true)
                    || !in_array($object['relkind'],$kind==='SEQUENCE'?['S']:['r','p'],true)) {
                    throw new RuntimeException('Managed object requires DBA ownership review');
                }
                $objectName='public.'.$quote($name);
                $db->execute('ALTER '.$kind.' '.$objectName.' OWNER TO shopkmp_owner');
                $db->execute('REVOKE ALL ON '.$kind.' '.$objectName.' FROM '.$revokedRoles);
                if ($kind==='TABLE') {
                    $columns=$db->one("SELECT string_agg(quote_ident(attname),',' ORDER BY attnum) AS names FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped",[$objectName])['names'];
                    $db->execute('REVOKE ALL PRIVILEGES ('.$columns.') ON '.$objectName.' FROM '.$revokedRoles);
                }
            }
        }
        foreach (DatabasePrivileges::FUNCTIONS as $signature) {
            $db->execute('ALTER FUNCTION public.'.$signature.' OWNER TO shopkmp_owner');
            $db->execute('REVOKE EXECUTE ON FUNCTION public.'.$signature.' FROM '.$revokedRoles);
        }
        $db->execute('GRANT EXECUTE ON FUNCTION public.uuid_generate_v4() TO shopkmp_owner,shopkmp_runtime');
        $tables = 'categories,products,product_images,addresses,cart_items,cart_metadata,orders,order_items,payments,reviews,device_pins,device_biometrics,biometric_challenges,idempotency_keys,processed_webhooks,avatar_files,rate_limit_hits,login_attempts,session_families,access_sessions,refresh_sessions,payment_operations,payment_operation_observations,payment_transaction_links,payment_callback_inbox';
        $db->execute('GRANT SELECT,INSERT,UPDATE,DELETE ON '.$tables.' TO shopkmp_runtime');
        $db->execute('GRANT SELECT,INSERT ON users TO shopkmp_runtime');
        $db->execute('GRANT UPDATE(password_hash,password_changed_at,session_security_version,full_name,phone,default_address_id,date_of_birth,avatar_url) ON users TO shopkmp_runtime');
        $db->execute('GRANT SELECT ON repository_metadata,password_session_operations TO shopkmp_runtime');
        $db->execute('GRANT USAGE ON SEQUENCE product_images_id_seq,orders_number_seq,order_items_id_seq TO shopkmp_runtime');
        $db->execute('GRANT USAGE ON SEQUENCE payment_operation_observations_id_seq TO shopkmp_runtime,shopkmp_recovery');
        $db->execute('GRANT SELECT ON users,password_session_operations,session_families,access_sessions,refresh_sessions,device_pins,device_biometrics TO shopkmp_identity_operator');
        $db->execute('GRANT UPDATE(password_hash,password_changed_at,is_admin,account_disabled,local_identity_ready,sessions_blocked,session_security_version) ON users TO shopkmp_identity_operator');
        $db->execute('GRANT UPDATE(state,operator_evidence_ref,operator_confirmed_at,updated_at,last_error,recovery_claim_until) ON password_session_operations TO shopkmp_identity_operator');
        $db->execute('GRANT UPDATE(revoked_at) ON session_families TO shopkmp_identity_operator');
        $db->execute('GRANT DELETE ON access_sessions,refresh_sessions,login_attempts TO shopkmp_identity_operator');
        $db->execute('GRANT SELECT(user_id) ON login_attempts TO shopkmp_identity_operator');
        $db->execute('GRANT SELECT,INSERT ON local_identity_migration_journal TO shopkmp_identity_operator');
        $db->execute('GRANT DELETE ON device_pins,device_biometrics TO shopkmp_identity_operator');
        // SELECT FOR UPDATE requires UPDATE on at least one column. Do not
        // grant proof/key/PIN writes merely to lock an addressed legacy row.
        $db->execute('GRANT UPDATE(last_used_at) ON device_pins,device_biometrics TO shopkmp_identity_operator');
        $db->execute('GRANT INSERT ON credential_remediation_audit TO shopkmp_identity_operator');
        $db->execute('GRANT SELECT ON categories,products,product_images,reviews TO shopkmp_search,shopkmp_indexer');
        // Explicit one-shot bootstrap requires PostgreSQL SHARE table locks,
        // which require table UPDATE (column UPDATE is insufficient). The
        // recurring search worker has SELECT only and never receives this login.
        $db->execute('GRANT UPDATE ON categories,products,product_images,reviews TO shopkmp_indexer');
        $db->execute('GRANT SELECT ON orders,payments,products,order_items,payment_operations TO shopkmp_maintenance,shopkmp_recovery');
        $db->execute('GRANT UPDATE(status,stock_restored_at) ON orders TO shopkmp_maintenance,shopkmp_recovery');
        $db->execute('GRANT UPDATE(stock) ON products TO shopkmp_maintenance,shopkmp_recovery');
        $db->execute('GRANT UPDATE(status,gateway_url,updated_at,cancel_requested_at) ON payments TO shopkmp_maintenance');
        $db->execute('GRANT UPDATE(status,gateway_url,transaction_id,updated_at,cancel_requested_at,refund_requested_at) ON payments TO shopkmp_recovery');
        $db->execute('GRANT UPDATE(state,updated_at) ON payment_operations TO shopkmp_maintenance');
        $db->execute('GRANT UPDATE(state,lease_token,lease_until,sent_at,last_error,result,updated_at) ON payment_operations TO shopkmp_recovery');
        $db->execute('GRANT INSERT ON payment_operations TO shopkmp_maintenance,shopkmp_recovery');
        $db->execute('GRANT SELECT,DELETE ON idempotency_keys,processed_webhooks,access_sessions,refresh_sessions,session_families,biometric_challenges,rate_limit_hits,login_attempts TO shopkmp_maintenance');
        $db->execute('GRANT SELECT,INSERT ON payment_operation_observations,payment_transaction_links TO shopkmp_recovery');
        $db->execute('GRANT SELECT ON payment_callback_inbox TO shopkmp_recovery');
        $db->execute('GRANT UPDATE(resolved_at) ON payment_callback_inbox TO shopkmp_recovery');
        $db->execute('GRANT SELECT,INSERT ON processed_webhooks TO shopkmp_recovery');
        $db->execute('GRANT SELECT(state) ON password_session_operations TO shopkmp_recovery');
        $db->execute('ALTER DEFAULT PRIVILEGES FOR ROLE shopkmp_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC');
        // Future tables are denied by default; rerun reviewed explicit grants
        // after an additive migration. No blanket future journal permission.
    });
    print "Database provisioning phase passed\n";
} catch (Throwable) {
    fwrite(STDERR, "DATABASE_PROVISIONING_FAILED: review roles, schema and grants with the DBA\n");
    exit(1);
}
