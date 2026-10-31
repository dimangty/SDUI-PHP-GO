<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\application\RecoveryService;
use Shop\domain\Ids;

foreach(['upper','mixed'] as $case){
    test('UUID '.$case.' legacy resolution cannot bypass the manual local migration fence',function()use($case){
        [$db,$sessions]=localServices();$u=localUser($db);$id=Ids::uuid();$wire=$case==='upper'?strtoupper($id):strtoupper(substr($id,0,18)).substr($id,18);
        $db->execute('UPDATE users SET sessions_blocked=true WHERE id=?',[$u]);$db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,'REVOKE_PENDING')",[$id,$u]);
        try{
            $before=$db->one('SELECT * FROM password_session_operations WHERE id=?',[$id]);$worker=new RecoveryService($db);
            errorCode('BAD_REQUEST',fn()=>$worker->recoverSession($wire,true,'SEC-201'));$worker->run();
            same($before,$db->one('SELECT * FROM password_session_operations WHERE id=?',[$id]));errorCode('PASSWORD_CHANGED',fn()=>$sessions->issue($u));
            same(0,$db->one("SELECT count(*) AS n FROM pg_locks WHERE pid=pg_backend_pid() AND locktype='advisory'")['n']);
        }finally{$db->execute('DELETE FROM users WHERE id=?',[$u]);}
    });
}
test('historical completion cannot clear a newer security-generation fence',function(){
    [$db,$sessions]=localServices();$u=localUser($db);$id=Ids::uuid();$next=Ids::uuid();
    $db->execute('UPDATE users SET sessions_blocked=true,session_security_version=1 WHERE id=?',[$u]);
    $db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,'COMPLETE'),(?,?,1,'RESET_PENDING')",[$id,$u,$next,$u]);
    try{
        $worker=new RecoveryService($db);errorCode('BAD_REQUEST',fn()=>$worker->recoverSession(strtoupper($id),true,'SEC-202'));$worker->run();
        same(['sessions_blocked'=>true,'session_security_version'=>1],$db->one('SELECT sessions_blocked,session_security_version FROM users WHERE id=?',[$u]));
        same('RESET_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$next])['state']);errorCode('PASSWORD_CHANGED',fn()=>$sessions->issue($u));
    }finally{$db->execute('DELETE FROM users WHERE id=?',[$u]);}
});
foreach([false,true] as $consoleFirst){
    test('live worker and uppercase legacy console preserve rows regardless of start order '.(int)$consoleFirst,function()use($consoleFirst){
        [$db]=localServices();$u=localUser($db);$id=Ids::uuid();$db->execute('UPDATE users SET sessions_blocked=true WHERE id=?',[$u]);
        $db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,'RESET_PENDING')",[$id,$u]);$workers=[];
        try{
            $before=$db->one('SELECT * FROM password_session_operations WHERE id=?',[$id]);$commands=[['maintenance/recover'],['maintenance/session-recover',strtoupper($id),'independently-confirmed','SEC-203']];if($consoleFirst){$commands=array_reverse($commands);}
            foreach($commands as $args){$p=proc_open([PHP_BINARY,'/app/yii',...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes,$args[0]];}
            foreach($workers as [$p,$pipes,$command]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe){fclose($pipe);}$exit=proc_close($p);if($command==='maintenance/recover'){same(0,$exit);same(0,json_decode($out,true,32,JSON_THROW_ON_ERROR)['sessionsCompleted']);}else{truth($exit!==0);truth(str_contains($out.$err,'auth/'));}}
            same($before,$db->one('SELECT * FROM password_session_operations WHERE id=?',[$id]));truth($db->one('SELECT sessions_blocked FROM users WHERE id=?',[$u])['sessions_blocked']);
        }finally{$db->execute('DELETE FROM users WHERE id=?',[$u]);}
    });
}
runTests();
