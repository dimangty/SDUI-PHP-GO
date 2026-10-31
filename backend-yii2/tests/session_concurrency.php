<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\application\{AuthService,CredentialService,SessionService};
use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env};
use Shop\domain\{PasswordHasher,Secret};
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
        'password'=>Shop\infrastructure\AuthComposition::create($db,new ShopRepository($db),$s)->changePassword($input['user'],['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']),
        'password-login'=>Shop\infrastructure\AuthComposition::create($db,new ShopRepository($db),$s)->login(['email'=>$input['user'].'@test.example','password'=>'StrongPass7395']),
        'disable'=>$db->transaction(function()use($db,$s,$input){$s->lockUser($input['user']);$db->execute('UPDATE users SET account_disabled=true WHERE id=?',[$input['user']]);$s->revokeUser($input['user']);}),
        'promote'=>(new Shop\application\LocalIdentityMigration($db,$s))->attest($input['user'],$input['ticket'],true,false,true),
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
foreach([false,true] as $promoteFirst){
    test('reviewed admin promotion versus PIN issuance '.($promoteFirst?'promotion first':'issuance first'),function()use($promoteFirst){
        [$db,$s,$c]=raceServices();$u=localUser($db);$device=Ids::uuid();$c->setPin($u,['deviceId'=>$device,'deviceName'=>'Promotion race','pin'=>'7395']);
        $observer=new Database(Env::get('DATABASE_URL'));$db->pdo()->beginTransaction();$s->lockUser($u);
        $issue=['operation'=>'login','user'=>$u,'device'=>$device];$promote=['operation'=>'promote','user'=>$u,'ticket'=>'PROMOTE-'.random_int(1,999999999)];
        try{$a=queuedWorker($observer,$promoteFirst?$promote:$issue);$b=queuedWorker($observer,$promoteFirst?$issue:$promote);}finally{$db->pdo()->commit();}
        $results=[workerResult($a),workerResult($b)];same(['ok'=>['status'=>'applied']],$results[$promoteFirst?0:1]);$issued=$results[$promoteFirst?1:0];
        if($promoteFirst){same(['error'=>'PIN_FORBIDDEN_FOR_ADMIN'],$issued);}else{errorCode('UNAUTHORIZED',fn()=>$s->authenticate($issued['ok']['accessToken']));}
        errorCode('PIN_FORBIDDEN_FOR_ADMIN',fn()=>$c->loginPin(['deviceId'=>$device,'pin'=>'7395']));
    });
}
foreach(['refresh','login','bio-login'] as $operation){foreach([false,true] as $revokeFirst){foreach([false,true] as $legacy){
    if($legacy && $operation==='login'){continue;}
    test('real PostgreSQL '.$operation.'/credential revoke lock queue '.($revokeFirst?'revoke first':'issuance first').($legacy?' legacy dots':''),function()use($operation,$revokeFirst,$legacy){
        [$db,$s,$c]=raceServices();$u=localUser($db);$device=Ids::uuid();
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
    [$db,$s]=raceServices();$u=localUser($db);$old=$s->issue($u,'password');
    $db->pdo()->beginTransaction();$s->lockUser($u);$observer=new Database(Env::get('DATABASE_URL'));
    try{$a=queuedWorker($observer,['operation'=>'refresh','token'=>$old['refreshToken']]);$b=queuedWorker($observer,['operation'=>'refresh','token'=>$old['refreshToken']]);}finally{$db->pdo()->commit();}
    $one=workerResult($a);$two=workerResult($b);truth(isset($one['ok']));same(['error'=>'INVALID_REFRESH_TOKEN'],$two);same($u,$s->authenticate($one['ok']['accessToken'])['id']);
});
foreach(['refresh','login'] as $operation){foreach([false,true] as $passwordFirst){
    test('real PostgreSQL '.$operation.'/password fence lock queue '.($passwordFirst?'fence first':'issuance first'),function()use($operation,$passwordFirst){
        [$db,$s,$c]=raceServices();$u=localUser($db,(new PasswordHasher())->hash('StrongPass7395'));$device=Ids::uuid();
        $c->setPin($u,['deviceId'=>$device,'deviceName'=>'Race phone','pin'=>'7395']);$old=$c->loginPin(['deviceId'=>$device,'pin'=>'7395']);
            $observer=new Database(Env::get('DATABASE_URL'));$db->pdo()->beginTransaction();$s->lockUser($u);
            $issue=['operation'=>$operation,'user'=>$u,'device'=>$device,'token'=>$old['refreshToken']];$password=['operation'=>'password','user'=>$u];
            try{$a=queuedWorker($observer,$passwordFirst?$password:$issue);$b=queuedWorker($observer,$passwordFirst?$issue:$password);}finally{$db->pdo()->commit();}
            $results=[workerResult($a),workerResult($b)];same(['ok'=>null],$results[$passwordFirst?0:1]);$issued=$results[$passwordFirst?1:0];
            if($passwordFirst && $operation==='refresh'){same(['error'=>'INVALID_REFRESH_TOKEN'],$issued);}
            elseif($passwordFirst){same($u,$s->authenticate($issued['ok']['accessToken'])['id']);}
            else{errorCode('UNAUTHORIZED',fn()=>$s->authenticate($issued['ok']['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($issued['ok']['refreshToken']));}
            errorCode('UNAUTHORIZED',fn()=>$s->authenticate($old['accessToken']));same($u,$s->issue($u)['userId']);
            same(0,$db->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$u])['n']);
    });
}}
foreach(['logout','disable'] as $mutation){foreach([false,true] as $mutationFirst){
    test('real PostgreSQL refresh/'.$mutation.' queue '.($mutationFirst?'mutation first':'issuance first'),function()use($mutation,$mutationFirst){
        [$db,$s]=raceServices();$u=localUser($db);$old=$s->issue($u,'password');$observer=new Database(Env::get('DATABASE_URL'));
        $db->pdo()->beginTransaction();$s->lockUser($u);$issue=['operation'=>'refresh','token'=>$old['refreshToken']];$change=['operation'=>$mutation,'user'=>$u];
        try{$a=queuedWorker($observer,$mutationFirst?$change:$issue);$b=queuedWorker($observer,$mutationFirst?$issue:$change);}finally{$db->pdo()->commit();}
        $results=[workerResult($a),workerResult($b)];same(['ok'=>null],$results[$mutationFirst?0:1]);$issued=$results[$mutationFirst?1:0];
        if($mutationFirst){same(['error'=>'INVALID_REFRESH_TOKEN'],$issued);}else{errorCode('UNAUTHORIZED',fn()=>$s->authenticate($issued['ok']['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($issued['ok']['refreshToken']));}
        errorCode('UNAUTHORIZED',fn()=>$s->authenticate($old['accessToken']));
    });
}}
foreach(['password-login','login','bio-login'] as $operation){foreach([false,true] as $disableFirst){
    test('real PostgreSQL '.$operation.'/disable queue '.($disableFirst?'disable first':'issuance first'),function()use($operation,$disableFirst){
        [$db,$s,$c]=raceServices();$u=localUser($db,(new PasswordHasher())->hash('StrongPass7395'));$device=Ids::uuid();$input=['operation'=>$operation,'user'=>$u,'device'=>$device];
        if($operation==='bio-login'){
            $kp=sodium_crypto_sign_keypair();$id=$c->enroll($u,['deviceId'=>$device,'deviceName'=>'Disable race','publicKey'=>base64_encode(hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($kp)),'keyAlgorithm'=>'EdDSA'])['credentialId'];
            $nonce=$c->challenge(['credentialId'=>$id])['challenge'];$input['assertion']=['credentialId'=>$id,'challenge'=>$nonce,'signature'=>base64_encode(sodium_crypto_sign_detached($nonce,sodium_crypto_sign_secretkey($kp)))];
        }elseif($operation==='login'){$c->setPin($u,['deviceId'=>$device,'deviceName'=>'Disable race','pin'=>'7395']);}
        $db->pdo()->beginTransaction();$s->lockUser($u);$observer=new Database(Env::get('DATABASE_URL'));
        $disable=['operation'=>'disable','user'=>$u];
        try{$a=queuedWorker($observer,$disableFirst?$disable:$input);$b=queuedWorker($observer,$disableFirst?$input:$disable);}finally{$db->pdo()->commit();}
        $results=[workerResult($a),workerResult($b)];same(['ok'=>null],$results[$disableFirst?0:1]);$issued=$results[$disableFirst?1:0];
        if($disableFirst){same(['error'=>$operation==='password-login'?'INVALID_CREDENTIALS':'ACCOUNT_LOCKED'],$issued);}
        else{errorCode('UNAUTHORIZED',fn()=>$s->authenticate($issued['ok']['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($issued['ok']['refreshToken']));}
        same(0,$db->one('SELECT count(*) AS n FROM session_families WHERE user_id=? AND revoked_at IS NULL',[$u])['n']);
    });
}}
runTests();
