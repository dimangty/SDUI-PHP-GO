<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/DemoPaymentIdentityFixture.php';

$fixture=new DemoPaymentIdentityFixture();

foreach(['lower','upper','mixed'] as $case){
    test("demo $case native confirm returns the stored UUID and one signed identity",function()use($fixture,$case){
        $payment=$fixture->payment();$id=$payment['payment'];$caller=DemoPaymentIdentityFixture::casing($id,$case);
        $confirmed=$fixture->payments->confirm($payment['user'],$caller);
        same([
            'paymentId'=>$id,'status'=>'AUTHORIZED',
            'paymentUrl'=>'/demo/payments/'.$id.'?token='.hash_hmac('sha256','demo-payment:'.$id,DemoPaymentIdentityFixture::SECRET),
        ],$confirmed);
        foreach(['lower','upper','mixed'] as $repeat){
            same($confirmed,$fixture->payments->confirm($payment['user'],DemoPaymentIdentityFixture::casing($id,$repeat)));
        }
        $operations=$fixture->operations($id);same(1,count($operations));same('authorize',$operations[0]['kind']);
        same($id.':authorize',$operations[0]['operation_key']);same('SUCCEEDED',$operations[0]['state']);
    });

    test("demo $case HTTP confirm link GET and explicit POST capture the same payment",function()use($fixture,$case){
        $server=new DemoPaymentIdentityHttp();
        try{
            $payment=$fixture->payment();$id=$payment['payment'];$caller=DemoPaymentIdentityFixture::casing($id,$case);
            $confirmed=$server->confirm($payment,$caller);$url=$confirmed['paymentUrl'];
            // Follow the actual returned link before DTO assertions: RED must expose the original 403.
            $before=$fixture->state($payment);$operations=$fixture->operations($id);
            $page=$server->request('GET',$url);same(200,$page['status']);
            truth(str_starts_with($page['headers']['content-type'],'text/html'));
            truth(str_contains($page['body'],'<form method="post">'));truth(str_contains($page['body'],$id));
            same($before,$fixture->state($payment));same($operations,$fixture->operations($id));
            $keys=array_keys($confirmed);sort($keys);same(['paymentId','paymentUrl','status'],$keys);
            same($id,$confirmed['paymentId']);same('AUTHORIZED',$confirmed['status']);
            same('/demo/payments/'.$id,parse_url($url,PHP_URL_PATH));
            parse_str(parse_url($url,PHP_URL_QUERY),$query);
            same(['token'=>hash_hmac('sha256','demo-payment:'.$id,DemoPaymentIdentityFixture::SECRET)],$query);
            foreach(['lower','upper','mixed'] as $repeat){same($confirmed,$server->confirm($payment,DemoPaymentIdentityFixture::casing($id,$repeat)));}
            same(1,count($operations));same('SUCCEEDED',$operations[0]['state']);same('authorize',$operations[0]['kind']);
            truth($operations[0]['sent_at']!==null);same($operations,$fixture->operations($id));

            // A UUID-only path variant shares the signature; token bytes are untouched.
            $variant='/demo/payments/'.$caller.'?token='.$query['token'];
            same(200,$server->request('GET',$variant)['status']);same($before,$fixture->state($payment));
            // Isolated backend test only: the payment-completing POST is deliberate and explicit.
            same(200,$server->request('POST',$variant)['status']);
            $after=$fixture->state($payment);same('CAPTURED',$after['status']);same('PAID',$after['order_status']);same(null,$after['gateway_url']);
            truth(str_starts_with($after['transaction_id'],'demo-'));same($operations,$fixture->operations($id));
            same(['paymentId'=>$id,'status'=>'CAPTURED','paymentUrl'=>null],$server->confirm($payment,$caller));
            same($operations,$fixture->operations($id));
            $read=$server->request('GET','/api/v1/payments/'.$caller,$payment['token']);same(200,$read['status']);
            $dto=json_decode($read['body'],true,32,JSON_THROW_ON_ERROR);same($id,$dto['id']);same('20.46',$dto['amount']);same('CAPTURED',$dto['status']);
        }finally{$server->close();}
    });
}

test('demo HTTP rejects missing changed and case-normalized signatures and substituted UUIDs without mutation',function()use($fixture){
    $server=new DemoPaymentIdentityHttp();
    try{
        $payment=$fixture->payment();$other=$fixture->payment();$url=$server->confirm($payment,$payment['payment'])['paymentUrl'];
        $otherUrl=$server->confirm($other,$other['payment'])['paymentUrl'];
        parse_str(parse_url($url,PHP_URL_QUERY),$query);$signature=$query['token'];$path=parse_url($url,PHP_URL_PATH);
        $before=[$fixture->state($payment),$fixture->state($other),$fixture->operations($payment['payment']),$fixture->operations($other['payment'])];
        $changed=($signature[0]==='0'?'1':'0').substr($signature,1);
        foreach([$path,$path.'?token='.$changed,$path.'?token='.strtoupper($signature),$path.'?token='.substr($signature,1),
            str_replace($payment['payment'],$other['payment'],$url),'/demo/payments/not-a-uuid?token='.$signature] as $bad){
            foreach(['GET','POST'] as $method){
                $response=$server->request($method,$bad);same(403,$response['status']);
                same('DEMO_PAYMENT_FORBIDDEN',json_decode($response['body'],true,32,JSON_THROW_ON_ERROR)['error']['code']);
            }
        }
        same($before,[$fixture->state($payment),$fixture->state($other),$fixture->operations($payment['payment']),$fixture->operations($other['payment'])]);
        same(200,$server->request('GET',$otherUrl)['status']);
    }finally{$server->close();}
});

test('demo HTTP confirm keeps owner checks and leaves foreign payment uninitiated',function()use($fixture){
    $server=new DemoPaymentIdentityHttp();
    try{
        $payment=$fixture->payment();$foreign=$fixture->payment();
        foreach(['lower','upper','mixed'] as $case){
            $path='/api/v1/payments/'.DemoPaymentIdentityFixture::casing($payment['payment'],$case).'/confirm';
            same(401,$server->request('POST',$path)['status']);
            $response=$server->request('POST',$path,$foreign['token']);same(404,$response['status']);
            same('PAYMENT_NOT_FOUND',json_decode($response['body'],true,32,JSON_THROW_ON_ERROR)['error']['code']);
        }
        same([],$fixture->operations($payment['payment']));same('PENDING',$fixture->state($payment)['status']);
    }finally{$server->close();}
});

foreach([
    'production without provider'=>['APP_ENV'=>'production'],
    'production with provider'=>['APP_ENV'=>'production','PAYMENT_GATEWAY_URL'=>'https://provider.invalid'],
    'development with provider'=>['PAYMENT_GATEWAY_URL'=>'https://provider.invalid'],
    'missing signing secret'=>['PAYMENT_WEBHOOK_SECRET'=>''],
] as $mode=>$environment){
    test("demo HTTP is closed for $mode even with an otherwise valid signed link",function()use($fixture,$environment,$mode){
        $payment=$fixture->payment();$url=$fixture->payments->confirm($payment['user'],$payment['payment'])['paymentUrl'];
        $before=$fixture->state($payment);$operations=$fixture->operations($payment['payment']);
        $server=new DemoPaymentIdentityHttp($environment);
        try{
            foreach(['GET','POST'] as $method){
                $response=$server->request($method,$url);same($mode==='missing signing secret'?403:404,$response['status']);
                same($mode==='missing signing secret'?'DEMO_PAYMENT_FORBIDDEN':'NOT_FOUND',json_decode($response['body'],true,32,JSON_THROW_ON_ERROR)['error']['code']);
            }
            same($before,$fixture->state($payment));same($operations,$fixture->operations($payment['payment']));
        }finally{$server->close();}
    });
}

test('demo token canonicalizes only validated payment UUIDs',function()use($fixture){
    $id='ab12cd34-56ef-4a78-9b01-23456789abcd';
    $expected=hash_hmac('sha256','demo-payment:'.$id,DemoPaymentIdentityFixture::SECRET);
    foreach(['lower','upper','mixed'] as $case){same($expected,$fixture->payments->demoToken(DemoPaymentIdentityFixture::casing($id,$case)));}
    try{$fixture->payments->demoToken('Opaque-Provider-Token');}catch(InvalidArgumentException){return;}
    throw new RuntimeException('Opaque tokens must not enter the payment UUID canonicalization boundary');
});

test('on-delivery confirm retains null URL and creates no provider operation',function()use($fixture){
    $server=new DemoPaymentIdentityHttp();
    try{
        $payment=$fixture->payment('ON_DELIVERY');$id=$payment['payment'];
        foreach(['lower','upper','mixed'] as $case){
            same(['paymentId'=>$id,'status'=>'AUTHORIZED','paymentUrl'=>null],$server->confirm($payment,DemoPaymentIdentityFixture::casing($id,$case)));
        }
        same([],$fixture->operations($id));same(null,$fixture->state($payment)['transaction_id']);
    }finally{$server->close();}
});

test('repeat confirm preserves opaque provider URL and transaction bytes',function()use($fixture){
    $payment=$fixture->payment();$id=$payment['payment'];$fixture->payments->confirm($payment['user'],$id);
    $url='https://Provider.Example/Pay/AbCd?Token=Opaque-UPPER-lower&Sig=ABCdef0123';
    $transaction='AB12CD34-56EF-4A78-9B01-23456789ABCD';
    $fixture->db->execute('UPDATE payments SET gateway_url=?,transaction_id=? WHERE id=?',[$url,$transaction,$id]);
    $operations=$fixture->operations($id);
    foreach(['lower','upper','mixed'] as $case){
        same(['paymentId'=>$id,'status'=>'AUTHORIZED','paymentUrl'=>$url],$fixture->payments->confirm($payment['user'],DemoPaymentIdentityFixture::casing($id,$case)));
        same($transaction,$fixture->payments->payment($payment['user'],$id)['transactionId']);
    }
    same($operations,$fixture->operations($id));
});

runTests();
