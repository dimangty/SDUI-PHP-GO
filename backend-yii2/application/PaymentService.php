<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

final class PaymentService
{
    private PaymentOperations $operations;
    public function __construct(Database $db,private ShopRepository $repo,CommerceService $commerce)
    {$this->operations=new PaymentOperations($db,$repo,$commerce);}
    public function payment(string $user,string $id): array {return $this->repo->paymentDto($this->repo->payment($id,$user));}
    public function confirm(string $user,string $id): array
    {
        $this->operations->confirm($user,$id);$p=$this->repo->payment($id,$user);$url=$p['status']==='CAPTURED'?null:$p['gateway_url'];
        if($url!==null && str_starts_with($url,'/demo/payments/')){$url.='?token='.$this->demoToken($id);}
        return ['paymentId'=>$id,'status'=>$p['status'],'paymentUrl'=>$url];
    }
    public function webhook(array $input,string $webhook): void {$this->operations->webhook($input,$webhook);}
    public function refund(string $id): array {$this->operations->reverse($id,'refund');return $this->repo->paymentDto($this->repo->payment($id));}
    public function void(string $id): array {$this->operations->reverse($id,'void');return $this->repo->paymentDto($this->repo->payment($id));}
    public function cancel(string $user,string $order): array {$this->operations->cancel($user,$order);return $this->repo->order($order,$user);}
    public function demoToken(string $id): string {return hash_hmac('sha256','demo-payment:'.$id,Env::get('PAYMENT_WEBHOOK_SECRET'));}
}
