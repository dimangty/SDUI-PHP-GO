<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\Ids;
use Shop\infrastructure\{ApiKernel,Database,Env,HttpClient};
use Shop\repositories\ShopRepository;
use Shop\application\SessionService;

$db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$sessions=new SessionService($db,$repo);$kernel=new ApiKernel($db);$successfulRoutes=[];
function request(string $method,string $path,?array $body=null,?string $token=null,array $headers=[],?string $raw=null): array {
    global $kernel,$successfulRoutes;
    $headers+=['content-type'=>'application/json'];if($token!==null){$headers['authorization']='Bearer '.$token;}
    $raw??=$body===null?'':json_encode($body,JSON_THROW_ON_ERROR);
    if(Env::get('API_ORIGIN')!==''){
        $r=(new HttpClient())->request($method,Env::get('API_ORIGIN').$path,$headers,$raw);
        $result=['status'=>$r['status'],'data'=>str_starts_with($r['headers']['content-type']??'','application/json')?json_decode($r['body'],true):$r['body'],'headers'=>$r['headers']];
    }else{$result=$kernel->handle($method,$path,$headers,$raw,'127.0.0.1');}
    if($result['status']>=200 && $result['status']<300){
        foreach(require dirname(__DIR__).'/config/routes.php' as $route){
            $pattern=preg_replace('/\{[^}]+\}/','[^/]+',$route[0]);
            if(preg_match('~^'.$pattern.'$~D',$method.' '.substr(parse_url($path,PHP_URL_PATH),7))){$successfulRoutes[$route[0]]=true;}
        }
    }
    return $result;
}
function apiFixture(bool $admin=false): array {
    global $db,$sessions;
    $u=Ids::uuid();$c=Ids::uuid();
    $db->execute('INSERT INTO users(id,email,password_hash,full_name,phone,is_admin,local_identity_ready) VALUES(?,?,\'\',\'API Buyer\',\'+79991234567\',?,true)',[$u,"$u@test.example",$admin]);
    $db->execute('INSERT INTO categories(id,name) VALUES(?,\'API Category\')',[$c]);
    $t=$db->transaction(fn()=>$sessions->issue($u));return [$u,$c,$t['accessToken']];
}
test('health endpoints and exact error envelope are served without cookies',function(){
    same(200,request('GET','/health/live')['status']);
    same(200,request('GET','/health/ready')['status']);
    $r=request('GET','/api/v1/missing');same(404,$r['status']);same('NOT_FOUND',$r['data']['error']['code']);truth(is_string($r['data']['error']['traceId']));
    truth(!isset($r['headers']['set-cookie']));
});
test('every protected route rejects unauthenticated access and each admin route rejects ordinary user',function(){
    [,,$token]=apiFixture();$routes=require dirname(__DIR__).'/config/routes.php';
    foreach($routes as $route){if($route[3]==='public'){continue;}[$method,$path]=explode(' ',$route[0],2);$path=preg_replace('/\{[^}]+\}/',Ids::uuid(),$path);
        $r=request($method,'/api/v1'.$path,[]);same(401,$r['status']);same('UNAUTHORIZED',$r['data']['error']['code']);
        if($route[3]==='admin'){$r=request($method,'/api/v1'.$path,[],$token);same(403,$r['status']);same('ADMIN_REQUIRED',$r['data']['error']['code']);}
    }
});
test('HTTP strict JSON types unknown keys content type and raw-body limits',function(){
    [,,$token]=apiFixture();
    foreach(['{"quantity":"2"}','{"quantity":2.0}','{"quantity":2,"isAdmin":true}','[]','{} {}'] as $raw){$r=request('POST','/api/v1/cart/items',null,$token,[],$raw);same(400,$r['status']);same('BAD_REQUEST',$r['data']['error']['code']);}
    same(415,request('POST','/api/v1/auth/login',[],null,['content-type'=>'text/plain'])['status']);
    same(413,request('POST','/api/v1/auth/login',null,null,[],str_repeat('x',1048577))['status']);
    same(405,request('PATCH','/api/v1/categories',null)['status']);
});
test('API catalog admin profile cart checkout webhook reviews and cancellation round trip',function(){
    global $db;
    [$admin,$c,$adminToken]=apiFixture(true);[$u,,$token]=apiFixture();[$other,,$otherToken]=apiFixture();
    $r=request('POST','/api/v1/admin/products',['categoryId'=>$c,'name'=>'Canonical product','price'=>'12.34','stock'=>8,'imageUrls'=>[]],$adminToken);same(201,$r['status']);$p=$r['data'];$id=$p['id'];same('12.34',$p['price']);same(null,$p['rating']);same([],$p['imageUrls']);
    same(200,request('PUT','/api/v1/admin/products/'.$id,['discountPrice'=>'10.01'],$adminToken)['status']);
    same('10.01',request('GET','/api/v1/products/'.$id)['data']['discountPrice']);
    same(200,request('PATCH','/api/v1/products/'.$id.'/stock',['delta'=>2],$adminToken)['status']);
    same(200,request('GET','/api/v1/categories/'.$c)['status']);same([] ,request('GET','/api/v1/categories/'.$c.'/children')['data']);
    $r=request('POST','/api/v1/addresses',['city'=>'Moscow','street'=>'API Street','house'=>'1','comment'=>null],$token);same(201,$r['status']);$a=$r['data']['id'];
    same(200,request('PUT','/api/v1/addresses/'.$a,['city'=>'Moscow','street'=>'Updated','house'=>'1'],$token)['status']);
    same(404,request('PUT','/api/v1/addresses/'.$a,['city'=>'X','street'=>'Y','house'=>'1'],$otherToken)['status']);
    same($a,request('GET','/api/v1/users/me',null,$token)['data']['defaultAddressId']);
    same(null,request('PUT','/api/v1/users/me',['fullName'=>'Buyer','phone'=>'+79991234567','defaultAddressId'=>null],$token)['data']['defaultAddressId']);
    $r=request('POST','/api/v1/cart/items',['productId'=>$id,'quantity'=>2],$token,['idempotency-key'=>'api-cart']);same(200,$r['status']);same('20.02',$r['data']['totalAmount']);
    same(3,request('PATCH','/api/v1/cart/items/'.$id,['quantity'=>3],$token)['data']['items'][0]['quantity']);
    same(2,request('POST','/api/v1/cart/items',['productId'=>$id,'quantity'=>2],$token,['idempotency-key'=>'api-cart'])['data']['items'][0]['quantity']);
    $r=request('POST','/api/v1/orders',['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],$token,['idempotency-key'=>'api-order']);same(201,$r['status']);$o=$r['data'];same('30.03',$o['totalAmount']);
    same(404,request('GET','/api/v1/orders/'.$o['id'],null,$otherToken)['status']);
    same(404,request('GET','/api/v1/payments/'.$o['paymentId'],null,$otherToken)['status']);
    same('AUTHORIZED',request('POST','/api/v1/payments/'.$o['paymentId'].'/confirm',null,$token)['data']['status']);
    $raw=json_encode(['paymentId'=>$o['paymentId'],'status'=>'CAPTURED','transactionId'=>'api-capture-'.$o['paymentId']]);$ts=(string)time();$wid=Ids::uuid();
    $headers=['x-webhook-id'=>$wid,'x-webhook-timestamp'=>$ts,'x-webhook-signature'=>hash_hmac('sha256',$ts.':'.$wid.':'.$raw,Env::get('PAYMENT_WEBHOOK_SECRET'))];
    same(401,request('POST','/api/v1/payments/webhook',null,null,[], $raw)['status']);
    same(204,request('POST','/api/v1/payments/webhook',null,null,$headers,$raw)['status']);
    same(409,request('POST','/api/v1/payments/webhook',null,null,$headers,$raw)['status']);
    same('PAID',request('GET','/api/v1/orders/'.$o['id'],null,$token)['data']['status']);
    foreach(['ASSEMBLING','DELIVERING','COMPLETED'] as $status){same($status,request('POST','/api/v1/orders/'.$o['id'].'/status',['status'=>$status],$adminToken)['data']['status']);}
    $r=request('POST','/api/v1/products/'.$id.'/reviews',['rating'=>5,'text'=>' API review '],$token);same(201,$r['status']);$review=$r['data'];
    same(1,request('GET','/api/v1/products/'.$id.'/reviews')['data']['totalItems']);
    same(403,request('DELETE','/api/v1/reviews/'.$review['id'],null,$otherToken)['status']);
    same(204,request('DELETE','/api/v1/reviews/'.$review['id'],null,$token)['status']);
    same('REFUNDED',request('POST','/api/v1/payments/'.$o['paymentId'].'/refund',null,$adminToken)['data']['status']);
    request('POST','/api/v1/cart/items',['productId'=>$id,'quantity'=>1],$token);
    $o2=request('POST','/api/v1/orders',['deliveryAddressId'=>$a,'paymentMethod'=>'SBP'],$token)['data'];
    request('POST','/api/v1/payments/'.$o2['paymentId'].'/confirm',null,$token);
    same('CANCELLED',request('POST','/api/v1/payments/'.$o2['paymentId'].'/void',null,$adminToken)['data']['status']);
    request('POST','/api/v1/cart/items',['productId'=>$id,'quantity'=>1],$token);
    $o3=request('POST','/api/v1/orders',['deliveryAddressId'=>$a,'paymentMethod'=>'ON_DELIVERY'],$token)['data'];
    same('CANCELLED',request('POST','/api/v1/orders/'.$o3['id'].'/cancel',null,$token)['data']['status']);
    same(200,request('GET','/api/v1/orders?page=0&size=1',null,$token)['status']);
    same(204,request('DELETE','/api/v1/cart',null,$token)['status']);
    $unused=request('POST','/api/v1/addresses',['city'=>'X','street'=>'Y','house'=>'2'],$token)['data']['id'];same(204,request('DELETE','/api/v1/addresses/'.$unused,null,$token)['status']);
    same(204,request('DELETE','/api/v1/admin/products/'.$id,null,$adminToken)['status']);
    same(404,request('GET','/api/v1/products/'.$id)['status']);
});
test('avatar image is normalized, downloaded by owner and cannot leak across users',function(){
    [$u,,$token]=apiFixture();[,,$other]=apiFixture();
    $image=imagecreatetruecolor(800,600);ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
    $boundary='shopkmp-test-boundary';$body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"file\"; filename=\"../evil.php\"\r\nContent-Type: image/png\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
    $r=request('PUT','/api/v1/users/me/avatar',null,$token,['content-type'=>'multipart/form-data; boundary='.$boundary],$body);same(200,$r['status']);$url=$r['data']['avatarUrl'];
    same(403,request('GET',$url,null,$other)['status']);$download=request('GET',$url,null,$token);same(200,$download['status']);$info=getimagesizefromstring($download['data']);same(512,$info[0]);same(384,$info[1]);
    same(400,request('PUT','/api/v1/users/me/avatar',['avatarUrl'=>'http://127.0.0.1/secret'],$token)['status']);
    same(null,request('PUT','/api/v1/users/me/avatar',['avatarUrl'=>null],$token)['data']['avatarUrl']);
    same(404,request('GET',$url,null,$token)['status']);
});
test('real HTTP password PIN biometric refresh device revocation and logout flows',function(){
    $email=Ids::uuid().'@test.example';$password='ApiStrong7395';
    $r=request('POST','/api/v1/auth/register',['email'=>$email,'password'=>$password,'fullName'=>'API Identity Buyer','phone'=>'+79991234567']);same(201,$r['status']);$t=$r['data'];
    same(200,request('POST','/api/v1/auth/login',['email'=>$email,'password'=>$password])['status']);
    $r=request('POST','/api/v1/auth/refresh',['refreshToken'=>$t['refreshToken']]);same(200,$r['status']);$t=$r['data'];$token=$t['accessToken'];
    $device=Ids::uuid();same(201,request('POST','/api/v1/auth/pin',['deviceId'=>$device,'deviceName'=>'API Phone','pin'=>'7395'],$token)['status']);
    same(true,request('GET','/api/v1/auth/session?deviceId='.$device,null,$token)['data']['device']['pinEnabled']);
    same(200,request('GET','/api/v1/auth/pin/devices',null,$token)['status']);
    same(204,request('PUT','/api/v1/auth/pin',['deviceId'=>$device,'currentPin'=>'7395','newPin'=>'6385'],$token)['status']);
    $r=request('POST','/api/v1/auth/pin/login',['deviceId'=>$device,'pin'=>'6385']);same(200,$r['status']);$pinTokens=$r['data'];
    same(200,request('POST','/api/v1/auth/refresh',['refreshToken'=>$pinTokens['refreshToken']])['status']);
    same(204,request('DELETE','/api/v1/auth/pin?deviceId='.$device,null,$token)['status']);
    same(201,request('POST','/api/v1/auth/pin',['deviceId'=>$device,'deviceName'=>'API Phone','pin'=>'7395'],$token)['status']);
    same(204,request('DELETE','/api/v1/auth/pin/devices/'.$device,null,$token)['status']);
    $kp=sodium_crypto_sign_keypair();$public=sodium_crypto_sign_publickey($kp);$private=sodium_crypto_sign_secretkey($kp);
    $enroll=['deviceId'=>$device,'deviceName'=>'API Biometric','publicKey'=>base64_encode(hex2bin('302a300506032b6570032100').$public),'keyAlgorithm'=>'EdDSA'];
    $r=request('POST','/api/v1/auth/biometric',$enroll,$token);same(201,$r['status']);$id=$r['data']['credentialId'];
    $r=request('POST','/api/v1/auth/biometric/challenge',['credentialId'=>$id]);same(200,$r['status']);$nonce=$r['data']['challenge'];
    same(200,request('POST','/api/v1/auth/biometric/login',['credentialId'=>$id,'challenge'=>$nonce,'signature'=>base64_encode(sodium_crypto_sign_detached($nonce,$private))])['status']);
    same(200,request('GET','/api/v1/auth/biometric/devices',null,$token)['status']);
    same(204,request('DELETE','/api/v1/auth/biometric?deviceId='.$device,null,$token)['status']);
    same(201,request('POST','/api/v1/auth/biometric',$enroll,$token)['status']);
    same(204,request('DELETE','/api/v1/auth/biometric/devices/'.$device,null,$token)['status']);
    same(204,request('POST','/api/v1/users/me/change-password',['oldPassword'=>$password,'newPassword'=>'ApiChanged8395'],$token)['status']);
    $t=request('POST','/api/v1/auth/login',['email'=>$email,'password'=>'ApiChanged8395'])['data'];
    same(204,request('POST','/api/v1/auth/logout',['refreshToken'=>$t['refreshToken']],$t['accessToken'])['status']);
});
test('remaining canonical catalog and cart reads and deletion succeed',function(){
    global $db;
    [$u,$c,$token]=apiFixture();$id=Ids::uuid();$db->execute('INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,\'Read product\',\'1.00\',3)',[$id,$c]);
    same(200,request('GET','/api/v1/categories')['status']);same(200,request('GET','/api/v1/products?categoryId='.$c)['status']);
    same(200,request('GET','/api/v1/products/search')['status']);same(200,request('GET','/api/v1/products/popular')['status']);
    same(200,request('GET','/api/v1/addresses',null,$token)['status']);same(200,request('GET','/api/v1/cart',null,$token)['status']);
    request('POST','/api/v1/cart/items',['productId'=>$id,'quantity'=>1],$token);
    same(200,request('DELETE','/api/v1/cart/items/'.$id,null,$token)['status']);
    request('POST','/api/v1/cart/items',['productId'=>$id,'quantity'=>1],$token);$a=request('POST','/api/v1/addresses',['city'=>'X','street'=>'Y','house'=>'1'],$token)['data']['id'];
    $o=request('POST','/api/v1/orders',['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],$token)['data'];
    same(200,request('GET','/api/v1/payments/'.$o['paymentId'],null,$token)['status']);
});
test('development browser form POST completes CARD payment without CORS permission but requires its capability',function(){
    global $db;
    [$u,$c,$token]=apiFixture();$id=Ids::uuid();$db->execute('INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,\'CORS CARD product\',\'1.00\',3)',[$id,$c]);
    same(200,request('POST','/api/v1/cart/items',['productId'=>$id,'quantity'=>1],$token)['status']);
    $address=request('POST','/api/v1/addresses',['city'=>'X','street'=>'CORS Street','house'=>'1'],$token);same(201,$address['status']);
    $order=request('POST','/api/v1/orders',['deliveryAddressId'=>$address['data']['id'],'paymentMethod'=>'CARD'],$token);same(201,$order['status']);$order=$order['data'];
    $confirm=request('POST','/api/v1/payments/'.$order['paymentId'].'/confirm',null,$token);same(200,$confirm['status']);same('AUTHORIZED',$confirm['data']['status']);$url=$confirm['data']['paymentUrl'];
    truth(str_starts_with($url,'/demo/payments/'.$order['paymentId'].'?token='));
    $page=request('GET',$url);same(200,$page['status']);truth(str_contains($page['data'],'<form method="post">'));
    same("default-src 'none'; form-action 'self'; base-uri 'none'",$page['headers']['content-security-policy']);
    $headers=['origin'=>'http://10.0.2.2:58095','content-type'=>'application/x-www-form-urlencoded'];
    $invalid=request('POST','/demo/payments/'.$order['paymentId'].'?token=invalid',null,null,$headers,'');
    same(403,$invalid['status']);same('DEMO_PAYMENT_FORBIDDEN',$invalid['data']['error']['code']);truth(!isset($invalid['headers']['access-control-allow-origin']));
    same('AUTHORIZED',request('GET','/api/v1/payments/'.$order['paymentId'],null,$token)['data']['status']);
    same('CREATED',request('GET','/api/v1/orders/'.$order['id'],null,$token)['data']['status']);
    $complete=request('POST',$url,null,null,$headers,'');same(200,$complete['status']);
    truth(str_starts_with($complete['headers']['content-type'],'text/html'));truth(str_contains($complete['data'],'Payment completed'));
    same('Origin',$complete['headers']['vary']??null);truth(!isset($complete['headers']['access-control-allow-origin']));truth(!isset($complete['headers']['access-control-allow-credentials']));
    same("default-src 'none'; form-action 'self'; base-uri 'none'",$complete['headers']['content-security-policy']);
    same('CAPTURED',request('GET','/api/v1/payments/'.$order['paymentId'],null,$token)['data']['status']);
    same('PAID',request('GET','/api/v1/orders/'.$order['id'],null,$token)['data']['status']);
});
test('each of the 54 canonical routes has a successful exercised HTTP response',function(){
    global $successfulRoutes;$missing=[];
    foreach(require dirname(__DIR__).'/config/routes.php' as $r){if(!isset($successfulRoutes[$r[0]])){$missing[]=$r[0];}}
    same([],$missing);same(54,count($successfulRoutes));
});
runTests();
