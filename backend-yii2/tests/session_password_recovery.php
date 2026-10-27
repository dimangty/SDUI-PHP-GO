<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SessionProviderFixture.php';
use Shop\application\{AuthService,CredentialService,PasswordSessionRecovery,SessionService};
use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env,HttpClient,Keycloak};
use Shop\repositories\ShopRepository;
function recoveryServices(): array {
    $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$s=new SessionService($db,$repo);
    return [$db,$s,new CredentialService($db,$repo,$s),new AuthService($db,$repo,$s,new Keycloak(new HttpClient(.2)))];
}
function recoveryUser(Database $db): string {$id=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Recovery Buyer','+79991234567')",[$id,$id.'@test.example']);return $id;}
foreach(['probe','recovery-lock-probe'] as $mode){test('opaque issuance and concurrent recovery are fenced before the remote reset '.$mode,function()use($mode){
    $fixture=new SessionProviderFixture();
    try{
        [$db,$s,$c,$auth]=recoveryServices();$u=recoveryUser($db);$fixture->close();$fixture=new SessionProviderFixture($mode,$u);
        $auth=new AuthService($db,new ShopRepository($db),$s,new Keycloak(new HttpClient(.2)));
        $auth->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);
        same($u,$s->issue($u)['userId']);same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE user_id=?',[$u])['state']);
    }finally{$fixture->close();}
});}
foreach(['revoke503'=>'REVOKE_PENDING','consent503'=>'REVOKE_PENDING','reset-timeout'=>'RESET_PENDING','reset-invalid'=>'RESET_PENDING'] as $mode=>$state){
    test('password partial remote outcome keeps a durable fail-closed job after restart '.$mode,function()use($mode,$state){
        $fixture=new SessionProviderFixture($mode);
        try{
            [$db,$s,$c,$auth]=recoveryServices();$u=recoveryUser($db);$t=$s->issue($u);$device=Ids::uuid();$c->setPin($u,['deviceId'=>$device,'deviceName'=>'Recovery phone','pin'=>'7395']);
            try{$auth->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);throw new RuntimeException('expected recovery signal');}
            catch(ApiError $e){same($mode==='reset-timeout'?'IDENTITY_PROVIDER_UNAVAILABLE':'IDENTITY_PROVIDER_ERROR',$e->errorCode);same('true',$e->details['recoveryRequired']??null);foreach($e->details as $value){truth(is_string($value));}$id=$e->details['recoveryOperationId'];}
            $job=$db->one('SELECT * FROM password_session_operations WHERE id=?',[$id]);same($state,$job['state']);
            $serialized=json_encode($job);foreach(['StrongPass7395','ChangedPass8395','provider-secret-not-for-storage',$t['refreshToken']] as $secret){truth(!str_contains($serialized,$secret));}
            [$freshDb,$fresh,$freshC]=recoveryServices();errorCode('UNAUTHORIZED',fn()=>$fresh->authenticate($t['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$fresh->refresh($t['refreshToken']));
            errorCode('PASSWORD_CHANGED',fn()=>$fresh->issue($u));errorCode('PASSWORD_CHANGED',fn()=>$freshC->loginPin(['deviceId'=>$device,'pin'=>'7395']));
            $fixture->close();$fixture=new SessionProviderFixture('success',$u);$recovery=new PasswordSessionRecovery($freshDb,$fresh,new Keycloak(new HttpClient(.2)));
            if($state==='RESET_PENDING'){errorCode('IDENTITY_PROVIDER_ERROR',fn()=>$recovery->recover($id));}
            $recovery->recover($id,$state==='RESET_PENDING');$recovery->recover($id);
            same('COMPLETE',$freshDb->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);same($u,$fresh->issue($u)['userId']);
            errorCode('UNAUTHORIZED',fn()=>$fresh->authenticate($t['accessToken']));
        }finally{$fixture->close();}
    });
}
test('a password verification racing an intervening security change cannot reset using stale verification',function(){
    $fixture=new SessionProviderFixture('verification-race');
    try{[$db,$s,$c,$auth]=recoveryServices();$u=recoveryUser($db);
        errorCode('PASSWORD_CHANGED',fn()=>$auth->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']));
        same(0,$db->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$u])['n']);
    }finally{$fixture->close();}
});
test('SQL completion failure after provider success leaves the committed fence recoverable',function(){
    $fixture=new SessionProviderFixture('success');
    try{
        [$db,$s,$c,$auth]=recoveryServices();$u=recoveryUser($db);$t=$s->issue($u);$suffix=str_replace('-','',Ids::uuid());
        $function='session_fail_'.$suffix;$trigger='session_fail_'.$suffix;
        $db->execute("CREATE FUNCTION ".$function."() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.user_id='".$u."'::uuid AND NEW.state='COMPLETE' THEN RAISE EXCEPTION 'session test completion failure'; END IF; RETURN NEW; END $$");
        $db->execute('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON password_session_operations FOR EACH ROW EXECUTE FUNCTION '.$function.'()');
        try{
            try{$auth->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);throw new RuntimeException('expected durable recovery error');}
            catch(ApiError $e){same('IDENTITY_PROVIDER_ERROR',$e->errorCode);$id=$e->details['recoveryOperationId'];}
            same('REVOKE_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);
            errorCode('PASSWORD_CHANGED',fn()=>$s->issue($u));errorCode('UNAUTHORIZED',fn()=>$s->authenticate($t['accessToken']));
        }finally{$db->execute('DROP TRIGGER '.$trigger.' ON password_session_operations');$db->execute('DROP FUNCTION '.$function.'()');}
        (new PasswordSessionRecovery($db,$s,new Keycloak(new HttpClient(.2))))->recover($id);same($u,$s->issue($u)['userId']);
    }finally{$fixture->close();}
});
test('lost SQL connection after remote reset still returns an explicit durable recovery signal',function(){
    $fixture=new SessionProviderFixture('success');
    try{
        [$db,$s,$c,$auth]=recoveryServices();$u=recoveryUser($db);$fixture->close();$fixture=new SessionProviderFixture('disconnect',$u);
        $auth=new AuthService($db,new ShopRepository($db),$s,new Keycloak(new HttpClient(.2)));
        $db->one("SELECT set_config('application_name',?,false)",['session-disconnect-'.$u]);
        try{$auth->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);throw new RuntimeException('expected recovery signal');}
        catch(ApiError $e){same('IDENTITY_PROVIDER_ERROR',$e->errorCode);same('true',$e->details['recoveryRequired']);foreach($e->details as $value){truth(is_string($value));}$id=$e->details['recoveryOperationId'];}
        [$freshDb,$fresh]=recoveryServices();same('RESET_PENDING',$freshDb->one('SELECT state FROM password_session_operations WHERE id=?',[$id])['state']);errorCode('PASSWORD_CHANGED',fn()=>$fresh->issue($u));
        $fixture->close();$fixture=new SessionProviderFixture('success',$u);
        (new PasswordSessionRecovery($freshDb,$fresh,new Keycloak(new HttpClient(.2))))->recover($id,true);same($u,$fresh->issue($u)['userId']);
    }finally{$fixture->close();}
});
test('wrong old password neither revokes local sessions nor creates a recovery job',function(){
    $fixture=new SessionProviderFixture('success');
    try{[$db,$s,$c,$auth]=recoveryServices();$u=recoveryUser($db);$t=$s->issue($u);
        errorCode('INVALID_CREDENTIALS',fn()=>$auth->changePassword($u,['oldPassword'=>'wrong','newPassword'=>'ChangedPass8395']));same($u,$s->authenticate($t['accessToken'])['id']);same(0,$db->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$u])['n']);
    }finally{$fixture->close();}
});
runTests();
