<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env,PaymentGateway};
use Shop\repositories\ShopRepository;

final class PaymentService
{
    public function __construct(private Database $db,private ShopRepository $repo,private CommerceService $commerce) {}
    public function payment(string $user,string $id): array {return $this->repo->paymentDto($this->repo->payment($id,$user));}
    private function lock(string $id,?string $user=null): array
    {
        $p=$this->repo->payment($id,$user);$this->commerce->lockOrderPayment($p['order_id']);return $this->repo->payment($id,$user);
    }
    public function confirm(string $user,string $id): array
    {
        return $this->db->transaction(function()use($user,$id){
            $p=$this->lock($id,$user);$url=$p['gateway_url'];$status=$p['status'];
            if($status==='PENDING'){
                if($p['method']==='ON_DELIVERY'){$status='AUTHORIZED';$url=null;$transaction=null;}
                else{$r=(new PaymentGateway())->call('authorize',$p);$status=$r['status'];$url=$r['paymentUrl'];$transaction=$r['transactionId']??null;}
                $this->db->execute('UPDATE payments SET status=?,gateway_url=?,transaction_id=?,updated_at=now() WHERE id=?',[$status,$url,$transaction,$id]);
            }elseif($status==='CAPTURED'){$url=null;}
            elseif($status!=='AUTHORIZED'){throw new ApiError('INVALID_PAYMENT_STATUS','payment cannot be confirmed in current status');}
            if($url!==null && str_starts_with($url,'/demo/payments/')){$url.='?token='.$this->demoToken($id);}
            return ['paymentId'=>$id,'status'=>$status,'paymentUrl'=>$url];
        });
    }
    public function webhook(array $input,string $webhook): void
    {
        $status=$input['status']??'';$transaction=$input['transactionId']??null;
        if($webhook==='' || strlen($webhook)>255){throw new ApiError('INVALID_WEBHOOK_ID','webhook id must contain 1 to 255 characters');}
        if($transaction!==null && mb_strlen($transaction)>255){throw new ApiError('INVALID_TRANSACTION_ID','transactionId exceeds 255 characters');}
        if(!in_array($status,['CAPTURED','FAILED'],true)){throw new ApiError('INVALID_WEBHOOK_STATUS','invalid webhook status');}
        $this->db->transaction(function()use($input,$status,$transaction,$webhook){
            if($this->db->execute('INSERT INTO processed_webhooks(id) VALUES(?) ON CONFLICT(id) DO NOTHING',[$webhook])===0){throw new ApiError('ALREADY_PROCESSED','webhook already processed');}
            $p=$this->lock($input['paymentId']??'');$o=$this->repo->order($p['order_id']);
            if($p['status']!=='AUTHORIZED'){throw new ApiError('INVALID_PAYMENT_STATUS','payment is not authorized');}
            if($o['status']!=='CREATED'){throw new ApiError('INVALID_ORDER_STATUS','order is not awaiting payment');}
            $this->db->execute('UPDATE payments SET status=?,transaction_id=?,updated_at=now() WHERE id=?',[$status,$transaction,$p['id']]);
            $this->db->execute('UPDATE orders SET status=? WHERE id=?',[$status==='CAPTURED'?'PAID':'CANCELLED',$p['order_id']]);
            if($status==='FAILED'){$this->commerce->restoreStock($p['order_id']);}
        });
    }
    public function refund(string $id): array {return $this->reverse($id,'CAPTURED','REFUNDED','refund');}
    public function void(string $id): array {return $this->reverse($id,'AUTHORIZED','CANCELLED','void');}
    private function reverse(string $id,string $expected,string $next,string $operation): array
    {
        return $this->db->transaction(function()use($id,$expected,$next,$operation){
            $p=$this->lock($id);
            if($p['status']!==$expected){throw new ApiError('INVALID_PAYMENT_STATUS',$operation==='refund'?'payment cannot be refunded in current status':'only an authorized payment can be voided');}
            (new PaymentGateway())->call($operation,$p);
            $this->finishReversal($p,$next);return $this->repo->paymentDto($this->repo->payment($id));
        });
    }
    private function finishReversal(array $p,string $next): void
    {
        $o=$this->repo->order($p['order_id']);
        if(!in_array($o['status'],['CANCELLED','COMPLETED'],true)){
            $this->db->execute('UPDATE orders SET status=\'CANCELLED\' WHERE id=?',[$p['order_id']]);$this->commerce->restoreStock($p['order_id']);
        }
        $this->db->execute('UPDATE payments SET status=?,updated_at=now() WHERE id=?',[$next,$p['id']]);
    }
    public function cancel(string $user,string $order): array
    {
        return $this->db->transaction(function()use($user,$order){
            $this->repo->order($order,$user);$o=$this->commerce->lockOrderPayment($order);
            if(!in_array($o['status'],['CREATED','PAID'],true)){throw new ApiError('CANCEL_NOT_ALLOWED','order cannot be cancelled');}
            $p=$this->repo->payment($o['payment_id'],$user);
            if($p['status']==='CAPTURED'){(new PaymentGateway())->call('refund',$p);$next='REFUNDED';}
            else{$next=in_array($p['status'],['PENDING','AUTHORIZED'],true)?'CANCELLED':$p['status'];}
            $this->finishReversal($p,$next);return $this->repo->order($order,$user);
        });
    }
    public function demoToken(string $id): string {return hash_hmac('sha256','demo-payment:'.$id,Env::get('PAYMENT_WEBHOOK_SECRET'));}
}
