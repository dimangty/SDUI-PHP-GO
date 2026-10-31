<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/payment-fixture.php';
use Shop\application\SessionService;
use Shop\infrastructure\{Env,HttpClient};
use Shop\repositories\ShopRepository;
use Shop\domain\Ids;
paymentTestSchema();
function paymentToken(string $user,bool $admin=false): string {
    $db=paymentDb();$db->execute('UPDATE users SET local_identity_ready=true WHERE id=?',[$user]);if($admin){$db->execute('UPDATE users SET is_admin=true WHERE id=?',[$user]);}
    return (new SessionService($db,new ShopRepository($db)))->issue($user)['accessToken'];
}
function paymentHttp(string $method,string $path,?string $token=null): array {
    $r=(new HttpClient())->request($method,Env::get('PAYMENT_TEST_HTTP_ORIGIN').'/api/v1'.$path,$token===null?[]:['Authorization'=>'Bearer '.$token]);
    return [$r['status'],json_decode($r['body'],true)];
}
test('PHP HTTP payment read confirm and cancel enforce ownership before any provider call',function(){
    [$u,$o,$product]=paymentFixture();[$other]=paymentFixture();$owner=paymentToken($u);$foreign=paymentToken($other);$id=$o['paymentId'];
    same(401,paymentHttp('POST','/payments/'.$id.'/confirm')[0]);same(404,paymentHttp('GET','/payments/'.$id,$foreign)[0]);same(404,paymentHttp('POST','/payments/'.$id.'/confirm',$foreign)[0]);same(404,paymentHttp('POST','/orders/'.$o['id'].'/cancel',$foreign)[0]);same([],journal($id));
    [$code,$body]=paymentHttp('GET','/payments/'.$id,$owner);same(200,$code);same('20.46',$body['amount']);same('PENDING',$body['status']);
    [$code,$body]=paymentHttp('POST','/payments/'.$id.'/confirm',$owner);same(200,$code);same('AUTHORIZED',$body['status']);same(0,journal($id)[0]['in_transaction']);same('SENT',journal($id)[0]['intent_state']);
    same(200,paymentHttp('POST','/orders/'.$o['id'].'/cancel',$owner)[0]);same(['authorize','void'],array_column(journal($id),'operation'));same(10,stock($product));
});
test('PHP HTTP refund and void require admin and signed capture stays correlated',function(){
    [$u,$o,$product]=paymentFixture('SBP','PENDING');[$admin]=paymentFixture();$owner=paymentToken($u);$adminToken=paymentToken($admin,true);$id=$o['paymentId'];
    same(403,paymentHttp('POST','/payments/'.$id.'/refund',$owner)[0]);same(403,paymentHttp('POST','/payments/'.$id.'/void',$owner)[0]);same([],journal($id));
    same(200,paymentHttp('POST','/payments/'.$id.'/confirm',$owner)[0]);$wid=Ids::uuid();same(204,signedPaymentHttp($o,'CAPTURED',$wid)[0]);same(409,signedPaymentHttp($o,'CAPTURED',$wid)[0]);
    [$code,$body]=paymentHttp('POST','/payments/'.$id.'/refund',$adminToken);same(200,$code);same('REFUNDED',$body['status']);same('20.46',$body['amount']);same(10,stock($product));same(['authorize','refund'],array_column(journal($id),'operation'));
});
test('PHP HTTP uncertain cancellation commits stock once and prevents renewed confirmation',function(){
    [$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['reverse_code'=>202]);$owner=paymentToken($u);$id=$o['paymentId'];same(200,paymentHttp('POST','/payments/'.$id.'/confirm',$owner)[0]);
    [$code,$body]=paymentHttp('POST','/orders/'.$o['id'].'/cancel',$owner);same(400,$code);same('PAYMENT_GATEWAY_UNAVAILABLE',$body['error']['code']);same(10,stock($product));
    [$code,$body]=paymentHttp('GET','/orders/'.$o['id'],$owner);same(200,$code);same('CANCELLED',$body['status']);same(409,paymentHttp('POST','/payments/'.$id.'/confirm',$owner)[0]);same(409,paymentHttp('POST','/orders/'.$o['id'].'/cancel',$owner)[0]);recoverPayments();same(1,journal($id)[1]['attempts']);same('AUTHORIZED',paymentServices()[1]->payment($id)['status']);
});
test('PHP HTTP ON_DELIVERY confirmation and cancellation never create a remote transaction',function(){
    [$u,$o,$product]=paymentFixture('ON_DELIVERY');$owner=paymentToken($u);$id=$o['paymentId'];[$code,$body]=paymentHttp('POST','/payments/'.$id.'/confirm',$owner);same(200,$code);same('AUTHORIZED',$body['status']);same(null,$body['paymentUrl']);
    same(200,paymentHttp('POST','/orders/'.$o['id'].'/cancel',$owner)[0]);same([],journal($id));same(10,stock($product));[$code,$body]=paymentHttp('GET','/payments/'.$id,$owner);same(200,$code);same('CANCELLED',$body['status']);same(null,$body['transactionId']);
});
runTests();
