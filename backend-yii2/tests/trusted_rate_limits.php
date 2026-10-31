<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\domain\Ids;
use Shop\infrastructure\{ApiKernel,Database,Env};
use Shop\repositories\ShopRepository;
use Shop\application\SessionService;

$db=new Database(Env::get('DATABASE_URL'));
putenv('TRUSTED_BFF_IPS=172.30.95.4,172.30.95.5');
putenv('READ_RATE_LIMIT=2');putenv('RATE_LIMIT_WINDOW=1m');putenv('AUTHENTICATED_NETWORK_RATE_LIMIT_MULTIPLIER=2');
function trustNetwork(): string {return '198.'.random_int(1,254).'.'.random_int(1,254);}
function trustRequest(string $client,string $peer='172.30.95.4',?string $token=null,array $extra=[]): array {
    global $db;$headers=['x-shop-rate-client-ip'=>$client]+$extra;
    if($token!==null){$headers['authorization']='Bearer '.$token;}
    return (new ApiKernel($db))->handle('GET','/api/v1/users/me',$headers,'',$peer);
}
function trustIdentity(): array {
    global $db;$id=Ids::uuid();$db->execute('INSERT INTO users(id,email,password_hash,full_name,phone,local_identity_ready) VALUES(?,?,\'\',\'Trust Buyer\',\'+79991234567\',true)',[$id,$id.'@trust.example']);
    return $db->transaction(fn()=>(new SessionService($db,new ShopRepository($db)))->issue($id));
}
test('separate external networks get independent quotas through both BFF replicas',function(){
    $a=trustNetwork();$b=trustNetwork();
    same(401,trustRequest($a.'.1')['status']);same(401,trustRequest($a.'.2','172.30.95.5')['status']);
    same(429,trustRequest($a.'.3')['status']);
    same(401,trustRequest($b.'.1','172.30.95.5')['status']);same(401,trustRequest($b.'.2')['status']);
    $r=trustRequest($b.'.3','172.30.95.5');same(429,$r['status']);same('no-store',$r['headers']['cache-control']);same('60',$r['headers']['retry-after']);
});
test('IPv6 64-bit network and mapped IPv4 limits persist across replicas',function(){
    $prefix='2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2));
    same(401,trustRequest($prefix.'::1')['status']);same(401,trustRequest($prefix.'::2','172.30.95.5')['status']);same(429,trustRequest($prefix.'::3')['status']);
    $v4=trustNetwork();same(401,trustRequest($v4.'.1')['status']);same(401,trustRequest('::ffff:'.$v4.'.2')['status']);same(429,trustRequest($v4.'.3')['status']);
});
test('one user keeps its quota when changing networks and BFF replicas',function(){
    $u=trustIdentity();$ip=trustNetwork();$other=trustNetwork();
    same(200,trustRequest($ip.'.1','172.30.95.4',$u['accessToken'])['status']);
    same(200,trustRequest($other.'.1','172.30.95.5',$u['accessToken'])['status']);
    same(429,trustRequest(trustNetwork().'.1','172.30.95.4',$u['accessToken'])['status']);
});
test('auth multiplier requires verified bearer independently of trusted rate context',function(){
    $ip=trustNetwork();$spoof=['x-user-id'=>Ids::uuid(),'x-admin'=>'true'];
    same(401,trustRequest($ip.'.1','172.30.95.4','app_access_forged',$spoof)['status']);
    same(401,trustRequest($ip.'.2','172.30.95.5',null,$spoof)['status']);
    same(429,trustRequest($ip.'.3','172.30.95.5','app_access_forged',$spoof)['status']);
    foreach([1,2] as $suffix){$u=trustIdentity();same(200,trustRequest($ip.'.'.$suffix,'172.30.95.4',$u['accessToken'])['status']);}
    $u=trustIdentity();same(429,trustRequest($ip.'.4','172.30.95.5',$u['accessToken'])['status']);
});
test('untrusted container cannot rotate client or identity headers to escape its network quota',function(){
    $peer=trustNetwork().'.99';$spoof=['x-real-ip'=>'203.0.113.2','x-forwarded-for'=>'203.0.113.3','x-user-id'=>Ids::uuid(),'x-admin'=>'true'];
    same(401,trustRequest(trustNetwork().'.1',$peer,null,$spoof)['status']);
    same(401,trustRequest(trustNetwork().'.1',$peer,null,$spoof)['status']);
    same(429,trustRequest(trustNetwork().'.1',$peer,null,$spoof)['status']);
});
runTests();
