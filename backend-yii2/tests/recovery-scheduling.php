<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/payment-fixture.php';
use Shop\application\{MaintenanceService,PaymentOperations,RecoveryService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient,Keycloak};
use Shop\repositories\ShopRepository;

// All business rows live in a test-owned final-schema namespace in the explicitly
// supplied disposable database. The HTTP fixtures journal only our payment/user IDs.
function schedulingSchema(): array {
    [$db,$schema]=paymentLegacySchema();applyPaymentMigration($db);
    foreach([13,14] as $n){$file=glob('/migrations/'.sprintf('%03d',$n).'_*.up.sql');same(1,count($file));$db->transaction(fn()=>$db->pdo()->exec(file_get_contents($file[0])));}
    return [$db,$schema];
}
function schedulingSession(Database $db,int $code=204,int $delay=0): array {
    $u=Ids::uuid();$id=Ids::uuid();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,sessions_blocked) VALUES(?,?,'','Scheduling','+79991234567',true)",[$u,$u.'@scheduling.test']);
    $db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,'REVOKE_PENDING')",[$id,$u]);
    paymentDb()->execute('INSERT INTO recovery_test_provider(user_id,response_code,delay_ms) VALUES(?,?,?)',[$u,$code,$delay]);
    return [$id,$u];
}
function schedulingPayment(Database $db,int $delay=0): array {
    [$u,$o]=paymentFixture('CARD','AUTHORIZED',['delay_ms'=>$delay],$db);$id=Ids::uuid();
    $db->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state) VALUES(?,?,'authorize',?,'READY')",[$id,$o['paymentId'],$o['paymentId'].':authorize']);
    return [$u,$o,$id];
}
function schedulingWorker(Database $db): RecoveryService {return new RecoveryService($db,new Keycloak(new HttpClient(2)));}
function schedulingCleanup(Database $db,string $schema): void {
    paymentDb()->execute('DELETE FROM recovery_test_provider WHERE user_id IN (SELECT id FROM '.$schema.'.users)');
    $ids=$db->all('SELECT id FROM payments');
    foreach($ids as $row){paymentDb()->execute('DELETE FROM payment_test_journal WHERE payment_id=?',[$row['id']]);paymentDb()->execute('DELETE FROM payment_test_scenarios WHERE payment_id=?',[$row['id']]);}
    dropPaymentLegacySchema($db,$schema);
}
paymentTestSchema();paymentDb()->execute('CREATE TABLE IF NOT EXISTS recovery_test_provider(user_id uuid PRIMARY KEY,response_code int,delay_ms int,calls int NOT NULL DEFAULT 0,open_transactions int NOT NULL DEFAULT 0)');
$socket=stream_socket_server('tcp://0.0.0.0:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
$provider=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/recovery-scheduling-provider.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
$owner=getmypid();register_shutdown_function(function()use($provider,$owner){if(getmypid()===$owner){proc_terminate($provider);proc_close($provider);}});
for($i=0;$i<100;$i++){$s=@stream_socket_client('tcp://127.0.0.1:'.explode(':',$address)[1],$errno,$error,.02);if($s){fclose($s);break;}usleep(10000);}
putenv('KEYCLOAK_ISSUER=http://127.0.0.1:'.explode(':',$address)[1].'/realms/worker');putenv('KEYCLOAK_ADMIN_TOKEN=');putenv('KEYCLOAK_CLIENT_ID=shopkmp-backend');putenv('KEYCLOAK_CLIENT_SECRET=fixture-secret');

test('session backlog is capped while a safe payment still progresses in the same scan',function(){
    [$db,$schema]=schedulingSchema();
    try{
        for($i=0;$i<6;$i++){schedulingSession($db,204,100);}
        [, $order]=schedulingPayment($db);$r=schedulingWorker($db)->run();
        same(1,$db->one("SELECT count(*) AS n FROM password_session_operations WHERE state='COMPLETE'")['n']);
        same(5,$db->one("SELECT count(*) AS n FROM password_session_operations WHERE state='REVOKE_PENDING'")['n']);
        same('AUTHORIZED',$db->one('SELECT status FROM payments WHERE id=?',[$order['paymentId']])['status']);same(1,journal($order['paymentId'])[0]['attempts']);
        same(1,$r['sessionsCompleted']);same(1,$r['paymentsDispatched']);
    }finally{schedulingCleanup($db,$schema);}
});
test('bounded payment scan leaves chained compensation durable and preserves default recovery API',function(){
    [$db,$schema]=schedulingSchema();
    try{
        [, $order]=schedulingPayment($db);$p=$order['paymentId'];$db->execute('UPDATE payments SET cancel_requested_at=now() WHERE id=?',[$p]);
        [, $repo,$commerce]=paymentServices($db);$ops=new PaymentOperations($db,$repo,$commerce);
        $r=$ops->recover(1,hrtime(true)/1e9+35,false);same([],$r['review']);
        same(['authorize'],array_column(journal($p),'operation'));same('READY',$db->one("SELECT state FROM payment_operations WHERE payment_id=? AND kind='void'",[$p])['state']);
        $ops->recover(1,hrtime(true)/1e9-1,false);same(['authorize'],array_column(journal($p),'operation'));
        $ops->recover();same(['authorize','void'],array_column(journal($p),'operation'));same('CANCELLED',$repo->payment($p)['status']);
        $db->execute("UPDATE payment_operations SET state='UNKNOWN' WHERE payment_id=? AND kind='void'",[$p]);
        $r=$ops->recover(1,hrtime(true)/1e9+35,false);same([],$r['review']);
        $r=$ops->recover();same(1,count($r['review']));same($p,$r['review'][0]['payment_id']);same(1,journal($p)[1]['attempts']);
    }finally{schedulingCleanup($db,$schema);}
});
test('SQL contention remains bounded and failed local session completion retains the fence',function(){
    [$db,$schema]=schedulingSchema();$blocker=paymentSchemaConnection($schema);
    try{
        [$id,$u]=schedulingSession($db);$settings=$db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock,current_setting('transaction_timeout') AS transaction");
        $blocker->pdo()->beginTransaction();$blocker->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$u]);$start=hrtime(true);
        $r=schedulingWorker($db)->run();$elapsed=(hrtime(true)-$start)/1e9;truth($elapsed<3);same(1,$r['sessionsFailed']);
        same('REVOKE_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);truth($db->one('SELECT sessions_blocked FROM users WHERE id=?',[$u])['sessions_blocked']);
        same($settings,$db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock,current_setting('transaction_timeout') AS transaction"));
    }finally{if($blocker->pdo()->inTransaction()){$blocker->pdo()->rollBack();}schedulingCleanup($db,$schema);}
});
test('a long multi-statement payment apply is bounded without replaying its uncertain remote effect',function(){
    [$db,$schema]=schedulingSchema();
    try{
        [$session]=schedulingSession($db);[$u,$order,$product]=paymentFixture('CARD','AUTHORIZED',[],$db);$p=$order['paymentId'];
        [, $repo,,$payments]=paymentServices($db);$payments->confirm($u,$p);
        $payments->webhook(['paymentId'=>$p,'status'=>'CAPTURED','transactionId'=>'txn-'.$p],Ids::uuid());
        [$trigger,$function]=paymentFailureTrigger($db,$p,'SENT');
        try{try{$payments->refund($p);throw new RuntimeException('expected claim failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}}
        finally{dropPaymentTrigger($db,$trigger,$function);}
        $category=$db->one('SELECT category_id FROM products WHERE id=?',[$product])['category_id'];
        for($i=0;$i<9;$i++){
            $extra=Ids::uuid();$db->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Bounded stock','10.23',8)",[$extra,$category]);
            $db->execute("INSERT INTO order_items(order_id,product_id,product_name,quantity,price_snapshot) VALUES(?,?,'Bounded stock',2,'10.23')",[$order['id'],$extra]);
        }
        // Each UPDATE finishes inside the 1s statement limit, but the transaction
        // would take >6s. Only a transaction-wide bound catches the growing loop.
        $db->execute('CREATE FUNCTION scheduling_slow_stock() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.6); RETURN NEW; END $$');
        $db->execute('CREATE TRIGGER scheduling_slow_stock BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION scheduling_slow_stock()');
        $pid=$db->one('SELECT pg_backend_pid() AS pid')['pid'];$start=hrtime(true);
        try{schedulingWorker($db)->run();throw new RuntimeException('expected bounded transaction failure');}
        catch(PDOException){} // PDO/rollback error text varies after the backend is terminated; verify time, disconnect and durable state below.
        $elapsed=(hrtime(true)-$start)/1e9;truth($elapsed>=4.5 && $elapsed<8);
        $db=paymentSchemaConnection($schema);
        same(0,$db->one('SELECT count(*) AS n FROM pg_stat_activity WHERE pid=?',[$pid])['n']);
        print 'Bounded apply observation: '.json_encode(['elapsedSeconds'=>round($elapsed,3),'connectionTerminated'=>true],JSON_THROW_ON_ERROR)."\n";
        same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$session])['state']);
        same('CAPTURED',$db->one('SELECT status FROM payments WHERE id=?',[$p])['status']);same(8,$db->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);
        same('SENT',$db->one("SELECT state FROM payment_operations WHERE payment_id=? AND kind='refund'",[$p])['state']);
        same(1,$db->one("SELECT count(*) AS n FROM payment_operation_observations o JOIN payment_operations op ON op.id=o.operation_id WHERE op.payment_id=? AND op.kind='refund'",[$p])['n']);
        $db->execute("UPDATE payment_operations SET lease_until=clock_timestamp()-interval '1 second' WHERE payment_id=? AND kind='refund'",[$p]);
        schedulingWorker($db)->run();same('UNKNOWN',$db->one("SELECT state FROM payment_operations WHERE payment_id=? AND kind='refund'",[$p])['state']);
        same(['authorize','refund'],array_column(journal($p),'operation'));same(1,journal($p)[1]['attempts']);
    }finally{schedulingCleanup(paymentSchemaConnection($schema),$schema);}
});
test('empty scheduler budget keeps the default and undersized budgets cannot claim work',function(){
    [$db,$schema]=schedulingSchema();$prior=getenv('SCHEDULER_JOB_TIMEOUT_SECONDS');
    try{
        [$id]=schedulingSession($db);putenv('SCHEDULER_JOB_TIMEOUT_SECONDS=89');
        try{schedulingWorker($db)->run();throw new RuntimeException('undersized recovery budget accepted');}catch(InvalidArgumentException){}
        same(0,$db->one('SELECT recovery_attempts FROM password_session_operations WHERE id=?',[$id])['recovery_attempts']);
        putenv('SCHEDULER_JOB_TIMEOUT_SECONDS=');schedulingWorker($db)->run();same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);
    }finally{putenv($prior===false?'SCHEDULER_JOB_TIMEOUT_SECONDS':'SCHEDULER_JOB_TIMEOUT_SECONDS='.$prior);schedulingCleanup($db,$schema);}
});
test('real scheduler completes sessions and payments across bounded ticks despite a delayed payment backlog',function(){
    [$db,$schema]=schedulingSchema();$scheduler=null;$pipes=[];$budget=90;
    try{
        [$failed,$blocked]=schedulingSession($db,503);[$healthy,$user]=schedulingSession($db);
        $db->execute("UPDATE password_session_operations SET next_retry_at=clock_timestamp()-interval '2 seconds' WHERE id=?",[$failed]);
        $db->execute("UPDATE password_session_operations SET next_retry_at=clock_timestamp()-interval '1 second' WHERE id=?",[$healthy]);
        // Fifty two-second responses exceed the real 90-second parent budget.
        // Even several fair ticks must leave a substantial durable payment queue.
        for($i=0;$i<50;$i++){schedulingPayment($db,2000);}
        $uncertain=[];
        foreach(['UNKNOWN','SENT'] as $state){
            [$u,$o]=paymentFixture('CARD','AUTHORIZED',[],$db);paymentServices($db)[3]->confirm($u,$o['paymentId']);
            $db->execute("UPDATE payment_operations SET state=?,lease_token=?,lease_until=clock_timestamp()+interval '10 minutes' WHERE payment_id=?",[$state,Ids::uuid(),$o['paymentId']]);$uncertain[]=$o['paymentId'];
        }
        truth(is_file('/opt/scheduler/scheduler.php'));
        @unlink('/app/runtime/scheduler-state.json');
        $env=getenv();$env['PGOPTIONS']='-c search_path='.$schema;$env['SCHEDULER_JOB_TIMEOUT_SECONDS']=(string)$budget;
        $start=hrtime(true);$scheduler=proc_open([PHP_BINARY,'/opt/scheduler/scheduler.php','maintenance/recover','1'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$state=[];$output='';$errors='';
        do{
            usleep(100000);$output.=stream_get_contents($pipes[1]);$errors.=stream_get_contents($pipes[2]);
            if(is_file('/app/runtime/scheduler-state.json')){$state=json_decode(file_get_contents('/app/runtime/scheduler-state.json'),true,32,JSON_THROW_ON_ERROR);}
            if(($state['lastResult']??null)==='timeout' || ($state['lastResult']??null)==='failed'){break;}
            $complete=$db->one('SELECT state FROM password_session_operations WHERE id=?',[$healthy])['state']==='COMPLETE';
            $sent=$db->one("SELECT count(*) AS n FROM payment_operations WHERE state='SUCCEEDED'")['n'];
            if($complete && $sent>=4 && ($state['lastResult']??null)==='success'){break;}
        }while((hrtime(true)-$start)/1e9<$budget+8);
        $elapsed=(hrtime(true)-$start)/1e9;
        print 'Scheduler observation: '.json_encode(['result'=>$state['lastResult']??null,'elapsedSeconds'=>round($elapsed,3),'healthySession'=>$db->one('SELECT state,recovery_attempts FROM password_session_operations WHERE id=?',[$healthy])],JSON_THROW_ON_ERROR)."\n";
        same('success',$state['lastResult']??null);same(0,$state['lastExitCode']);truth($elapsed<$budget-5);
        same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$healthy])['state']);
        same(1,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$user])['calls']);
        same('REVOKE_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$failed])['state']);truth($db->one('SELECT sessions_blocked FROM users WHERE id=?',[$blocked])['sessions_blocked']);
        errorCode('PASSWORD_CHANGED',fn()=>(new SessionService($db,new ShopRepository($db)))->issue($blocked));
        truth($db->one("SELECT count(*) AS n FROM payment_operations WHERE state='READY'")['n']>=45);
        foreach($uncertain as $p){same(1,journal($p)[0]['attempts']);same(1,count(journal($p)));}
        $reports=array_filter(explode("\n",trim($output)),fn($line)=>str_starts_with($line,'{'));truth(count($reports)>=2);
        foreach($reports as $line){$r=json_decode($line,true,32,JSON_THROW_ON_ERROR);truth($r['sessionsCompleted']+$r['sessionsFailed']<=1);truth($r['paymentsDispatched']<=1);foreach($r as $count){truth(is_int($count));}}
        same('',$errors);
    }finally{
        if(is_resource($scheduler)){proc_terminate($scheduler);foreach($pipes as $pipe){fclose($pipe);}proc_close($scheduler);}
        schedulingCleanup($db,$schema);
    }
});
test('F2 two replicas skip an advisory-busy head across three scans without consuming its attempts',function(){
    [$db,$schema]=schedulingSchema();$blocker=paymentSchemaConnection($schema);
    try{
        [$head,$blocked]=schedulingSession($db);$scope='password-session-recovery:'.$head;
        $db->execute("UPDATE password_session_operations SET next_retry_at=clock_timestamp()-interval '1 day' WHERE id=?",[$head]);
        $blocker->one('SELECT pg_advisory_lock(hashtextextended(?,0))',[$scope]);
        $before=$db->one('SELECT * FROM password_session_operations WHERE id=?',[$head]);
        for($scan=0;$scan<3;$scan++){
            $later=[];for($i=0;$i<3;$i++){$later[]=schedulingSession($db);}
            $start=hrtime(true);$replicas=[];$env=getenv();$env['PGOPTIONS']='-c search_path='.$schema;
            for($i=0;$i<2;$i++){
                $process=proc_open([PHP_BINARY,'/app/yii','maintenance/recover'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
                $replicas[]=[$process,$pipes];
            }
            $done=0;foreach($replicas as [$process,$pipes]){
                $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);foreach($pipes as $pipe){fclose($pipe);}same(0,proc_close($process));same('',$errors);
                $report=json_decode($output,true,32,JSON_THROW_ON_ERROR);truth($report['sessionsCompleted']+$report['sessionsFailed']<=1);$done+=$report['sessionsCompleted'];
            }
            truth((hrtime(true)-$start)/1e9<5);truth($done>=1);
            same($before,$db->one('SELECT * FROM password_session_operations WHERE id=?',[$head]));
            same(0,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$blocked])['calls']);
            truth($db->one('SELECT sessions_blocked FROM users WHERE id=?',[$blocked])['sessions_blocked']);
            errorCode('PASSWORD_CHANGED',fn()=>(new SessionService($db,new ShopRepository($db)))->issue($blocked));
            // No SELECT side effect may leave locks on other candidates behind.
            foreach($later as [$id,$user]){
                $lock='password-session-recovery:'.$id;
                truth($blocker->one('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired',[$lock])['acquired']);
                $blocker->one('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$lock]);
                $job=$db->one('SELECT state,recovery_attempts FROM password_session_operations WHERE id=?',[$id]);
                same($job['state']==='COMPLETE'?1:0,$job['recovery_attempts']);
                same($job['state']!=='COMPLETE',$db->one('SELECT sessions_blocked FROM users WHERE id=?',[$user])['sessions_blocked']);
            }
        }
    }finally{$blocker->one('SELECT pg_advisory_unlock_all()');schedulingCleanup($db,$schema);}
});
test('F1 combined worker keeps a verified no-effect refund in its manual count without replay',function(){
    [$db,$schema]=schedulingSchema();
    try{
        [$user,$order]=paymentFixture('CARD','AUTHORIZED',['reverse_code'=>409],$db);$p=$order['paymentId'];[, $repo,$commerce,$payments]=paymentServices($db);
        $payments->confirm($user,$p);$payments->webhook(['paymentId'=>$p,'status'=>'CAPTURED','transactionId'=>'txn-'.$p],Ids::uuid());
        errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$payments->refund($p));$op=$db->one("SELECT id FROM payment_operations WHERE payment_id=? AND kind='refund'",[$p]);
        (new PaymentOperations($db,$repo,$commerce))->reconcile($op['id'],'NO_EFFECT','txn-'.$p,'PAY-506: final no effect; old sender terminated');
        same(1,schedulingWorker($db)->run()['paymentsManual']);same(1,schedulingWorker($db)->run()['paymentsManual']);same(1,journal($p)[1]['attempts']);same('CAPTURED',$repo->payment($p)['status']);
    }finally{schedulingCleanup($db,$schema);}
});
test('F2 the busy candidate window is bounded and leaves no advisory locks on a reused worker connection',function(){
    [$db,$schema]=schedulingSchema();$blocker=paymentSchemaConnection($schema);
    try{
        $heads=[];for($i=0;$i<25;$i++){
            [$id]=schedulingSession($db);$heads[]=$id;$db->execute("UPDATE password_session_operations SET next_retry_at=clock_timestamp()-interval '1 day' WHERE id=?",[$id]);
            $blocker->one('SELECT pg_advisory_lock(hashtextextended(?,0))',['password-session-recovery:'.$id]);
        }
        [$later]=schedulingSession($db);$start=hrtime(true);same(0,schedulingWorker($db)->run()['sessionsCompleted']);truth((hrtime(true)-$start)/1e9<3);
        same(0,$db->one('SELECT sum(recovery_attempts)::int AS n FROM password_session_operations')['n']);
        $blocker->one('SELECT pg_advisory_unlock_all()');same(1,schedulingWorker($db)->run()['sessionsCompleted']);
        same(1,$db->one('SELECT sum(recovery_attempts)::int AS n FROM password_session_operations')['n']);
        same(0,$db->one("SELECT count(*) AS n FROM pg_locks WHERE pid=pg_backend_pid() AND locktype='advisory'")['n']);
        same('REVOKE_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$later])['state']);
    }finally{$blocker->one('SELECT pg_advisory_unlock_all()');schedulingCleanup($db,$schema);}
});
test('F3 local expiry transaction timeout preserves committed retention and rolls back stock and intent',function(){
    [$db,$schema]=schedulingSchema();
    try{
        [$user,$order,$product]=paymentFixture('ON_DELIVERY','AUTHORIZED',[],$db);
        $db->execute("UPDATE orders SET created_at=now()-interval '1 day' WHERE id=?",[$order['id']]);
        $category=$db->one('SELECT category_id FROM products WHERE id=?',[$product])['category_id'];
        for($i=0;$i<9;$i++){
            $extra=Ids::uuid();$db->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Maintenance stock','10.23',8)",[$extra,$category]);
            $db->execute("INSERT INTO order_items(order_id,product_id,product_name,quantity,price_snapshot) VALUES(?,?,'Maintenance stock',2,'10.23')",[$order['id'],$extra]);
        }
        $db->execute("INSERT INTO processed_webhooks(id,processed_at) VALUES('retention-before-local-timeout',now()-interval '2 days')");
        $db->execute('CREATE FUNCTION maintenance_slow_stock() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.6); RETURN NEW; END $$');
        $db->execute('CREATE TRIGGER maintenance_slow_stock BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION maintenance_slow_stock()');
        [, $repo,$commerce]=paymentServices($db);$start=hrtime(true);
        try{(new MaintenanceService($db,$repo,$commerce))->run();throw new RuntimeException('expected bounded local expiry failure');}catch(PDOException){}
        $elapsed=(hrtime(true)-$start)/1e9;truth($elapsed>=4.5 && $elapsed<8);
        $db=paymentSchemaConnection($schema);same(0,$db->one('SELECT count(*) AS n FROM processed_webhooks')['n']);
        same('CREATED',$db->one('SELECT status FROM orders WHERE id=?',[$order['id']])['status']);same(null,$db->one('SELECT stock_restored_at FROM orders WHERE id=?',[$order['id']])['stock_restored_at']);
        same(8,$db->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);same(0,$db->one('SELECT count(*) AS n FROM payment_operations')['n']);
        $db->execute('DROP TRIGGER maintenance_slow_stock ON products');[, $repo,$commerce]=paymentServices($db);
        $settings=$db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock,current_setting('transaction_timeout') AS transaction");
        same(1,(new MaintenanceService($db,$repo,$commerce))->run());same(10,$db->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);
        same($settings,$db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock,current_setting('transaction_timeout') AS transaction"));
        print 'Maintenance bounded local observation: '.json_encode(['elapsedSeconds'=>round($elapsed,3),'retentionCommitted'=>true],JSON_THROW_ON_ERROR)."\n";
    }finally{schedulingCleanup(paymentSchemaConnection($schema),$schema);}
});
test('F3 real maintenance scheduler retains cleanup progress and queues expiry without payment HTTP',function(){
    [$db,$schema]=schedulingSchema();$scheduler=null;$pipes=[];
    try{
        [, $repo,$commerce,$payments]=paymentServices($db);$expired=[];
        for($i=0;$i<105;$i++){
            [$user,$order,$product]=paymentFixture('CARD','AUTHORIZED',[],$db);$payments->confirm($user,$order['paymentId']);$expired[]=[$order,$product];
            paymentDb()->execute('UPDATE payment_test_scenarios SET delay_ms=2000 WHERE payment_id=?',[$order['paymentId']]);
        }
        $db->execute("UPDATE orders SET created_at=now()-interval '1 day'");$db->execute("UPDATE payments SET updated_at=now()-interval '1 day'");
        $uncertain=[];
        foreach(['SENT','UNKNOWN'] as $state){
            [$user,$order]=paymentFixture('CARD','AUTHORIZED',[],$db);$payments->confirm($user,$order['paymentId']);$p=$order['paymentId'];
            $db->execute("UPDATE orders SET status='PAID' WHERE id=?",[$order['id']]);
            $db->execute("UPDATE payment_operations SET state=?,lease_until=clock_timestamp()+interval '10 minutes' WHERE payment_id=?",[$state,$p]);$uncertain[]=$p;
        }
        [$user]=paymentFixture('ON_DELIVERY','AUTHORIZED',[],$db);$session=new SessionService($db,$repo);$old=$session->issue($user);$live=$session->issue($user);
        foreach(['access','refresh'] as $kind){$db->execute('UPDATE '.$kind.'_sessions SET expires_at=now()-interval \'1 day\' WHERE token_hash=decode(?,\'hex\')',[hash('sha256',$old[$kind.'Token'])]);}
        $db->execute("INSERT INTO idempotency_keys(user_id,endpoint,key,response,created_at) SELECT ?,'retention',g::text,'{}',now()-interval '2 days' FROM generate_series(1,1001) g",[$user]);
        $db->execute("INSERT INTO idempotency_keys(user_id,endpoint,key,response) VALUES(?,'retention','live','{}')",[$user]);
        $db->execute("INSERT INTO processed_webhooks(id,processed_at) SELECT 'old-'||g,now()-interval '2 days' FROM generate_series(1,1001) g");$db->execute("INSERT INTO processed_webhooks(id) VALUES('live')");
        $db->execute("INSERT INTO rate_limit_hits(bucket_key,occurred_at) SELECT 'old',now()-interval '1 day' FROM generate_series(1,1001)");$db->execute("INSERT INTO rate_limit_hits(bucket_key,occurred_at) VALUES('live',now())");
        $db->execute("INSERT INTO login_attempts(user_id,last_attempt) VALUES(?,now()-interval '2 days')",[$user]);
        $device=Ids::uuid();$db->execute("INSERT INTO device_biometrics(id,user_id,device_id,device_name,public_key,key_algorithm) VALUES(?,?,?,'Retention',decode('01','hex'),'EdDSA')",[$device,$user,Ids::uuid()]);
        $db->execute("INSERT INTO biometric_challenges(challenge,credential_id,expires_at) VALUES('expired',?,now()-interval '1 day'),('live',?,now()+interval '1 day')",[$device,$device]);
        truth(is_file('/opt/scheduler/scheduler.php'));@unlink('/app/runtime/scheduler-state.json');
        $env=getenv();$env['PGOPTIONS']='-c search_path='.$schema;$env['SCHEDULER_JOB_TIMEOUT_SECONDS']='40';
        $start=hrtime(true);$scheduler=proc_open([PHP_BINARY,'/opt/scheduler/scheduler.php','maintenance/run','30'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        foreach($pipes as $pipe){stream_set_blocking($pipe,false);}$state=[];$output='';$errors='';
        do{
            usleep(100000);$output.=stream_get_contents($pipes[1]);$errors.=stream_get_contents($pipes[2]);
            if(is_file('/app/runtime/scheduler-state.json')){$state=json_decode(file_get_contents('/app/runtime/scheduler-state.json'),true,32,JSON_THROW_ON_ERROR);}
            if(($state['lastResult']??null)!==null){break;}
        }while((hrtime(true)-$start)/1e9<45);
        $elapsed=(hrtime(true)-$start)/1e9;
        print 'Maintenance scheduler observation: '.json_encode(['result'=>$state['lastResult']??null,'elapsedSeconds'=>round($elapsed,3),'output'=>trim($output)],JSON_THROW_ON_ERROR)."\n";
        same('success',$state['lastResult']??null);same(0,$state['lastExitCode']);truth($elapsed<35);same('',$errors);
        proc_terminate($scheduler);foreach($pipes as $pipe){fclose($pipe);}proc_close($scheduler);$scheduler=null;
        same(100,$db->one("SELECT count(*) AS n FROM payment_operations WHERE kind='void' AND state='READY'")['n']);
        same(5,$db->one("SELECT count(*) AS n FROM orders WHERE status='CREATED' AND payment_method='CARD'")['n']);
        foreach(['idempotency_keys'=>'key','processed_webhooks'=>'id','rate_limit_hits'=>'bucket_key'] as $table=>$key){
            same(2,$db->one('SELECT count(*) AS n FROM '.$table)['n']);same(1,$db->one('SELECT count(*) AS n FROM '.$table.' WHERE '.$key.'=?',['live'])['n']);
        }
        foreach(['access','refresh'] as $kind){same(1,$db->one('SELECT count(*) AS n FROM '.$kind.'_sessions')['n']);}
        same(0,$db->one('SELECT count(*) AS n FROM login_attempts')['n']);same([['challenge'=>'live']],$db->all('SELECT challenge FROM biometric_challenges'));
        // Another local tick drains the remainder, preserving every queued effect.
        same(5,(new MaintenanceService($db,$repo,$commerce))->run());same(0,(new MaintenanceService($db,$repo,$commerce))->run());
        same(105,$db->one("SELECT count(*) AS n FROM payment_operations WHERE kind='void' AND state='READY'")['n']);
        foreach(['idempotency_keys','processed_webhooks','rate_limit_hits'] as $table){same(1,$db->one('SELECT count(*) AS n FROM '.$table)['n']);}
        foreach($expired as [$order,$product]){
            same(['authorize'],array_column(journal($order['paymentId']),'operation'));same(10,$db->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);
            paymentDb()->execute('UPDATE payment_test_scenarios SET delay_ms=0 WHERE payment_id=?',[$order['paymentId']]);
        }
        // Actual dedicated worker now sends one committed intent per scan.
        same(1,schedulingWorker($db)->run()['paymentsDispatched']);same(1,schedulingWorker($db)->run()['paymentsDispatched']);
        same(2,$db->one("SELECT count(*) AS n FROM payment_operations WHERE kind='void' AND state='SUCCEEDED'")['n']);
        foreach($expired as [$order,$product]){foreach(journal($order['paymentId']) as $entry){same(1,$entry['attempts']);same(0,$entry['in_transaction']);}same(10,$db->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);}
        foreach($uncertain as $p){same(['authorize'],array_column(journal($p),'operation'));same(1,journal($p)[0]['attempts']);}
    }finally{
        if(is_resource($scheduler)){proc_terminate($scheduler);foreach($pipes as $pipe){fclose($pipe);}proc_close($scheduler);}
        schedulingCleanup($db,$schema);
    }
});
test('R3 round2 two workers progress past row-busy first expired SENT without HTTP replay',function(){
    [$db,$schema]=schedulingSchema();$blocker=paymentSchemaConnection($schema);
    try{
        $orders=[];
        for($i=0;$i<2;$i++){
            [$u,$o]=paymentFixture('CARD','AUTHORIZED',[],$db);paymentServices($db)[3]->confirm($u,$o['paymentId']);$orders[]=$o;
            $db->execute("UPDATE payment_operations SET state='SENT',lease_until=clock_timestamp()-interval '1 second' WHERE payment_id=?",[$o['paymentId']]);
        }
        usort($orders,fn($a,$b)=>strcmp($a['paymentId'],$b['paymentId']));[$a,$b]=$orders;
        $blocker->pdo()->beginTransaction();$blocker->one('SELECT id FROM orders WHERE id=? FOR UPDATE',[$a['id']]);
        $observations=[];
        for($scan=0;$scan<2;$scan++){
            $env=getenv();$env['PGOPTIONS']='-c search_path='.$schema;$workers=[];$start=hrtime(true);
            for($i=0;$i<2;$i++){
                $process=proc_open([PHP_BINARY,'/app/yii','maintenance/recover'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);$workers[]=[$process,$pipes];
            }
            $results=[];
            foreach($workers as [$process,$pipes]){
                $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);foreach($pipes as $pipe){fclose($pipe);}
                $results[]=[proc_close($process),$output,$errors];
            }
            truth((hrtime(true)-$start)/1e9<4);
            $observations[]=['workers'=>$results,'a'=>$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$a['paymentId']])['state'],
                'b'=>$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$b['paymentId']])['state']];
        }
        print 'R3 contention observations: '.json_encode($observations,JSON_THROW_ON_ERROR)."\n";
        foreach($observations as $observation){
            same('UNKNOWN',$observation['b']);same('SENT',$observation['a']);
            foreach($observation['workers'] as [$exit,$output,$errors]){same(0,$exit);same('',$errors);same(0,json_decode($output,true,32,JSON_THROW_ON_ERROR)['paymentsDispatched']);}
        }
        $blocker->pdo()->rollBack();schedulingWorker($db)->run();
        same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$a['paymentId']])['state']);
        foreach($orders as $o){same(1,count(journal($o['paymentId'])));same(1,journal($o['paymentId'])[0]['attempts']);same('AUTHORIZED',$db->one('SELECT status FROM payments WHERE id=?',[$o['paymentId']])['status']);}
    }finally{if($blocker->pdo()->inTransaction()){$blocker->pdo()->rollBack();}schedulingCleanup($db,$schema);}
});
/** Real PDO statement observer: every SQL result still comes from PostgreSQL.
 * Installs a second-connection lock precisely after preparation commits. */
class RecoveryClaimBarrier extends PDOStatement {
    protected function __construct(private Closure $barrier) {}
    public function execute(?array $params=null): bool {
        $result=parent::execute($params);
        if($this->queryString==="SELECT id FROM payment_operations WHERE payment_id=? AND state='READY'"){($this->barrier)();}
        return $result;
    }
}
test('R3 round2 READY claim skips an order taken after preparation and progresses another candidate',function(){
    [$db,$schema]=schedulingSchema();$blocker=paymentSchemaConnection($schema);$armed=true;
    try{
        $orders=[];for($i=0;$i<2;$i++){[, $o]=schedulingPayment($db);$orders[]=$o;}
        usort($orders,fn($a,$b)=>strcmp($a['paymentId'],$b['paymentId']));[$a,$b]=$orders;
        $db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[RecoveryClaimBarrier::class,[function()use($db,$blocker,$a,&$armed){
            if(!$armed){return;}$armed=false;same(false,$db->pdo()->inTransaction());
            $blocker->pdo()->beginTransaction();$blocker->one('SELECT id FROM orders WHERE id=? FOR UPDATE',[$a['id']]);
        }]]);
        same(1,schedulingWorker($db)->run()['paymentsDispatched']);same(false,$armed);
        same([],journal($a['paymentId']));same(1,journal($b['paymentId'])[0]['attempts']);
        same('READY',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$a['paymentId']])['state']);
        $blocker->pdo()->rollBack();same(1,schedulingWorker($db)->run()['paymentsDispatched']);
        same(1,journal($a['paymentId'])[0]['attempts']);same(0,journal($a['paymentId'])[0]['in_transaction']);
    }finally{if($blocker->pdo()->inTransaction()){$blocker->pdo()->rollBack();}schedulingCleanup($db,$schema);}
});
test('R3 round2 unexpected SQL errors roll back and propagate rather than become successful busy scans',function(){
    [$db,$schema]=schedulingSchema();
    try{
        [$u,$o]=paymentFixture('CARD','AUTHORIZED',[],$db);paymentServices($db)[3]->confirm($u,$o['paymentId']);
        $db->execute("UPDATE payment_operations SET state='SENT',lease_until=clock_timestamp()-interval '1 second' WHERE payment_id=?",[$o['paymentId']]);
        [$trigger,$function]=paymentFailureTrigger($db,$o['paymentId'],'UNKNOWN');
        try{try{schedulingWorker($db)->run();throw new RuntimeException('SQL failure hidden');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}}
        finally{dropPaymentTrigger($db,$trigger,$function);}
        same('SENT',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$o['paymentId']])['state']);same(1,journal($o['paymentId'])[0]['attempts']);
    }finally{schedulingCleanup($db,$schema);}
});
$filter=Env::get('RECOVERY_TEST_FILTER');
if($filter!==''){$tests=array_filter($tests,fn($name)=>str_contains($name,$filter),ARRAY_FILTER_USE_KEY);if(!$tests){throw new RuntimeException('No matching recovery tests');}}
runTests();
