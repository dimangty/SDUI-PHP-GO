<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;
use Shop\application\{CommerceService,SessionService,CredentialService,PaymentService};

function connection(): Database {return new Database(Env::get('DATABASE_URL'));}
function concurrentFixture(int $stock=1): array {
    $db=connection();$c=Ids::uuid();$p=Ids::uuid();$db->execute('INSERT INTO categories(id,name) VALUES(?,\'Concurrent\')',[$c]);$db->execute('INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,\'Concurrent\',\'0.10\',?)',[$p,$c,$stock]);$users=[];
    for($i=0;$i<2;$i++){$u=Ids::uuid();$a=Ids::uuid();$db->execute('INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,\'\',\'Race Buyer\',\'+79991234567\')',[$u,"$u@test.example"]);$db->execute('INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,\'X\',\'Y\',\'1\')',[$a,$u]);$s=new CommerceService($db,new ShopRepository($db));$s->addCart($u,['productId'=>$p,'quantity'=>1]);$users[]=[$u,$a];}
    return [$p,$users];
}
function race(array $operations): array {
    $children=[];$sockets=[];
    foreach($operations as $op){[$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
        if($pid===0){fclose($parent);fread($child,1);try{$result=$op();$out=['ok'=>$result];}catch(ApiError $e){$out=['error'=>$e->errorCode];}catch(Throwable $e){$out=['exception'=>$e->getMessage()];}fwrite($child,json_encode($out));fclose($child);exit(0);}
        fclose($child);$children[]=$pid;$sockets[]=$parent;
    }
    foreach($sockets as $s){fwrite($s,'x');}$out=[];
    foreach($sockets as $s){$out[]=json_decode(stream_get_contents($s),true);fclose($s);}foreach($children as $pid){pcntl_waitpid($pid,$status);same(0,pcntl_wexitstatus($status));}return $out;
}
test('two buyers racing last stock produce exactly one coherent checkout',function(){
    [$p,$users]=concurrentFixture();$ops=[];
    foreach($users as [$u,$a]){$ops[]=function()use($u,$a){$db=connection();return (new CommerceService($db,new ShopRepository($db)))->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],'race')['id'];};}
    $out=race($ops);same(1,count(array_filter($out,fn($r)=>isset($r['ok']))));same(1,count(array_filter($out,fn($r)=>($r['error']??'')==='OUT_OF_STOCK')));
    $db=connection();same(0,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);same(1,$db->one('SELECT count(*) AS n FROM order_items WHERE product_id=?',[$p])['n']);same(1,$db->one('SELECT count(*) AS n FROM cart_items WHERE product_id=?',[$p])['n']);
});
test('concurrent same-key checkout returns identical original snapshot only once',function(){
    [$p,$users]=concurrentFixture(10);[$u,$a]=$users[0];$op=function()use($u,$a){$db=connection();return (new CommerceService($db,new ShopRepository($db)))->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],'same-key');};
    $out=race([$op,$op]);truth(isset($out[0]['ok'])&&isset($out[1]['ok']));same($out[0]['ok'],$out[1]['ok']);$db=connection();same(1,$db->one('SELECT count(*) AS n FROM orders WHERE user_id=?',[$u])['n']);same(9,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
});
test('concurrent refresh token consumption has one winner',function(){
    [, $users]=concurrentFixture(10);[$u]=$users[0];$db=connection();$sessions=new SessionService($db,new ShopRepository($db));$t=$db->transaction(fn()=>$sessions->issue($u));unset($sessions,$db);
    $op=function()use($t){$db=connection();return (new SessionService($db,new ShopRepository($db)))->refresh($t['refreshToken']);};
    $out=race([$op,$op]);same(1,count(array_filter($out,fn($r)=>isset($r['ok']))));same(1,count(array_filter($out,fn($r)=>($r['error']??'')==='INVALID_REFRESH_TOKEN')));
});
test('concurrent PIN failures cannot lose counters',function(){
    [, $users]=concurrentFixture(10);[$u]=$users[0];$device=Ids::uuid();$db=connection();$repo=new ShopRepository($db);$c=new CredentialService($db,$repo,new SessionService($db,$repo));$c->setPin($u,['deviceId'=>$device,'deviceName'=>'Race Phone','pin'=>'7395']);unset($c,$repo,$db);
    $op=function()use($device){$db=connection();$r=new ShopRepository($db);return (new CredentialService($db,$r,new SessionService($db,$r)))->loginPin(['deviceId'=>$device,'pin'=>'0000']);};
    $out=race([$op,$op,$op,$op,$op]);same(4,count(array_filter($out,fn($r)=>($r['error']??'')==='INVALID_PIN')));same(1,count(array_filter($out,fn($r)=>($r['error']??'')==='PIN_LOCKED')));$db=connection();same(1,$db->one('SELECT lock_count FROM device_pins WHERE device_id=?',[$device])['lock_count']);
});
test('concurrent duplicate FAILED webhooks restore stock at most once',function(){
    [$p,$users]=concurrentFixture(10);[$u,$a]=$users[0];$db=connection();$repo=new ShopRepository($db);$commerce=new CommerceService($db,$repo);$payments=new PaymentService($db,$repo,$commerce);$o=$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD']);$payments->confirm($u,$o['paymentId']);unset($payments,$commerce,$repo,$db);$wid=Ids::uuid();
    $op=function()use($o,$wid){$db=connection();$r=new ShopRepository($db);$p=new PaymentService($db,$r,new CommerceService($db,$r));$p->webhook(['paymentId'=>$o['paymentId'],'status'=>'FAILED'],$wid);return true;};
    $out=race([$op,$op]);same(1,count(array_filter($out,fn($r)=>isset($r['ok']))));same(1,count(array_filter($out,fn($r)=>($r['error']??'')==='ALREADY_PROCESSED')));$db=connection();same(10,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
});
test('multi-product checkout locks in ID order despite opposite cart insertion order',function(){
    [$p,$users]=concurrentFixture(10);$db=connection();$c=$db->one('SELECT category_id FROM products WHERE id=?',[$p])['category_id'];$other=Ids::uuid();$db->execute('INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,\'Second race product\',\'0.20\',10)',[$other,$c]);
    foreach($users as $index=>[$u]){$commerce=new CommerceService($db,new ShopRepository($db));if($index===1){$commerce->removeCart($u,$p);}$commerce->addCart($u,['productId'=>$other,'quantity'=>1]);if($index===1){$commerce->addCart($u,['productId'=>$p,'quantity'=>1]);}}$db->execute('UPDATE products SET stock=1 WHERE id IN (?,?)',[$p,$other]);unset($commerce,$db);
    $ops=[];foreach($users as [$u,$a]){$ops[]=function()use($u,$a){$db=connection();return (new CommerceService($db,new ShopRepository($db)))->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD']);};}
    $out=race($ops);same(1,count(array_filter($out,fn($r)=>isset($r['ok']))));same(1,count(array_filter($out,fn($r)=>($r['error']??'')==='OUT_OF_STOCK')));
    $db=connection();same(0,$db->one('SELECT sum(stock)::int AS n FROM products WHERE id IN (?,?)',[$p,$other])['n']);same(2,$db->one('SELECT count(*) AS n FROM order_items WHERE product_id IN (?,?)',[$p,$other])['n']);
});
test('concurrent valid biometric replay issues only one session pair',function(){
    [, $users]=concurrentFixture(10);[$u]=$users[0];$db=connection();$r=new ShopRepository($db);$c=new CredentialService($db,$r,new SessionService($db,$r));$kp=sodium_crypto_sign_keypair();
    $id=$c->enroll($u,['deviceId'=>Ids::uuid(),'deviceName'=>'Race Bio','publicKey'=>base64_encode(hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($kp)),'keyAlgorithm'=>'EdDSA'])['credentialId'];$nonce=$c->challenge(['credentialId'=>$id])['challenge'];$input=['credentialId'=>$id,'challenge'=>$nonce,'signature'=>base64_encode(sodium_crypto_sign_detached($nonce,sodium_crypto_sign_secretkey($kp)))];unset($c,$r,$db);
    $op=function()use($input){$db=connection();$r=new ShopRepository($db);return (new CredentialService($db,$r,new SessionService($db,$r)))->loginBiometric($input);};
    $out=race([$op,$op]);same(1,count(array_filter($out,fn($r)=>isset($r['ok']))));same(1,count(array_filter($out,fn($r)=>($r['error']??'')==='CHALLENGE_EXPIRED')));
    $db=connection();same(1,$db->one('SELECT count(*) AS n FROM refresh_sessions WHERE user_id=?',[$u])['n']);
});
runTests();
