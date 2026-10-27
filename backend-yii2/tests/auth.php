<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\{Ids,ApiError};
use Shop\infrastructure\{Database,Env,OidcVerifier,Keycloak};
use Shop\repositories\ShopRepository;
use Shop\application\{AuthService,SessionService,CredentialService};

$db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$sessions=new SessionService($db,$repo);$auth=new AuthService($db,$repo,$sessions);
test('Keycloak registration login refresh JWT authentication and logout use real realm',function()use($auth,$repo){
    $email=Ids::uuid().'@test.example';$password='StrongPass7395';
    $t=$auth->register(['email'=>$email,'password'=>$password,'fullName'=>'Real Realm Buyer','phone'=>'+79991234567']);
    truth(!str_starts_with($t['accessToken'],'app_access_'));
    $u=$auth->authenticate($t['accessToken']);same($t['userId'],$u['id']);same($email,$u['email']);
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>$email,'password'=>'wrong']));
    $login=$auth->login(['email'=>$email,'password'=>$password]);same($t['userId'],$login['userId']);
    $refresh=$auth->refresh(['refreshToken'=>$login['refreshToken']]);same($t['userId'],$refresh['userId']);
    $parts=explode('.',$refresh['accessToken']);$parts[2][0]=$parts[2][0]==='a'?'b':'a';
    errorCode('UNAUTHORIZED',fn()=>$auth->authenticate(implode('.',$parts)));
    $auth->logout($t['userId'],['refreshToken'=>$refresh['refreshToken']]);
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->refresh(['refreshToken'=>$refresh['refreshToken']]));
});
test('OIDC issuer audience type authorized-party and expiry are mandatory',function(){
    $claims=['iss'=>'https://issuer.example/realms/shop','aud'=>['account','shopkmp-backend'],'typ'=>'Bearer','azp'=>'shopkmp-backend','sub'=>Ids::uuid(),'exp'=>time()+300];
    OidcVerifier::validateClaims($claims,'https://issuer.example/realms/shop','shopkmp-backend');
    foreach(['iss'=>'https://evil.example','aud'=>['account'],'typ'=>'ID','azp'=>'another','sub'=>'','exp'=>time()-1] as $key=>$bad){
        errorCode('UNAUTHORIZED',fn()=>OidcVerifier::validateClaims(array_replace($claims,[$key=>$bad]),'https://issuer.example/realms/shop','shopkmp-backend'));
    }
});
test('registration bootstrap restrictions and live password change revoke prior opaque families',function()use($auth,$db,$repo,$sessions){
    errorCode('ADMIN_BOOTSTRAP_REQUIRED',fn()=>$auth->register(['email'=>'admin@test.example','password'=>'StrongPass7395','fullName'=>'Admin','phone'=>'+79991234567']));
    $email=Ids::uuid().'@test.example';$t=$auth->register(['email'=>$email,'password'=>'StrongPass7395','fullName'=>'Password Buyer','phone'=>'+79991234567']);
    $credentials=new CredentialService($db,$repo,$sessions);$old=[];
    foreach([Ids::uuid(),Ids::uuid()] as $device){
        $credentials->setPin($t['userId'],['deviceId'=>$device,'deviceName'=>'Live password phone','pin'=>'7395']);
        $pair=$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395']);same($t['userId'],$auth->authenticate($pair['accessToken'])['id']);$old[]=$pair;
    }
    $old[]=$sessions->refresh($old[0]['refreshToken']);
    $auth->changePassword($t['userId'],['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);
    foreach($old as $pair){errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($pair['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$sessions->refresh($pair['refreshToken']));}
    same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE user_id=?',[$t['userId']])['state']);
    same(false,$db->one('SELECT sessions_blocked FROM users WHERE id=?',[$t['userId']])['sessions_blocked']);
    same($t['userId'],$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395'])['userId']);
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>$email,'password'=>'StrongPass7395']));
    same($t['userId'],$auth->login(['email'=>$email,'password'=>'ChangedPass8395'])['userId']);
});
test('live Keycloak user logout removes online and offline sessions while signed JWT lasts until expiry',function()use($auth){
    $email=Ids::uuid().'@test.example';$password='StrongPass7395';
    $registered=$auth->register(['email'=>$email,'password'=>$password,'fullName'=>'Live Logout Buyer','phone'=>'+79991234567']);
    $provider=new Keycloak();$user=$registered['userId'];
    $online=$provider->tokens(['grant_type'=>'password','username'=>$email,'password'=>$password,'scope'=>'openid']);
    $offline=$provider->password($email,$password);
    $claims=fn(string $jwt)=>json_decode(\Firebase\JWT\JWT::urlsafeB64Decode(explode('.',$jwt)[1]),true,32,JSON_THROW_ON_ERROR);
    same('Refresh',$claims($online['refreshToken'])['typ']);same('Offline',$claims($offline['refreshToken'])['typ']);
    $jwt=$claims($online['accessToken']);same(300,$jwt['exp']-$jwt['iat']);truth($jwt['exp']>time());
    $get=function(string $path)use($provider): array {$r=$provider->admin('GET',$path);same(200,$r['status']);return json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);};
    $path='/users/'.$user;$active=$get($path.'/sessions');truth(count($active)>0);$clientUuid=null;
    foreach($active as $session){foreach($session['clients'] as $id=>$name){if($name===Env::get('KEYCLOAK_CLIENT_ID','shopkmp-backend')){$clientUuid=$id;}}}
    truth(Ids::valid($clientUuid??''));$offlinePath=$path.'/offline-sessions/'.$clientUuid;
    truth(count($get($offlinePath))>0);
    $auth->logout($user,['refreshToken'=>$offline['refreshToken']]);
    same([],$get($path.'/sessions'));same([],$get($offlinePath));
    foreach([$online,$offline,$registered] as $pair){errorCode('INVALID_CREDENTIALS',fn()=>$auth->refresh(['refreshToken'=>$pair['refreshToken']]));}
    // Signature-only verification deliberately has no immediate JWT denylist.
    same($user,$auth->authenticate($online['accessToken'])['id']);truth($jwt['exp']>time());
});
runTests();
