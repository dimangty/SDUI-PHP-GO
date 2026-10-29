<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SessionProviderFixture.php';
use Shop\application\{AuthService,CredentialService,SessionService};
use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env,HttpClient,Keycloak};
use Shop\repositories\ShopRepository;
function raceServices(): array {$db=new Database(Env::get('DATABASE_URL'));$r=new ShopRepository($db);$s=new SessionService($db,$r);return [$db,$s,new CredentialService($db,$r,$s)];}
if(($argv[1]??'')==='worker'){
    $input=json_decode(fgets(STDIN),true,32,JSON_THROW_ON_ERROR);[$db,$s,$c]=raceServices();
    $db->one("SELECT set_config('application_name',?,false)",[$input['name']]);print "ready\n";flush();
    fgets(STDIN);
    try{$result=match($input['operation']){
        'refresh'=>$s->refresh($input['token']),
        'login'=>$c->loginPin(['deviceId'=>$input['device'],'pin'=>'7395','userId'=>Ids::uuid()]),
        'bio-login'=>$c->loginBiometric($input['assertion']),
        'revoke'=>$c->deletePin($input['user'],$input['device']),
        'bio-revoke'=>$c->deleteBiometric($input['user'],$input['device']),
        'password'=>(new AuthService($db,new ShopRepository($db),$s,new Keycloak(new HttpClient(.2))))->changePassword($input['user'],['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']),
        'logout'=>$s->logout($input['user']),
    };print json_encode(['ok'=>$result]);}
    catch(ApiError $e){print json_encode(['error'=>$e->errorCode]);}catch(Throwable $e){print json_encode(['exception'=>$e->getMessage()]);}
    exit;
}
function queuedWorker(Database $observer,array $input): array {
    $input['name']='session-race-'.Ids::uuid();$p=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($input)."\n");same("ready\n",fgets($pipes[1]));fwrite($pipes[0],"go\n");
    for($i=0;$i<500;$i++){
        if($observer->one("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'",[$input['name']])['n']===1){return [$p,$pipes];}
        usleep(10000);
    }
    throw new RuntimeException('worker did not reach the PostgreSQL user lock');
}
function workerResult(array $worker): array {[$p,$pipes]=$worker;fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);same('',$stderr);same(0,proc_close($p));$v=json_decode($body,true,32,JSON_THROW_ON_ERROR);truth(!isset($v['exception']));return $v;}
foreach(['refresh','login','bio-login'] as $operation){foreach([false,true] as $revokeFirst){foreach([false,true] as $legacy){
    if($legacy && $operation==='login'){continue;}
    test('real PostgreSQL '.$operation.'/credential revoke lock queue '.($revokeFirst?'revoke first':'issuance first').($legacy?' legacy dots':''),function()use($operation,$revokeFirst,$legacy){
        [$db,$s,$c]=raceServices();$u=Ids::uuid();$device=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Race Buyer','+79991234567')",[$u,$u.'@test.example']);
        $assertion=[];
        if($operation==='bio-login'){
            $kp=sodium_crypto_sign_keypair();$id=$c->enroll($u,['deviceId'=>$device,'deviceName'=>'Bio race','publicKey'=>base64_encode(hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($kp)),'keyAlgorithm'=>'EdDSA'])['credentialId'];
            $sign=function()use($c,$id,$kp){$nonce=$c->challenge(['credentialId'=>$id])['challenge'];return ['credentialId'=>$id,'challenge'=>$nonce,'signature'=>base64_encode(sodium_crypto_sign_detached($nonce,sodium_crypto_sign_secretkey($kp)))];};
            $old=$c->loginBiometric($sign());$assertion=$sign();
        }else{$c->setPin($u,['deviceId'=>$device,'deviceName'=>'Race phone','pin'=>'7395']);$old=$c->loginPin(['deviceId'=>$device,'pin'=>'7395']);}
        if($legacy){$table=$operation==='bio-login'?'device_biometrics':'device_pins';$legacyDevice=$operation==='bio-login'?'..':'.';$db->execute('UPDATE '.$table.' SET device_id=? WHERE user_id=? AND device_id=?',[$legacyDevice,$u,$device]);$device=$legacyDevice;}
        $observer=new Database(Env::get('DATABASE_URL'));$db->pdo()->beginTransaction();$s->lockUser($u);
        $issue=['operation'=>$operation,'user'=>$u,'device'=>$device,'token'=>$old['refreshToken'],'assertion'=>$assertion];$revoke=['operation'=>$operation==='bio-login'?'bio-revoke':'revoke','user'=>$u,'device'=>$device];
        try{$a=queuedWorker($observer,$revokeFirst?$revoke:$issue);$b=queuedWorker($observer,$revokeFirst?$issue:$revoke);}finally{$db->pdo()->commit();}
        $results=[workerResult($a),workerResult($b)];$issued=$results[$revokeFirst?1:0];same(['ok'=>null],$results[$revokeFirst?0:1]);
        if($revokeFirst){same(['error'=>match($operation){'refresh'=>'INVALID_REFRESH_TOKEN','bio-login'=>'BIOMETRIC_NOT_SET',default=>'PIN_NOT_SET'}],$issued);}
        else{errorCode('UNAUTHORIZED',fn()=>$s->authenticate($issued['ok']['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($issued['ok']['refreshToken']));}
        errorCode('UNAUTHORIZED',fn()=>$s->authenticate($old['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($old['refreshToken']));
    });
}}}
test('real PostgreSQL refresh replay has exactly one committed winner',function(){
    [$db,$s]=raceServices();$u=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Replay Buyer','+79991234567')",[$u,$u.'@test.example']);$old=$s->issue($u);
    $db->pdo()->beginTransaction();$s->lockUser($u);$observer=new Database(Env::get('DATABASE_URL'));
    try{$a=queuedWorker($observer,['operation'=>'refresh','token'=>$old['refreshToken']]);$b=queuedWorker($observer,['operation'=>'refresh','token'=>$old['refreshToken']]);}finally{$db->pdo()->commit();}
    $one=workerResult($a);$two=workerResult($b);truth(isset($one['ok']));same(['error'=>'INVALID_REFRESH_TOKEN'],$two);same($u,$s->authenticate($one['ok']['accessToken'])['id']);
});
foreach(['refresh','login'] as $operation){foreach([false,true] as $passwordFirst){
    test('real PostgreSQL '.$operation.'/password fence lock queue '.($passwordFirst?'fence first':'issuance first'),function()use($operation,$passwordFirst){
        [$db,$s,$c]=raceServices();$u=Ids::uuid();$device=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Fence Race','+79991234567')",[$u,$u.'@test.example']);
        $c->setPin($u,['deviceId'=>$device,'deviceName'=>'Race phone','pin'=>'7395']);$old=$c->loginPin(['deviceId'=>$device,'pin'=>'7395']);$fixture=new SessionProviderFixture('reset-timeout',$u);
        try{
            $observer=new Database(Env::get('DATABASE_URL'));$db->pdo()->beginTransaction();$s->lockUser($u);
            $issue=['operation'=>$operation,'user'=>$u,'device'=>$device,'token'=>$old['refreshToken']];$password=['operation'=>'password','user'=>$u];
            try{$a=queuedWorker($observer,$passwordFirst?$password:$issue);$b=queuedWorker($observer,$passwordFirst?$issue:$password);}finally{$db->pdo()->commit();}
            $results=[workerResult($a),workerResult($b)];same(['error'=>'IDENTITY_PROVIDER_UNAVAILABLE'],$results[$passwordFirst?0:1]);$issued=$results[$passwordFirst?1:0];
            if($passwordFirst){same(['error'=>$operation==='refresh'?'INVALID_REFRESH_TOKEN':'PASSWORD_CHANGED'],$issued);}else{errorCode('UNAUTHORIZED',fn()=>$s->authenticate($issued['ok']['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($issued['ok']['refreshToken']));}
            errorCode('UNAUTHORIZED',fn()=>$s->authenticate($old['accessToken']));errorCode('PASSWORD_CHANGED',fn()=>$s->issue($u));
            same('RESET_PENDING',$db->one('SELECT state FROM password_session_operations WHERE user_id=?',[$u])['state']);
        }finally{$fixture->close();}
    });
}}
runTests();
