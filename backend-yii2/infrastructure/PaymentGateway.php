<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\domain\{ApiError,Money};

final class PaymentGateway
{
    public function call(string $operation,array $payment): ?array
    {
        if(!in_array($operation,['authorize','void','refund'],true)){throw new \InvalidArgumentException('Invalid payment operation');}
        $base=rtrim(Env::get('PAYMENT_GATEWAY_URL'),'/');
        if($base===''){
            if(Env::production()){throw new ApiError('PAYMENT_GATEWAY_UNAVAILABLE','payment gateway is unavailable');}
            return $operation==='authorize'?['status'=>'AUTHORIZED','paymentUrl'=>'/demo/payments/'.$payment['id'],'transactionId'=>null]:null;
        }
        $path=$operation==='authorize'?'/v1/payments/authorize':'/v1/payments/'.rawurlencode($payment['id']).'/'.$operation;
        try{
            $key=Env::get('PAYMENT_GATEWAY_API_KEY');if($key===''){throw new \RuntimeException('Missing gateway API key');}
            $body=['paymentId'=>$payment['id'],'orderId'=>$payment['order_id'],'method'=>$payment['method'],'amountCents'=>Money::parse($payment['amount'])];
            $r=(new HttpClient())->request('POST',$base.$path,['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json','Idempotency-Key'=>$payment['operation_key']??$payment['id'].':'.$operation],json_encode($body,JSON_THROW_ON_ERROR));
            // This adapter's only synchronous reversal contract is an empty 204.
            // Accepted/pending, arbitrary 2xx and errors say nothing about execution.
            if($operation!=='authorize'){
                if($r['status']!==204 || $r['body']!==''){throw new \RuntimeException('Unconfirmed reversal');}
                return null;
            }
            if(!in_array($r['status'],[200,201,202],true)){throw new \RuntimeException('Unconfirmed authorization');}
            $v=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($v) || !is_string($v['status']??null) || !in_array($v['status'],['AUTHORIZED','PENDING'],true)
                || ($r['status']===202 && $v['status']!=='PENDING') || !is_string($v['paymentUrl']??null)
                || !is_string($v['transactionId']??null) || trim($v['transactionId'])==='' || mb_strlen($v['transactionId'])>255){throw new \RuntimeException('Invalid authorization');}
            foreach(['paymentId'=>'id','orderId'=>'order_id'] as $field=>$source){if(array_key_exists($field,$v) && (!is_string($v[$field]) || $v[$field]!==$payment[$source])){throw new \RuntimeException('Foreign authorization');}}
            $url=parse_url($v['paymentUrl']);
            if(!$url || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
                || !in_array($url['scheme']??'',Env::production()?['https']:['http','https'],true)){throw new \RuntimeException('Unsafe payment redirect');}
            return ['status'=>$v['status'],'paymentUrl'=>$v['paymentUrl'],'transactionId'=>$v['transactionId']];
        }catch(\Throwable $e){error_log('gateway '.$operation.' failed: '.$e->getMessage());throw new ApiError('PAYMENT_GATEWAY_UNAVAILABLE','payment gateway is unavailable');}
    }
}
