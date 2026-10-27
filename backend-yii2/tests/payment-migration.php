<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/payment-fixture.php';
require __DIR__.'/payment-baseline-confirm.php';
use Shop\infrastructure\{Database,Env};
use Shop\application\{PaymentBaselineConfirm,PaymentOperations};
use Shop\domain\Ids;
paymentTestSchema();

/** Real 001-011 predecessor schema, isolated transaction/schema in payment_fixes. */
function paymentMigrationWork(Closure $work): void {
    $db=new Database(Env::get('DATABASE_URL'));$schema='payment_upgrade_'.str_replace('-','',Ids::uuid());$dir=Env::get('PAYMENT_MIGRATION_DIR','/migrations');
    $db->pdo()->beginTransaction();
    try{
        $db->execute('CREATE SCHEMA '.$schema);$db->execute('SET LOCAL search_path TO '.$schema);
        // No public table can be resolved through this search_path. Only the existing
        // uuid extension is delegated; it is not replaced or installed in public.
        $db->execute('CREATE FUNCTION uuid_generate_v4() RETURNS uuid LANGUAGE sql AS $$ SELECT public.uuid_generate_v4() $$');
        for($n=1;$n<=11;$n++){$files=glob($dir.'/'.sprintf('%03d',$n).'_*.up.sql');same(1,count($files));$db->pdo()->exec(file_get_contents($files[0]));}
        $work($db,$dir);
    }finally{$db->pdo()->rollBack();}
}
function legacyPayment(Database $db,string $paymentStatus,?string $transaction=null,?string $url=null,string $method='CARD',string $orderStatus='CREATED'): array {
    $u=Ids::uuid();$a=Ids::uuid();$o=Ids::uuid();$p=Ids::uuid();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Legacy','+79991234567')",[$u,$u.'@payment-upgrade.test']);
    $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'X','Y','1')",[$a,$u]);
    $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method) VALUES(?,?,?,'20.46',?,'X',?)",[$o,$u,$orderStatus,$a,$method]);
    $db->execute("INSERT INTO payments(id,order_id,user_id,method,status,amount,transaction_id,gateway_url) VALUES(?,?,?,?,?,'20.46',?,?)",[$p,$o,$u,$method,$paymentStatus,$transaction,$url]);
    $db->execute('UPDATE orders SET payment_id=? WHERE id=?',[$p,$o]);return [$o,$p];
}
test('012 upgrades real clean predecessor schema and enforces one live operation and stable key',function(){
    paymentMigrationWork(function($db,$dir){
        $db->pdo()->exec(file_get_contents($dir.'/012_payment_operations.up.sql'));same(0,$db->one('SELECT count(*) AS n FROM payment_operations')['n']);
        [, $p]=legacyPayment($db,'PENDING');$db->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state) VALUES(?,?,'authorize',?,'READY')",[Ids::uuid(),$p,$p.':authorize']);
        $db->execute('SAVEPOINT competing_effect');
        try{$db->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state) VALUES(?,?,'void',?,'READY')",[Ids::uuid(),$p,$p.':void']);throw new RuntimeException('expected live operation conflict');}
        catch(PDOException $e){same('23505',$e->getCode());$db->execute('ROLLBACK TO SAVEPOINT competing_effect');}
        same(1,$db->one('SELECT count(*) AS n FROM payment_operations')['n']);
    });
});
test('012 populated upgrade quarantines ambiguous legacy initiation without inventing transactions or restocking completed purchases',function(){
    paymentMigrationWork(function($db,$dir){
        [, $known]=legacyPayment($db,'AUTHORIZED','legacy-known');[, $ambiguous]=legacyPayment($db,'PENDING');[, $pending]=legacyPayment($db,'PENDING',null,'https://payments.example.test/legacy');
        [$completed,$duplicate]=legacyPayment($db,'CAPTURED','legacy-duplicate',null,'CARD','COMPLETED');legacyPayment($db,'CAPTURED','legacy-duplicate');legacyPayment($db,'AUTHORIZED','cash-test',null,'ON_DELIVERY');
        legacyPayment($db,'FAILED','legacy-failed',null,'CARD','CANCELLED');[$cancelled]=legacyPayment($db,'CANCELLED',null,null,'CARD','CANCELLED');
        $db->pdo()->exec(file_get_contents($dir.'/012_payment_operations.up.sql'));
        same(7,$db->one('SELECT count(*) AS n FROM payment_operations')['n']);same(5,$db->one("SELECT count(*) AS n FROM payment_operations WHERE state='UNKNOWN'")['n']);same(2,$db->one('SELECT count(*) AS n FROM payment_transaction_links')['n']);
        same('SUCCEEDED',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$known])['state']);same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$ambiguous])['state']);same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$pending])['state']);same('UNKNOWN',$db->one('SELECT state FROM payment_operations WHERE payment_id=?',[$duplicate])['state']);
        truth($db->one('SELECT stock_restored_at FROM orders WHERE id=?',[$cancelled])['stock_restored_at']!==null);same(null,$db->one('SELECT stock_restored_at FROM orders WHERE id=?',[$completed])['stock_restored_at']);same('20.46',$db->one('SELECT amount FROM payments WHERE id=?',[$known])['amount']);
    });
});
foreach(['CARD','SBP'] as $method){
    test("R1 $method baseline executed lost authorization is never replayed or cancelled as unsent after upgrade",function()use($method){
        [$db,$schema]=paymentLegacySchema();
        try{
            [$u,$o,$product]=paymentFixture($method,'AUTHORIZED',['lose_response'=>true],$db);[, $repo,$commerce]=paymentServices($db);$id=$o['paymentId'];
            errorCode('PAYMENT_GATEWAY_UNAVAILABLE',fn()=>(new PaymentBaselineConfirm($db,$repo,$commerce))->confirm($u,$id));
            same(['status'=>'PENDING','gateway_url'=>null,'transaction_id'=>null],$db->one('SELECT status,gateway_url,transaction_id FROM payments WHERE id=?',[$id]));same(1,journal($id)[0]['attempts']);same(1,journal($id)[0]['in_transaction']);
            paymentDb()->execute('UPDATE payment_test_scenarios SET lose_response=false WHERE payment_id=?',[$id]);applyPaymentMigration($db);
            $restarted=paymentSchemaConnection($schema);[, $repo,$commerce,$payments]=paymentServices($restarted);$operations=new PaymentOperations($restarted,$repo,$commerce);
            $payments->confirm($u,$id);same(1,journal($id)[0]['attempts']);
            same('UNKNOWN',$restarted->one('SELECT state FROM payment_operations WHERE payment_id=?',[$id])['state']);
            $payments->cancel($u,$o['id']);$operations->recover();same('PENDING',$repo->payment($id)['status']);same('CANCELLED',$repo->order($o['id'])['status']);same(10,$restarted->one('SELECT stock FROM products WHERE id=?',[$product])['stock']);
            $wid=Ids::uuid();errorCode('INVALID_TRANSACTION_ID',fn()=>$payments->webhook(['paymentId'=>$id,'status'=>'CAPTURED','transactionId'=>'txn-'.$id],$wid));truth($restarted->one('SELECT id FROM payment_callback_inbox WHERE id=? AND resolved_at IS NULL',[$wid])!==null);same(1,journal($id)[0]['attempts']);
            // A new post-upgrade payment is genuinely unsent and remains automatic.
            [$newUser,$newOrder]=paymentFixture($method,'AUTHORIZED',[],$restarted);$payments->confirm($newUser,$newOrder['paymentId']);$payments->cancel($newUser,$newOrder['id']);same(['authorize','void'],array_column(journal($newOrder['paymentId']),'operation'));foreach(journal($newOrder['paymentId']) as $entry){same(1,$entry['attempts']);}
        }finally{dropPaymentLegacySchema($db,$schema);}
    });
}
test('R1 all pre-existing external PENDING payments are quarantined even with a saved transaction',function(){
    paymentMigrationWork(function($db,$dir){
        [, $card]=legacyPayment($db,'PENDING');[, $sbp]=legacyPayment($db,'PENDING',null,null,'SBP');[, $known]=legacyPayment($db,'PENDING','legacy-pending-known','https://payments.example.test/legacy');[, $cash]=legacyPayment($db,'PENDING',null,null,'ON_DELIVERY');
        $db->pdo()->exec(file_get_contents($dir.'/012_payment_operations.up.sql'));
        same(3,$db->one("SELECT count(*) AS n FROM payment_operations WHERE state='UNKNOWN'")['n']);
        foreach([$card,$sbp,$known] as $id){$op=$db->one('SELECT * FROM payment_operations WHERE payment_id=?',[$id]);same('UNKNOWN',$op['state']);truth($op['sent_at']!==null);same('20.46',$db->one('SELECT amount FROM payments WHERE id=?',[$id])['amount']);}
        same(null,$db->one('SELECT id FROM payment_operations WHERE payment_id=?',[$cash]));
    });
});
function paymentAuditSnapshot(Database $db): array {
    $snapshot=[];
    foreach(['orders'=>'id','payments'=>'id','payment_operations'=>'id','payment_transaction_links'=>'transaction_id','payment_callback_inbox'=>'id','payment_operation_observations'=>'id'] as $table=>$key){
        if($db->one('SELECT to_regclass(?) IS NOT NULL AS present',[$table])['present']){$snapshot[$table]=$db->all('SELECT * FROM '.$table.' ORDER BY '.$key);}
    }
    return $snapshot;
}
foreach(['EMPTY','READY','SENT','UNKNOWN','EARLY_CAPTURE','CANCELLATION','INITIATED_PENDING','DRAINED_REFUND'] as $case){
    test("R5 roll-forward-only down refuses $case and preserves all payment audit",function()use($case){
        paymentMigrationWork(function($db,$dir)use($case){
            $db->pdo()->exec(file_get_contents($dir.'/012_payment_operations.up.sql'));
            if($case!=='EMPTY'){
                $status=$case==='CANCELLATION'?'AUTHORIZED':($case==='DRAINED_REFUND'?'REFUNDED':'PENDING');
                [$order,$payment]=legacyPayment($db,$status,'r5-'.Ids::uuid(),'https://payments.example.test/pending','CARD',$case==='CANCELLATION'||$case==='DRAINED_REFUND'?'CANCELLED':'CREATED');
                $p=$db->one('SELECT * FROM payments WHERE id=?',[$payment]);$id=Ids::uuid();$token=Ids::uuid();
                $state=in_array($case,['READY','SENT','UNKNOWN'],true)?$case:($case==='EARLY_CAPTURE'?'SENT':'SUCCEEDED');
                $db->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state,lease_token,lease_until,sent_at,result) VALUES(?,?,'authorize',?,?,?,clock_timestamp()+interval '30 seconds',clock_timestamp(),?::jsonb)",[$id,$payment,$payment.':authorize',$state,$token,json_encode(['status'=>'PENDING','transactionId'=>$p['transaction_id']])]);
                $db->execute('INSERT INTO payment_transaction_links(transaction_id,payment_id) VALUES(?,?)',[$p['transaction_id'],$payment]);
                if($case==='EARLY_CAPTURE'){$db->execute("INSERT INTO payment_callback_inbox(id,operation_id,payment_id,transaction_id,status) VALUES(?,?,?,?,'CAPTURED')",[Ids::uuid(),$id,$payment,$p['transaction_id']]);}
                if($case==='CANCELLATION'){$db->execute('UPDATE payments SET cancel_requested_at=now() WHERE id=?',[$payment]);$db->execute('UPDATE orders SET stock_restored_at=now() WHERE id=?',[$order]);}
                if($case==='DRAINED_REFUND'){$db->execute('UPDATE payments SET refund_requested_at=now() WHERE id=?',[$payment]);$db->execute("INSERT INTO payment_operations(id,payment_id,kind,operation_key,state,result) VALUES(?,?,'refund',?,'SUCCEEDED',?::jsonb)",[Ids::uuid(),$payment,$payment.':refund',json_encode(['evidence'=>'PAY-505: settled refund'])]);}
                if($db->one("SELECT to_regclass('payment_operation_observations') IS NOT NULL AS present")['present']){
                    $db->execute('INSERT INTO payment_operation_observations(operation_id,lease_token,result) VALUES(?,?,?::jsonb)',[$id,$token,json_encode(['status'=>'PENDING','transactionId'=>$p['transaction_id']])]);
                }
            }
            $before=paymentAuditSnapshot($db);$db->execute('SAVEPOINT refuse_downgrade');$failure=null;
            try{$db->pdo()->exec(file_get_contents($dir.'/012_payment_operations.down.sql'));}catch(PDOException $e){$failure=$e;}
            $db->execute('ROLLBACK TO SAVEPOINT refuse_downgrade');
            truth($failure!==null);same('0A000',$failure->getCode());truth(str_contains($failure->getMessage(),'roll-forward-only'));same($before,paymentAuditSnapshot($db));
        });
    });
}
$filter=Env::get('PAYMENT_TEST_FILTER');
if($filter!==''){$tests=array_filter($tests,fn($name)=>str_contains($name,$filter),ARRAY_FILTER_USE_KEY);if(!$tests){throw new RuntimeException('No matching payment migration tests');}}
runTests();
