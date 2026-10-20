<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\{Ids,ApiError};
use Shop\infrastructure\{Database,Env,OidcVerifier};
use Shop\repositories\ShopRepository;
use Shop\application\{AuthService,SessionService};

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
test('registration bootstrap restrictions and password change preserve identity ownership',function()use($auth){
    errorCode('ADMIN_BOOTSTRAP_REQUIRED',fn()=>$auth->register(['email'=>'admin@test.example','password'=>'StrongPass7395','fullName'=>'Admin','phone'=>'+79991234567']));
    $email=Ids::uuid().'@test.example';$t=$auth->register(['email'=>$email,'password'=>'StrongPass7395','fullName'=>'Password Buyer','phone'=>'+79991234567']);
    $auth->changePassword($t['userId'],['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>$email,'password'=>'StrongPass7395']));
    same($t['userId'],$auth->login(['email'=>$email,'password'=>'ChangedPass8395'])['userId']);
});
runTests();
