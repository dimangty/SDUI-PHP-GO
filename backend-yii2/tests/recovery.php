<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/payment-fixture.php';
use Shop\application\{RecoveryService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient,Keycloak};
use Shop\repositories\ShopRepository;
function workerDb(): Database {
    $db=paymentDb();$db->one("SELECT set_config('application_name',?,false)",['recovery-worker-'.Ids::uuid()]);return $db;
}
function recoveryWorker(): RecoveryService {return new RecoveryService(workerDb(),new Keycloak(new HttpClient(2)));}
function recoveryJob(string $state='REVOKE_PENDING',int $code=204,int $delay=0): array {
    $db=paymentDb();$u=Ids::uuid();$id=Ids::uuid();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,sessions_blocked) VALUES(?,?,'','Worker','+79991234567',true)",[$u,$u.'@recovery.test']);
    $db->execute('INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,?)',[$id,$u,$state]);
    $db->execute('INSERT INTO recovery_test_provider(user_id,response_code,delay_ms) VALUES(?,?,?)',[$u,$code,$delay]);return [$id,$u];
}
function workerJob(string $id): array {return paymentDb()->one('SELECT * FROM password_session_operations WHERE id=?',[$id]);}
function clearWorkerJobs(): void {paymentDb()->execute('DELETE FROM users WHERE id IN (SELECT user_id FROM recovery_test_provider)');paymentDb()->execute('DELETE FROM recovery_test_provider');}
$db=paymentDb();$db->execute('CREATE TABLE IF NOT EXISTS recovery_test_provider(user_id uuid PRIMARY KEY,response_code int,delay_ms int,calls int NOT NULL DEFAULT 0,open_transactions int NOT NULL DEFAULT 0)');
$socket=stream_socket_server('tcp://0.0.0.0:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,__DIR__.'/recovery-provider.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
$owner=getmypid();
register_shutdown_function(function()use($process,$owner){if(getmypid()===$owner){proc_terminate($process);proc_close($process);}});
for($i=0;$i<100;$i++){$s=@stream_socket_client('tcp://127.0.0.1:'.explode(':',$address)[1],$errno,$error,.02);if($s){fclose($s);break;}usleep(10000);}
putenv('KEYCLOAK_ISSUER=http://127.0.0.1:'.explode(':',$address)[1].'/realms/worker');putenv('KEYCLOAK_ADMIN_TOKEN=fixture-admin');putenv('KEYCLOAK_CLIENT_ID=shopkmp-backend');putenv('KEYCLOAK_CLIENT_SECRET=fixture-secret');
test('fresh worker automatically completes safe revocation without a transaction across HTTP',function(){
    clearWorkerJobs();[$id,$u]=recoveryJob();$report=recoveryWorker()->run();same('COMPLETE',workerJob($id)['state']);same(1,$report['sessionsCompleted']);
    same(0,paymentDb()->one('SELECT open_transactions FROM recovery_test_provider WHERE user_id=?',[$u])['open_transactions']);
    $fresh=paymentDb();same($u,(new SessionService($fresh,new ShopRepository($fresh)))->issue($u)['userId']);
    recoveryWorker()->run();same(1,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls']);
});
test('failed revocations back off exponentially across worker restarts then enter manual queue',function(){
    clearWorkerJobs();$manualBefore=recoveryWorker()->run()['sessionsManual'];[$id,$u]=recoveryJob('REVOKE_PENDING',503);
    for($attempt=1;$attempt<=5;$attempt++){
        $before=microtime(true);$r=recoveryWorker()->run();$job=workerJob($id);same($attempt,$job['recovery_attempts']);same('REVOKE_PENDING',$job['state']);
        truth(strtotime($job['next_retry_at'])-$before>=30*(2**($attempt-1))-2);
        recoveryWorker()->run();same($attempt,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls']);
        paymentDb()->execute("UPDATE password_session_operations SET next_retry_at=clock_timestamp()-interval '1 second' WHERE id=?",[$id]);
    }
    $r=recoveryWorker()->run();same($manualBefore+1,$r['sessionsManual']);same(5,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls']);
    truth(!str_contains(json_encode($r),'provider-secret'));truth(paymentDb()->one('SELECT sessions_blocked FROM users WHERE id=?',[$u])['sessions_blocked']);
});
test('RESET_PENDING never runs automatically and manual recovery requires explicit confirmation and ticket',function(){
    clearWorkerJobs();$w=recoveryWorker();$manualBefore=$w->run()['sessionsManual'];[$id,$u]=recoveryJob('RESET_PENDING');same($manualBefore+1,$w->run()['sessionsManual']);same(0,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls']);
    errorCode('BAD_REQUEST',fn()=>$w->recoverSession($id,false,'OPS-123'));errorCode('BAD_REQUEST',fn()=>$w->recoverSession($id,true,'raw password here'));
    $w->recoverSession($id,true,'OPS-123');$job=workerJob($id);same('COMPLETE',$job['state']);same('OPS-123',$job['operator_evidence_ref']);truth($job['operator_confirmed_at']!==null);
    $w->recoverSession($id,true,'OPS-456');same('OPS-123',workerJob($id)['operator_evidence_ref']);
    same(1,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls']);
});
test('two worker processes reserve one due job and expired reservations recover after a crash',function(){
    clearWorkerJobs();[$id,$u]=recoveryJob('REVOKE_PENDING',204,300);
    $workers=[];for($i=0;$i<2;$i++){$workers[]=paymentWorker(fn()=>recoveryWorker()->run());}foreach($workers as $worker){finishPaymentWorker($worker);}
    same(1,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls']);same('COMPLETE',workerJob($id)['state']);
    [$id2]=recoveryJob();paymentDb()->execute("UPDATE password_session_operations SET recovery_claim_until=clock_timestamp()+interval '120 seconds',next_retry_at=clock_timestamp()-interval '1 second' WHERE id=?",[$id2]);
    recoveryWorker()->run();same('REVOKE_PENDING',workerJob($id2)['state']);
    paymentDb()->execute("UPDATE password_session_operations SET recovery_claim_until=clock_timestamp()-interval '1 second' WHERE id=?",[$id2]);recoveryWorker()->run();same('COMPLETE',workerJob($id2)['state']);
});
test('worker dispatches unsent payments and reports UNKNOWN only as counts without replay',function(){
    clearWorkerJobs();paymentTestSchema();[$u,$o]=paymentFixture();$id=Ids::uuid();$p=$o['paymentId'];
    paymentDb()->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state) VALUES(?,?,'authorize',?,'READY')",[$id,$p,$p.':authorize']);
    $r=recoveryWorker()->run();same('SUCCEEDED',paymentDb()->one('SELECT state FROM payment_operations WHERE id=?',[$id])['state']);same(1,journal($p)[0]['attempts']);
    paymentDb()->execute("UPDATE payment_operations SET state='UNKNOWN' WHERE id=?",[$id]);$r=recoveryWorker()->run();truth($r['paymentsManual']>=1);truth(!isset($r['review']));same(1,journal($p)[0]['attempts']);
    foreach(['amount','transactionId','operation_key','evidence'] as $field){truth(!str_contains(json_encode($r),$field));}
});
test('readiness requires the combined payment session and recovery schema',function(){
    putenv('OPENSEARCH_URL=');$db=paymentDb();$kernel=new \Shop\infrastructure\ApiKernel($db);
    same(200,$kernel->handle('GET','/health/ready',[],'','192.0.2.94')['status']);
    foreach(['ALTER TABLE payment_operations RENAME TO hidden_payment_operations',
             'ALTER TABLE payment_operation_observations RENAME TO hidden_payment_observations',
             'ALTER TABLE payments RENAME COLUMN refund_requested_at TO hidden_refund_requested_at',
             'ALTER TABLE payment_callback_inbox RENAME TO hidden_callback_inbox',
             'ALTER TABLE payment_transaction_links RENAME TO hidden_transaction_links',
             'ALTER TABLE session_families RENAME TO hidden_session_families',
             'ALTER TABLE users RENAME COLUMN sessions_blocked TO hidden_sessions_blocked',
             'ALTER TABLE password_session_operations RENAME COLUMN recovery_attempts TO hidden_attempts'] as $sql){
        $db->pdo()->beginTransaction();
        try{$db->execute($sql);same(503,$kernel->handle('GET','/health/ready',[],'','192.0.2.94')['status']);}
        finally{$db->pdo()->rollBack();}
    }
});
test('migration 014 refuses pending downgrade and preserves completed operations on down up',function(){
    $db=paymentDb();$schema='recovery_upgrade_'.str_replace('-','',Ids::uuid());
    $db->pdo()->beginTransaction();
    try{
        $db->execute('CREATE SCHEMA '.$schema);$db->execute('SET LOCAL search_path TO '.$schema);
        $db->execute('CREATE FUNCTION uuid_generate_v4() RETURNS uuid LANGUAGE sql AS $$ SELECT public.uuid_generate_v4() $$');
        foreach(glob('/migrations/*.up.sql') as $file){if((int)basename($file)>13){continue;}$db->pdo()->exec(file_get_contents($file));}
        $u=Ids::uuid();$op=Ids::uuid();
        $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Migration','+79991234567')",[$u,$u.'@migration.test']);
        $db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,'RESET_PENDING')",[$op,$u]);
        $db->execute('UPDATE users SET sessions_blocked=TRUE WHERE id=?',[$u]);
        $db->pdo()->exec(file_get_contents('/migrations/014_recovery_schedule.up.sql'));
        same('RESET_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$op])['state']);
        same(0,$db->one('SELECT recovery_attempts FROM password_session_operations WHERE id=?',[$op])['recovery_attempts']);
        truth($db->one('SELECT sessions_blocked FROM users WHERE id=?',[$u])['sessions_blocked']);
        $db->execute('SAVEPOINT pending_rollback');
        try{$db->pdo()->exec(file_get_contents('/migrations/014_recovery_schedule.down.sql'));throw new RuntimeException('unsafe downgrade accepted');}
        catch(PDOException $e){truth(str_contains($e->getMessage(),'pending password session recovery'));$db->execute('ROLLBACK TO SAVEPOINT pending_rollback');}
        $db->execute("UPDATE password_session_operations SET state='COMPLETE' WHERE id=?",[$op]);
        $db->pdo()->exec(file_get_contents('/migrations/014_recovery_schedule.down.sql'));
        $db->pdo()->exec(file_get_contents('/migrations/014_recovery_schedule.up.sql'));
        $job=$db->one('SELECT state,recovery_attempts FROM password_session_operations WHERE id=?',[$op]);same('COMPLETE',$job['state']);same(0,$job['recovery_attempts']);
    }finally{$db->pdo()->rollBack();}
});
test('killed revocation worker leaves a durable fence and a fresh worker retries only after claim expiry',function(){
    clearWorkerJobs();[$id,$u]=recoveryJob('REVOKE_PENDING',204,500);
    [$pid,$socket]=paymentWorker(fn()=>recoveryWorker()->run());
    try{
        $deadline=microtime(true)+5;
        do{$calls=paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls'];if($calls===1){break;}usleep(10000);}while(microtime(true)<$deadline);
        same(1,$calls);posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);truth(pcntl_wifsignaled($status));same(SIGKILL,pcntl_wtermsig($status));
        $job=workerJob($id);same('REVOKE_PENDING',$job['state']);same(1,$job['recovery_attempts']);truth($job['recovery_claim_until']!==null);
        truth(paymentDb()->one('SELECT sessions_blocked FROM users WHERE id=?',[$u])['sessions_blocked']);
        recoveryWorker()->run();same(1,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls']);
        paymentDb()->execute("UPDATE password_session_operations SET recovery_claim_until=clock_timestamp()-interval '1 second',next_retry_at=clock_timestamp()-interval '1 second' WHERE id=?",[$id]);
        recoveryWorker()->run();same('COMPLETE',workerJob($id)['state']);same(2,paymentDb()->one('SELECT calls FROM recovery_test_provider WHERE user_id=?',[$u])['calls']);
    }finally{fclose($socket);posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}
});
test('real console emits counts and requires the explicit operator confirmation argument',function(){
    clearWorkerJobs();[$id,$u]=recoveryJob('RESET_PENDING');
    $cli=function(array $args): array {
        $p=proc_open([PHP_BINARY,dirname(__DIR__).'/yii',...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    [$exit,$out]=$cli(['maintenance/recover']);same(0,$exit);$report=json_decode($out,true,32,JSON_THROW_ON_ERROR);truth($report['sessionsManual']>=1);
    foreach($report as $count){truth(is_int($count));}truth(!str_contains($out,$id));same('RESET_PENDING',workerJob($id)['state']);
    [$exit]=$cli(['maintenance/session-recover',$id,'unconfirmed','OPS-789']);truth($exit!==0);same('RESET_PENDING',workerJob($id)['state']);
    [$exit]=$cli(['maintenance/session-recover',$id,'independently-confirmed','OPS-789']);same(0,$exit);same('COMPLETE',workerJob($id)['state']);same('OPS-789',workerJob($id)['operator_evidence_ref']);
});
runTests();
