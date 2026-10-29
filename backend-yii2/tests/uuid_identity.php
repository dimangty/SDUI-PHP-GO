<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/payment-fixture.php';
use Shop\application\{CredentialService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\ApiKernel;
paymentTestSchema();
function uuidCase(string $id,string $case): string {
    return match($case){'upper'=>strtoupper($id),'mixed'=>strtoupper(substr($id,0,18)).substr($id,18),default=>$id};
}
foreach(['lower','upper','mixed'] as $case){
    test("UUID $case confirm is repeatable and reversals keep one external effect",function()use($case){
        foreach(['refund','void'] as $kind){
            [$user,$order]=paymentFixture();[, $repo,,$payments]=paymentServices();$id=uuidCase($order['paymentId'],$case);
            same('AUTHORIZED',$payments->confirm(uuidCase($user,$case),$id)['status']);
            same('AUTHORIZED',$payments->confirm($user,$id)['status']);
            if($kind==='refund'){callback($order,'CAPTURED');}
            same($kind==='refund'?'REFUNDED':'CANCELLED',$payments->$kind($id)['status']);
            same(['authorize',$kind],array_column(journal($id),'operation'));
            foreach(journal($id) as $entry){same(1,$entry['attempts']);same(0,$entry['in_transaction']);}
        }
    });
    test("UUID $case foreign payment and order stay inaccessible",function()use($case){
        [$user,$order]=paymentFixture();[$foreign,$other]=paymentFixture();[$db,$repo,,$payments]=paymentServices();
        errorCode('PAYMENT_NOT_FOUND',fn()=>$payments->confirm($foreign,uuidCase($order['paymentId'],$case)));
        errorCode('ORDER_NOT_FOUND',fn()=>$payments->cancel($foreign,uuidCase($order['id'],$case)));
        same([],journal($order['paymentId']));
        $db->execute('UPDATE orders SET payment_id=? WHERE id=?',[$other['paymentId'],$order['id']]);
        try{errorCode('INVALID_PAYMENT_STATUS',fn()=>$payments->confirm($user,uuidCase($order['paymentId'],$case)));}
        finally{$db->execute('UPDATE orders SET payment_id=? WHERE id=?',[$order['paymentId'],$order['id']]);}
    });
    test("UUID $case signed HTTP webhook keeps provider correlation and dedup",function()use($case){
        [$user,$order]=paymentFixture();[, $repo,,$payments]=paymentServices();$payments->confirm($user,$order['paymentId']);
        $wire=$order;$wire['paymentId']=uuidCase($order['paymentId'],$case);$transaction='txn-'.$order['paymentId'];
        same(401,signedPaymentHttp($wire,'CAPTURED',Ids::uuid(),$transaction,false)[0]);
        same(401,signedPaymentHttp($wire,'CAPTURED',Ids::uuid(),$transaction,true,time()-1000)[0]);
        same(400,signedPaymentHttp($wire,'CAPTURED',Ids::uuid(),'unknown')[0]);
        [$other,$foreign]=paymentFixture();$payments->confirm($other,$foreign['paymentId']);
        same(400,signedPaymentHttp($wire,'CAPTURED',Ids::uuid(),'txn-'.$foreign['paymentId'])[0]);
        $wid=Ids::uuid();same(204,signedPaymentHttp($wire,'CAPTURED',$wid,$transaction)[0]);
        same(409,signedPaymentHttp($wire,'CAPTURED',$wid,$transaction)[0]);same('CAPTURED',$repo->payment($order['paymentId'])['status']);
    });
    foreach(['EdDSA','ES256'] as $algorithm){
        test("UUID $case $algorithm biometric identity retains nonce signature and ownership checks",function()use($case,$algorithm){
            [$user]=paymentFixture('ON_DELIVERY');[$db,$repo]=paymentServices();$service=new CredentialService($db,$repo,new SessionService($db,$repo));
            if($algorithm==='EdDSA'){
                $pair=sodium_crypto_sign_keypair();$public=base64_encode(hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey($pair));
                $sign=fn($nonce)=>base64_encode(sodium_crypto_sign_detached($nonce,sodium_crypto_sign_secretkey($pair)));
            }else{
                $pair=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$public=openssl_pkey_get_details($pair)['key'];
                $sign=function($nonce)use($pair){openssl_sign($nonce,$signature,$pair,OPENSSL_ALGO_SHA256);return base64_encode($signature);};
            }
            $enroll=fn()=> $service->enroll($user,['deviceId'=>'OpaqueDevice-'.Ids::uuid(),'deviceName'=>'Native UUID','publicKey'=>$public,'keyAlgorithm'=>$algorithm])['credentialId'];
            $stored=$enroll();$id=uuidCase($stored,$case);$foreign=$enroll();
            $nonce=$service->challenge(['credentialId'=>$id])['challenge'];$input=['credentialId'=>$id,'challenge'=>$nonce,'signature'=>$sign($nonce)];
            errorCode('CHALLENGE_EXPIRED',fn()=>$service->loginBiometric(array_replace($input,['credentialId'=>uuidCase($foreign,$case)])));
            same($user,$service->loginBiometric($input)['userId']);errorCode('CHALLENGE_EXPIRED',fn()=>$service->loginBiometric($input));
            $nonce=$service->challenge(['credentialId'=>$id])['challenge'];
            $db->execute("UPDATE biometric_challenges SET expires_at=now()-interval '1 second' WHERE challenge=?",[$nonce]);
            errorCode('CHALLENGE_EXPIRED',fn()=>$service->loginBiometric(['credentialId'=>$id,'challenge'=>$nonce,'signature'=>$sign($nonce)]));
            for($i=0;$i<5;$i++){
                $nonce=$service->challenge(['credentialId'=>$id])['challenge'];
                errorCode($i===4?'BIOMETRIC_LOCKED':'INVALID_ASSERTION',fn()=>$service->loginBiometric(['credentialId'=>$id,'challenge'=>$nonce,'signature'=>$sign('wrong-'.$nonce)]));
            }
            errorCode('BIOMETRIC_LOCKED',fn()=>$service->challenge(['credentialId'=>$id]));
        });
    }
}
test('UUID invalid and missing HTTP identifiers retain existing errors',function(){
    [$user]=paymentFixture('ON_DELIVERY');[$db,$repo]=paymentServices();$kernel=new ApiKernel($db);
    $r=$kernel->handle('POST','/api/v1/payments/not-a-uuid/confirm',[],'','192.0.2.231');
    // Authentication still precedes protected route UUID parsing.
    same(401,$r['status']);
    $token=(new SessionService($db,$repo))->issue($user)['accessToken'];$headers=['authorization'=>'Bearer '.$token];
    $r=$kernel->handle('POST','/api/v1/payments/not-a-uuid/confirm',$headers,'','192.0.2.231');
    same(400,$r['status']);same('BAD_REQUEST',$r['data']['error']['code']);
    errorCode('PAYMENT_NOT_FOUND',fn()=>paymentServices()[3]->confirm(Ids::uuid(),Ids::uuid()));
    $repo=paymentServices()[1];$c=new CredentialService($db,$repo,new SessionService($db,$repo));
    errorCode('INVALID_CREDENTIAL_ID',fn()=>$c->challenge(['credentialId'=>'bad']));
    errorCode('BIOMETRIC_NOT_SET',fn()=>$c->challenge(['credentialId'=>Ids::uuid()]));
});
runTests();
