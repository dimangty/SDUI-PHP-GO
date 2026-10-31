<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/payment-fixture.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\application\{RecoveryService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

// Recovery scans whole queues. Give this suite its own committed namespace so
// interrupted payment fault tests cannot supply unrelated READY work to it.
paymentTestSchema();[$schemaDb,$recoverySchema]=paymentLegacySchema();applyPaymentMigration($schemaDb);
foreach([13,14,15] as $n){$files=glob('/migrations/'.sprintf('%03d',$n).'_*.up.sql');same(1,count($files));$schemaDb->transaction(fn()=>$schemaDb->pdo()->exec(file_get_contents($files[0])));}
$schemaDb=null;$priorPgOptions=getenv('PGOPTIONS');putenv('PGOPTIONS=-c search_path='.$recoverySchema.',public');$recoveryOwner=getmypid();
register_shutdown_function(function()use($recoverySchema,$priorPgOptions,$recoveryOwner){
    if(getmypid()!==$recoveryOwner){return;}
    try{$db=paymentSchemaConnection($recoverySchema);foreach($db->all('SELECT id FROM payments') as $row){$db->execute('DELETE FROM public.payment_test_journal WHERE payment_id=?',[$row['id']]);$db->execute('DELETE FROM public.payment_test_scenarios WHERE payment_id=?',[$row['id']]);}dropPaymentLegacySchema($db,$recoverySchema);}
    finally{putenv($priorPgOptions===false?'PGOPTIONS':'PGOPTIONS='.$priorPgOptions);}
});
function workerDb(): Database {
    $db=paymentDb();$db->one("SELECT set_config('application_name',?,false)",['recovery-worker-'.Ids::uuid()]);return $db;
}
function recoveryWorker(): RecoveryService {return new RecoveryService(workerDb());}
function recoveryJob(string $state='REVOKE_PENDING',int $code=204,int $delay=0): array {
    $db=paymentDb();$u=Ids::uuid();$id=Ids::uuid();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,sessions_blocked) VALUES(?,?,'','Worker','+79991234567',true)",[$u,$u.'@recovery.test']);
    $db->execute('INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,?)',[$id,$u,$state]);
    global $workerUsers;$workerUsers[]=$u;return [$id,$u];
}
function workerJob(string $id): array {return paymentDb()->one('SELECT * FROM password_session_operations WHERE id=?',[$id]);}
function clearWorkerJobs(): void {global $workerUsers;foreach($workerUsers??[] as $u){paymentDb()->execute('DELETE FROM users WHERE id=?',[$u]);}$workerUsers=[];}
test('fresh worker keeps historical revocation manual and fail-closed after restart',function(){
    clearWorkerJobs();[$id,$u]=recoveryJob();$before=workerJob($id);$report=recoveryWorker()->run();same($before,workerJob($id));same(0,$report['sessionsCompleted']);
    $fresh=paymentDb();$fresh->execute('UPDATE users SET local_identity_ready=true WHERE id=?',[$u]);errorCode('PASSWORD_CHANGED',fn()=>(new SessionService($fresh,new ShopRepository($fresh)))->issue($u));
    recoveryWorker()->run();same($before,workerJob($id));
});
test('old retry attempts and schedules never authorize automatic identity resolution',function(){
    clearWorkerJobs();$manualBefore=recoveryWorker()->run()['sessionsManual'];
    foreach([0,1,5] as $attempt){[$id,$u]=recoveryJob();paymentDb()->execute("UPDATE password_session_operations SET recovery_attempts=?,next_retry_at=clock_timestamp()-interval '1 day' WHERE id=?",[$attempt,$id]);$before=workerJob($id);
        recoveryWorker()->run();recoveryWorker()->run();same($before,workerJob($id));truth(paymentDb()->one('SELECT sessions_blocked FROM users WHERE id=?',[$u])['sessions_blocked']);}
    $r=recoveryWorker()->run();same($manualBefore+3,$r['sessionsManual']);same(0,$r['sessionsPending']);truth(!str_contains(json_encode($r),'provider-secret'));
});
test('RESET_PENDING never runs automatically and old confirmation tickets cannot resolve it',function(){
    clearWorkerJobs();$w=recoveryWorker();$manualBefore=$w->run()['sessionsManual'];[$id,$u]=recoveryJob('RESET_PENDING');same($manualBefore+1,$w->run()['sessionsManual']);$before=workerJob($id);
    errorCode('BAD_REQUEST',fn()=>$w->recoverSession($id,false,'OPS-123'));errorCode('BAD_REQUEST',fn()=>$w->recoverSession($id,true,'raw password here'));
    errorCode('BAD_REQUEST',fn()=>$w->recoverSession($id,true,'OPS-123'));errorCode('BAD_REQUEST',fn()=>$w->recoverSession($id,true,'OPS-456'));same($before,workerJob($id));
});
test('two worker processes leave identity jobs and expired historical reservations untouched',function(){
    clearWorkerJobs();[$id,$u]=recoveryJob('REVOKE_PENDING',204,300);
    $workers=[];for($i=0;$i<2;$i++){$workers[]=paymentWorker(fn()=>recoveryWorker()->run());}foreach($workers as $worker){finishPaymentWorker($worker);}
    same('REVOKE_PENDING',workerJob($id)['state']);same(0,workerJob($id)['recovery_attempts']);
    [$id2]=recoveryJob();paymentDb()->execute("UPDATE password_session_operations SET recovery_claim_until=clock_timestamp()+interval '120 seconds',next_retry_at=clock_timestamp()-interval '1 second' WHERE id=?",[$id2]);
    recoveryWorker()->run();same('REVOKE_PENDING',workerJob($id2)['state']);
    paymentDb()->execute("UPDATE password_session_operations SET recovery_claim_until=clock_timestamp()-interval '1 second' WHERE id=?",[$id2]);$before=workerJob($id2);recoveryWorker()->run();same($before,workerJob($id2));
});
test('worker dispatches unsent payments and reports UNKNOWN only as counts without replay',function(){
    clearWorkerJobs();[$u,$o]=paymentFixture();$id=Ids::uuid();$p=$o['paymentId'];
    paymentDb()->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state) VALUES(?,?,'authorize',?,'READY')",[$id,$p,$p.':authorize']);
    $r=recoveryWorker()->run();same('SUCCEEDED',paymentDb()->one('SELECT state FROM payment_operations WHERE id=?',[$id])['state']);same(1,journal($p)[0]['attempts']);
    paymentDb()->execute("UPDATE payment_operations SET state='UNKNOWN' WHERE id=?",[$id]);$r=recoveryWorker()->run();truth($r['paymentsManual']>=1);truth(!isset($r['review']));same(1,journal($p)[0]['attempts']);
    foreach(['amount','transactionId','operation_key','evidence'] as $field){truth(!str_contains(json_encode($r),$field));}
});
test('readiness requires the combined payment session and recovery schema',function()use($recoverySchema){
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
        try{$db->execute('SET LOCAL search_path TO '.$recoverySchema);$db->execute($sql);same(503,$kernel->handle('GET','/health/ready',[],'','192.0.2.94')['status']);}
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
test('abandoned historical recovery reservation never permits automatic fence clearance',function(){
    clearWorkerJobs();[$id,$u]=recoveryJob();paymentDb()->execute("UPDATE password_session_operations SET recovery_attempts=1,recovery_claim_until=clock_timestamp()-interval '1 second',next_retry_at=clock_timestamp()-interval '1 second' WHERE id=?",[$id]);
    $before=workerJob($id);recoveryWorker()->run();same($before,workerJob($id));truth(paymentDb()->one('SELECT sessions_blocked FROM users WHERE id=?',[$u])['sessions_blocked']);
});
test('real console emits counts and rejects legacy resolution even with operator confirmation',function(){
    clearWorkerJobs();[$id,$u]=recoveryJob('RESET_PENDING');
    $cli=function(array $args): array {
        $p=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=0',dirname(__DIR__).'/yii',...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    [$exit,$out]=$cli(['maintenance/recover']);same(0,$exit);$report=json_decode($out,true,32,JSON_THROW_ON_ERROR);truth($report['sessionsManual']>=1);
    foreach($report as $count){truth(is_int($count));}truth(!str_contains($out,$id));same('RESET_PENDING',workerJob($id)['state']);
    [$exit]=$cli(['maintenance/session-recover',$id,'unconfirmed','OPS-789']);truth($exit!==0);same('RESET_PENDING',workerJob($id)['state']);
    [$exit,$out,$err]=$cli(['maintenance/session-recover',$id,'independently-confirmed','OPS-789']);truth($exit!==0);truth(str_contains($out.$err,'auth/'));same('RESET_PENDING',workerJob($id)['state']);same(null,workerJob($id)['operator_evidence_ref']);
    $sentinel='raw-password-not-for-console-7395';[$exit,$out,$err]=$cli(['maintenance/session-recover',$id,'independently-confirmed',$sentinel]);truth($exit!==0);truth(str_contains($out.$err,'auth/'));truth(!str_contains($out.$err,$sentinel));clearWorkerJobs();
});
runTests();
