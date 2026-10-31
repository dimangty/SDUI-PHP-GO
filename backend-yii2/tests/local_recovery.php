<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\application\RecoveryService;
use Shop\domain\{ApiError,Ids};

test('historical identity jobs are count-only manual evidence and never clear any fence',function(){
    [$db,$sessions]=localServices();$ids=[];
    foreach(['RESET_PENDING','REVOKE_PENDING','COMPLETE'] as $state){
        $u=localUser($db);$id=Ids::uuid();$ids[]=$u;
        $db->execute('UPDATE users SET sessions_blocked=true WHERE id=?',[$u]);
        $db->execute('INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,?)',[$id,$u,$state]);
    }
    try{
        $before=$db->all('SELECT * FROM password_session_operations WHERE user_id IN (?,?,?) ORDER BY id',$ids);
        $worker=new RecoveryService($db);$report=$worker->run();same(0,$report['sessionsCompleted']);same(0,$report['sessionsFailed']);same(0,$report['sessionsPending']);truth($report['sessionsManual']>=2);
        foreach($report as $count){truth(is_int($count));}
        same($before,$db->all('SELECT * FROM password_session_operations WHERE user_id IN (?,?,?) ORDER BY id',$ids));
        foreach($ids as $u){errorCode('PASSWORD_CHANGED',fn()=>$sessions->issue($u));truth(!str_contains(json_encode($report),$u));}
    }finally{foreach($ids as $u){$db->execute('DELETE FROM users WHERE id=?',[$u]);}}
});
test('legacy resolution refuses even confirmed tickets and directs the trusted local auth tool',function(){
    [$db]=localServices();$u=localUser($db);$id=Ids::uuid();
    $db->execute('UPDATE users SET sessions_blocked=true WHERE id=?',[$u]);
    $db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,'RESET_PENDING')",[$id,$u]);
    try{
        $before=$db->one('SELECT * FROM password_session_operations WHERE id=?',[$id]);
        foreach([[$id,false,'OPS-123'],[strtoupper($id),true,'OPS-123'],[$id,true,'raw password here']] as [$wire,$confirmed,$ticket]){
            try{(new RecoveryService($db))->recoverSession($wire,$confirmed,$ticket);throw new RuntimeException('unsafe legacy resolution accepted');}
            catch(ApiError $e){same('BAD_REQUEST',$e->errorCode);truth(str_contains($e->getMessage(),'auth/'));}
        }
        same($before,$db->one('SELECT * FROM password_session_operations WHERE id=?',[$id]));
        truth($db->one('SELECT sessions_blocked FROM users WHERE id=?',[$u])['sessions_blocked']);
    }finally{$db->execute('DELETE FROM users WHERE id=?',[$u]);}
});
runTests();
