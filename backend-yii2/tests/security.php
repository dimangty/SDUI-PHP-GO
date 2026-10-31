<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\Ids;
use Shop\infrastructure\{ApiKernel,Database,Env,Network,RateLimiter};
use Shop\repositories\ShopRepository;
use Shop\application\AvatarService;
$db=new Database(Env::get('DATABASE_URL'));$kernel=new ApiKernel($db);
test('wrong opaque-token kind is rejected locally',function()use($kernel){
    $r=$kernel->handle('POST','/api/v1/auth/refresh',['content-type'=>'application/json'],json_encode(['refreshToken'=>'app_access_'.Ids::opaque()]),'192.0.2.91');
    same(401,$r['status']);same('INVALID_REFRESH_TOKEN',$r['data']['error']['code']);
});
test('webhook HMAC covers original bytes timestamp and ID, with trusted source checks',function()use($kernel){
    putenv('PAYMENT_WEBHOOK_SECRET=security-test-secret');$raw=' { "paymentId":"'.Ids::uuid().'", "status":"CAPTURED" } ';$ts=(string)time();$id=Ids::uuid();
    $h=['origin'=>'http://10.0.2.2:58095','content-type'=>'application/json','x-webhook-timestamp'=>$ts,'x-webhook-id'=>$id,'x-webhook-signature'=>hash_hmac('sha256',$ts.':'.$id.':'.$raw,'security-test-secret')];
    same(404,$kernel->handle('POST','/api/v1/payments/webhook',$h,$raw,'192.0.2.90')['status']);
    same(401,$kernel->handle('POST','/api/v1/payments/webhook',$h,trim($raw),'192.0.2.90')['status']);
    foreach([time()-301,time()+61] as $time){$changed=$h;$changed['x-webhook-timestamp']=(string)$time;$changed['x-webhook-signature']=hash_hmac('sha256',$time.':'.$id.':'.$raw,'security-test-secret');same(401,$kernel->handle('POST','/api/v1/payments/webhook',$changed,$raw,'192.0.2.90')['status']);}
    putenv('PAYMENT_WEBHOOK_ALLOWED_CIDRS=203.0.113.0/24');$h['x-forwarded-for']='203.0.113.1';same(403,$kernel->handle('POST','/api/v1/payments/webhook',$h,$raw,'192.0.2.90')['status']);putenv('PAYMENT_WEBHOOK_ALLOWED_CIDRS');
});
test('distributed rate limit persists across application instances',function()use($db){
    $bucket='test:'.Ids::uuid();(new RateLimiter($db))->check($bucket,2);(new RateLimiter($db))->check($bucket,2);
    errorCode('RATE_LIMITED',fn()=>(new RateLimiter($db))->check($bucket,2));
});
test('invalid webhook CIDR configuration rejects signed requests and readiness',function()use($kernel){
    putenv('PAYMENT_WEBHOOK_SECRET=security-test-secret');$body=json_encode(['paymentId'=>Ids::uuid(),'status'=>'CAPTURED']);$ts=(string)time();$id=Ids::uuid();
    $h=['content-type'=>'application/json','x-webhook-timestamp'=>$ts,'x-webhook-id'=>$id,'x-webhook-signature'=>hash_hmac('sha256',$ts.':'.$id.':'.$body,'security-test-secret')];
    try{
        foreach(['192.0.2.0/24,203.0.113.0/','203.0.113.0/garbage','::/0garbage'] as $list){
            putenv('PAYMENT_WEBHOOK_ALLOWED_CIDRS='.$list);
            $r=$kernel->handle('POST','/api/v1/payments/webhook',$h,$body,'192.0.2.234');
            same(503,$r['status']);same('WEBHOOK_NOT_CONFIGURED',$r['data']['error']['code']);
            same(503,$kernel->handle('GET','/health/ready',[],'','192.0.2.234')['status']);
        }
    }finally{putenv('PAYMENT_WEBHOOK_ALLOWED_CIDRS');}
});
test('forwarded addresses require trusted proxies and IPv4 IPv6 CIDR boundaries',function(){
    truth(Network::contains('203.0.113.1','203.0.113.0/24'));same(false,Network::contains('203.0.114.1','203.0.113.0/24'));
    truth(Network::contains('2001:db8::1','2001:db8::/32'));same(false,Network::contains('2001:db9::1','2001:db8::/32'));
    same('192.0.2.1',Network::client('192.0.2.1',['x-forwarded-for'=>'203.0.113.1']));
});
test('trusted BFF rate context cannot replace the transport webhook source',function()use($kernel){
    $before=getenv('TRUSTED_BFF_IPS');putenv('TRUSTED_BFF_IPS=192.0.2.235');putenv('PAYMENT_WEBHOOK_ALLOWED_CIDRS=203.0.113.1/32');
    putenv('PAYMENT_WEBHOOK_SECRET=security-test-secret');$body=json_encode(['paymentId'=>Ids::uuid(),'status'=>'CAPTURED']);$ts=(string)time();$id=Ids::uuid();
    $h=['content-type'=>'application/json','x-webhook-timestamp'=>$ts,'x-webhook-id'=>$id,'x-webhook-signature'=>hash_hmac('sha256',$ts.':'.$id.':'.$body,'security-test-secret'),'x-shop-rate-client-ip'=>'203.0.113.1'];
    try{same(403,$kernel->handle('POST','/api/v1/payments/webhook',$h,$body,'192.0.2.235')['status']);}
    finally{putenv('PAYMENT_WEBHOOK_ALLOWED_CIDRS');putenv($before===false?'TRUSTED_BFF_IPS':'TRUSTED_BFF_IPS='.$before);}
});
test('avatar rejects script input and excessive decoded pixels before allocation',function()use($db){
    $avatar=new AvatarService($db,new ShopRepository($db));errorCode('INVALID_FILE_TYPE',fn()=>$avatar->normalize('<svg onload="alert(1)"></svg>'));
    $image=imagecreatetruecolor(1,1);ob_start();imagepng($image);$png=ob_get_clean();imagedestroy($image);
    $bomb=substr_replace($png,pack('NN',100000,100000),16,8);errorCode('INVALID_FILE_TYPE',fn()=>$avatar->normalize($bomb));
});
test('cookies and forged identity headers cannot authorize private API',function()use($kernel){
    $before=getenv('CORS_ALLOWED_ORIGINS');putenv('CORS_ALLOWED_ORIGINS=https://shop.example');
    try{
        foreach(['https://shop.example','http://10.0.2.2:58095','null',''] as $origin){
            $r=$kernel->handle('GET','/api/v1/users/me',['origin'=>$origin,'cookie'=>'accessToken=app_access_fake','x-user-id'=>Ids::uuid(),'x-admin'=>'true'],'','192.0.2.93');
            same(401,$r['status']);same('UNAUTHORIZED',$r['data']['error']['code']);truth(!isset($r['headers']['set-cookie']));truth(!isset($r['headers']['access-control-allow-credentials']));
        }
    }finally{putenv($before===false?'CORS_ALLOWED_ORIGINS':'CORS_ALLOWED_ORIGINS='.$before);}
});
runTests();
