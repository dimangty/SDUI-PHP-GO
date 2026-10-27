<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

use Shop\infrastructure\Database;
use Shop\application\CommerceService;
use Shop\application\CredentialService;
use Shop\application\SessionService;
use Shop\repositories\ShopRepository;
use Shop\domain\Ids;

$db = new Database(getenv('DATABASE_URL') ?: throw new RuntimeException('DATABASE_URL required (isolated test DB only)'));
$repo = new ShopRepository($db);
$sessions = new SessionService($db, $repo);
$credentials = new CredentialService($db, $repo, $sessions);
$commerce = new CommerceService($db, $repo);
function fixture(): array {
    global $db;
    $u=Ids::uuid(); $a=Ids::uuid(); $p=Ids::uuid(); $c=Ids::uuid();
    $db->execute('INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,\'\',\'Test Buyer\',\'+79991234567\')', [$u,"$u@test.example"]);
    $db->execute('INSERT INTO categories(id,name) VALUES(?,\'Test category\')',[$c]);
    $db->execute('INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,\'Exact product\',\'0.10\',10)',[$p,$c]);
    $db->execute('INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,\'Moscow\',\'Test\',\'1\')',[$a,$u]);
    return [$u,$a,$p,$c];
}
test('checkout reprices, atomically inserts items/payment/reserves/clears and replays snapshot', function () use ($db,$commerce) {
    [$u,$a,$p]=fixture();
    $cart=$commerce->addCart($u,['productId'=>$p,'quantity'=>3],'cart-key');
    same('0.30',$cart['totalAmount']);
    $db->execute('UPDATE products SET price=\'0.20\' WHERE id=?',[$p]);
    $order=$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],'order-key');
    same('0.60',$order['totalAmount']); same('0.20',$order['items'][0]['priceSnapshot']);
    same(7,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
    same(0,$db->one('SELECT count(*) AS n FROM cart_items WHERE user_id=?',[$u])['n']);
    same('0.60',$db->one('SELECT amount FROM payments WHERE id=?',[$order['paymentId']])['amount']);
    $db->execute('UPDATE orders SET status=\'PAID\' WHERE id=?',[$order['id']]);
    same($order,$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],'order-key'));
    same($cart,$commerce->addCart($u,['productId'=>$p,'quantity'=>3],'cart-key'));
    errorCode('IDEMPOTENCY_KEY_REUSED',fn()=>$commerce->addCart($u,['productId'=>$p,'quantity'=>2],'cart-key'));
});
test('checkout failure rolls back every table and retains cart', function () use ($db,$commerce) {
    [$u,$a,$p]=fixture(); $commerce->addCart($u,['productId'=>$p,'quantity'=>3]);
    $db->execute('UPDATE products SET stock=1 WHERE id=?',[$p]);
    errorCode('OUT_OF_STOCK',fn()=>$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'SBP'],'failed'));
    same(0,$db->one('SELECT count(*) AS n FROM orders WHERE user_id=?',[$u])['n']);
    same(1,$db->one('SELECT count(*) AS n FROM cart_items WHERE user_id=?',[$u])['n']);
    same(0,$db->one('SELECT count(*) AS n FROM idempotency_keys WHERE user_id=?',[$u])['n']);
});
test('PIN failure persists and locks; success issues only binary hashed opaque sessions',function()use($db,$credentials,$sessions){
    [$u]=fixture(); $device=Ids::uuid();
    $credentials->setPin($u,['deviceId'=>$device,'deviceName'=>'Phone','pin'=>'7395']);
    for($i=0;$i<4;$i++){errorCode('INVALID_PIN',fn()=>$credentials->loginPin(['deviceId'=>$device,'pin'=>'0000']));}
    errorCode('PIN_LOCKED',fn()=>$credentials->loginPin(['deviceId'=>$device,'pin'=>'0000']));
    same(1,$db->one('SELECT lock_count FROM device_pins WHERE device_id=?',[$device])['lock_count']);
    $db->execute('UPDATE device_pins SET locked_until=now()-interval \'1 second\' WHERE device_id=?',[$device]);
    $t=$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395']);
    truth(str_starts_with($t['accessToken'],'app_access_'));
    same(hash('sha256',$t['accessToken']),$db->one('SELECT encode(token_hash,\'hex\') AS h FROM access_sessions WHERE user_id=?',[$u])['h']);
    same($u,$sessions->authenticate($t['accessToken'])['id']);
    $next=$sessions->refresh($t['refreshToken']);
    errorCode('INVALID_REFRESH_TOKEN',fn()=>$sessions->refresh($t['refreshToken']));
    $sessions->logout($u,$next['refreshToken']);
    errorCode('UNAUTHORIZED',fn()=>$sessions->authenticate($next['accessToken']));
});
test('cart uses canonical OUT_OF_STOCK code and message for excess quantity',function()use($commerce){
    [$u,,$p]=fixture();
    try{$commerce->addCart($u,['productId'=>$p,'quantity'=>11]);throw new RuntimeException('expected failure');}
    catch(\Shop\domain\ApiError $e){same('OUT_OF_STOCK',$e->errorCode);same('requested quantity exceeds stock',$e->getMessage());}
});
test('biometric Ed25519 assertions are one-use and failed signatures persist',function()use($db,$credentials){
    [$u]=fixture();$kp=sodium_crypto_sign_keypair();$public=sodium_crypto_sign_publickey($kp);$private=sodium_crypto_sign_secretkey($kp);
    $enrolled=$credentials->enroll($u,['deviceId'=>Ids::uuid(),'deviceName'=>'Ed phone','publicKey'=>base64_encode(hex2bin('302a300506032b6570032100').$public),'keyAlgorithm'=>'EdDSA']);
    $id=$enrolled['credentialId'];$c=$credentials->challenge(['credentialId'=>$id]);same(120,$c['expiresIn']);
    $assertion=['credentialId'=>$id,'challenge'=>$c['challenge'],'signature'=>base64_encode(sodium_crypto_sign_detached($c['challenge'],$private))];
    same($u,$credentials->loginBiometric($assertion)['userId']);
    errorCode('CHALLENGE_EXPIRED',fn()=>$credentials->loginBiometric($assertion));
    $c=$credentials->challenge(['credentialId'=>$id]);
    errorCode('INVALID_ASSERTION',fn()=>$credentials->loginBiometric(['credentialId'=>$id,'challenge'=>$c['challenge'],'signature'=>base64_encode(str_repeat('x',64))]));
    same(1,$db->one('SELECT failed_attempts FROM device_biometrics WHERE id=?',[$id])['failed_attempts']);
});
test('biometric P-256 accepts DER signature and rejects expired challenges',function()use($db,$credentials){
    [$u]=fixture();$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
    $id=$credentials->enroll($u,['deviceId'=>Ids::uuid(),'deviceName'=>'P256 phone','publicKey'=>openssl_pkey_get_details($key)['key'],'keyAlgorithm'=>'ES256'])['credentialId'];
    $c=$credentials->challenge(['credentialId'=>$id]);openssl_sign($c['challenge'],$signature,$key,OPENSSL_ALGO_SHA256);
    same($u,$credentials->loginBiometric(['credentialId'=>$id,'challenge'=>$c['challenge'],'signature'=>base64_encode($signature)])['userId']);
    $c=$credentials->challenge(['credentialId'=>$id]);$db->execute('UPDATE biometric_challenges SET expires_at=now()-interval \'1 second\' WHERE challenge=?',[$c['challenge']]);
    errorCode('CHALLENGE_EXPIRED',fn()=>$credentials->loginBiometric(['credentialId'=>$id,'challenge'=>$c['challenge'],'signature'=>base64_encode($signature)]));
});
test('payment confirmation webhook dedup refund and stock restoration are transactional',function()use($db,$repo,$commerce){
    [$u,$a,$p]=fixture();$payments=new \Shop\application\PaymentService($db,$repo,$commerce);
    $commerce->addCart($u,['productId'=>$p,'quantity'=>2]);$o=$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD']);
    same('AUTHORIZED',$payments->confirm($u,$o['paymentId'])['status']);
    $id=Ids::uuid();$payments->webhook(['paymentId'=>$o['paymentId'],'status'=>'CAPTURED','transactionId'=>'test-txn-'.$o['paymentId']],$id);
    same('PAID',$repo->order($o['id'],$u)['status']);
    errorCode('ALREADY_PROCESSED',fn()=>$payments->webhook(['paymentId'=>$o['paymentId'],'status'=>'CAPTURED'],$id));
    same('REFUNDED',$payments->refund($o['paymentId'])['status']);
    same(10,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
    errorCode('INVALID_PAYMENT_STATUS',fn()=>$payments->refund($o['paymentId']));
    same(10,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
    $retry=Ids::uuid();errorCode('INVALID_PAYMENT_STATUS',fn()=>$payments->webhook(['paymentId'=>$o['paymentId'],'status'=>'FAILED'],$retry));
    same(0,$db->one('SELECT count(*) AS n FROM processed_webhooks WHERE id=?',[$retry])['n']);
});
test('ownership profile null semantics catalog descendants and completed-purchase reviews',function()use($db,$repo,$commerce){
    [$u,$a,$p,$c]=fixture();[$other]=fixture();
    $profile=new \Shop\application\ProfileService($db,$repo);$catalog=new \Shop\application\CatalogService($db,$repo);
    errorCode('ADDRESS_NOT_FOUND',fn()=>$profile->updateAddress($other,$a,['city'=>'X','street'=>'Y','house'=>'1']));
    $profile->update($u,['fullName'=>'Buyer','phone'=>'+79991234567','defaultAddressId'=>$a]);
    same($a,$profile->update($u,['fullName'=>'Buyer','phone'=>'+79991234567'])['defaultAddressId']);
    same(null,$profile->update($u,['fullName'=>'Buyer','phone'=>'+79991234567','defaultAddressId'=>null])['defaultAddressId']);
    errorCode('INVALID_DEFAULT_ADDRESS',fn()=>$profile->update($other,['fullName'=>'Buyer','phone'=>'+79991234567','defaultAddressId'=>$a]));
    $child=Ids::uuid();$db->execute('INSERT INTO categories(id,name,parent_id) VALUES(?,\'Child\',?)',[$child,$c]);$db->execute('UPDATE products SET category_id=? WHERE id=?',[$child,$p]);
    same($p,$catalog->products(['categoryId'=>$c])['items'][0]['id']);
    errorCode('PURCHASE_REQUIRED',fn()=>$catalog->createReview($u,$p,['rating'=>5,'text'=>'Great']));
    $commerce->addCart($u,['productId'=>$p,'quantity'=>1]);$o=$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'ON_DELIVERY']);
    $payments=new \Shop\application\PaymentService($db,$repo,$commerce);$payments->confirm($u,$o['paymentId']);
    foreach(['ASSEMBLING','DELIVERING','COMPLETED'] as $status){$commerce->transition($o['id'],['status'=>$status]);}
    $review=$catalog->createReview($u,$p,['rating'=>5,'text'=>' Great ']);same(5.0,$catalog->product($p)['rating']);
    errorCode('REVIEW_EXISTS',fn()=>$catalog->createReview($u,$p,['rating'=>4,'text'=>'Again']));
    errorCode('FORBIDDEN',fn()=>$catalog->deleteReview($repo->user($other),$review['id']));
    $catalog->deleteReview($repo->user($u),$review['id']);same(null,$catalog->product($p)['rating']);
});
test('maintenance expires reservations and cleans only expired application state',function()use($db,$repo,$commerce){
    [$u,$a,$p]=fixture();$commerce->addCart($u,['productId'=>$p,'quantity'=>2]);$o=$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],'maintenance-key');
    $db->execute('UPDATE orders SET created_at=now()-interval \'1 day\' WHERE id=?',[$o['id']]);
    $maintenance=new \Shop\application\MaintenanceService($db,$repo,$commerce);$maintenance->run();
    same('CANCELLED',$repo->order($o['id'])['status']);same(10,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
    $maintenance->run();same(10,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
    same(1,$db->one('SELECT count(*) AS n FROM idempotency_keys WHERE user_id=?',[$u])['n']);
    $db->execute('UPDATE idempotency_keys SET created_at=now()-interval \'2 days\' WHERE user_id=?',[$u]);$maintenance->run();
    same(0,$db->one('SELECT count(*) AS n FROM idempotency_keys WHERE user_id=?',[$u])['n']);
});
test('third PIN and biometric lockouts permanently revoke credentials',function()use($db,$credentials){
    [$u]=fixture();$device=Ids::uuid();$credentials->setPin($u,['deviceId'=>$device,'deviceName'=>'Lockout Phone','pin'=>'7395']);
    for($lock=0;$lock<3;$lock++){
        for($i=0;$i<4;$i++){errorCode('INVALID_PIN',fn()=>$credentials->loginPin(['deviceId'=>$device,'pin'=>'0000']));}
        errorCode($lock===2?'PIN_NOT_SET':'PIN_LOCKED',fn()=>$credentials->loginPin(['deviceId'=>$device,'pin'=>'0000']));
        $db->execute('UPDATE device_pins SET locked_until=now()-interval \'1 second\' WHERE device_id=?',[$device]);
    }
    same(0,$db->one('SELECT count(*) AS n FROM device_pins WHERE device_id=?',[$device])['n']);
    $kp=sodium_crypto_sign_keypair();$id=$credentials->enroll($u,['deviceId'=>$device,'deviceName'=>'Lockout Bio','publicKey'=>base64_encode(hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($kp)),'keyAlgorithm'=>'EdDSA'])['credentialId'];
    for($lock=0;$lock<3;$lock++){
        for($i=0;$i<5;$i++){$c=$credentials->challenge(['credentialId'=>$id]);$code=$i<4?'INVALID_ASSERTION':($lock===2?'BIOMETRIC_REVOKED':'BIOMETRIC_LOCKED');errorCode($code,fn()=>$credentials->loginBiometric(['credentialId'=>$id,'challenge'=>$c['challenge'],'signature'=>base64_encode(str_repeat('x',64))]));}
        $db->execute('UPDATE device_biometrics SET locked_until=now()-interval \'1 second\' WHERE id=?',[$id]);
    }
    same(0,$db->one('SELECT count(*) AS n FROM device_biometrics WHERE id=?',[$id])['n']);
});
test('mid-checkout SQL failure rolls back inserted order items payment stock cart and replay',function()use($db,$commerce){
    [$u,$a,$p]=fixture();$commerce->addCart($u,['productId'=>$p,'quantity'=>2]);
    $suffix=str_replace('-','',Ids::uuid());$function='test_fail_payment_'.$suffix;$trigger='test_fail_'.$suffix;
    $db->execute('CREATE FUNCTION '.$function.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.user_id=\''.$u.'\'::uuid THEN RAISE EXCEPTION \'injected test-only payment failure\'; END IF; RETURN NEW; END $$');
    $db->execute('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION '.$function.'()');
    try{
        try{$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],'mid-failure');throw new RuntimeException('expected PDO failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected test-only'));}
        same(0,$db->one('SELECT count(*) AS n FROM orders WHERE user_id=?',[$u])['n']);same(0,$db->one('SELECT count(*) AS n FROM order_items WHERE product_id=?',[$p])['n']);
        same(0,$db->one('SELECT count(*) AS n FROM payments WHERE user_id=?',[$u])['n']);same(10,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
        same(2,$db->one('SELECT quantity FROM cart_items WHERE user_id=?',[$u])['quantity']);same(0,$db->one('SELECT count(*) AS n FROM idempotency_keys WHERE user_id=?',[$u])['n']);
    }finally{$db->execute('DROP TRIGGER '.$trigger.' ON payments');$db->execute('DROP FUNCTION '.$function.'()');}
});
runTests();
