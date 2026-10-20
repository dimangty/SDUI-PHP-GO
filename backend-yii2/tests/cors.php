<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\infrastructure\{ApiKernel,Database};

// These boundary cases must not need SQL, identity or a permissive runtime config.
$kernel=new ApiKernel(new Database(''));
putenv('CORS_ALLOWED_ORIGINS= https://shop.example, , *, https://admin.shop.example, ');
putenv('APP_ENV=development');putenv('PAYMENT_GATEWAY_URL=');putenv('PAYMENT_WEBHOOK_SECRET=cors-regression-secret');
function corsHeaders(array $response): array {
    return array_filter($response['headers'],fn($key)=>str_starts_with($key,'access-control-'),ARRAY_FILTER_USE_KEY);
}
test('unknown actual origins reach the handler with Vary but no CORS permissions',function()use($kernel){
    foreach(['http://10.0.2.2:58095','https://unknown.example','null','https://shop.example.evil','https://shop.example:443','*'] as $origin){
        $r=$kernel->handle('GET','/health/live',['Origin'=>$origin],'','192.0.2.94');
        same(200,$r['status']);same('ok',$r['data']['status']);same('Origin',$r['headers']['vary']??null);same([],corsHeaders($r));
    }
});
test('unknown preflights alone are rejected without reflecting Origin',function()use($kernel){
    foreach(['http://10.0.2.2:58095','https://unknown.example','null','*'] as $origin){
        $r=$kernel->handle('OPTIONS','/api/v1/cart/items',['origin'=>$origin,'access-control-request-method'=>'POST'],'','192.0.2.94');
        same(403,$r['status']);same('CORS_ORIGIN_DENIED',$r['data']['error']['code']);same('Origin',$r['headers']['vary']??null);same([],corsHeaders($r));
    }
});
test('finite exact origins receive the canonical headers on actual requests and preflights',function()use($kernel){
    foreach(['https://shop.example','https://admin.shop.example'," \thttps://shop.example\t "] as $origin){
        foreach(['GET'=>200,'OPTIONS'=>204] as $method=>$status){
            $r=$kernel->handle($method,'/health/live',['origin'=>$origin,'x-request-id'=>'cors-regression-request'],'','192.0.2.94');
            same($status,$r['status']);same('Origin',$r['headers']['vary']??null);same('cors-regression-request',$r['headers']['x-request-id']);
            same([
                'access-control-allow-origin'=>trim($origin),
                'access-control-allow-headers'=>'Authorization, Content-Type, Idempotency-Key, X-Admin-Bootstrap-Token, X-Request-Id, X-Webhook-Timestamp, X-Webhook-Id, X-Webhook-Signature',
                'access-control-allow-methods'=>'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'access-control-expose-headers'=>'Retry-After, X-Request-Id',
                'access-control-max-age'=>'600',
            ],corsHeaders($r));
        }
    }
});
test('missing empty and whitespace origins remain ordinary requests without CORS headers',function()use($kernel){
    foreach([[],['origin'=>''],['origin'=>" \t "]] as $headers){
        foreach(['GET'=>200,'OPTIONS'=>204] as $method=>$status){
            $r=$kernel->handle($method,'/health/live',$headers,'','192.0.2.94');
            same($status,$r['status']);same(false,isset($r['headers']['vary']));same([],corsHeaders($r));
        }
    }
});
test('wildcard-only configuration grants no origins and ignores blank entries',function()use($kernel){
    $before=getenv('CORS_ALLOWED_ORIGINS');putenv('CORS_ALLOWED_ORIGINS= , *, , ');
    try{
        foreach(['https://unknown.example','*',''] as $origin){
            $r=$kernel->handle('GET','/health/live',['origin'=>$origin],'','192.0.2.94');same(200,$r['status']);same([],corsHeaders($r));
            $r=$kernel->handle('OPTIONS','/health/live',['origin'=>$origin],'','192.0.2.94');same($origin===''?204:403,$r['status']);same([],corsHeaders($r));
        }
    }finally{putenv('CORS_ALLOWED_ORIGINS='.$before);}
});
test('demo capability checks still reject absent forged and payment-mismatched tokens',function()use($kernel){
    $id='10000000-0000-4000-8000-000000000001';$other='10000000-0000-4000-8000-000000000002';
    foreach(['','forged',hash_hmac('sha256','demo-payment:'.$other,'cors-regression-secret')] as $token){
        foreach(['GET','POST'] as $method){
            $r=$kernel->handle($method,'/demo/payments/'.$id.'?token='.$token,['origin'=>'http://10.0.2.2:58095'],'','192.0.2.94');
            same(403,$r['status']);same('DEMO_PAYMENT_FORBIDDEN',$r['data']['error']['code']);same('Origin',$r['headers']['vary']??null);same([],corsHeaders($r));
        }
    }
});
test('production has no demo route even with a valid capability and an unknown Origin',function()use($kernel){
    $id='10000000-0000-4000-8000-000000000001';$token=hash_hmac('sha256','demo-payment:'.$id,'cors-regression-secret');putenv('APP_ENV=production');
    try{
        foreach(['GET','POST'] as $method){
            $r=$kernel->handle($method,'/demo/payments/'.$id.'?token='.$token,['origin'=>'http://10.0.2.2:58095'],'','192.0.2.94');
            same(404,$r['status']);same('NOT_FOUND',$r['data']['error']['code']);same([],corsHeaders($r));
        }
    }finally{putenv('APP_ENV=development');}
});
runTests();
