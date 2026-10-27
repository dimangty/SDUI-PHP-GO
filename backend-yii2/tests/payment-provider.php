<?php
// Disposable, database-journaled provider. Never loaded by production.
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Shop\infrastructure\{Database,Env,HttpClient};
$db=new Database(Env::get('DATABASE_URL'));
$body=json_decode(file_get_contents('php://input'),true);
$id=$body['paymentId']??'';$key=$_SERVER['HTTP_IDEMPOTENCY_KEY']??'';
$operation=basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer payment-fixture-key' || !is_int($body['amountCents']??null) || !in_array($operation,['authorize','void','refund'],true) || $key!==$id.':'.$operation){http_response_code(400);exit;}
$scenario=$db->one('SELECT * FROM payment_test_scenarios WHERE payment_id=?',[$id]);
if(!$scenario){http_response_code(404);exit;}
$inTransaction=$db->one("SELECT count(*)::int AS n FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND state='idle in transaction'")['n'];
$intent=$db->one('SELECT state,operation_key FROM payment_operations WHERE payment_id=? AND kind=?',[$id,$operation]);
$result=$db->transaction(function()use($db,$id,$key,$operation,$scenario,$body,$inTransaction,$intent){
    $db->advisory('fixture:'.$key);
    $old=$db->one('SELECT * FROM payment_test_journal WHERE operation_key=?',[$key]);
    if($old){$db->execute('UPDATE payment_test_journal SET attempts=attempts+1 WHERE operation_key=?',[$key]);return $old;}
    $code=$operation==='authorize'?$scenario['authorize_code']:($operation==='void'?($scenario['void_code']??$scenario['reverse_code']):$scenario['reverse_code']);
    $response=$operation==='authorize'?$scenario['authorize_body']:$scenario['reverse_body'];
    $db->execute('INSERT INTO payment_test_journal(operation_key,payment_id,operation,amount_cents,body,response_code,response_body,in_transaction,intent_state,intent_key) VALUES(?,?,?,?,?::jsonb,?,?,?,?,?)',[$key,$id,$operation,$body['amountCents'],json_encode($body),$code,$response,$inTransaction,$intent['state']??null,$intent['operation_key']??null]);
    return ['response_code'=>$code,'response_body'=>$response];
});
if($operation==='authorize' && $scenario['callback_status']!==null){
    $payload=json_encode(['paymentId'=>$id,'transactionId'=>'txn-'.$id,'status'=>$scenario['callback_status']],JSON_THROW_ON_ERROR);
    $timestamp=(string)time();$wid='early-'.$id;
    $r=(new HttpClient())->request('POST',Env::get('PAYMENT_TEST_HTTP_ORIGIN').'/api/v1/payments/webhook',['Content-Type'=>'application/json','X-Webhook-Id'=>$wid,'X-Webhook-Timestamp'=>$timestamp,'X-Webhook-Signature'=>hash_hmac('sha256',$timestamp.':'.$wid.':'.$payload,'payment-test-secret')],$payload);
    $db->execute('UPDATE payment_test_scenarios SET callback_http=? WHERE payment_id=?',[$r['status'],$id]);
}
if($scenario['delay_ms']>0){usleep($scenario['delay_ms']*1000);}
// Deterministic send/result boundary for lease/reconciliation races. Never keep
// a SQL transaction open while the test controls the network response barrier.
if(!$scenario['response_released']){
    $deadline=microtime(true)+8;
    while(!$db->one('SELECT response_released FROM payment_test_scenarios WHERE payment_id=?',[$id])['response_released'] && microtime(true)<$deadline){usleep(10000);}
}
if($scenario['lose_response']){http_response_code(200);header('Content-Type: application/json');print '{response-lost';exit;}
http_response_code($result['response_code']);header('Content-Type: application/json');print $result['response_body'];
