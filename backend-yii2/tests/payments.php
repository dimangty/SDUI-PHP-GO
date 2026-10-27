<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/payment-fixture.php';
use Shop\application\{MaintenanceService,PaymentOperations};
use Shop\infrastructure\{Env,PaymentGateway};
use Shop\domain\Ids;
paymentTestSchema();

test('authorize commits intent before provider HTTP and sends exact cents once',function(){
    [$u,$o]=paymentFixture();[$db,$repo,,$p]=paymentServices();
    same('AUTHORIZED',$p->confirm($u,$o['paymentId'])['status']);$p->confirm($u,$o['paymentId']);
    $journal=journal($o['paymentId']);same(1,count($journal));same(1,$journal[0]['attempts']);same(2046,$journal[0]['amount_cents']);same(0,$journal[0]['in_transaction']);
    same('SENT',$journal[0]['intent_state']);same($o['paymentId'].':authorize',$journal[0]['intent_key']);
    same('20.46',$p->payment($u,$o['paymentId'])['amount']);
    $op=$db->one('SELECT * FROM payment_operations WHERE payment_id=?',[$o['paymentId']]);same('SUCCEEDED',$op['state']);same($o['paymentId'].':authorize',$op['operation_key']);
    same('txn-'.$o['paymentId'],$repo->payment($o['paymentId'])['transaction_id']);
});
foreach(['AUTHORIZED','PENDING'] as $status){
    foreach(['CAPTURED','FAILED'] as $event){
        test("$status initiated authorization accepts correlated $event once",function()use($status,$event){
            [$u,$o,$product]=paymentFixture('SBP',$status);[, $repo,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);$wid=Ids::uuid();
            callback($o,$event,$wid);same($event,$repo->payment($o['paymentId'])['status']);same($event==='CAPTURED'?'PAID':'CANCELLED',$repo->order($o['id'])['status']);
            same($event==='CAPTURED'?8:10,stock($product));errorCode('ALREADY_PROCESSED',fn()=>callback($o,$event,$wid));same($event==='CAPTURED'?8:10,stock($product));
        });
    }
}
foreach(['CARD','SBP','ON_DELIVERY'] as $method){
    foreach(['UNSTARTED','AUTHORIZED','PENDING','CAPTURED'] as $state){
        test("cancel $method $state performs correct remote effect and restores once",function()use($method,$state){
            [$u,$o,$product]=paymentFixture($method,$state==='PENDING'?'PENDING':'AUTHORIZED');[, $repo,,$p]=paymentServices();
            if($state!=='UNSTARTED'){$p->confirm($u,$o['paymentId']);}
            if($state==='CAPTURED'){
                if($method==='ON_DELIVERY'){paymentDb()->execute("UPDATE payments SET status='CAPTURED',transaction_id='cash-test' WHERE id=?",[$o['paymentId']]);paymentDb()->execute("UPDATE orders SET status='PAID' WHERE id=?",[$o['id']]);}
                else{callback($o,'CAPTURED');}
            }
            same('CANCELLED',$p->cancel($u,$o['id'])['status']);same(10,stock($product));
            $ops=array_column(journal($o['paymentId']),'operation');
            same($method==='ON_DELIVERY'||$state==='UNSTARTED'?[]:($state==='CAPTURED'?['authorize','refund']:['authorize','void']),$ops);
            same($state==='CAPTURED'?'REFUNDED':'CANCELLED',$repo->payment($o['paymentId'])['status']);
            errorCode('CANCEL_NOT_ALLOWED',fn()=>$p->cancel($u,$o['id']));same(10,stock($product));
        });
    }
}
foreach(['CARD','SBP','ON_DELIVERY'] as $method){
    foreach(['UNSTARTED','AUTHORIZED','PENDING'] as $state){
        test("expiry $method $state schedules remote void when initiated",function()use($method,$state){
            [$u,$o,$product]=paymentFixture($method,$state==='PENDING'?'PENDING':'AUTHORIZED');[$db,$repo,$commerce,$p]=paymentServices();
            if($state!=='UNSTARTED'){$p->confirm($u,$o['paymentId']);}
            $db->execute("UPDATE orders SET created_at=now()-interval '1 day' WHERE id=?",[$o['id']]);$db->execute("UPDATE payments SET updated_at=now()-interval '1 day' WHERE id=?",[$o['paymentId']]);
            (new MaintenanceService($db,$repo,$commerce))->run();same('CANCELLED',$repo->order($o['id'])['status']);same(10,stock($product));
            same($method==='ON_DELIVERY'||$state==='UNSTARTED'?[]:['authorize'],array_column(journal($o['paymentId']),'operation'));
            recoverPayments(); // Scheduled expiry commits intent; the recovery worker owns HTTP.
            same($method==='ON_DELIVERY'||$state==='UNSTARTED'?[]:['authorize','void'],array_column(journal($o['paymentId']),'operation'));
            (new MaintenanceService($db,$repo,$commerce))->run();same(10,stock($product));
        });
    }
}
foreach(['CARD','SBP'] as $method){
    foreach(['CAPTURED','FAILED','REFUNDED','CANCELLED'] as $state){
        test("expiry excludes $method $state from reservation cancellation",function()use($method,$state){
            [$u,$o,$product]=paymentFixture($method);[$db,$repo,$commerce,$p]=paymentServices();$p->confirm($u,$o['paymentId']);
            if($state==='CANCELLED'){$p->cancel($u,$o['id']);}
            else{callback($o,$state==='FAILED'?'FAILED':'CAPTURED');if($state==='REFUNDED'){$p->refund($o['paymentId']);}}
            $before=count(journal($o['paymentId']));$db->execute("UPDATE orders SET created_at=now()-interval '1 day' WHERE id=?",[$o['id']]);$db->execute("UPDATE payments SET updated_at=now()-interval '1 day' WHERE id=?",[$o['paymentId']]);
            (new MaintenanceService($db,$repo,$commerce))->run();same($state,$repo->payment($o['paymentId'])['status']);same($state==='CAPTURED'?'PAID':'CANCELLED',$repo->order($o['id'])['status']);same($state==='CAPTURED'?8:10,stock($product));same($before,count(journal($o['paymentId'])));
        });
    }
}
test('expiry excludes completed ON_DELIVERY purchase and preserves consumed stock',function(){
    [$u,$o,$product]=paymentFixture('ON_DELIVERY');[$db,$repo,$commerce,$p]=paymentServices();$p->confirm($u,$o['paymentId']);foreach(['ASSEMBLING','DELIVERING','COMPLETED'] as $status){$commerce->transition($o['id'],['status'=>$status]);}
    $db->execute("UPDATE orders SET created_at=now()-interval '1 day' WHERE id=?",[$o['id']]);$db->execute("UPDATE payments SET updated_at=now()-interval '1 day' WHERE id=?",[$o['paymentId']]);(new MaintenanceService($db,$repo,$commerce))->run();same('COMPLETED',$repo->order($o['id'])['status']);same('CAPTURED',$repo->payment($o['paymentId'])['status']);same(8,stock($product));same([],journal($o['paymentId']));
});
foreach(['void','refund'] as $operation){
    foreach([[204,'',true],[202,'{"status":"PENDING"}',false],[200,'{}',false],[201,'',false],[200,'broken',false],[400,'{}',false],[500,'{}',false]] as [$code,$body,$done]){
        test("$operation HTTP $code ".($body?:'empty').' only synchronous 204 finishes',function()use($operation,$code,$body,$done){
            [$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['reverse_code'=>$code,'reverse_body'=>$body]);[$db,$repo,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);
            if($operation==='refund'){callback($o,'CAPTURED');}
            if($done){same($operation==='refund'?'REFUNDED':'CANCELLED',$p->$operation($o['paymentId'])['status']);same(10,stock($product));}
            else{errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$p->$operation($o['paymentId']));same($operation==='refund'?'CAPTURED':'AUTHORIZED',$repo->payment($o['paymentId'])['status']);same(8,stock($product));recoverPayments();same(1,journal($o['paymentId'])[1]['attempts']);same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=? AND kind=?',[$o['paymentId'],$operation])['state']);}
        });
    }
}
test('lost executed authorization remains uncertain across confirm and restart without replay',function(){
    [$u,$o]=paymentFixture('CARD','AUTHORIZED',['lose_response'=>true]);[$db,$repo,,$p]=paymentServices();
    errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$p->confirm($u,$o['paymentId']));same('PENDING',$repo->payment($o['paymentId'])['status']);
    $p->confirm($u,$o['paymentId']);recoverPayments();same(1,journal($o['paymentId'])[0]['attempts']);same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$o['paymentId']])['state']);
});
test('signed PHP HTTP boundary validates signature timestamp unknown and foreign transaction then deduplicates',function(){
    [$u,$o,$product]=paymentFixture('CARD','PENDING');[, $repo,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);
    same(401,signedPaymentHttp($o,'CAPTURED',Ids::uuid(),null,false)[0]);same(401,signedPaymentHttp($o,'CAPTURED',Ids::uuid(),null,true,time()-1000)[0]);
    [$code,$body]=signedPaymentHttp($o,'CAPTURED',Ids::uuid(),'unknown');same(400,$code);same('INVALID_TRANSACTION_ID',$body['error']['code']);
    [$other,$foreign]=paymentFixture();$p->confirm($other,$foreign['paymentId']);same(400,signedPaymentHttp($o,'CAPTURED',Ids::uuid(),'txn-'.$foreign['paymentId'])[0]);
    same('PENDING',$repo->payment($o['paymentId'])['status']);same(8,stock($product));
    $wid=Ids::uuid();same(204,signedPaymentHttp($o,'CAPTURED',$wid)[0]);[$code,$body]=signedPaymentHttp($o,'CAPTURED',$wid);same(409,$code);same('ALREADY_PROCESSED',$body['error']['code']);same('PAID',$repo->order($o['id'])['status']);
});
test('callback before authorize response is durably correlated before capture is applied',function(){
    [$u,$o]=paymentFixture('CARD','PENDING',['callback_status'=>'CAPTURED']);[$db,$repo,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);
    same('CAPTURED',$repo->payment($o['paymentId'])['status']);same('PAID',$repo->order($o['id'])['status']);same(0,journal($o['paymentId'])[0]['in_transaction']);
    same(400,$db->one('SELECT callback_http FROM payment_test_scenarios WHERE payment_id=?',[$o['paymentId']])['callback_http']);truth($db->one('SELECT resolved_at FROM payment_callback_inbox WHERE id=?',['early-'.$o['paymentId']])['resolved_at']!==null);
    errorCode('ALREADY_PROCESSED',fn()=>callback($o,'CAPTURED','early-'.$o['paymentId']));
});
test('late capture after failed callback queues one compensation refund without restoring twice',function(){
    [$u,$o,$product]=paymentFixture();[, $repo,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);callback($o,'FAILED');callback($o,'CAPTURED');recoverPayments();
    same('REFUNDED',$repo->payment($o['paymentId'])['status']);same('CANCELLED',$repo->order($o['id'])['status']);same(10,stock($product));same(['authorize','refund'],array_column(journal($o['paymentId']),'operation'));
    callback($o,'CAPTURED');recoverPayments();same(1,journal($o['paymentId'])[1]['attempts']);same(10,stock($product));
});
test('late capture after confirmed cancellation compensates instead of disappearing',function(){
    [$u,$o,$product]=paymentFixture();[, $repo,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);$p->cancel($u,$o['id']);callback($o,'CAPTURED');recoverPayments();
    same('REFUNDED',$repo->payment($o['paymentId'])['status']);same(10,stock($product));same(['authorize','void','refund'],array_column(journal($o['paymentId']),'operation'));
});
test('two confirm workers create and send one authorization',function(){
    [$u,$o]=paymentFixture('CARD','AUTHORIZED',['delay_ms'=>250]);$out=paymentRace([function()use($u,$o){return paymentServices()[3]->confirm($u,$o['paymentId']);},function()use($u,$o){return paymentServices()[3]->confirm($u,$o['paymentId']);}]);
    foreach($out as $r){truth(isset($r['ok']));}same(1,journal($o['paymentId'])[0]['attempts']);same(0,journal($o['paymentId'])[0]['in_transaction']);
});
test('concurrent cancel capture refund and expiry serialize effects and stock',function(){
    [$u,$o,$product]=paymentFixture();paymentServices()[3]->confirm($u,$o['paymentId']);
    $db=paymentDb();$db->execute("UPDATE orders SET created_at=now()-interval '1 day' WHERE id=?",[$o['id']]);$db->execute("UPDATE payments SET updated_at=now()-interval '1 day' WHERE id=?",[$o['paymentId']]);unset($db);
    $out=paymentRace([fn()=>paymentServices()[3]->cancel($u,$o['id']),function()use($o){callback($o,'CAPTURED');return true;},fn()=>paymentServices()[3]->refund($o['paymentId']),function(){[$d,$r,$c]=paymentServices();return (new MaintenanceService($d,$r,$c))->run();}]);
    foreach($out as $r){truth(!isset($r['exception']));}recoverPayments();same(10,stock($product));same('REFUNDED',paymentServices()[1]->payment($o['paymentId'])['status']);foreach(journal($o['paymentId']) as $j){same(1,$j['attempts']);}
});
foreach([
    ['status'=>12],['status'=>['AUTHORIZED']],['paymentUrl'=>null],['paymentUrl'=>[]],
    ['paymentUrl'=>'https:///missing-host'],['paymentUrl'=>'https://user:password@example.test/pay'],
    ['paymentUrl'=>'javascript:alert(1)'],['transactionId'=>null],['transactionId'=>42],
    ['transactionId'=>''],['transactionId'=>str_repeat('x',256)],['paymentId'=>'foreign'],['orderId'=>false],
] as $i=>$patch){
    test("structured authorize rejects malformed or foreign result $i without losing intent",function()use($patch){
        [$u,$o]=paymentFixture();[$db,$repo,,$p]=paymentServices();$body=['status'=>'AUTHORIZED','paymentUrl'=>'https://payments.example.test/pay','transactionId'=>'txn-'.$o['paymentId']];$body=array_replace($body,$patch);
        $db->execute('UPDATE payment_test_scenarios SET authorize_body=? WHERE payment_id=?',[json_encode($body),$o['paymentId']]);
        errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$p->confirm($u,$o['paymentId']));same('PENDING',$repo->payment($o['paymentId'])['status']);same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$o['paymentId']])['state']);
    });
}
foreach([204,206,400,500] as $code){
    test("authorize unexpected HTTP $code preserves uncertain initiated operation",function()use($code){
        [$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['authorize_code'=>$code]);[$db,$repo,,$p]=paymentServices();errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$p->confirm($u,$o['paymentId']));
        same('PENDING',$repo->payment($o['paymentId'])['status']);same(8,stock($product));same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$o['paymentId']])['state']);
    });
}
test('uninitiated and foreign callbacks cannot bind a transaction or mutate stock',function(){
    [, $o,$product]=paymentFixture();errorCode('INVALID_PAYMENT_STATUS',fn()=>callback($o,'CAPTURED'));same(8,stock($product));same([],journal($o['paymentId']));
});
test('structured 202 PENDING is initiated and cancellable, not a terminal reversal',function(){
    [$u,$o]=paymentFixture('SBP','PENDING',['authorize_code'=>202]);[, $repo,,$p]=paymentServices();same('PENDING',$p->confirm($u,$o['paymentId'])['status']);$p->cancel($u,$o['id']);same('CANCELLED',$repo->payment($o['paymentId'])['status']);same(['authorize','void'],array_column(journal($o['paymentId']),'operation'));
});
test('completed purchase refund preserves consumed stock',function(){
    [$u,$o,$product]=paymentFixture();[, $repo,$c,$p]=paymentServices();$p->confirm($u,$o['paymentId']);callback($o,'CAPTURED');foreach(['ASSEMBLING','DELIVERING','COMPLETED'] as $s){$c->transition($o['id'],['status'=>$s]);}
    same('REFUNDED',$p->refund($o['paymentId'])['status']);same('COMPLETED',$repo->order($o['id'])['status']);same(8,stock($product));
});
test('restart recovers committed unsent intent after dispatch claim commit fails',function(){
    [$u,$o]=paymentFixture();[$db,,,$p]=paymentServices();[$trigger,$function]=paymentFailureTrigger($db,$o['paymentId'],'SENT');
    try{try{$p->confirm($u,$o['paymentId']);throw new RuntimeException('expected failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}same([],journal($o['paymentId']));same('READY',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$o['paymentId']])['state']);}
    finally{dropPaymentTrigger($db,$trigger,$function);}
    recoverPayments();same('AUTHORIZED',paymentServices()[1]->payment($o['paymentId'])['status']);same(1,journal($o['paymentId'])[0]['attempts']);
});
test('intent commit failure sends no HTTP and leaves no durable operation',function(){
    [$u,$o]=paymentFixture();[$db,,,$p]=paymentServices();[$trigger,$function]=paymentFailureTrigger($db,$o['paymentId'],'READY');
    try{try{$p->confirm($u,$o['paymentId']);throw new RuntimeException('expected failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}same([],journal($o['paymentId']));same(null,$db->one('SELECT id FROM payment_operations WHERE payment_id=?',[$o['paymentId']]));}
    finally{dropPaymentTrigger($db,$trigger,$function);}
});
test('apply commit failure leaves SENT and restart escalates without provider replay',function(){
    [$u,$o]=paymentFixture();[$db,$repo,,$p]=paymentServices();[$trigger,$function]=paymentFailureTrigger($db,$o['paymentId'],'SUCCEEDED');
    try{try{$p->confirm($u,$o['paymentId']);throw new RuntimeException('expected failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}same('PENDING',$repo->payment($o['paymentId'])['status']);same('SENT',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$o['paymentId']])['state']);}
    finally{dropPaymentTrigger($db,$trigger,$function);}
    $db->execute("UPDATE payment_operations SET lease_until=now()-interval '1 second' WHERE payment_id=?",[$o['paymentId']]);recoverPayments();same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$o['paymentId']])['state']);same(1,journal($o['paymentId'])[0]['attempts']);
});
test('killed sender restart never replays SENT and cancellation waits for reconciliation',function(){
    [$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['delay_ms'=>700]);
    $pid=pcntl_fork();if($pid===0){paymentServices()[3]->confirm($u,$o['paymentId']);exit(0);}
    waitPaymentJournal($o['paymentId']);posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);truth(pcntl_wifsignaled($status));
    [$db,$repo,,$p]=paymentServices();$p->cancel($u,$o['id']);same(10,stock($product));same(1,count(journal($o['paymentId'])));
    $db->execute("UPDATE payment_operations SET lease_until=now()-interval '1 second' WHERE payment_id=?",[$o['paymentId']]);recoverPayments();same('PENDING',$repo->payment($o['paymentId'])['status']);same(1,journal($o['paymentId'])[0]['attempts']);
});
test('two refund workers never send competing reversal requests',function(){
    [$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['delay_ms'=>150]);paymentServices()[3]->confirm($u,$o['paymentId']);callback($o,'CAPTURED');
    $out=paymentRace([fn()=>paymentServices()[3]->refund($o['paymentId']),fn()=>paymentServices()[3]->refund($o['paymentId'])]);foreach($out as $r){truth(!isset($r['exception']));}
    same(10,stock($product));same(['authorize','refund'],array_column(journal($o['paymentId']),'operation'));same(1,journal($o['paymentId'])[1]['attempts']);
});
test('manual provider evidence resolves UNKNOWN and drains matching early capture before compensation',function(){
    [$u,$o,$product]=paymentFixture('CARD','PENDING',['lose_response'=>true,'callback_status'=>'CAPTURED']);[$db,$repo,$c,$p]=paymentServices();errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$p->confirm($u,$o['paymentId']));$p->cancel($u,$o['id']);
    $op=$db->one('SELECT id FROM payment_operations WHERE payment_id=?',[$o['paymentId']]);$operations=new PaymentOperations($db,$repo,$c);
    errorCode('BAD_REQUEST',fn()=>$operations->reconcile($op['id'],'PENDING','txn-'.$o['paymentId'],''));
    $db->execute('UPDATE payment_test_scenarios SET lose_response=false WHERE payment_id=?',[$o['paymentId']]);
    $operations->reconcile($op['id'],'PENDING','txn-'.$o['paymentId'],'provider journal verified by operator ticket PAY-123');recoverPayments();same('REFUNDED',$repo->payment($o['paymentId'])['status']);same(10,stock($product));same(['authorize','refund'],array_column(journal($o['paymentId']),'operation'));
});
test('cancel while authorization HTTP is in flight cannot send a competing void and late capture refunds',function(){
    [$u,$o,$product]=paymentFixture('CARD','PENDING',['delay_ms'=>300,'callback_status'=>'CAPTURED']);
    $worker=paymentWorker(fn()=>paymentServices()[3]->confirm($u,$o['paymentId']));waitPaymentJournal($o['paymentId']);paymentServices()[3]->cancel($u,$o['id']);same(['authorize'],array_column(journal($o['paymentId']),'operation'));same(10,stock($product));
    finishPaymentWorker($worker);recoverPayments();same('REFUNDED',paymentServices()[1]->payment($o['paymentId'])['status']);same(['authorize','refund'],array_column(journal($o['paymentId']),'operation'));same(10,stock($product));
});
test('capture during void HTTP cannot be overwritten by void response and refund follows once',function(){
    [$u,$o,$product]=paymentFixture();paymentServices()[3]->confirm($u,$o['paymentId']);paymentDb()->execute('UPDATE payment_test_scenarios SET delay_ms=300 WHERE payment_id=?',[$o['paymentId']]);
    $worker=paymentWorker(fn()=>paymentServices()[3]->cancel($u,$o['id']));waitPaymentJournal($o['paymentId'],2);callback($o,'CAPTURED');same(['authorize','void'],array_column(journal($o['paymentId']),'operation'));
    finishPaymentWorker($worker);recoverPayments();same('REFUNDED',paymentServices()[1]->payment($o['paymentId'])['status']);same(['authorize','void','refund'],array_column(journal($o['paymentId']),'operation'));same(10,stock($product));
});
test('callback stock failure rolls back guard and dedup marker then retry restores exactly once',function(){
    [$u,$o,$product]=paymentFixture();paymentServices()[3]->confirm($u,$o['paymentId']);$db=paymentDb();$suffix=str_replace('-','',Ids::uuid());$f='payment_stock_fail_'.$suffix;$t='payment_stock_trigger_'.$suffix;$wid=Ids::uuid();
    $db->execute('CREATE FUNCTION '.$f.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id=\''.$product.'\'::uuid THEN RAISE EXCEPTION \'injected stock failure\'; END IF; RETURN NEW; END $$');$db->execute('CREATE TRIGGER '.$t.' BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION '.$f.'()');
    try{try{callback($o,'FAILED',$wid);throw new RuntimeException('expected stock error');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected stock failure'));}same(8,stock($product));same(null,$db->one('SELECT stock_restored_at FROM orders WHERE id=?',[$o['id']])['stock_restored_at']);same(null,$db->one('SELECT id FROM processed_webhooks WHERE id=?',[$wid]));}
    finally{$db->execute('DROP TRIGGER '.$t.' ON products');$db->execute('DROP FUNCTION '.$f.'()');}
    callback($o,'FAILED',$wid);same(10,stock($product));errorCode('ALREADY_PROCESSED',fn()=>callback($o,'FAILED',$wid));
});
test('provider response cannot steal an already correlated foreign transaction',function(){
    [$first,$one]=paymentFixture();paymentServices()[3]->confirm($first,$one['paymentId']);[$u,$two]=paymentFixture();[$db,$repo,,$p]=paymentServices();
    $db->execute('UPDATE payment_test_scenarios SET authorize_body=? WHERE payment_id=?',[json_encode(['status'=>'AUTHORIZED','paymentUrl'=>'https://example.test/pay','transactionId'=>'txn-'.$one['paymentId']]),$two['paymentId']]);
    $p->confirm($u,$two['paymentId']);same('PENDING',$repo->payment($two['paymentId'])['status']);same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$two['paymentId']])['state']);errorCode('INVALID_TRANSACTION_ID',fn()=>callback($two,'CAPTURED','foreign-'.$two['paymentId'],'txn-'.$one['paymentId']));
});
test('repeated concurrent callback id returns ALREADY_PROCESSED and stock restores once',function(){
    [$u,$o,$product]=paymentFixture('SBP','PENDING');paymentServices()[3]->confirm($u,$o['paymentId']);$wid=Ids::uuid();
    $out=paymentRace([function()use($o,$wid){callback($o,'FAILED',$wid);return true;},function()use($o,$wid){callback($o,'FAILED',$wid);return true;}]);same(1,count(array_filter($out,fn($r)=>isset($r['ok']))));same(1,count(array_filter($out,fn($r)=>($r['error']??null)==='ALREADY_PROCESSED')));same(10,stock($product));
});
test('manual reversal reconciliation requires original transaction and preserves evidence',function(){
    [$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['reverse_code'=>202]);[$db,$repo,$c,$p]=paymentServices();$p->confirm($u,$o['paymentId']);callback($o,'CAPTURED');errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$p->refund($o['paymentId']));
    $op=$db->one("SELECT id FROM payment_operations WHERE payment_id=? AND kind='refund'",[$o['paymentId']]);$operations=new PaymentOperations($db,$repo,$c);
    errorCode('INVALID_TRANSACTION_ID',fn()=>$operations->reconcile($op['id'],'REVERSED','foreign','ticket PAY-456'));
    $operations->reconcile($op['id'],'REVERSED','txn-'.$o['paymentId'],'provider settlement verified ticket PAY-456');same('REFUNDED',$repo->payment($o['paymentId'])['status']);same(10,stock($product));same(1,journal($o['paymentId'])[1]['attempts']);
    $result=json_decode($db->one('SELECT result FROM payment_operations WHERE id=?',[$op['id']])['result'],true);same('provider settlement verified ticket PAY-456',$result['evidence']);errorCode('INVALID_PAYMENT_STATUS',fn()=>$operations->reconcile($op['id'],'REVERSED','txn-'.$o['paymentId'],'duplicate ticket'));
});
test('FAILED after REFUNDED preserves historical INVALID_PAYMENT_STATUS contract without dedup insert',function(){
    [$u,$o]=paymentFixture();[$db,,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);callback($o,'CAPTURED');$p->refund($o['paymentId']);$wid=Ids::uuid();errorCode('INVALID_PAYMENT_STATUS',fn()=>callback($o,'FAILED',$wid));same(null,$db->one('SELECT id FROM processed_webhooks WHERE id=?',[$wid]));
});
test('FAILED after REFUNDED with omitted transaction keeps historical status error precedence',function(){
    [$u,$o]=paymentFixture();[, ,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);callback($o,'CAPTURED');$p->refund($o['paymentId']);errorCode('INVALID_PAYMENT_STATUS',fn()=>$p->webhook(['paymentId'=>$o['paymentId'],'status'=>'FAILED'],Ids::uuid()));
});
test('confirm after cancellation intent cannot expose a live payment redirect',function(){
    [$u,$o]=paymentFixture('CARD','AUTHORIZED',['reverse_code'=>202]);[, $repo,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$p->cancel($u,$o['id']));same('AUTHORIZED',$repo->payment($o['paymentId'])['status']);errorCode('INVALID_PAYMENT_STATUS',fn()=>$p->confirm($u,$o['paymentId']));
});
test('unsent committed authorize is aborted locally if cancellation wins before dispatch',function(){
    [$u,$o,$product]=paymentFixture();[$db,$repo,,$p]=paymentServices();[$t,$f]=paymentFailureTrigger($db,$o['paymentId'],'SENT');
    try{try{$p->confirm($u,$o['paymentId']);throw new RuntimeException('expected claim failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}}finally{dropPaymentTrigger($db,$t,$f);}
    $p->cancel($u,$o['id']);recoverPayments();same('CANCELLED',$repo->payment($o['paymentId'])['status']);same('ABORTED',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$o['paymentId']])['state']);same([],journal($o['paymentId']));same(10,stock($product));
});
test('expired lease fences a returning result and active lease cannot be manually reconciled',function(){
    [$u,$o]=paymentFixture('CARD','AUTHORIZED',['delay_ms'=>300]);$worker=paymentWorker(fn()=>paymentServices()[3]->confirm($u,$o['paymentId']));waitPaymentJournal($o['paymentId']);
    [$db,$repo,$c]=paymentServices();$op=$db->one('SELECT id FROM payment_operations WHERE payment_id=?',[$o['paymentId']]);errorCode('INVALID_PAYMENT_STATUS',fn()=>(new PaymentOperations($db,$repo,$c))->reconcile($op['id'],'AUTHORIZED','txn-'.$o['paymentId'],'operator cannot override a live sender'));
    $db->execute("UPDATE payment_operations SET lease_until=now()-interval '1 second' WHERE id=?",[$op['id']]);recoverPayments();finishPaymentWorker($worker);
    same('PENDING',$repo->payment($o['paymentId'])['status']);same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE id=?',[$op['id']])['state']);same(1,journal($o['paymentId'])[0]['attempts']);
});
test('late capture behind UNKNOWN void is retained for manual review without competing refund',function(){
    [$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['reverse_code'=>202]);[$db,$repo,,$p]=paymentServices();$p->confirm($u,$o['paymentId']);errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$p->cancel($u,$o['id']));callback($o,'CAPTURED');$p->refund($o['paymentId']);$report=recoverPayments();
    same('CAPTURED',$repo->payment($o['paymentId'])['status']);same(['authorize','void'],array_column(journal($o['paymentId']),'operation'));same(10,stock($product));truth(count(array_filter($report['review'],fn($r)=>$r['payment_id']===$o['paymentId'] && $r['kind']==='void'))===1);
});
test('gateway exact maximum cents and repeated key produce one provider journal effect',function(){
    [, $o]=paymentFixture();$payment=['id'=>$o['paymentId'],'order_id'=>$o['id'],'amount'=>'9999999999.99','method'=>'CARD'];$gateway=new PaymentGateway();$first=$gateway->call('authorize',$payment);same($first,$gateway->call('authorize',$payment));$rows=journal($o['paymentId']);same(1,count($rows));same(2,$rows[0]['attempts']);same(999999999999,$rows[0]['amount_cents']);
});
test('recovery does not starve unsent work behind a large manual review backlog',function(){
    [$u,$o]=paymentFixture();[$db,$repo,,$p]=paymentServices();[$t,$f]=paymentFailureTrigger($db,$o['paymentId'],'SENT');
    try{try{$p->confirm($u,$o['paymentId']);throw new RuntimeException('expected claim failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}}finally{dropPaymentTrigger($db,$t,$f);}
    $db->transaction(function()use($db,$o){
        $db->execute("CREATE TEMP TABLE payment_backlog ON COMMIT DROP AS SELECT ('00000000-0000-4000-8000-'||lpad(g::text,12,'0'))::uuid AS payment,uuid_generate_v4() AS order_id FROM generate_series(1,1001) g");
        $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method,comment) SELECT b.order_id,o.user_id,'CANCELLED',o.total_amount,o.delivery_address_id,o.delivery_address,'CARD','payment-recovery-backlog-test' FROM payment_backlog b CROSS JOIN orders o WHERE o.id=?",[$o['id']]);
        $db->execute("INSERT INTO payments(id,order_id,user_id,method,status,amount,cancel_requested_at) SELECT b.payment,b.order_id,o.user_id,'CARD','PENDING',o.total_amount,now() FROM payment_backlog b JOIN orders o ON o.id=b.order_id");
        $db->execute('UPDATE orders o SET payment_id=b.payment FROM payment_backlog b WHERE o.id=b.order_id');
        $db->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state,sent_at) SELECT uuid_generate_v4(),b.payment,'authorize',b.payment::text||':authorize','UNKNOWN',now() FROM payment_backlog b");
    });
    try{recoverPayments();same('AUTHORIZED',$repo->payment($o['paymentId'])['status']);same(1,count(journal($o['paymentId'])));}
    finally{$db->transaction(function()use($db){$db->execute("UPDATE orders SET payment_id=NULL WHERE comment='payment-recovery-backlog-test'");$db->execute("DELETE FROM payment_operations WHERE payment_id IN (SELECT p.id FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.comment='payment-recovery-backlog-test')");$db->execute("DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE comment='payment-recovery-backlog-test')");$db->execute("DELETE FROM orders WHERE comment='payment-recovery-backlog-test'");});}
});
foreach(['authorize','void','refund'] as $kind){
    test("executed $kind response timeout remains UNKNOWN across restart without replay",function()use($kind){
        [$u,$o,$product]=paymentFixture();[$db,$repo,,$p]=paymentServices();
        if($kind!=='authorize'){$p->confirm($u,$o['paymentId']);if($kind==='refund'){callback($o,'CAPTURED');}}
        $db->execute('UPDATE payment_test_scenarios SET delay_ms=11000 WHERE payment_id=?',[$o['paymentId']]);$start=microtime(true);
        errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$kind==='authorize'?$p->confirm($u,$o['paymentId']):$p->$kind($o['paymentId']));truth(microtime(true)-$start<10.8);
        same($kind==='authorize'?'PENDING':($kind==='void'?'AUTHORIZED':'CAPTURED'),$repo->payment($o['paymentId'])['status']);same(8,stock($product));recoverPayments();
        $row=$db->one('SELECT state FROM payment_operations WHERE payment_id=? AND kind=?',[$o['paymentId'],$kind]);same('UNKNOWN',$row['state']);$entries=journal($o['paymentId']);same(1,$entries[count($entries)-1]['attempts']);same(0,$entries[count($entries)-1]['in_transaction']);
        // Single-worker fixture may still be completing its response after our deadline.
        usleep(1200000);
    });
}
foreach(['CARD','SBP'] as $method){
    foreach(['PAID','COMPLETED'] as $orderStatus){
        test("R2 $method blocked admin refund survives restart and reconciliation for $orderStatus order",function()use($method,$orderStatus){
            [$db,$schema]=paymentLegacySchema();
            try{
                [$u,$o,$product]=paymentFixture($method,'AUTHORIZED',[],$db);$id=$o['paymentId'];
                $db->execute("UPDATE payments SET status='CAPTURED' WHERE id=?",[$id]);$db->execute('UPDATE orders SET status=? WHERE id=?',[$orderStatus,$o['id']]);applyPaymentMigration($db);
                [, $repo,,$payments]=paymentServices($db);$authorization=$db->one("SELECT id,state FROM payment_operations WHERE payment_id=? AND kind='authorize'",[$id]);same('UNKNOWN',$authorization['state']);
                same('CAPTURED',$payments->refund($id)['status']);same([],journal($id));
                // The caller disappears. All continuation uses a fresh connection.
                $restart=paymentSchemaConnection($schema);[, $repo,$commerce]=paymentServices($restart);$operations=new PaymentOperations($restart,$repo,$commerce);$operations->recover();same([],journal($id));
                $operations->reconcile($authorization['id'],'CAPTURED','txn-'.$id,'PAY-202: independent capture evidence');
                $restart=paymentSchemaConnection($schema);[, $repo,$commerce]=paymentServices($restart);$operations=new PaymentOperations($restart,$repo,$commerce);$operations->recover();
                same(['refund'],array_column(journal($id),'operation'));same(1,journal($id)[0]['attempts']);same(2046,journal($id)[0]['amount_cents']);same(0,journal($id)[0]['in_transaction']);same('REFUNDED',$repo->payment($id)['status']);
                truth(($repo->payment($id)['refund_requested_at']??null)!==null);same($orderStatus==='COMPLETED'?'COMPLETED':'CANCELLED',$repo->order($o['id'])['status']);same($orderStatus==='COMPLETED'?8:10,$restart->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);
                $operations->recover();same(1,journal($id)[0]['attempts']);
            }finally{dropPaymentLegacySchema($db,$schema);}
        });
    }
}
foreach(['customer cancellation','admin void'] as $origin){
    test("R3 capture before send supersedes READY void from $origin without any void POST",function()use($origin){
        [$db,$schema]=paymentLegacySchema();
        try{
            applyPaymentMigration($db);[$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['void_code'=>409],$db);$id=$o['paymentId'];[, $repo,$commerce,$payments]=paymentServices($db);$payments->confirm($u,$id);
            [$trigger,$function]=paymentFailureTrigger($db,$id,'SENT');
            try{try{$origin==='admin void'?$payments->void($id):$payments->cancel($u,$o['id']);throw new RuntimeException('expected void claim failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}}
            finally{dropPaymentTrigger($db,$trigger,$function);}
            same('READY',$db->one("SELECT state FROM payment_operations WHERE payment_id=? AND kind='void'",[$id])['state']);same(['authorize'],array_column(journal($id),'operation'));
            $payments->webhook(['paymentId'=>$id,'status'=>'CAPTURED','transactionId'=>'txn-'.$id],Ids::uuid());
            $restart=paymentSchemaConnection($schema);[, $repo,$commerce]=paymentServices($restart);$operations=new PaymentOperations($restart,$repo,$commerce);$operations->recover();
            same(['authorize','refund'],array_column(journal($id),'operation'));foreach(journal($id) as $entry){same(1,$entry['attempts']);}
            same('ABORTED',$restart->one("SELECT state FROM payment_operations WHERE payment_id=? AND kind='void'",[$id])['state']);same('REFUNDED',$repo->payment($id)['status']);same(10,$restart->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);
            $operations->recover();same(1,journal($id)[1]['attempts']);
        }finally{dropPaymentLegacySchema($db,$schema);}
    });
}
foreach([false,true] as $reconcile){
    test('R4 late response is durable after '.($reconcile?'operator reconciliation without overwriting evidence':'recovery fenced the sender'),function()use($reconcile){
        [$db,$schema]=paymentLegacySchema();$worker=null;$id=null;
        try{
            applyPaymentMigration($db);[$u,$o]=paymentFixture('CARD','AUTHORIZED',['response_released'=>false],$db);$id=$o['paymentId'];$db=null;
            $worker=paymentWorker(function()use($u,$id,$schema){return paymentServices(paymentSchemaConnection($schema))[3]->confirm($u,$id);});waitPaymentJournal($id);
            $db=paymentSchemaConnection($schema);[, $repo,$commerce,$payments]=paymentServices($db);$operations=new PaymentOperations($db,$repo,$commerce);$op=$db->one('SELECT * FROM payment_operations WHERE payment_id=?',[$id]);$token=$op['lease_token'];same('SENT',$op['state']);
            $wid=Ids::uuid();errorCode('INVALID_TRANSACTION_ID',fn()=>$payments->webhook(['paymentId'=>$id,'status'=>'CAPTURED','transactionId'=>'txn-'.$id],$wid));
            $db->execute("UPDATE payment_operations SET lease_until=now()-interval '1 second' WHERE id=?",[$op['id']]);$operations->recover();same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE id=?',[$op['id']])['state']);
            if($reconcile){$operations->reconcile($op['id'],'CAPTURED','txn-'.$id,'PAY-404: independently verified capture; sender is response-blocked');}
            $authority=$db->one('SELECT state,result,last_error FROM payment_operations WHERE id=?',[$op['id']]);$financialState=$repo->payment($id);$observedAfter=microtime(true);
            releasePaymentResponse($id);finishPaymentWorker($worker);$worker=null;
            same($authority,$db->one('SELECT state,result,last_error FROM payment_operations WHERE id=?',[$op['id']]));same($financialState,$repo->payment($id));same(1,journal($id)[0]['attempts']);
            $exists=$db->one("SELECT to_regclass('payment_operation_observations') IS NOT NULL AS present")['present'];
            $observation=$exists?$db->one('SELECT * FROM payment_operation_observations WHERE operation_id=?',[$op['id']]):null;
            truth($observation!==null);same($token,$observation['lease_token']);$result=json_decode($observation['result'],true,32,JSON_THROW_ON_ERROR);same('txn-'.$id,$result['transactionId']);same('AUTHORIZED',$result['status']);truth(Ids::epoch($observation['observed_at'])>=$observedAfter-0.1);truth(Ids::epoch($observation['observed_at'])<=microtime(true));
            if($reconcile){same('PAY-404: independently verified capture; sender is response-blocked',json_decode($authority['result'],true)['evidence']);same('CAPTURED',$repo->payment($id)['status']);}
            else{same('PENDING',$repo->payment($id)['status']);same(null,$repo->payment($id)['transaction_id']);truth($db->one('SELECT resolved_at FROM payment_callback_inbox WHERE id=?',[$wid])['resolved_at']===null);}
        }finally{
            if($worker!==null){releasePaymentResponse($id);finishPaymentWorker($worker);}
            dropPaymentLegacySchema(paymentSchemaConnection($schema),$schema);
        }
    });
}
foreach(['CARD','SBP'] as $method){
    test("F1 $method verified no-effect void releases one refund without rewriting the send audit",function()use($method){
        [$db,$schema]=paymentLegacySchema();
        try{
            applyPaymentMigration($db);[$u,$o,$product]=paymentFixture($method,'AUTHORIZED',['void_code'=>409],$db);$id=$o['paymentId'];
            [, $repo,$commerce,$payments]=paymentServices($db);$payments->confirm($u,$id);
            errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$payments->cancel($u,$o['id']));
            $payments->webhook(['paymentId'=>$id,'status'=>'CAPTURED','transactionId'=>'txn-'.$id],Ids::uuid());
            $payments->refund($id);$ops=new PaymentOperations($db,$repo,$commerce);$ops->recover();
            $void=$db->one("SELECT * FROM payment_operations WHERE payment_id=? AND kind='void'",[$id]);
            same('UNKNOWN',$void['state']);same(['authorize','void'],array_column(journal($id),'operation'));same('CAPTURED',$repo->payment($id)['status']);
            same(null,$db->one("SELECT id FROM payment_operations WHERE payment_id=? AND kind='refund'",[$id]));
            $observations=$db->all('SELECT * FROM payment_operation_observations WHERE operation_id IN (SELECT id FROM payment_operations WHERE payment_id=?) ORDER BY id',[$id]);same(1,count($observations));
            errorCode('BAD_REQUEST',fn()=>$ops->reconcile($void['id'],'NO_EFFECT','txn-'.$id,''));
            errorCode('INVALID_TRANSACTION_ID',fn()=>$ops->reconcile($void['id'],'NO_EFFECT','foreign','PAY-501: final no effect; sender terminated'));
            same($void,$db->one('SELECT * FROM payment_operations WHERE id=?',[$void['id']]));
            [$trigger,$function]=paymentFailureTrigger($db,$id,'READY');
            try{try{$ops->reconcile($void['id'],'NO_EFFECT','txn-'.$id,'PAY-501: final no effect; sender terminated');throw new RuntimeException('expected refund intent commit failure');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected payment commit'));}}
            finally{dropPaymentTrigger($db,$trigger,$function);}
            same($void,$db->one('SELECT * FROM payment_operations WHERE id=?',[$void['id']]));same(null,$db->one("SELECT id FROM payment_operations WHERE payment_id=? AND kind='refund'",[$id]));
            $env=getenv();$env['PGOPTIONS']='-c search_path='.$schema;
            $cli=proc_open([PHP_BINARY,'/app/yii','maintenance/payment-reconcile',$void['id'],'NO_EFFECT','txn-'.$id,'PAY-501: provider final no effect verified; sender terminated'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
            stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);foreach($pipes as $pipe){fclose($pipe);}same(0,proc_close($cli));same('',$errors);
            same('CAPTURED',$repo->payment($id)['status']);same(['authorize','void'],array_column(journal($id),'operation'));
            $closed=$db->one('SELECT * FROM payment_operations WHERE id=?',[$void['id']]);same('ABORTED',$closed['state']);
            foreach(['sent_at','lease_token','lease_until','operation_key'] as $field){same($void[$field],$closed[$field]);}
            same($observations,$db->all('SELECT * FROM payment_operation_observations WHERE operation_id IN (SELECT id FROM payment_operations WHERE payment_id=?) ORDER BY id',[$id]));
            $result=json_decode($closed['result'],true,32,JSON_THROW_ON_ERROR);same('NO_EFFECT',$result['outcome']);same('txn-'.$id,$result['transactionId']);
            same('PAY-501: provider final no effect verified; sender terminated',$result['evidence']);truth(isset($result['reconciledAt']));
            same('READY',$db->one("SELECT state FROM payment_operations WHERE payment_id=? AND kind='refund'",[$id])['state']);
            errorCode('INVALID_PAYMENT_STATUS',fn()=>$ops->reconcile($void['id'],'NO_EFFECT','txn-'.$id,'PAY-502: repeat'));
            $restart=paymentSchemaConnection($schema);[, $repo,$commerce]=paymentServices($restart);$fresh=new PaymentOperations($restart,$repo,$commerce);
            $fresh->recover();$fresh->recover();same(['authorize','void','refund'],array_column(journal($id),'operation'));
            foreach(journal($id) as $entry){same(1,$entry['attempts']);same(0,$entry['in_transaction']);}
            same('REFUNDED',$repo->payment($id)['status']);same(10,$restart->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);
            same($closed,$restart->one('SELECT * FROM payment_operations WHERE id=?',[$void['id']]));
        }finally{dropPaymentLegacySchema($db,$schema);}
    });
}
foreach([false,true] as $completed){
    test('F1 no-effect refund stays manual without replay for '.($completed?'completed':'paid').' purchase',function()use($completed){
        [$db,$schema]=paymentLegacySchema();
        try{
            applyPaymentMigration($db);[$u,$o,$product]=paymentFixture('CARD','AUTHORIZED',['reverse_code'=>409],$db);$id=$o['paymentId'];
            [, $repo,$commerce,$payments]=paymentServices($db);$payments->confirm($u,$id);
            $payments->webhook(['paymentId'=>$id,'status'=>'CAPTURED','transactionId'=>'txn-'.$id],Ids::uuid());
            if($completed){foreach(['ASSEMBLING','DELIVERING','COMPLETED'] as $status){$commerce->transition($o['id'],['status'=>$status]);}}
            errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$payments->refund($id));$ops=new PaymentOperations($db,$repo,$commerce);
            $refund=$db->one("SELECT * FROM payment_operations WHERE payment_id=? AND kind='refund'",[$id]);
            $ops->reconcile($refund['id'],'NO_EFFECT','txn-'.$id,'PAY-503: final no refund effect verified; sender terminated');
            $report=$ops->recover();$ops->recover();$payments->refund($id);
            same('ABORTED',$db->one('SELECT state FROM payment_operations WHERE id=?',[$refund['id']])['state']);
            same('CAPTURED',$repo->payment($id)['status']);same($completed?'COMPLETED':'PAID',$repo->order($o['id'])['status']);
            same(8,$db->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);same(['authorize','refund'],array_column(journal($id),'operation'));same(1,journal($id)[1]['attempts']);
            same([$refund['id']],array_column($report['review'],'id'));
            errorCode('INVALID_PAYMENT_STATUS',fn()=>$ops->reconcile($refund['id'],'REVERSED','txn-'.$id,'PAY-504: cannot overwrite no-effect evidence'));
        }finally{dropPaymentLegacySchema($db,$schema);}
    });
}
test('F1 NO_EFFECT is distinct from an authorization FAILED outcome',function(){
    [$u,$o]=paymentFixture('CARD','AUTHORIZED',['authorize_code'=>409]);[$db,$repo,$commerce,$payments]=paymentServices();
    errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>$payments->confirm($u,$o['paymentId']));
    $op=$db->one('SELECT * FROM payment_operations WHERE payment_id=?',[$o['paymentId']]);$ops=new PaymentOperations($db,$repo,$commerce);
    errorCode('BAD_REQUEST',fn()=>$ops->reconcile($op['id'],'NO_EFFECT','txn-'.$o['paymentId'],'PAY-505: reversal disposition is not authorization failure'));
    same($op,$db->one('SELECT * FROM payment_operations WHERE id=?',[$op['id']]));
});
test('F3 direct application expiry retains synchronous dispatch by default',function(){
    [$db,$schema]=paymentLegacySchema();
    try{
        applyPaymentMigration($db);[$user,$order,$product]=paymentFixture('CARD','AUTHORIZED',[],$db);[, $repo,$commerce,$payments]=paymentServices($db);$payments->confirm($user,$order['paymentId']);
        $db->execute("UPDATE orders SET created_at=now()-interval '1 day' WHERE id=?",[$order['id']]);$db->execute("UPDATE payments SET updated_at=now()-interval '1 day' WHERE id=?",[$order['paymentId']]);
        $ops=new PaymentOperations($db,$repo,$commerce);truth($ops->expire($order['id'],900,7200));same(false,$ops->expire($order['id'],900,7200));
        same(['authorize','void'],array_column(journal($order['paymentId']),'operation'));same('CANCELLED',$repo->payment($order['paymentId'])['status']);same(10,$db->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);
    }finally{dropPaymentLegacySchema($db,$schema);}
});
$filter=Env::get('PAYMENT_TEST_FILTER');
if($filter!==''){$tests=array_filter($tests,fn($name)=>str_contains($name,$filter),ARRAY_FILTER_USE_KEY);if(!$tests){throw new RuntimeException('No matching payment tests');}}
runTests();
