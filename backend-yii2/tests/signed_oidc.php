<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SocketUpstream.php';
use Firebase\JWT\JWT;
use Shop\domain\Ids;
use Shop\infrastructure\{ApiKernel,Database,Env};

function signedFixture(Closure $test): void {
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);$details=openssl_pkey_get_details($key);$realm=Ids::uuid();
    $jwk=['kty'=>'RSA','alg'=>'RS256','use'=>'sig','kid'=>'signed-fixture','n'=>JWT::urlsafeB64Encode($details['rsa']['n']),'e'=>JWT::urlsafeB64Encode($details['rsa']['e'])];
    // The fixture signs real tokens; all authorization/business rows remain real SQL fixtures.
    $server=new SocketUpstream(function($connection,$headers,$first)use($realm,$jwk){
        $origin='http://'.$headers['host'];$path=explode(' ',$first)[1];
        $response=str_contains($path,'.well-known')?['issuer'=>$origin.'/realms/'.$realm,'jwks_uri'=>$origin.'/keys']:['keys'=>[$jwk]];
        $body=json_encode($response);fwrite($connection,"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    });
    $issuer=$server->origin.'/realms/'.$realm;$old=[];
    foreach(['KEYCLOAK_ISSUER'=>$issuer,'KEYCLOAK_CLIENT_ID'=>'shopkmp-backend','KEYCLOAK_CLIENT_SECRET'=>'signed-fixture-secret','READ_RATE_LIMIT'=>'100','MUTATION_RATE_LIMIT'=>'100'] as $name=>$value){$old[$name]=getenv($name);putenv($name.'='.$value);}
    try{
        $db=new Database(Env::get('DATABASE_URL'));$u=Ids::uuid();$c=Ids::uuid();$p=Ids::uuid();
        $db->execute('INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,\'\',\'Signed Claims Buyer\',\'+79991234567\')',[$u,$u.'@test.example']);
        $db->execute('INSERT INTO categories(id,name) VALUES(?,\'Signed category\')',[$c]);$db->execute('INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,\'Signed product\',\'1.00\',10)',[$p,$c]);
        $claims=['iss'=>$issuer,'aud'=>['account','shopkmp-backend'],'azp'=>'shopkmp-backend','typ'=>'Bearer','sub'=>$u,'iat'=>time()-1,'exp'=>time()+300];
        $test(new ApiKernel($db),$db,$key,$claims,$p);
    }finally{foreach($old as $name=>$value){putenv($value===false?$name:$name.'='.$value);}$server->finish();}
}
function signedRequest(ApiKernel $kernel,$key,array $claims,string $method='GET',string $path='/users/me',string $body=''): array {
    $token=JWT::encode($claims,$key,'RS256','signed-fixture');
    return $kernel->handle($method,'/api/v1'.$path,['authorization'=>'Bearer '.$token,'content-type'=>'application/json'],$body,'203.0.'.random_int(1,254).'.1');
}
test('validly signed wrong issuer audience type authorized-party expiry and nbf fail bearer boundary',function(){
    signedFixture(function($kernel,$db,$key,$claims){
        same(200,signedRequest($kernel,$key,$claims)['status']);
        foreach(['iss'=>'https://wrong.example/realms/test','aud'=>['account'],'typ'=>'ID','azp'=>'other-client','exp'=>time()-10,'nbf'=>time()+60] as $name=>$bad){
            $r=signedRequest($kernel,$key,array_replace($claims,[$name=>$bad]));same(401,$r['status']);same('UNAUTHORIZED',$r['data']['error']['code']);
        }
    });
});
test('signed realm-role-only admin authorizes mutation without changing persisted admin flag',function(){
    signedFixture(function($kernel,$db,$key,$claims,$product){
        same(false,$db->one('SELECT is_admin FROM users WHERE id=?',[$claims['sub']])['is_admin']);
        same(403,signedRequest($kernel,$key,$claims,'PATCH','/products/'.$product.'/stock','{"delta":1}')['status']);
        $claims['realm_access']=['roles'=>['shopkmp-user','shopkmp-admin']];
        $r=signedRequest($kernel,$key,$claims,'PATCH','/products/'.$product.'/stock','{"delta":1}');same(200,$r['status']);same(11,$r['data']['stock']);
        same(false,$db->one('SELECT is_admin FROM users WHERE id=?',[$claims['sub']])['is_admin']);
    });
});
runTests();
