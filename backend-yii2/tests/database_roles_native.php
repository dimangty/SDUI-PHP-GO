<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
require __DIR__.'/SocketUpstream.php';
require __DIR__.'/payment-fixture.php';

use Shop\application\{CommerceService,LocalIdentityMigration,PaymentOperations,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,DatabaseRole,Env};
use Shop\repositories\ShopRepository;

// These fixture-only credentials are supplied to this process, never web/workers.
if (!preg_match('/^shopkmp-round6-[a-z0-9-]+-e2e$/D', Env::get('ROLE_TEST_DISPOSABLE'))) {
    throw new RuntimeException('Explicit owned disposable role fixture required');
}
foreach (['ROLE_TEST_ADMIN_URL','ROLE_TEST_OPERATOR_URL'] as $key) {
    if (Env::get($key) === '') { throw new RuntimeException('Native role fixture credentials required'); }
}
$admin = new Database(Env::get('ROLE_TEST_ADMIN_URL'));
$operator = new Database(Env::get('ROLE_TEST_OPERATOR_URL'));

function denied(Closure $operation): void {
    try { $operation(); } catch (PDOException $e) { same('42501', $e->getCode()); return; }
    throw new RuntimeException('Expected SQL permission denial');
}

test('actual production runtime is non-owner and cannot write journal, DDL or SET ROLE', function () {
    global $admin;
    [$db] = localServices();
    DatabaseRole::preflight($db, 'runtime');
    same('shopkmp_runtime', $db->one('SELECT current_user AS name')['name']);
    same(['is_admin'=>true,'account_disabled'=>true,'local_identity_ready'=>false,'sessions_blocked'=>true,'password_hash'=>''],
        $db->one("SELECT is_admin,account_disabled,local_identity_ready,sessions_blocked,password_hash FROM users WHERE id='671f0000-0000-4000-8000-000000000006'"));
    same(['price'=>'17.29','stock'=>7,'is_active'=>false], $db->one("SELECT price,stock,is_active FROM products WHERE id='671f0000-0000-4000-8000-000000000008'"));
    same('shopkmp', $admin->one("SELECT pg_get_userbyid(proowner) AS owner FROM pg_proc WHERE oid='role_upgrade_audit_trigger()'::regprocedure")['owner']);
    $before = $admin->one('SELECT count(*) AS n FROM role_upgrade_audit.events')['n'];
    $db->execute("UPDATE users SET phone='+79991234567' WHERE id='671f0000-0000-4000-8000-000000000006'");
    same($before+1, $admin->one('SELECT count(*) AS n FROM role_upgrade_audit.events')['n']);
    foreach ([
        'INSERT INTO local_identity_migration_journal DEFAULT VALUES',
        "UPDATE local_identity_migration_journal SET action='attest'",
        'DELETE FROM local_identity_migration_journal', 'TRUNCATE local_identity_migration_journal',
        'SELECT * FROM local_identity_migration_journal',
        'CREATE TABLE public.runtime_ddl_probe(id int)', 'CREATE TEMP TABLE runtime_temp_probe(id int)',
        'ALTER TABLE users ADD COLUMN runtime_probe int',
        'SET ROLE shopkmp_owner', 'SET ROLE shopkmp_identity_operator',
        'GRANT shopkmp_owner TO shopkmp_runtime',
    ] as $sql) { denied(fn()=>$db->execute($sql)); }
});

test('owner future functions are not PUBLIC executable by default', function () {
    global $admin;
    $admin->execute('SET ROLE shopkmp_owner');
    try { $admin->execute('CREATE FUNCTION role_test_future_function() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$'); }
    finally { $admin->execute('RESET ROLE'); }
    try {
        same(false, $admin->one("SELECT has_function_privilege('shopkmp_runtime','role_test_future_function()','EXECUTE') AS allowed")['allowed']);
    } finally { $admin->execute('DROP FUNCTION role_test_future_function()'); }
});

test('cross-schema PUBLIC owner-rights views cannot pass runtime preflight', function () {
    global $admin;
    [$db] = localServices();
    $admin->execute('CREATE SCHEMA role_test_reporting');
    $admin->execute('CREATE VIEW role_test_reporting.identity_journal AS SELECT * FROM public.local_identity_migration_journal');
    $admin->execute('GRANT USAGE ON SCHEMA role_test_reporting TO PUBLIC');
    $admin->execute('GRANT SELECT,INSERT ON role_test_reporting.identity_journal TO PUBLIC');
    try {
        try { DatabaseRole::preflight($db,'runtime'); throw new RuntimeException('Owner-rights view accepted'); }
        catch (RuntimeException $e) { same('DATABASE_RELATION_PRIVILEGE_UNSAFE',$e->getMessage()); }
    } finally {
        $admin->execute('DROP VIEW role_test_reporting.identity_journal');
        $admin->execute('DROP SCHEMA role_test_reporting');
    }
});

test('runtime real register login refresh checkout summaries password change and recovery retain DML rights', function () {
    global $admin;
    [$db, , , $auth] = localServices();
    [$pair, $email] = localRegister($auth);
    $user = $pair['userId'];
    $next = $auth->refresh(['refreshToken'=>$auth->login(['email'=>$email,'password'=>'StrongPass7395'])['refreshToken']]);
    same($user, $auth->authenticate($next['accessToken'])['id']);
    $category=Ids::uuid(); $product=Ids::uuid(); $address=Ids::uuid();
    $admin->execute("INSERT INTO categories(id,name) VALUES(?,'Role fixture')", [$category]);
    $admin->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Role cents','10.23',10)", [$product,$category]);
    $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'X','Y','1')", [$address,$user]);
    $repo = new ShopRepository($db); $commerce = new CommerceService($db,$repo);
    $commerce->addCart($user, ['productId'=>$product,'quantity'=>2]);
    $order = $commerce->createOrder($user, ['deliveryAddressId'=>$address,'paymentMethod'=>'ON_DELIVERY']);
    $GLOBALS['roleOrder'] = $order;
    same('20.46', $order['totalAmount']);
    same($order['id'], $commerce->order($user,$order['id'])['id']);
    truth(count($commerce->orders($user,['projection'=>'sdui-order-summary-v1'])['items']) > 0);
    (new PaymentOperations($db,$repo,$commerce))->recover();
    $auth->changePassword($user,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);
    foreach ([$pair,$next] as $old) {
        errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($old['accessToken']));
        errorCode('INVALID_REFRESH_TOKEN',fn()=>$auth->refresh(['refreshToken'=>$old['refreshToken']]));
    }
    $fresh=$auth->login(['email'=>$email,'password'=>'ChangedPass8395']);
    $auth->logout($user,[]);
    errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($fresh['accessToken']));
});

test('identity operator has append-only audit and atomically approved selected-user actions', function () {
    global $admin,$operator;
    DatabaseRole::preflight($operator,'operator');
    $repo=new ShopRepository($operator);
    $service=new LocalIdentityMigration($operator,new SessionService($operator,$repo));
    $user=localUser($admin); $ticket='ROLE-'.random_int(1,999999999);
    same(['status'=>'applied'],$service->attest($user,$ticket,false,false,true));
    same(['status'=>'applied'],$service->resetPassword($user,'ROLE-'.random_int(1,999999999),'OperatorPass7395',true,true));
    same('shopkmp_identity_operator',$operator->one('SELECT applied_by FROM local_identity_migration_journal WHERE ticket=?',[$ticket])['applied_by']);
    foreach (['UPDATE local_identity_migration_journal SET security_version=0','DELETE FROM local_identity_migration_journal','TRUNCATE local_identity_migration_journal',"UPDATE products SET name='forbidden'",'CREATE TABLE public.operator_ddl_probe(id int)'] as $sql) {
        denied(fn()=>$operator->execute($sql));
    }
    [$runtime] = localServices();
    $runtimeRepo = new ShopRepository($runtime);
    errorCode('MIGRATION_OPERATOR_PERMISSION_REQUIRED',fn()=>(new LocalIdentityMigration($runtime,new SessionService($runtime,$runtimeRepo)))->attest($user,'ROLE-'.random_int(1,999999999),false,false,true));
    $admin->execute('REVOKE INSERT ON local_identity_migration_journal FROM shopkmp_identity_operator');
    try {
        errorCode('MIGRATION_OPERATOR_PERMISSION_REQUIRED',fn()=>$service->setDisabled($user,'ROLE-'.random_int(1,999999999),true,true));
        same(false,$admin->one('SELECT account_disabled FROM users WHERE id=?',[$user])['account_disabled']);
    } finally { $admin->execute('GRANT INSERT ON local_identity_migration_journal TO shopkmp_identity_operator'); }
    // Force the final audit insert to fail AFTER user mutation and revocation.
    $admin->execute("CREATE FUNCTION role_test_fail_journal() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture audit failure'; END $$");
    $admin->execute('CREATE TRIGGER role_test_fail_journal BEFORE INSERT ON local_identity_migration_journal FOR EACH ROW EXECUTE FUNCTION role_test_fail_journal()');
    $before=$admin->one('SELECT account_disabled,session_security_version FROM users WHERE id=?',[$user]);
    try {
        try { $service->setDisabled($user,'ROLE-'.random_int(1,999999999),true,true); throw new RuntimeException('Expected audit rollback'); }
        catch (PDOException $e) { same('P0001',$e->getCode()); }
        same($before,$admin->one('SELECT account_disabled,session_security_version FROM users WHERE id=?',[$user]));
    } finally {
        $admin->execute('DROP TRIGGER role_test_fail_journal ON local_identity_migration_journal');
        $admin->execute('DROP FUNCTION role_test_fail_journal()');
    }
});

test('bounded preflight rejects actual superuser, membership and executable definer escalation', function () {
    global $admin;
    try { DatabaseRole::preflight($admin,'runtime'); throw new RuntimeException('Unsafe superuser accepted'); }
    catch (RuntimeException $e) { same('DATABASE_ROLE_UNSAFE',$e->getMessage()); }
    [$db] = localServices();
    $admin->execute('GRANT shopkmp_owner TO shopkmp_runtime WITH INHERIT FALSE, SET TRUE');
    try {
        try { DatabaseRole::preflight($db,'runtime'); throw new RuntimeException('Membership accepted'); }
        catch (RuntimeException $e) { same('DATABASE_ROLE_UNSAFE',$e->getMessage()); }
    } finally { $admin->execute('REVOKE shopkmp_owner FROM shopkmp_runtime'); }
    $admin->execute('CREATE FUNCTION role_test_unsafe_definer() RETURNS int LANGUAGE sql SECURITY DEFINER AS $$ SELECT 1 $$');
    $admin->execute('GRANT EXECUTE ON FUNCTION role_test_unsafe_definer() TO shopkmp_runtime');
    try {
        try { DatabaseRole::preflight($db,'runtime'); throw new RuntimeException('Definer accepted'); }
        catch (RuntimeException $e) { same('DATABASE_ROLE_UNSAFE',$e->getMessage()); }
    } finally { $admin->execute('DROP FUNCTION role_test_unsafe_definer()'); }
    DatabaseRole::preflight($db,'runtime');
    $admin->execute('GRANT SELECT(ticket) ON local_identity_migration_journal TO shopkmp_runtime');
    try {
        try { DatabaseRole::preflight($db,'runtime'); throw new RuntimeException('Column journal access accepted'); }
        catch (RuntimeException $e) { same('DATABASE_JOURNAL_PRIVILEGE_UNSAFE',$e->getMessage()); }
    } finally { $admin->execute('REVOKE SELECT(ticket) ON local_identity_migration_journal FROM shopkmp_runtime'); }
});

test('production process preflight fails safely for missing credentials and superuser override', function () {
    global $admin;
    foreach (['', Env::get('ROLE_TEST_ADMIN_URL')] as $url) {
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/database-preflight.php'],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,
            array_replace(getenv(), ['APP_ENV'=>'production','DATABASE_ROLE'=>'runtime','DATABASE_URL'=>$url]));
        same('',stream_get_contents($pipes[1]));
        same("DATABASE_ROLE_PREFLIGHT_FAILED: verify service credentials and SQL grants\n",stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]); same(1,proc_close($process));
    }
});

test('dedicated worker logins cannot read or rewrite identity or mint sessions and perform their SQL duties', function () {
    global $admin;
    $urls=json_decode(Env::get('ROLE_TEST_WORKER_URLS'),true,32,JSON_THROW_ON_ERROR);
    same(['indexer','search','maintenance','recovery'],array_keys($urls));
    foreach ($urls as $kind=>$url) {
        $db=new Database($url);
        DatabaseRole::preflight($db,$kind);
        same('shopkmp_'.$kind,$db->one('SELECT current_user AS name')['name']);
        foreach (['SELECT password_hash FROM users','UPDATE users SET password_hash=password_hash',
            'INSERT INTO access_sessions DEFAULT VALUES','INSERT INTO refresh_sessions DEFAULT VALUES',
            'INSERT INTO session_families DEFAULT VALUES','INSERT INTO local_identity_migration_journal DEFAULT VALUES',
            'SET ROLE shopkmp_owner','SET ROLE shopkmp_runtime','CREATE TABLE public.worker_probe(id int)'] as $sql) {
            denied(fn()=>$db->execute($sql));
        }
        if (in_array($kind,['search','indexer'],true)) {
            truth(count($db->all('SELECT id FROM products LIMIT 1'))>0);
            if ($kind==='search') { denied(fn()=>$db->execute('UPDATE products SET stock=stock WHERE false')); }
            else { $db->transaction(fn()=>$db->execute('LOCK TABLE categories,products,product_images,reviews IN SHARE MODE NOWAIT')); }
        } elseif ($kind==='maintenance') {
            denied(fn()=>$db->execute('UPDATE products SET price=price WHERE false'));
            denied(fn()=>$db->execute('UPDATE orders SET total_amount=total_amount WHERE false'));
            $admin->execute("UPDATE orders SET created_at=clock_timestamp()-interval '16 minutes' WHERE id=?",[$GLOBALS['roleOrder']['id']]);
            $repo=new ShopRepository($db);
            (new Shop\application\MaintenanceService($db,$repo,new CommerceService($db,$repo)))->run();
            same('CANCELLED',$admin->one('SELECT status FROM orders WHERE id=?',[$GLOBALS['roleOrder']['id']])['status']);
        } else {
            denied(fn()=>$db->execute('UPDATE products SET price=price WHERE false'));
            denied(fn()=>$db->execute('UPDATE payments SET amount=amount WHERE false'));
            $report=(new Shop\application\RecoveryService($db))->run();
            same(0,$report['sessionsCompleted']);
            denied(fn()=>$db->execute('DELETE FROM access_sessions WHERE false'));
        }
    }
});

test('selected identity operator retains atomic legacy-device remediation and audit', function () {
    global $admin,$operator;
    $user=localUser($admin); $credential=Ids::uuid();
    $admin->execute("INSERT INTO device_pins(id,user_id,device_id,device_name,pin_hash) VALUES(?, ?, '.', 'Historical role fixture', '')",[$credential,$user]);
    $repo=new ShopRepository($operator); $sessions=new SessionService($operator,$repo);
    $service=new Shop\application\CredentialService($operator,$repo,$sessions);
    $admin->execute('REVOKE INSERT ON credential_remediation_audit FROM shopkmp_identity_operator');
    try {
        denied(fn()=>$service->remediateLegacyDevice($user,'pin','.','ROLE-'.random_int(1,999999999)));
        truth($admin->one('SELECT id FROM device_pins WHERE id=?',[$credential])!==null);
    } finally { $admin->execute('GRANT INSERT ON credential_remediation_audit TO shopkmp_identity_operator'); }
    $service->remediateLegacyDevice($user,'pin','.','ROLE-'.random_int(1,999999999));
    same(null,$admin->one('SELECT id FROM device_pins WHERE id=?',[$credential]));
    same(1,$admin->one('SELECT count(*) AS n FROM credential_remediation_audit WHERE credential_id=?',[$credential])['n']);
});

test('production recovery login dispatches one durable authorization over verified HTTPS outside SQL transactions', function () {
    global $admin;
    [$db, , , $auth]=localServices();
    [$pair]=localRegister($auth); $user=$pair['userId'];
    $category=Ids::uuid(); $product=Ids::uuid(); $address=Ids::uuid();
    $admin->execute("INSERT INTO categories(id,name) VALUES(?,'TLS worker fixture')",[$category]);
    $admin->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'TLS worker cents','10.23',10)",[$product,$category]);
    $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'X','Y','1')",[$address,$user]);
    $repo=new ShopRepository($db); $commerce=new CommerceService($db,$repo);
    $commerce->addCart($user,['productId'=>$product,'quantity'=>2]);
    $order=$commerce->createOrder($user,['deliveryAddressId'=>$address,'paymentMethod'=>'CARD']);
    $payment=$order['paymentId'];
    $admin->execute('CREATE TABLE IF NOT EXISTS role_test_gateway_journal(operation_key text PRIMARY KEY,payment_id uuid,amount_cents bigint,in_transaction int,intent_state text,body jsonb)');
    $server=new SocketUpstream(static function ($peer,$headers,$first,$body) {
        $input=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        same('POST /v1/payments/authorize HTTP/1.1'."\r\n",$first);
        same('Bearer payment-fixture-key',$headers['authorization']??null);
        same(2046,$input['amountCents']);
        $provider=new Database(Env::get('ROLE_TEST_ADMIN_URL'));
        $idle=$provider->one("SELECT count(*) AS n FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND state='idle in transaction'")['n'];
        $intent=$provider->one("SELECT state FROM payment_operations WHERE payment_id=? AND kind='authorize'",[$input['paymentId']])['state'];
        $provider->execute('INSERT INTO role_test_gateway_journal VALUES(?,?,?,?,?,?::jsonb)',[$headers['idempotency-key'],$input['paymentId'],$input['amountCents'],$idle,$intent,$body]);
        $reply=json_encode(['status'=>'AUTHORIZED','paymentUrl'=>'https://localhost/pay/'.$input['paymentId'],'transactionId'=>'role-'.$input['paymentId']],JSON_THROW_ON_ERROR);
        fwrite($peer,"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($reply)."\r\nConnection: close\r\n\r\n".$reply);
    },['local_cert'=>'/run/test-provider.crt','local_pk'=>'/run/test-provider.key','verify_peer'=>false]);
    $oldGateway=Env::get('PAYMENT_GATEWAY_URL'); $oldKey=Env::get('PAYMENT_GATEWAY_API_KEY');
    putenv('PAYMENT_GATEWAY_URL='.$server->origin); putenv('PAYMENT_GATEWAY_API_KEY=payment-fixture-key');
    try {
        // Crash the send reservation commit: real application intent stays READY,
        // without contacting the provider or inventing a replacement business result.
        [$trigger,$function]=paymentFailureTrigger($admin,$payment,'SENT');
        try {
            try { (new Shop\application\PaymentService($db,$repo,$commerce))->confirm($user,$payment); throw new RuntimeException('Expected failed send reservation'); }
            catch (PDOException $error) { same('P0001',$error->getCode()); }
        } finally { dropPaymentTrigger($admin,$trigger,$function); }
        same('READY',$admin->one("SELECT state FROM payment_operations WHERE payment_id=?",[$payment])['state']);
        same(0,$admin->one('SELECT count(*) AS n FROM role_test_gateway_journal WHERE payment_id=?',[$payment])['n']);
        $urls=json_decode(Env::get('ROLE_TEST_WORKER_URLS'),true,32,JSON_THROW_ON_ERROR);
        $worker=new Database($urls['recovery']); DatabaseRole::preflight($worker,'recovery');
        $report=(new Shop\application\RecoveryService($worker))->run();
        same(1,$report['paymentsDispatched']);
        same('AUTHORIZED',$admin->one('SELECT status FROM payments WHERE id=?',[$payment])['status']);
        same('SUCCEEDED',$admin->one('SELECT state FROM payment_operations WHERE payment_id=?',[$payment])['state']);
        same(['amount_cents'=>2046,'in_transaction'=>0,'intent_state'=>'SENT'],
            $admin->one('SELECT amount_cents,in_transaction,intent_state FROM role_test_gateway_journal WHERE payment_id=?',[$payment]));
        // The ordinary web login must also persist a real provider observation.
        $commerce->addCart($user,['productId'=>$product,'quantity'=>2]);
        $second=$commerce->createOrder($user,['deliveryAddressId'=>$address,'paymentMethod'=>'CARD']);
        $result=(new Shop\application\PaymentService($db,$repo,$commerce))->confirm($user,$second['paymentId']);
        same('AUTHORIZED',$result['status']);
        same(1,$admin->one('SELECT count(*) AS n FROM payment_operation_observations o JOIN payment_operations p ON p.id=o.operation_id WHERE p.payment_id=?',[$second['paymentId']])['n']);
        same(0,$admin->one('SELECT in_transaction FROM role_test_gateway_journal WHERE payment_id=?',[$second['paymentId']])['in_transaction']);
    } finally {
        putenv('PAYMENT_GATEWAY_URL='.$oldGateway); putenv('PAYMENT_GATEWAY_API_KEY='.$oldKey);
        $requests=$server->finish();
    }
    same(2,$requests);
});

runTests();
