<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\application\{AuthService,SessionService,CredentialService};
use Shop\domain\{Ids,PasswordHasher,Secret};
use Shop\infrastructure\{Database,Env,ApiKernel};
use Shop\repositories\ShopRepository;

$db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);
$sessions=new SessionService($db,$repo);$auth=Shop\infrastructure\AuthComposition::create($db,$repo,$sessions);
function localRegistration(AuthService $auth): array {
    $email=Ids::uuid().'@test.example';
    return [$auth->register(['email'=>$email,'password'=>'StrongPass7395','fullName'=>'Local Buyer','phone'=>'+79991234567']),$email];
}
test('password registration persists a native hash, ordinary role, cart and password family atomically',function()use($db,$auth,$repo){
    [$pair,$email]=localRegistration($auth);
    same(['userId','accessToken','refreshToken','expiresIn'],array_keys($pair));
    truth(str_starts_with($pair['accessToken'],'app_access_'));truth(str_starts_with($pair['refreshToken'],'app_refresh_'));same(300,$pair['expiresIn']);
    $u=$repo->user($pair['userId']);truth(password_verify('StrongPass7395',$u['password_hash']));truth(str_starts_with($u['password_hash'],'$argon2id$'));
    same(false,$u['is_admin']);same(true,$u['local_identity_ready']);same(false,$u['account_disabled']);
    same('password',$db->one('SELECT credential_kind FROM session_families WHERE user_id=?',[$u['id']])['credential_kind']);
    truth($db->one('SELECT user_id FROM cart_metadata WHERE user_id=?',[$u['id']])!==null);
    errorCode('EMAIL_ALREADY_EXISTS',fn()=>$auth->register(['email'=>'  '.strtoupper($email).'  ','password'=>'OtherPass8395','fullName'=>'Other','phone'=>'+79991234567']));
    same($u['password_hash'],$repo->user($u['id'])['password_hash']);
});
test('local password login normalizes email without transforming passwords',function()use($auth){
    [$pair,$email]=localRegistration($auth);$next=$auth->login(['email'=>' '.strtoupper($email).' ','password'=>'StrongPass7395']);
    same($pair['userId'],$next['userId']);same($pair['userId'],$auth->authenticate($next['accessToken'])['id']);
    foreach(['strongpass7395',' StrongPass7395','StrongPass7395 ',str_repeat('a',129)] as $bad){errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>$email,'password'=>$bad]));}
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>Ids::uuid().'@test.example','password'=>'StrongPass7395']));
});
test('native hashing preserves Unicode and legacy PIN hashes without accepting unknown encodings',function(){
    $h=new PasswordHasher();$secret=' ПарольUnicode7395 ';$hash=$h->hash($secret);truth($h->verify($secret,$hash));same(false,$h->verify(trim($secret),$hash));same(false,$h->needsRehash($hash));
    $legacy=Secret::hash($secret);truth($h->verify($secret,$legacy));truth($h->needsRehash($legacy));truth(Secret::verify($secret,$legacy));
    foreach(['','garbage','$argon2id$broken','$2y$unsupported'] as $bad){same(false,$h->verify($secret,$bad));}
    foreach([['memory_cost'=>1,'time_cost'=>2,'threads'=>1],['memory_cost'=>19456,'time_cost'=>0,'threads'=>1]] as $bad){
        try{new PasswordHasher($bad);throw new RuntimeException('invalid hash budget accepted');}catch(InvalidArgumentException){}
    }
});
test('empty and corrupt existing hashes never establish password identity',function()use($auth,$db){
    foreach(['','not-a-hash'] as $hash){$id=Ids::uuid();$email=$id.'@test.example';$db->execute('INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,?,?,?)',[$id,$email,$hash,'Existing','+79991234567']);
        errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>$email,'password'=>'StrongPass7395']));
    }
});
test('single-use refresh retains one family, token kinds and absolute expiry',function()use($auth,$db,$sessions){
    [$pair]=localRegistration($auth);$next=$auth->refresh(['refreshToken'=>$pair['refreshToken']]);same($pair['userId'],$next['userId']);
    errorCode('INVALID_REFRESH_TOKEN',fn()=>$auth->refresh(['refreshToken'=>$pair['refreshToken']]));
    errorCode('INVALID_REFRESH_TOKEN',fn()=>$auth->refresh(['refreshToken'=>$pair['accessToken']]));errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($next['refreshToken']));
    foreach(['old.jwt.value','app_access_bad','app_access_'.str_repeat('a',10000)] as $bad){errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($bad));}
    $db->execute("UPDATE session_families SET expires_at=clock_timestamp()-interval '1 second' WHERE user_id=?",[$pair['userId']]);
    errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($next['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$sessions->refresh($next['refreshToken']));
});
test('password change atomically revokes all kinds and returns no replacement pair',function()use($auth,$db,$repo,$sessions){
    [$pair,$email]=localRegistration($auth);$credentials=new CredentialService($db,$repo,$sessions);$device=Ids::uuid();
    $credentials->setPin($pair['userId'],['deviceId'=>$device,'deviceName'=>'Phone','pin'=>'7395']);$pin=$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395']);
    $before=$repo->user($pair['userId']);errorCode('INVALID_CREDENTIALS',fn()=>$auth->changePassword($pair['userId'],['oldPassword'=>'WrongPass7395','newPassword'=>'ChangedPass8395']));same($before['password_hash'],$repo->user($pair['userId'])['password_hash']);
    $auth->changePassword($pair['userId'],['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);
    foreach([$pair,$pin] as $old){errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($old['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$auth->refresh(['refreshToken'=>$old['refreshToken']]));}
    same(0,$db->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$pair['userId']])['n']);
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>$email,'password'=>'StrongPass7395']));same($pair['userId'],$auth->login(['email'=>$email,'password'=>'ChangedPass8395'])['userId']);
    same($pair['userId'],$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395'])['userId']);
});
test('account ban, unreviewed migration and pending recovery fence all session kinds',function()use($auth,$db,$repo,$sessions){
    foreach(['account_disabled=TRUE','local_identity_ready=FALSE','sessions_blocked=TRUE'] as $state){
        [$pair,$email]=localRegistration($auth);$credentials=new CredentialService($db,$repo,$sessions);$device=Ids::uuid();
        $credentials->setPin($pair['userId'],['deviceId'=>$device,'deviceName'=>'Phone','pin'=>'7395']);$db->execute('UPDATE users SET '.$state.' WHERE id=?',[$pair['userId']]);
        errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($pair['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$auth->refresh(['refreshToken'=>$pair['refreshToken']]));
        try{$auth->login(['email'=>$email,'password'=>'StrongPass7395']);throw new RuntimeException('password bypass');}catch(Shop\domain\ApiError $e){truth(in_array($e->errorCode,['ACCOUNT_LOCKED','PASSWORD_CHANGED','INVALID_CREDENTIALS','UNAUTHORIZED'],true));}
        try{$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395']);throw new RuntimeException('PIN bypass');}catch(Shop\domain\ApiError $e){truth(in_array($e->errorCode,['ACCOUNT_LOCKED','PASSWORD_CHANGED','UNAUTHORIZED'],true));}
    }
});
test('durable password failures lock temporarily and do not roll back on API error',function()use($auth,$db){
    [$pair,$email]=localRegistration($auth);
    for($i=0;$i<5;$i++){try{$auth->login(['email'=>$email,'password'=>'wrong']);}catch(Shop\domain\ApiError $e){truth(in_array($e->errorCode,['INVALID_CREDENTIALS','ACCOUNT_LOCKED'],true));}}
    $attempt=$db->one('SELECT * FROM login_attempts WHERE user_id=?',[$pair['userId']]);truth($attempt!==null);truth($attempt['locked_until']!==null);
    errorCode('ACCOUNT_LOCKED',fn()=>$auth->login(['email'=>$email,'password'=>'StrongPass7395']));
    $db->execute("UPDATE login_attempts SET locked_until=clock_timestamp()-interval '1 second' WHERE user_id=?",[$pair['userId']]);
    same($pair['userId'],$auth->login(['email'=>$email,'password'=>'StrongPass7395'])['userId']);
});
test('server roles are fresh and logout ignores another users refresh token',function()use($auth,$db){
    [$a]=localRegistration($auth);[$b]=localRegistration($auth);$db->execute('UPDATE users SET is_admin=TRUE WHERE id=?',[$a['userId']]);same(true,$auth->authenticate($a['accessToken'])['is_admin']);
    $db->execute('UPDATE users SET is_admin=FALSE WHERE id=?',[$a['userId']]);same(false,$auth->authenticate($a['accessToken'])['is_admin']);
    $auth->logout($a['userId'],['refreshToken'=>$b['refreshToken']]);errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($a['accessToken']));same($b['userId'],$auth->authenticate($b['accessToken'])['id']);
});
test('reviewed promotion cannot turn an existing PIN into administrator authentication',function()use($auth,$db,$repo,$sessions){
    [$pair]=localRegistration($auth);$credentials=new CredentialService($db,$repo,$sessions);$device=Ids::uuid();
    $credentials->setPin($pair['userId'],['deviceId'=>$device,'deviceName'=>'Before promotion','pin'=>'7395']);
    $pin=$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395']);
    (new Shop\application\LocalIdentityMigration($db,$sessions))->attest($pair['userId'],'REVIEW-'.random_int(1,999999999),true,false,true);
    errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($pin['accessToken']));
    errorCode('PIN_FORBIDDEN_FOR_ADMIN',fn()=>$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395']));
    $credential=$db->one('SELECT id FROM device_pins WHERE user_id=? AND device_id=?',[$pair['userId'],$device]);
    errorCode('PIN_FORBIDDEN_FOR_ADMIN',fn()=>$sessions->issue($pair['userId'],'pin',$credential['id']));
    truth($credential!==null); // Preserve device data; policy denies use, not destructive cleanup.
});
test('HTTP token envelope is no-store with strict body types and rejects old signed tokens',function()use($db){
    $kernel=new ApiKernel($db);$base=['content-type'=>'application/json'];
    foreach(['{"email":1,"password":"StrongPass7395"}','{"email":"x@example.com","password":[]}','{broken'] as $body){$r=$kernel->handle('POST','/api/v1/auth/login',$base,$body,'198.51.100.17');same(400,$r['status']);same('no-store',$r['headers']['cache-control']);}
    $r=$kernel->handle('GET','/api/v1/users/me',['authorization'=>'Bearer old.signed.token'],'','198.51.100.17');same(401,$r['status']);same('UNAUTHORIZED',$r['data']['error']['code']);
});
runTests();
