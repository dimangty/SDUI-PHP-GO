<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env,PaymentGateway};
use Shop\repositories\ShopRepository;

/** PostgreSQL outbox: commit intent, lease/send without SQL locks, conditionally apply.
 * SENT/UNKNOWN are never replayed: the provider has no lookup/replay guarantee.
 */
final class PaymentOperations
{
    // Greater than HttpClient's hard ten-second deadline. Expiry fences stale owners.
    private const LEASE_SECONDS=30;
    // Bounded recovery: one HTTP call (10s), three local transactions (5s
    // each), selection/observation statements and completion margin. The caller
    // must scope SQL timeouts as RecoveryService does; this is admission, not cancellation.
    private const RECOVERY_CANDIDATE_SECONDS=30;
    public function __construct(private Database $db,private ShopRepository $repo,private CommerceService $commerce) {}
    private function lock(string $id,?string $user=null): array
    {
        $p=$this->repo->payment($id,$user);$o=$this->commerce->lockOrderPayment($p['order_id']);
        if($o['payment_id']!==$id){throw new ApiError('INVALID_PAYMENT_STATUS','payment is not linked to order');}
        return $this->repo->payment($id,$user);
    }
    private function active(string $id): ?array
    {return $this->db->one("SELECT * FROM payment_operations WHERE payment_id=? AND state IN ('READY','SENT','UNKNOWN') FOR UPDATE",[$id]);}
    private function queue(array $p,string $kind): ?string
    {
        if($this->active($p['id'])){return null;}
        $old=$this->db->one('SELECT id FROM payment_operations WHERE payment_id=? AND kind=?',[$p['id'],$kind]);
        if($old){return null;}
        $id=Ids::uuid();$this->db->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state) VALUES(?,?,?,?,'READY')",[$id,$p['id'],$kind,$p['id'].':'.$kind]);return $id;
    }
    public function confirm(string $user,string $id): void
    {
        $op=$this->db->transaction(function()use($user,$id){
            $p=$this->lock($id,$user);
            if($p['cancel_requested_at']!==null){throw new ApiError('INVALID_PAYMENT_STATUS','payment cancellation has been requested');}
            if(in_array($p['status'],['AUTHORIZED','CAPTURED'],true)){return null;}
            if($p['status']!=='PENDING'){throw new ApiError('INVALID_PAYMENT_STATUS','payment cannot be confirmed in current status');}
            if($p['method']==='ON_DELIVERY'){$this->db->execute("UPDATE payments SET status='AUTHORIZED',updated_at=now() WHERE id=?",[$id]);return null;}
            return $this->queue($p,'authorize');
        });
        if($op!==null){$this->dispatch($op);}
    }
    public function reverse(string $id,string $kind): void
    {
        $op=$this->db->transaction(function()use($id,$kind){
            $p=$this->lock($id);
            if(($kind==='refund' && $p['status']!=='CAPTURED') || ($kind==='void' && !in_array($p['status'],['AUTHORIZED','PENDING'],true))){throw new ApiError('INVALID_PAYMENT_STATUS','payment cannot be reversed in current status');}
            if($kind==='void' && $p['status']==='PENDING' && !$this->initiated($id)){throw new ApiError('INVALID_PAYMENT_STATUS','payment has not been initiated');}
            if($kind==='refund'){$this->db->execute('UPDATE payments SET refund_requested_at=COALESCE(refund_requested_at,now()) WHERE id=?',[$id]);}
            if($p['method']==='ON_DELIVERY'){$this->finish($p,$kind==='refund'?'REFUNDED':'CANCELLED');return null;}
            if($kind==='void'){$this->db->execute('UPDATE payments SET cancel_requested_at=COALESCE(cancel_requested_at,now()) WHERE id=?',[$id]);}
            return $this->queue($p,$kind);
        });
        if($op!==null){$this->dispatch($op);}
    }
    private function initiated(string $id): bool
    {return $this->db->one("SELECT id FROM payment_operations WHERE payment_id=? AND kind='authorize' AND sent_at IS NOT NULL",[$id])!==null;}
    private function cancelLocked(array $p): void
    {
        $this->db->execute('UPDATE payments SET cancel_requested_at=COALESCE(cancel_requested_at,now()) WHERE id=?',[$p['id']]);
        $this->cancelOrder($p['order_id']);
        $active=$this->active($p['id']);
        if($active && $active['kind']==='authorize' && $active['state']==='READY'){
            $this->db->execute("UPDATE payment_operations SET state='ABORTED',updated_at=now() WHERE id=?",[$active['id']]);
        }
        if($p['method']==='ON_DELIVERY' || ($p['status']==='PENDING' && !$this->initiated($p['id']))){
            $this->db->execute('UPDATE payments SET status=?,gateway_url=NULL,updated_at=now() WHERE id=?',[$p['status']==='CAPTURED'?'REFUNDED':'CANCELLED',$p['id']]);
        }
        $this->compensate($this->repo->payment($p['id']));
    }
    public function cancel(string $user,string $order): void
    {
        $id=$this->db->transaction(function()use($user,$order){
            $this->repo->order($order,$user);$o=$this->commerce->lockOrderPayment($order);
            if(!in_array($o['status'],['CREATED','PAID'],true)){throw new ApiError('CANCEL_NOT_ALLOWED','order cannot be cancelled');}
            $this->cancelLocked($this->repo->payment($o['payment_id'],$user));return $o['payment_id'];
        });$this->dispatchReady($id);
    }
    public function expire(string $order,int|float $pending,int|float $authorized,bool $dispatch=true): bool
    {
        $id=$this->db->transaction(function()use($order,$pending,$authorized){
            $o=$this->commerce->lockOrderPayment($order);$p=$this->repo->payment($o['payment_id']);$now=microtime(true);
            if($o['status']!=='CREATED' || !(($p['status']==='PENDING' && Ids::epoch($o['created_at'])<=$now-$pending) || ($p['status']==='AUTHORIZED' && Ids::epoch($p['updated_at'])<=$now-$authorized))){return null;}
            $this->cancelLocked($p);return $p['id'];
        });
        // Application callers retain synchronous dispatch. Scheduled maintenance
        // commits cancellation/stock/intent only; the dedicated worker owns HTTP.
        if($id!==null){if($dispatch){try{$this->dispatchReady($id);}catch(ApiError $e){if($e->errorCode!=='PAYMENT_GATEWAY_UNAVAILABLE'){throw $e;}}}return true;}return false;
    }
    private function cancelOrder(string $order): void
    {
        // Completed purchases retain their stock consumption, as before.
        $this->db->execute("UPDATE orders SET status='CANCELLED' WHERE id=? AND status<>'COMPLETED'",[$order]);$this->commerce->restoreStock($order);
    }
    private function finish(array $p,string $next): void
    {$this->cancelOrder($p['order_id']);$this->db->execute('UPDATE payments SET status=?,gateway_url=NULL,updated_at=now() WHERE id=?',[$next,$p['id']]);}
    private function compensate(array $p): void
    {
        if($p['method']==='ON_DELIVERY'){return;}
        if($p['status']==='CAPTURED' && ($p['cancel_requested_at']!==null || $p['refund_requested_at']!==null)){$this->queue($p,'refund');}
        elseif($p['cancel_requested_at']!==null && in_array($p['status'],['AUTHORIZED','PENDING'],true) && $this->initiated($p['id'])){$this->queue($p,'void');}
    }
    private function dispatchReady(string $payment): void
    {
        // At most authorize -> void/refund -> refund, each with a unique logical key.
        for($i=0;$i<3;$i++){
            $op=$this->db->one("SELECT id FROM payment_operations WHERE payment_id=? AND state='READY'",[$payment]);
            if(!$op){return;}$this->dispatch($op['id']);
        }
    }
    public function dispatch(string $id,bool $followUp=true): void
    {
        $claim=$this->db->transaction(function()use($id){
            $row=$this->db->required('SELECT * FROM payment_operations WHERE id=?',[$id],'INVALID_PAYMENT_STATUS','operation not found');
            $p=$this->lock($row['payment_id']);$op=$this->db->one('SELECT * FROM payment_operations WHERE id=? FOR UPDATE',[$id]);
            if($op['state']!=='READY'){return null;}
            // Capture can win after intent commit but before send claim. This void
            // is provably unsent: supersede it atomically instead of creating a
            // new remote uncertainty. SENT/UNKNOWN operations never take this path.
            if($op['kind']==='void' && $p['status']==='CAPTURED'){
                $this->db->execute("UPDATE payment_operations SET state='ABORTED',last_error='unsent void superseded by capture',updated_at=now() WHERE id=?",[$id]);
                $this->db->execute('UPDATE payments SET refund_requested_at=COALESCE(refund_requested_at,now()) WHERE id=?',[$p['id']]);
                $this->compensate($this->repo->payment($p['id']));return null;
            }
            $token=Ids::uuid();$this->db->execute("UPDATE payment_operations SET state='SENT',lease_token=?,lease_until=clock_timestamp()+?*interval '1 second',sent_at=clock_timestamp(),updated_at=now() WHERE id=?",[$token,self::LEASE_SECONDS,$id]);
            $p['operation_key']=$op['operation_key'];return [$p,$op['kind'],$token];
        });
        if($claim===null){return;}[$p,$kind,$token]=$claim;
        // No SQL transaction (or row lock) survives this boundary.
        try{$result=(new PaymentGateway())->call($kind,$p);}
        catch(\Throwable $e){
            $this->db->transaction(function()use($p,$id,$token){$this->lock($p['id']);$this->db->execute("UPDATE payment_operations SET state='UNKNOWN',last_error='provider outcome uncertain',updated_at=now() WHERE id=? AND state='SENT' AND lease_token=?",[$id,$token]);});
            throw $e;
        }
        // An observed response must survive a later apply failure, an expired
        // lease, or an operator already resolving UNKNOWN. This autocommit audit
        // write never changes financial state or authoritative operator evidence.
        $this->db->execute('INSERT INTO payment_operation_observations(operation_id,lease_token,result) VALUES(?,?,?::jsonb) ON CONFLICT(operation_id,lease_token) DO NOTHING',[$id,$token,json_encode($result,JSON_THROW_ON_ERROR)]);
        $this->db->transaction(function()use($p,$kind,$id,$token,$result){
            $current=$this->lock($p['id']);
            $owned=$this->db->one("SELECT * FROM payment_operations WHERE id=? AND state='SENT' AND lease_token=? AND lease_until>clock_timestamp() FOR UPDATE",[$id,$token]);
            if(!$owned){
                $this->db->execute("UPDATE payment_operations SET state='UNKNOWN',last_error='result arrived after lease',updated_at=now() WHERE id=? AND state='SENT' AND lease_token=?",[$id,$token]);return;
            }
            if($kind==='authorize' && $result['transactionId']!==null && !$this->link($p['id'],$result['transactionId'])){
                $this->db->execute("UPDATE payment_operations SET state='UNKNOWN',last_error='foreign transaction in provider response',result=?::jsonb,updated_at=now() WHERE id=?",[json_encode($result,JSON_THROW_ON_ERROR),$id]);return;
            }
            $this->db->execute("UPDATE payment_operations SET state='SUCCEEDED',result=?::jsonb,updated_at=now() WHERE id=?",[json_encode($result,JSON_THROW_ON_ERROR),$id]);
            if($kind==='authorize'){
                if($current['status']==='PENDING'){$this->db->execute('UPDATE payments SET status=?,gateway_url=?,transaction_id=?,updated_at=now() WHERE id=?',[$result['status'],$result['paymentUrl'],$result['transactionId'],$p['id']]);}
                $this->drainInbox($p['id']);
            }elseif($kind==='refund'){$this->finish($current,'REFUNDED');}
            elseif($current['status']!=='CAPTURED'){$this->finish($current,'CANCELLED');}
            $this->compensate($this->repo->payment($p['id']));
        });
        if($followUp){$this->dispatchReady($p['id']);}
    }
    private function link(string $payment,string $transaction): bool
    {
        $this->db->execute('INSERT INTO payment_transaction_links(transaction_id,payment_id) VALUES(?,?) ON CONFLICT DO NOTHING',[$transaction,$payment]);
        $row=$this->db->one('SELECT payment_id FROM payment_transaction_links WHERE transaction_id=?',[$transaction]);return ($row['payment_id']??null)===$payment;
    }
    public function webhook(array $input,string $webhook): void
    {
        if($webhook==='' || strlen($webhook)>255){throw new ApiError('INVALID_WEBHOOK_ID','webhook id must contain 1 to 255 characters');}
        $status=$input['status']??null;$transaction=$input['transactionId']??null;
        if(!in_array($status,['CAPTURED','FAILED'],true)){throw new ApiError('INVALID_WEBHOOK_STATUS','invalid webhook status');}
        if($transaction!==null && (!is_string($transaction) || trim($transaction)==='' || mb_strlen($transaction)>255)){throw new ApiError('INVALID_TRANSACTION_ID','invalid transactionId');}
        $accepted=$this->db->transaction(function()use($input,$webhook,$status,$transaction){
            // Duplicate HTTP contract takes precedence over revalidation of linkage.
            if($this->db->one('SELECT id FROM processed_webhooks WHERE id=?',[$webhook])){throw new ApiError('ALREADY_PROCESSED','webhook already processed');}
            $p=$this->lock($input['paymentId']??'');
            // A duplicate may have committed while this worker waited for the order.
            if($this->db->one('SELECT id FROM processed_webhooks WHERE id=?',[$webhook])){throw new ApiError('ALREADY_PROCESSED','webhook already processed');}
            if($p['status']==='REFUNDED' && $status==='FAILED'){throw new ApiError('INVALID_PAYMENT_STATUS','refunded payment cannot fail');}
            if($p['method']==='ON_DELIVERY' || !$this->initiated($p['id'])){throw new ApiError('INVALID_PAYMENT_STATUS','payment has not been remotely initiated');}
            $demo=!Env::production() && Env::get('PAYMENT_GATEWAY_URL')==='';
            if($demo && $p['transaction_id']===null){$transaction??='demo-'.$p['id'];if($this->link($p['id'],$transaction)){$this->db->execute('UPDATE payments SET transaction_id=? WHERE id=?',[$transaction,$p['id']]);}}
            $link=$transaction===null?null:$this->db->one('SELECT payment_id FROM payment_transaction_links WHERE transaction_id=?',[$transaction]);
            if(($link['payment_id']??null)!==$p['id']){
                $auth=$this->db->one("SELECT id FROM payment_operations WHERE payment_id=? AND kind='authorize' AND state IN ('SENT','UNKNOWN')",[$p['id']]);
                if($link===null && $transaction!==null && $auth){
                    $this->db->execute('INSERT INTO payment_callback_inbox(id,operation_id,payment_id,transaction_id,status) VALUES(?,?,?,?,?) ON CONFLICT DO NOTHING',[$webhook,$auth['id'],$p['id'],$transaction,$status]);
                }
                return false;
            }
            $this->recordEvent($p,$status,$webhook);return true;
        });
        if(!$accepted){throw new ApiError('INVALID_TRANSACTION_ID','transaction is not correlated to this payment');}
        // Compensation is durable before returning ACK; recovery dispatches it.
    }
    private function recordEvent(array $p,string $status,string $webhook): void
    {
        if($p['status']==='REFUNDED' && $status==='FAILED'){throw new ApiError('INVALID_PAYMENT_STATUS','refunded payment cannot fail');}
        if($this->db->execute('INSERT INTO processed_webhooks(id) VALUES(?) ON CONFLICT DO NOTHING',[$webhook])===0){throw new ApiError('ALREADY_PROCESSED','webhook already processed');}
        $o=$this->repo->order($p['order_id']);
        if($status==='CAPTURED'){
            if($p['status']==='REFUNDED'){return;}
            $late=in_array($p['status'],['CANCELLED','FAILED'],true) || $o['status']==='CANCELLED' || $p['cancel_requested_at']!==null;
            $this->db->execute("UPDATE payments SET status='CAPTURED',gateway_url=NULL,cancel_requested_at=CASE WHEN ? THEN COALESCE(cancel_requested_at,now()) ELSE cancel_requested_at END,updated_at=now() WHERE id=?",[$late,$p['id']]);
            if($late){$this->cancelOrder($p['order_id']);}
            elseif($o['status']==='CREATED'){$this->db->execute("UPDATE orders SET status='PAID' WHERE id=?",[$p['order_id']]);}
        }elseif(!in_array($p['status'],['CAPTURED','REFUNDED'],true)){
            $this->db->execute("UPDATE payments SET status='FAILED',gateway_url=NULL,cancel_requested_at=COALESCE(cancel_requested_at,now()),updated_at=now() WHERE id=?",[$p['id']]);$this->cancelOrder($p['order_id']);
        }
        $this->compensate($this->repo->payment($p['id']));
    }
    private function drainInbox(string $payment): void
    {
        $rows=$this->db->all('SELECT i.* FROM payment_callback_inbox i JOIN payment_transaction_links l ON l.transaction_id=i.transaction_id AND l.payment_id=i.payment_id WHERE i.payment_id=? AND i.resolved_at IS NULL ORDER BY i.received_at,i.id FOR UPDATE OF i',[$payment]);
        foreach($rows as $row){
            if(!$this->db->one('SELECT id FROM processed_webhooks WHERE id=?',[$row['id']])){$this->recordEvent($this->repo->payment($payment),$row['status'],$row['id']);}
            $this->db->execute('UPDATE payment_callback_inbox SET resolved_at=now() WHERE id=?',[$row['id']]);
        }
    }
    /** Console-only: independently verified final facts, with senders quiesced.
     * NO_EFFECT closes a sent reversal without asserting success or permitting replay.
     */
    public function reconcile(string $id,string $outcome,string $transaction,string $evidence): void
    {
        if(trim($evidence)==='' || mb_strlen($evidence)>2000 || !in_array($outcome,['AUTHORIZED','PENDING','CAPTURED','FAILED','REVERSED','NO_EFFECT'],true)){
            throw new ApiError('BAD_REQUEST','verified provider evidence and a supported outcome are required');
        }
        $this->db->transaction(function()use($id,$outcome,$transaction,$evidence){
            $row=$this->db->required('SELECT * FROM payment_operations WHERE id=?',[$id],'INVALID_PAYMENT_STATUS','operation not found');$p=$this->lock($row['payment_id']);
            $op=$this->db->one('SELECT * FROM payment_operations WHERE id=? FOR UPDATE',[$id]);
            if($op['state']!=='UNKNOWN'){throw new ApiError('INVALID_PAYMENT_STATUS','only UNKNOWN operations can be reconciled');}
            if($op['kind']==='authorize'){
                if(in_array($outcome,['REVERSED','NO_EFFECT'],true) || trim($transaction)==='' || mb_strlen($transaction)>255){throw new ApiError('BAD_REQUEST','authorization needs its verified transaction and outcome');}
                if(!$this->link($p['id'],$transaction)){throw new ApiError('INVALID_TRANSACTION_ID','transaction belongs to another payment');}
            }elseif(!in_array($outcome,['REVERSED','NO_EFFECT'],true) || $transaction!==$p['transaction_id']
                || !$this->db->one('SELECT transaction_id FROM payment_transaction_links WHERE transaction_id=? AND payment_id=?',[$transaction,$p['id']])){
                throw new ApiError('INVALID_TRANSACTION_ID','reversal needs its original verified transaction and REVERSED or NO_EFFECT outcome');
            }
            if($outcome==='NO_EFFECT' && $op['sent_at']===null){throw new ApiError('INVALID_PAYMENT_STATUS','no-effect disposition requires a sent reversal');}
            $result=['outcome'=>$outcome,'transactionId'=>$transaction,'evidence'=>$evidence,'reconciledAt'=>Ids::now()];
            $this->db->execute('UPDATE payment_operations SET state=?,last_error=NULL,result=?::jsonb,updated_at=now() WHERE id=?',[$outcome==='NO_EFFECT'?'ABORTED':'SUCCEEDED',json_encode($result,JSON_THROW_ON_ERROR),$id]);
            if($op['kind']==='authorize'){
                $this->db->execute('UPDATE payments SET transaction_id=?,updated_at=now() WHERE id=?',[$transaction,$p['id']]);
                if(in_array($outcome,['CAPTURED','FAILED'],true)){$this->recordEvent($this->repo->payment($p['id']),$outcome,'reconcile-'.$id);}
                elseif($p['status']==='PENDING'){$this->db->execute('UPDATE payments SET status=? WHERE id=?',[$outcome,$p['id']]);}
                $this->drainInbox($p['id']);
            }elseif($outcome==='NO_EFFECT'){
                // Preserve financial status and all send/observation history. A
                // failed void can release the owed refund; a failed refund stays
                // manual (the unique kind/key prevents another automatic send).
            }elseif($op['kind']==='refund'){$this->finish($p,'REFUNDED');}
            elseif($p['status']!=='CAPTURED'){$this->finish($p,'CANCELLED');}
            $this->compensate($this->repo->payment($p['id']));
        });
    }
    /** Optional monotonic deadline bounds admission; bounded scans dispatch only
     * one effect per candidate and leave chained READY work for a later scan.
     * Existing callers retain the 1000-candidate/chained/full-review behavior.
     */
    public function recover(int $limit=1000,?float $deadline=null,bool $includeReview=true): array
    {
        if($limit<1 || $limit>1000 || ($deadline!==null && !is_finite($deadline))){throw new \InvalidArgumentException('Invalid recovery scan bound');}
        // Unresolved manual cases must not consume the bounded dispatch batch.
        $rows=$deadline===null || hrtime(true)/1e9+self::RECOVERY_CANDIDATE_SECONDS<=$deadline?$this->db->all(<<<'SQL'
SELECT p.id FROM payments p
WHERE EXISTS (SELECT 1 FROM payment_operations op WHERE op.payment_id=p.id
              AND (op.state='READY' OR (op.state='SENT' AND op.lease_until<=clock_timestamp())))
   OR ((p.cancel_requested_at IS NOT NULL OR p.refund_requested_at IS NOT NULL) AND p.method<>'ON_DELIVERY'
       AND NOT EXISTS (SELECT 1 FROM payment_operations live WHERE live.payment_id=p.id AND live.state IN ('READY','SENT','UNKNOWN'))
       AND ((p.status='CAPTURED' AND NOT EXISTS (SELECT 1 FROM payment_operations r WHERE r.payment_id=p.id AND r.kind='refund'))
         OR (p.cancel_requested_at IS NOT NULL AND p.status IN ('PENDING','AUTHORIZED')
             AND EXISTS (SELECT 1 FROM payment_operations a WHERE a.payment_id=p.id AND a.kind='authorize' AND a.sent_at IS NOT NULL)
             AND NOT EXISTS (SELECT 1 FROM payment_operations v WHERE v.payment_id=p.id AND v.kind='void'))))
ORDER BY p.id LIMIT ?
SQL,[$limit]):[];$dispatched=0;
        foreach($rows as $row){
            if($deadline!==null && hrtime(true)/1e9+self::RECOVERY_CANDIDATE_SECONDS>$deadline){break;}
            $this->db->transaction(function()use($row){$p=$this->lock($row['id']);$this->db->execute("UPDATE payment_operations SET state='UNKNOWN',last_error='worker lease expired; do not replay',updated_at=now() WHERE payment_id=? AND state='SENT' AND lease_until<=clock_timestamp()",[$p['id']]);$this->compensate($p);});
            try{$ready=$this->db->one("SELECT id FROM payment_operations WHERE payment_id=? AND state='READY'",[$row['id']]);if($ready){if($deadline===null){$this->dispatchReady($row['id']);}else{$this->dispatch($ready['id'],false);}$dispatched++;}}
            catch(ApiError $e){if($e->errorCode!=='PAYMENT_GATEWAY_UNAVAILABLE'){throw $e;}}
        }
        return ['dispatched'=>$dispatched,'review'=>$includeReview?$this->db->all("SELECT op.id,op.payment_id,op.kind,op.operation_key,op.state,op.last_error,op.sent_at,p.order_id,p.status AS payment_status,p.transaction_id,p.amount,p.cancel_requested_at,p.refund_requested_at FROM payment_operations op JOIN payments p ON p.id=op.payment_id WHERE op.state='UNKNOWN' OR (op.state='ABORTED' AND op.kind='refund' AND op.result->>'outcome'='NO_EFFECT') ORDER BY op.created_at,op.id"):[]];
    }
}
