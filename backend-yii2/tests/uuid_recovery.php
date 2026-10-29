<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SessionProviderFixture.php';
use Shop\application\{PasswordSessionRecovery,RecoveryService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient,Keycloak};
use Shop\repositories\ShopRepository;
function uuidRecoveryDb(): Database {return new Database(Env::get('DATABASE_URL'));}
function uuidRecoveryJob(Database $db): array {
    $user=Ids::uuid();$id=Ids::uuid();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,sessions_blocked) VALUES(?,?,'','UUID Recovery','+79991234567',true)",[$user,$user.'@uuid.test']);
    $db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,'REVOKE_PENDING')",[$id,$user]);return [$id,$user];
}
foreach(['upper','mixed'] as $case){
    test("UUID $case application and console recoveries share the worker advisory lock",function()use($case){
        $db=uuidRecoveryDb();$blocker=uuidRecoveryDb();[$id,$user]=uuidRecoveryJob($db);$scope='password-session-recovery:'.$id;
        $wire=$case==='upper'?strtoupper($id):strtoupper(substr($id,0,18)).substr($id,18);
        $fixture=new SessionProviderFixture('success',$user);$provider=new Keycloak(new HttpClient(.5));
        $sessions=new SessionService($db,new ShopRepository($db));
        try{
            $blocker->one('SELECT pg_advisory_lock(hashtextextended(?,0))',[$scope]);
            errorCode('IDENTITY_PROVIDER_UNAVAILABLE',fn()=>(new PasswordSessionRecovery($db,$sessions,$provider))->recover($wire));
            errorCode('IDENTITY_PROVIDER_UNAVAILABLE',fn()=>(new RecoveryService($db,$provider))->recoverSession($wire,true,'SEC-201'));
            same('REVOKE_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);
        }finally{$blocker->one('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$scope]);}
        try{
            (new RecoveryService($db,$provider))->recoverSession($wire,true,'SEC-201');
            same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);
            same(0,$db->one("SELECT count(*) AS n FROM pg_locks WHERE pid=pg_backend_pid() AND locktype='advisory'")['n']);
            truth($blocker->one('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS ok',[$scope])['ok']);
        }finally{$blocker->one('SELECT pg_advisory_unlock_all()');$fixture->close();}
    });
}
test('old recovery completing across real HTTP cannot clear the next security generation fence',function(){
    $db=uuidRecoveryDb();[$id,$user]=uuidRecoveryJob($db);$fixture=new SessionProviderFixture('next-generation',$user);
    try{
        $sessions=new SessionService($db,new ShopRepository($db));
        (new PasswordSessionRecovery($db,$sessions,new Keycloak(new HttpClient(.5))))->recover($id);
        same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);
        same(1,$db->one('SELECT session_security_version FROM users WHERE id=?',[$user])['session_security_version']);
        truth($db->one('SELECT sessions_blocked FROM users WHERE id=?',[$user])['sessions_blocked']);
        errorCode('PASSWORD_CHANGED',fn()=>$sessions->issue($user));
    }finally{$fixture->close();}
});
foreach([false,true] as $consoleFirst){
    test('live worker and uppercase console serialize over HTTP, owner='.($consoleFirst?'console':'worker'),function()use($consoleFirst){
        $db=uuidRecoveryDb();[$id,$user]=uuidRecoveryJob($db);$fixture=new SessionProviderFixture('uuid-barrier',$user);$process=null;$pipes=[];
        $db->execute('CREATE TABLE IF NOT EXISTS uuid_recovery_barrier(user_id uuid PRIMARY KEY,entered bool NOT NULL DEFAULT false,released bool NOT NULL DEFAULT false,calls int NOT NULL DEFAULT 0,open_transactions int NOT NULL DEFAULT 0)');
        $db->execute('INSERT INTO uuid_recovery_barrier(user_id) VALUES(?)',[$user]);
        $db->execute("UPDATE password_session_operations SET next_retry_at=clock_timestamp()-interval '1 day' WHERE id=?",[$id]);
        try{
            $env=getenv();$env['PGAPPNAME']='uuid-recovery-'.$user;
            $cmd=[PHP_BINARY,'/app/yii',...($consoleFirst?['maintenance/session-recover',strtoupper($id),'independently-confirmed','SEC-202']:['maintenance/recover'])];
            $process=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
            $deadline=microtime(true)+4;
            while(!$db->one('SELECT entered FROM uuid_recovery_barrier WHERE user_id=?',[$user])['entered']){if(microtime(true)>$deadline){throw new RuntimeException('owner did not reach HTTP barrier');}usleep(10000);}
            $provider=new Keycloak(new HttpClient(.5));$r=new RecoveryService($db,$provider);
            errorCode('IDENTITY_PROVIDER_UNAVAILABLE',fn()=>$r->recoverSession(strtoupper($id),true,'SEC-203'));
            same(0,$r->run()['sessionsCompleted']);
            same('REVOKE_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);
            $sessions=new SessionService($db,new ShopRepository($db));$sessions->logout($user);
            same(1,$db->one('SELECT session_security_version FROM users WHERE id=?',[$user])['session_security_version']);
            $db->execute('UPDATE uuid_recovery_barrier SET released=true WHERE user_id=?',[$user]);
            $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);foreach($pipes as $pipe){fclose($pipe);}$pipes=[];
            same(0,proc_close($process));$process=null;same('',$errors);
            same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);
            same(false,$db->one('SELECT sessions_blocked FROM users WHERE id=?',[$user])['sessions_blocked']);
            same($user,$sessions->issue($user)['userId']);
            same(['calls'=>1,'open_transactions'=>0],$db->one('SELECT calls,open_transactions FROM uuid_recovery_barrier WHERE user_id=?',[$user]));
            same(0,$db->one("SELECT count(*) AS n FROM pg_locks WHERE pid=pg_backend_pid() AND locktype='advisory'")['n']);
        }finally{
            $db->execute('UPDATE uuid_recovery_barrier SET released=true WHERE user_id=?',[$user]);
            if(is_resource($process)){foreach($pipes as $pipe){fclose($pipe);}proc_terminate($process);proc_close($process);}
            $fixture->close();$db->execute('DELETE FROM uuid_recovery_barrier WHERE user_id=?',[$user]);
        }
    });
}
runTests();
