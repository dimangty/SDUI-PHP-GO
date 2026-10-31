<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Shop\infrastructure\{Database,Env};

// Task-owned disposable predecessor data. Deliberately unready, banned and admin:
// SQL provisioning must not infer or rewrite any of these identity decisions.
$db = new Database(Env::get('DATABASE_URL'));
$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,is_admin,account_disabled,local_identity_ready,sessions_blocked) VALUES('671f0000-0000-4000-8000-000000000006','role-upgrade@test.example','','Preserve role upgrade','+79991234567',true,true,false,true) ON CONFLICT(id) DO NOTHING");
$db->execute('CREATE SCHEMA IF NOT EXISTS role_upgrade_audit');
$db->execute('CREATE TABLE IF NOT EXISTS role_upgrade_audit.events(user_id uuid,label text)');
$db->execute("CREATE OR REPLACE FUNCTION public.role_upgrade_audit_trigger() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,role_upgrade_audit AS $$ BEGIN IF NEW.id='671f0000-0000-4000-8000-000000000006'::uuid THEN INSERT INTO role_upgrade_audit.events VALUES(NEW.id,'preserved'); END IF; RETURN NEW; END $$");
$db->execute('REVOKE ALL ON FUNCTION public.role_upgrade_audit_trigger() FROM PUBLIC');
if (!$db->one("SELECT 1 FROM pg_trigger WHERE tgname='role_upgrade_audit_trigger' AND tgrelid='users'::regclass")) {
    $db->execute('CREATE TRIGGER role_upgrade_audit_trigger AFTER UPDATE ON users FOR EACH ROW EXECUTE FUNCTION public.role_upgrade_audit_trigger()');
}
$db->execute("UPDATE users SET phone='+79991234567' WHERE id='671f0000-0000-4000-8000-000000000006'");
$db->execute("INSERT INTO categories(id,name) VALUES('671f0000-0000-4000-8000-000000000007','Existing role category') ON CONFLICT(id) DO NOTHING");
$db->execute("INSERT INTO products(id,category_id,name,price,stock,is_active) VALUES('671f0000-0000-4000-8000-000000000008','671f0000-0000-4000-8000-000000000007','Existing role product','17.29',7,false) ON CONFLICT(id) DO NOTHING");
print "Existing-data role-upgrade fixture prepared\n";
