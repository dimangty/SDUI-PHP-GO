<?php
declare(strict_types=1);
// Retained suite identifier: migration regression for previously signed bearers.
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\infrastructure\ApiKernel;

test('even validly signed historical JWTs and their claim variants fail the bearer boundary',function(){
    [$db,$sessions]=localServices();$u=localUser($db);$local=$sessions->issue($u);$kernel=new ApiKernel($db);
    $claims=['iss'=>'https://retired.example/realms/shop','aud'=>['account','shopkmp-backend'],'azp'=>'shopkmp-backend','typ'=>'Bearer','sub'=>$u,'iat'=>time()-1,'exp'=>time()+300];
    same(200,$kernel->handle('GET','/api/v1/users/me',['authorization'=>'Bearer '.$local['accessToken']],'','203.0.113.31')['status']);
    foreach([[],['iss'=>'https://wrong.example'],['aud'=>['account']],['typ'=>'ID'],['azp'=>'other-client'],['exp'=>time()-10],['nbf'=>time()+60],['realm_access'=>['roles'=>['shopkmp-admin']]]] as $change){
        $token=historicalJwt(array_replace($claims,$change));$r=$kernel->handle('GET','/api/v1/users/me',['authorization'=>'Bearer '.$token],'','203.0.113.31');same(401,$r['status']);same('UNAUTHORIZED',$r['data']['error']['code']);
    }
});
test('admin authority is fresh SQL state and signed realm roles cannot elevate it',function(){
    [$db,$sessions]=localServices();$u=localUser($db);$pair=$sessions->issue($u);$kernel=new ApiKernel($db);
    $request=fn($token)=>$kernel->handle('PATCH','/api/v1/products/00000000-0000-4000-8000-000000000001/stock',['authorization'=>'Bearer '.$token,'content-type'=>'application/json'],'{"delta":1}','203.0.113.32');
    same('ADMIN_REQUIRED',$request($pair['accessToken'])['data']['error']['code']);
    $jwt=historicalJwt(['sub'=>$u,'exp'=>time()+300,'realm_access'=>['roles'=>['shopkmp-admin']]]);same(401,$request($jwt)['status']);
    $db->execute('UPDATE users SET is_admin=true WHERE id=?',[$u]);same(404,$request($pair['accessToken'])['status']);
    $db->execute('UPDATE users SET is_admin=false WHERE id=?',[$u]);same('ADMIN_REQUIRED',$request($pair['accessToken'])['data']['error']['code']);
});
runTests();
