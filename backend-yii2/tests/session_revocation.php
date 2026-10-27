<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SessionProviderFixture.php';
use Shop\application\{AuthService,CredentialService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

function sessionServices(): array {
    $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$s=new SessionService($db,$repo);
    return [$db,$s,new CredentialService($db,$repo,$s),new AuthService($db,$repo,$s)];
}
function sessionUser(Database $db): string {
    $id=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Session Buyer','+79991234567')",[$id,$id.'@test.example']);return $id;
}
function pinSession(CredentialService $c,string $user,?string $device=null): array {
    $device??=Ids::uuid();$c->setPin($user,['deviceId'=>$device,'deviceName'=>'Session phone','pin'=>'7395']);
    return [$device,$c->loginPin(['deviceId'=>$device,'pin'=>'7395'])];
}
function rejected(SessionService $s,array $tokens): void {
    errorCode('UNAUTHORIZED',fn()=>$s->authenticate($tokens['accessToken']));
    errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($tokens['refreshToken']));
}
function bioSession(CredentialService $c,string $user,string $device,string $suppliedDevice='attacker-supplied-device'): array {
    $kp=sodium_crypto_sign_keypair();$id=$c->enroll($user,['deviceId'=>$device,'deviceName'=>'Bio phone','publicKey'=>base64_encode(hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($kp)),'keyAlgorithm'=>'EdDSA'])['credentialId'];
    $nonce=$c->challenge(['credentialId'=>$id])['challenge'];
    return [$id,$c->loginBiometric(['credentialId'=>$id,'deviceId'=>$suppliedDevice,'challenge'=>$nonce,'signature'=>base64_encode(sodium_crypto_sign_detached($nonce,sodium_crypto_sign_secretkey($kp)))])];
}
test('PIN removal revokes the credential family and rotated descendants only',function(){
    [$db,$s,$c]=sessionServices();$u=sessionUser($db);$other=sessionUser($db);[$device,$old]=pinSession($c,$u);[, $unrelated]=pinSession($c,$u);[, $foreign]=pinSession($c,$other);
    $rotated=$s->refresh($old['refreshToken']);errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($old['refreshToken']));
    errorCode('PIN_NOT_SET',fn()=>$c->deletePin($other,$device));same($u,$s->authenticate($old['accessToken'])['id']);
    $c->deletePin($u,$device);rejected($s,$rotated);errorCode('UNAUTHORIZED',fn()=>$s->authenticate($old['accessToken']));
    same($u,$s->authenticate($unrelated['accessToken'])['id']);same($other,$s->refresh($foreign['refreshToken'])['userId']);
});
test('biometric removal uses server credential identity and does not revoke same-device PIN or another user',function(){
    [$db,$s,$c]=sessionServices();$u=sessionUser($db);$other=sessionUser($db);[$victimDevice,$victim]=pinSession($c,$other);[$device,$pin]=pinSession($c,$u);[$id,$tokens]=bioSession($c,$u,$device,$victimDevice);[, $foreign]=bioSession($c,$other,$device);
    errorCode('BIOMETRIC_NOT_SET',fn()=>$c->deleteBiometric($other,'attacker-supplied-device'));
    $c->deleteBiometric($u,$device);rejected($s,$tokens);same($u,$s->authenticate($pin['accessToken'])['id']);same($other,$s->authenticate($foreign['accessToken'])['id']);same($other,$s->authenticate($victim['accessToken'])['id']);
});
test('terminal PIN lockout revokes every session of that credential',function(){
    [$db,$s,$c]=sessionServices();$u=sessionUser($db);[$device,$t]=pinSession($c,$u);[, $safe]=pinSession($c,$u);
    $db->execute('UPDATE device_pins SET lock_count=2,failed_attempts=4 WHERE device_id=?',[$device]);
    errorCode('PIN_NOT_SET',fn()=>$c->loginPin(['deviceId'=>$device,'pin'=>'0000']));rejected($s,$t);same($u,$s->authenticate($safe['accessToken'])['id']);
});
test('terminal biometric lockout revokes its family',function(){
    [$db,$s,$c]=sessionServices();$u=sessionUser($db);[$id,$t]=bioSession($c,$u,Ids::uuid());
    $db->execute('UPDATE device_biometrics SET lock_count=2,failed_attempts=4 WHERE id=?',[$id]);$nonce=$c->challenge(['credentialId'=>$id])['challenge'];
    errorCode('BIOMETRIC_REVOKED',fn()=>$c->loginBiometric(['credentialId'=>$id,'challenge'=>$nonce,'signature'=>base64_encode(str_repeat('x',64))]));rejected($s,$t);
});
test('password reset rejects all prior opaque access and refresh across devices including internal families',function(){
    [$db,$s,$c,$auth]=sessionServices();$u=sessionUser($db);$other=sessionUser($db);[, $pin]=pinSession($c,$u);[, $bio]=bioSession($c,$u,Ids::uuid());$internal=$s->issue($u);$safe=$s->issue($other);$fixture=new SessionProviderFixture('success',$u);
    try{$auth->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);rejected($s,$pin);rejected($s,$bio);rejected($s,$internal);same($other,$s->authenticate($safe['accessToken'])['id']);}
    finally{$fixture->close();}
});
test('local logout revokes all user refresh families even when one token is supplied',function(){
    [$db,$s,$c]=sessionServices();$u=sessionUser($db);[, $one]=pinSession($c,$u);[, $two]=pinSession($c,$u);$s->logout($u,$one['refreshToken']);rejected($s,$one);rejected($s,$two);
});
test('new PIN and biometric families use verified server UUIDs and store only token digests across service restart',function(){
    [$db,$s,$c]=sessionServices();$u=sessionUser($db);[$device,$pin]=pinSession($c,$u);[$id,$bio]=bioSession($c,$u,Ids::uuid());
    $pinId=$db->one('SELECT id FROM device_pins WHERE user_id=? AND device_id=?',[$u,$device])['id'];
    foreach([['pin',$pinId,$pin],['biometric',$id,$bio]] as [$kind,$credential,$tokens]){
        $row=$db->one('SELECT f.user_id,f.credential_kind,f.credential_id,encode(s.token_hash,\'hex\') AS digest FROM refresh_sessions s JOIN session_families f ON f.id=s.family_id WHERE s.token_hash=decode(?,\'hex\')',[hash('sha256',$tokens['refreshToken'])]);
        same(['user_id'=>$u,'credential_kind'=>$kind,'credential_id'=>$credential,'digest'=>hash('sha256',$tokens['refreshToken'])],$row);
        [, $fresh]=sessionServices();same($u,$fresh->authenticate($tokens['accessToken'])['id']);$next=$fresh->refresh($tokens['refreshToken']);same($u,$s->authenticate($next['accessToken'])['id']);
    }
});
runTests();
