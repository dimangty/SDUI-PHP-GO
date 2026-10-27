<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\ApiError;
use Shop\infrastructure\{Database,Env,PaymentGateway};
use Shop\repositories\ShopRepository;

/** Frozen authorize path from 01d84b3: only class name changed and unrelated
 * methods omitted. Reproduces the real transaction/HTTP rollback boundary. */
final class PaymentBaselineConfirm
{
    public function __construct(private Database $db,private ShopRepository $repo,private CommerceService $commerce) {}
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
    public function demoToken(string $id): string {return hash_hmac('sha256','demo-payment:'.$id,Env::get('PAYMENT_WEBHOOK_SECRET'));}
}
