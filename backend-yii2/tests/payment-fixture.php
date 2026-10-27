<?php
declare(strict_types=1);
use Shop\application\{CommerceService,PaymentService,PaymentOperations};
use Shop\infrastructure\{Database,Env,HttpClient};
use Shop\repositories\ShopRepository;
use Shop\domain\{Ids,ApiError};
function paymentDb(): Database {return new Database(Env::get('DATABASE_URL'));}
function paymentServices(?Database $db=null): array {
    $db??=paymentDb();$repo=new ShopRepository($db);$commerce=new CommerceService($db,$repo);
    return [$db,$repo,$commerce,new PaymentService($db,$repo,$commerce)];
}
function paymentTestSchema(): void {
    paymentDb()->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS payment_test_scenarios (
 payment_id uuid PRIMARY KEY, authorize_code int NOT NULL DEFAULT 200, authorize_body text NOT NULL,
 reverse_code int NOT NULL DEFAULT 204, reverse_body text NOT NULL DEFAULT '',
 delay_ms int NOT NULL DEFAULT 0, lose_response boolean NOT NULL DEFAULT false,
 callback_status text, callback_http int
);
ALTER TABLE payment_test_scenarios ADD COLUMN IF NOT EXISTS void_code int;
ALTER TABLE payment_test_scenarios ADD COLUMN IF NOT EXISTS response_released boolean NOT NULL DEFAULT true;
CREATE TABLE IF NOT EXISTS payment_test_journal (
 operation_key text PRIMARY KEY,payment_id uuid NOT NULL,operation text NOT NULL,
 amount_cents bigint NOT NULL,body jsonb NOT NULL,response_code int NOT NULL,response_body text NOT NULL,
 attempts int NOT NULL DEFAULT 1,in_transaction int NOT NULL,created_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE payment_test_journal ADD COLUMN IF NOT EXISTS intent_state text;
ALTER TABLE payment_test_journal ADD COLUMN IF NOT EXISTS intent_key text;
SQL);
}
function paymentFixture(string $method='CARD',string $status='AUTHORIZED',array $scenario=[],?Database $database=null): array {
    [$db,$repo,$commerce,$payments]=paymentServices($database);$u=Ids::uuid();$a=Ids::uuid();$product=Ids::uuid();$category=Ids::uuid();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Payment Buyer','+79991234567')",[$u,$u.'@payments.test']);
    $db->execute("INSERT INTO categories(id,name) VALUES(?,'Payments')",[$category]);
    $db->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Exact cents','10.23',10)",[$product,$category]);
    $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'X','Y','1')",[$a,$u]);
    $commerce->addCart($u,['productId'=>$product,'quantity'=>2]);
    $o=$commerce->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>$method]);$id=$o['paymentId'];
    $body=json_encode(['status'=>$status,'paymentUrl'=>'https://payments.example.test/'.$id,'transactionId'=>'txn-'.$id],JSON_THROW_ON_ERROR);
    $db->execute('INSERT INTO public.payment_test_scenarios(payment_id,authorize_body) VALUES(?,?)',[$id,$body]);
    foreach($scenario as $field=>$value){if(!in_array($field,['authorize_code','authorize_body','reverse_code','reverse_body','void_code','delay_ms','lose_response','callback_status','response_released'],true)){throw new RuntimeException('Invalid scenario');}$db->execute('UPDATE public.payment_test_scenarios SET '.$field.'=? WHERE payment_id=?',[$value,$id]);}
    return [$u,$o,$product];
}
function journal(string $id): array {return paymentDb()->all('SELECT * FROM payment_test_journal WHERE payment_id=? ORDER BY created_at',[$id]);}
function stock(string $product): int {return paymentDb()->one('SELECT stock FROM products WHERE id=?',[$product])['stock'];}
function callback(array $o,string $status,string $wid='',?string $transaction=null): void {
    [,,$c,$p]=paymentServices();$p->webhook(['paymentId'=>$o['paymentId'],'status'=>$status,'transactionId'=>$transaction??'txn-'.$o['paymentId']],$wid?:Ids::uuid());
}
function signedPaymentHttp(array $o,string $status,string $wid,?string $transaction=null,bool $valid=true,?int $timestamp=null): array {
    $body=json_encode(['paymentId'=>$o['paymentId'],'status'=>$status,'transactionId'=>$transaction??'txn-'.$o['paymentId']],JSON_THROW_ON_ERROR);$ts=(string)($timestamp??time());
    $r=(new HttpClient())->request('POST',Env::get('PAYMENT_TEST_HTTP_ORIGIN').'/api/v1/payments/webhook',['Content-Type'=>'application/json','X-Webhook-Id'=>$wid,'X-Webhook-Timestamp'=>$ts,'X-Webhook-Signature'=>$valid?hash_hmac('sha256',$ts.':'.$wid.':'.$body,'payment-test-secret'):'invalid'],$body);
    return [$r['status'],json_decode($r['body'],true)];
}
function paymentRace(array $operations): array {
    $children=[];$sockets=[];
    foreach($operations as $op){[$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
        if($pid===0){fclose($parent);fread($child,1);try{$out=['ok'=>$op()];}catch(ApiError $e){$out=['error'=>$e->errorCode];}catch(Throwable $e){$out=['exception'=>$e->getMessage()];}fwrite($child,json_encode($out));fclose($child);exit(0);}
        fclose($child);$children[]=$pid;$sockets[]=$parent;
    }
    foreach($sockets as $s){fwrite($s,'x');}$out=[];
    foreach($sockets as $s){$out[]=json_decode(stream_get_contents($s),true);fclose($s);}foreach($children as $pid){pcntl_waitpid($pid,$status);same(0,pcntl_wexitstatus($status));}return $out;
}
function recoverPayments(): array {
    [$db,$repo,$commerce]=paymentServices();return (new PaymentOperations($db,$repo,$commerce))->recover();
}
function paymentFailureTrigger(Database $db,string $payment,string $state): array {
    $suffix=str_replace('-','',Ids::uuid());$function='payment_fail_'.$suffix;$trigger='payment_trigger_'.$suffix;
    $db->execute('CREATE FUNCTION '.$function.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.payment_id=\''.$payment.'\'::uuid AND NEW.state=\''.$state.'\' THEN RAISE EXCEPTION \'injected payment commit\'; END IF; RETURN NEW; END $$');
    $db->execute('CREATE CONSTRAINT TRIGGER '.$trigger.' AFTER INSERT OR UPDATE ON payment_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION '.$function.'()');return [$trigger,$function];
}
function dropPaymentTrigger(Database $db,string $trigger,string $function): void {
    $db->execute('DROP TRIGGER '.$trigger.' ON payment_operations');$db->execute('DROP FUNCTION '.$function.'()');
}
function waitPaymentJournal(string $payment,int $count=1): void {
    $deadline=microtime(true)+4;
    do{if(count(journal($payment))>=$count){return;}usleep(10000);}while(microtime(true)<$deadline);
    throw new RuntimeException('Provider did not receive request');
}
function paymentWorker(Closure $work): array {
    [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
    if($pid===0){fclose($parent);try{$out=['ok'=>$work()];}catch(Throwable $e){$out=['exception'=>$e->getMessage()];}fwrite($child,json_encode($out));fclose($child);exit(0);}
    fclose($child);return [$pid,$parent];
}
function finishPaymentWorker(array $worker): array {
    [$pid,$socket]=$worker;$out=json_decode(stream_get_contents($socket),true);fclose($socket);pcntl_waitpid($pid,$status);same(0,pcntl_wexitstatus($status));truth(!isset($out['exception']));return $out;
}
/** Committed predecessor schema: unlike migration smoke tests, HTTP can really
 * survive a business transaction rollback and subsequent fresh connections. */
function paymentLegacySchema(): array {
    $db=paymentDb();$schema='payment_legacy_'.str_replace('-','',Ids::uuid());
    $db->transaction(function()use($db,$schema){
        $db->execute('CREATE SCHEMA '.$schema);$db->execute('SET LOCAL search_path TO '.$schema);
        $db->execute('CREATE FUNCTION uuid_generate_v4() RETURNS uuid LANGUAGE sql AS $$ SELECT public.uuid_generate_v4() $$');
        for($n=1;$n<=11;$n++){$files=glob(Env::get('PAYMENT_MIGRATION_DIR','/migrations').'/'.sprintf('%03d',$n).'_*.up.sql');same(1,count($files));$db->pdo()->exec(file_get_contents($files[0]));}
    });
    $db->execute('SET search_path TO '.$schema);return [$db,$schema];
}
function paymentSchemaConnection(string $schema): Database {
    if(!preg_match('/^payment_legacy_[a-f0-9]{32}$/D',$schema)){throw new RuntimeException('Invalid fixture schema');}
    $db=paymentDb();$db->execute('SET search_path TO '.$schema);return $db;
}
function dropPaymentLegacySchema(Database $db,string $schema): void {
    if(!preg_match('/^payment_legacy_[a-f0-9]{32}$/D',$schema)){throw new RuntimeException('Invalid fixture schema');}
    $db->execute('SET search_path TO public');$db->execute('DROP SCHEMA '.$schema.' CASCADE');
}
function applyPaymentMigration(Database $db): void {
    $db->transaction(fn()=>$db->pdo()->exec(file_get_contents(Env::get('PAYMENT_MIGRATION_DIR','/migrations').'/012_payment_operations.up.sql')));
}
function releasePaymentResponse(string $payment): void {
    paymentDb()->execute('UPDATE payment_test_scenarios SET response_released=true WHERE payment_id=?',[$payment]);
}
