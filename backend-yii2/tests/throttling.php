<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\domain\Ids;
use Shop\infrastructure\{ApiKernel,Database,Env};
use Shop\repositories\ShopRepository;
use Shop\application\SessionService;

$db=new Database(Env::get('DATABASE_URL'));
function limits(Closure $test,array $overrides=[]): void {
    $values=$overrides+['READ_RATE_LIMIT'=>'100','SEARCH_RATE_LIMIT'=>'30','MUTATION_RATE_LIMIT'=>'120','SENSITIVE_RATE_LIMIT'=>'20','AUTHENTICATED_NETWORK_RATE_LIMIT_MULTIPLIER'=>'4','RATE_LIMIT_WINDOW'=>'1m'];$old=[];
    foreach($values as $key=>$value){$old[$key]=getenv($key);putenv($key.'='.$value);}
    try{$test();}finally{foreach($old as $key=>$value){putenv($value===false?$key:$key.'='.$value);}}
}
function networkIp(): string {return '198.'.random_int(1,254).'.'.random_int(1,254).'.1';}
function identity(): array {
    global $db;$id=Ids::uuid();$db->execute('INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,\'\',\'Throttle Buyer\',\'+79991234567\')',[$id,$id.'@test.example']);
    $session=new SessionService($db,new ShopRepository($db));return $db->transaction(fn()=>$session->issue($id));
}
function boundary(string $method,string $path,string $ip,?string $token=null,string $body='[]'): array {
    global $db;$headers=['content-type'=>'application/json'];if($token!==null){$headers['authorization']='Bearer '.$token;}
    return (new ApiKernel($db))->handle($method,'/api/v1'.$path,$headers,$body,$ip);
}
test('missing invalid and expired protected bearers consume the unverified network ceiling',function()use($db){
    limits(function()use($db){
        $expired=identity();$db->execute('UPDATE access_sessions SET expires_at=now()-interval \'1 second\' WHERE user_id=?',[$expired['userId']]);
        foreach([null,'app_access_invalid',$expired['accessToken']] as $token){$ip=networkIp();$statuses=[];
            for($i=0;$i<3;$i++){$r=boundary('GET','/users/me',$ip,$token);$statuses[]=$r['status'];}
            same([401,429,429],$statuses);same('RATE_LIMITED',$r['data']['error']['code']);truth((int)$r['headers']['retry-after']>=1);
        }
    },['READ_RATE_LIMIT'=>'1']);
});
test('only verified identities receive the multiplier and user limits precede admin rejection',function(){
    limits(function(){
        $ip=networkIp();$one=identity();same(200,boundary('GET','/users/me',$ip,$one['accessToken'])['status']);
        same(429,boundary('GET','/users/me',$ip,'app_access_forged')['status']);
        for($i=0;$i<3;$i++){$t=identity();same(200,boundary('GET','/users/me',$ip,$t['accessToken'])['status']);}
        $t=identity();same(429,boundary('GET','/users/me',$ip,$t['accessToken'])['status']);
        $ip=networkIp();$t=identity();$path='/products/'.Ids::uuid().'/stock';
        same(403,boundary('PATCH',$path,$ip,$t['accessToken'])['status']);same(429,boundary('PATCH',$path,$ip,$t['accessToken'])['status']);
    },['READ_RATE_LIMIT'=>'1','MUTATION_RATE_LIMIT'=>'1']);
});
test('IPv4 /24 IPv6 /64 and IPv4-mapped addresses share canonical network budgets',function(){
    limits(function(){
        $ip=networkIp();$prefix=substr($ip,0,strrpos($ip,'.')+1);
        same(401,boundary('GET','/users/me',$prefix.'1')['status']);same(429,boundary('GET','/users/me',$prefix.'200')['status']);
        $ip=networkIp();same(401,boundary('GET','/users/me',$ip)['status']);same(429,boundary('GET','/users/me','::ffff:'.$ip)['status']);
        $prefix='2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).':';
        same(401,boundary('GET','/users/me',$prefix.':1')['status']);same(429,boundary('GET','/users/me',$prefix.':ff00')['status']);
        same(401,boundary('GET','/users/me','2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::1')['status']);
    },['READ_RATE_LIMIT'=>'1']);
});
test('registration login PIN biometric and refresh use distinct canonical auth ceilings',function(){
    limits(function(){
        $ip=networkIp();
        foreach(['/auth/register'=>10,'/auth/login'=>20,'/auth/pin/login'=>20,'/auth/biometric/challenge'=>20,'/auth/biometric/login'=>20,'/auth/refresh'=>60] as $path=>$ceiling){
            for($i=0;$i<$ceiling;$i++){same(400,boundary('POST',$path,$ip)['status']);}
            same(429,boundary('POST',$path,$ip)['status']);
        }
        // Refresh keeps its own ceiling even with a lower configured credential limit.
        $ip=networkIp();same(400,boundary('POST','/auth/login',$ip)['status']);same(400,boundary('POST','/auth/refresh',$ip)['status']);
    });
});
test('orders reviews payment mutations and ordinary cart mutations have independent budgets',function(){
    limits(function(){
        $t=identity();$token=$t['accessToken'];$ip=networkIp();
        foreach(['/orders','/products/'.Ids::uuid().'/reviews'] as $path){for($i=0;$i<10;$i++){same(400,boundary('POST',$path,$ip,$token)['status']);}same(429,boundary('POST',$path,$ip,$token)['status']);}
        $payment='/payments/'.Ids::uuid();
        for($i=0;$i<20;$i++){same(404,boundary('POST',$payment.'/confirm',$ip,$token,'')['status']);}
        same(429,boundary('POST',$payment.'/refund',$ip,$token,'')['status']);same(429,boundary('POST',$payment.'/void',$ip,$token,'')['status']);
        same(400,boundary('POST','/cart/items',$ip,$token)['status']);
    });
});
test('webhooks are exempt and search remains capped independently of read traffic',function(){
    limits(function(){
        putenv('PAYMENT_WEBHOOK_SECRET=throttle-webhook-secret');$ip=networkIp();
        for($i=0;$i<3;$i++){same(401,boundary('POST','/payments/webhook',$ip)['status']);}
        same(200,boundary('GET','/products/search',$ip,null,'')['status']);same(429,boundary('GET','/products/search',$ip,null,'')['status']);
        same(200,boundary('GET','/categories',$ip,null,'')['status']);
    },['MUTATION_RATE_LIMIT'=>'1','SEARCH_RATE_LIMIT'=>'1','READ_RATE_LIMIT'=>'2']);
});
test('configured sensitive ceiling caps credentials and management without reducing refresh budget',function(){
    limits(function(){
        $ip=networkIp();same(400,boundary('POST','/auth/login',$ip)['status']);same(429,boundary('POST','/auth/login',$ip)['status']);
        same(400,boundary('POST','/auth/refresh',$ip)['status']);same(400,boundary('POST','/auth/refresh',$ip)['status']);
        $t=identity();same(400,boundary('POST','/auth/pin',$ip,$t['accessToken'])['status']);same(429,boundary('PUT','/auth/pin',$ip,$t['accessToken'])['status']);
        same(200,boundary('GET','/auth/pin/devices',$ip,$t['accessToken'],'')['status']);
    },['SENSITIVE_RATE_LIMIT'=>'1']);
});
runTests();
