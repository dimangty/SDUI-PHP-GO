<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\domain\{ApiError,Money};

final class PaymentGateway
{
    public function call(string $operation,array $payment): ?array
    {
        $base=rtrim(Env::get('PAYMENT_GATEWAY_URL'),'/');
        if($base===''){
            if(Env::production()){throw new ApiError('PAYMENT_GATEWAY_UNAVAILABLE','payment gateway is unavailable');}
            return $operation==='authorize'?['status'=>'AUTHORIZED','paymentUrl'=>'/demo/payments/'.$payment['id'],'transactionId'=>null]:null;
        }
        $path=$operation==='authorize'?'/v1/payments/authorize':'/v1/payments/'.rawurlencode($payment['id']).'/'.$operation;
        try{
            $key=Env::get('PAYMENT_GATEWAY_API_KEY');if($key===''){throw new \RuntimeException('Missing gateway API key');}
            $body=['paymentId'=>$payment['id'],'orderId'=>$payment['order_id'],'method'=>$payment['method'],'amountCents'=>Money::parse($payment['amount'])];
            $r=(new HttpClient())->request('POST',$base.$path,['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json','Idempotency-Key'=>$payment['id'].':'.$operation],json_encode($body,JSON_THROW_ON_ERROR));
            if($r['status']<200 || $r['status']>=300){throw new \RuntimeException('Gateway rejected request');}
            if($operation!=='authorize'){return null;}
            $v=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($v) || !in_array($v['status']??'', ['AUTHORIZED','PENDING'],true) || !is_string($v['paymentUrl']??null)){throw new \RuntimeException('Invalid authorization');}
            $url=parse_url($v['paymentUrl']);if(!$url || !in_array($url['scheme']??'', ['https'],true) && !( !Env::production() && ($url['scheme']??'')==='http')){throw new \RuntimeException('Unsafe payment redirect');}
            return $v;
        }catch(\Throwable $e){error_log('gateway '.$operation.' failed: '.$e->getMessage());throw new ApiError('PAYMENT_GATEWAY_UNAVAILABLE','payment gateway is unavailable');}
    }
}
