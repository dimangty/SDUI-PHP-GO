<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\Ids;
use Shop\infrastructure\{Env,HttpClient,PaymentGateway};
$origin=Env::get('UPSTREAM_FIXTURE_ORIGIN','http://shopkmp-yii2-task1-upstream:8080');
test('bounded external HTTP client does not follow redirects or read oversized bodies',function()use($origin){
    same(302,(new HttpClient(2,1024))->request('GET',$origin.'/redirect')['status']);
    try{(new HttpClient(2,1024))->request('GET',$origin.'/oversize');throw new RuntimeException('Expected limit failure');}catch(RuntimeException $e){truth(str_contains($e->getMessage(),'limit'));}
});
test('external HTTP deadline bounds unresponsive upstream',function()use($origin){
    $start=microtime(true);
    try{(new HttpClient(1))->request('GET',$origin.'/slow');throw new RuntimeException('Expected timeout');}catch(RuntimeException $e){truth(str_contains($e->getMessage(),'unavailable')||str_contains($e->getMessage(),'timeout'));}
    truth(microtime(true)-$start<2.5);
});
test('gateway sends integer cents stable operation key and never blindly retries failed mutation',function()use($origin){
    // Wait for the single-threaded test provider's deliberate slow response to finish.
    usleep(2200000);
    putenv('PAYMENT_GATEWAY_URL='.$origin);putenv('PAYMENT_GATEWAY_API_KEY=task1_gateway_key');
    $p=['id'=>Ids::uuid(),'order_id'=>Ids::uuid(),'method'=>'CARD','amount'=>'9999999999.99'];
    $gateway=new PaymentGateway();$r=$gateway->call('authorize',$p);same('cents-999999999999',$r['transactionId']);same('AUTHORIZED',$r['status']);
    $gateway->call('refund',$p);$gateway->call('void',$p);
    $p['id']=Ids::uuid();putenv('PAYMENT_GATEWAY_URL='.$origin.'/fail');errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$gateway->call('authorize',$p));
    $r=(new HttpClient())->request('GET',$origin.'/attempts/'.$p['id']);same(1,json_decode($r['body'],true)['attempts']);
    putenv('PAYMENT_GATEWAY_URL');putenv('PAYMENT_GATEWAY_API_KEY');
});
test('failed provider HTTP response maps to inherited 400 through API boundary without partial payment state',function()use($origin){
    $db=new \Shop\infrastructure\Database(Env::get('DATABASE_URL'));$repo=new \Shop\repositories\ShopRepository($db);$u=Ids::uuid();$c=Ids::uuid();$p=Ids::uuid();$a=Ids::uuid();
    $db->execute('INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,\'\',\'Provider Buyer\',\'+79991234567\')',[$u,$u.'@test.example']);
    $db->execute('INSERT INTO categories(id,name) VALUES(?,\'Provider category\')',[$c]);$db->execute('INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,\'Provider product\',\'1.25\',10)',[$p,$c]);
    $db->execute('INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,\'X\',\'Y\',\'1\')',[$a,$u]);
    $commerce=new \Shop\application\CommerceService($db,$repo);$commerce->addCart($u,['productId'=>$p,'quantity'=>2]);$o=$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD']);
    $sessions=new \Shop\application\SessionService($db,$repo);$tokens=$db->transaction(fn()=>$sessions->issue($u));
    putenv('PAYMENT_GATEWAY_URL='.$origin.'/fail');putenv('PAYMENT_GATEWAY_API_KEY=task1_gateway_key');
    try{
        $r=(new \Shop\infrastructure\ApiKernel($db))->handle('POST','/api/v1/payments/'.$o['paymentId'].'/confirm',['authorization'=>'Bearer '.$tokens['accessToken']],'','192.0.2.'.random_int(1,254));
        same(400,$r['status']);same('PAYMENT_GATEWAY_UNAVAILABLE',$r['data']['error']['code']);
        same('PENDING',$repo->payment($o['paymentId'])['status']);same('CREATED',$repo->order($o['id'])['status']);same(8,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
        $attempts=(new HttpClient())->request('GET',$origin.'/attempts/'.$o['paymentId']);same(1,json_decode($attempts['body'],true)['attempts']);
    }finally{putenv('PAYMENT_GATEWAY_URL');putenv('PAYMENT_GATEWAY_API_KEY');}
});
runTests();
