<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;
use Shop\application\{CommerceService,ProfileService};

/** Test-only SQL barrier: pauses an actual service after it acquires a row lock. */
final class LockBarrierStatement extends PDOStatement {
    private bool $paused=false;
    protected function __construct(private $barrier,private string $fragment) {}
    public function execute(?array $params=null): bool {
        $result=parent::execute($params);
        if(!$this->paused && str_contains($this->queryString,$this->fragment)){
            $this->paused=true;fwrite($this->barrier,"locked\n");
            if(trim((string)fgets($this->barrier))!=='continue'){throw new RuntimeException('Barrier release missing');}
        }
        return $result;
    }
}
function db(): Database {return new Database(Env::get('DATABASE_URL'));}
function addressFixture(): array {
    $db=db();$u=Ids::uuid();$a=Ids::uuid();$c=Ids::uuid();$p=Ids::uuid();
    $db->execute('INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,\'\',\'Address Race Buyer\',\'+79991234567\')',[$u,$u.'@test.example']);
    $db->execute('INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,\'City\',\'Street\',\'1\')',[$a,$u]);
    $db->execute('INSERT INTO categories(id,name) VALUES(?,\'Race category\')',[$c]);
    $db->execute('INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,\'Race product\',\'1.25\',10)',[$p,$c]);
    (new CommerceService($db,new ShopRepository($db)))->addCart($u,['productId'=>$p,'quantity'=>2]);
    return [$u,$a,$p];
}
function startWorker(string $operation,array $fixture,?string $barrier=null): array {
    [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
    if($pid===0){
        fclose($parent);stream_set_timeout($child,12);$db=db();$repo=new ShopRepository($db);
        fwrite($child,json_encode(['pid'=>$db->one('SELECT pg_backend_pid() AS pid')['pid']])."\n");
        if($barrier!==null){$db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[LockBarrierStatement::class,[$child,$barrier]]);}
        [$u,$a]=$fixture;
        try{
            if($operation==='checkout'){$value=(new CommerceService($db,$repo))->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD'],'address-race');}
            else{(new ProfileService($db,$repo))->deleteAddress($u,$a);$value=true;}
            $out=['ok'=>$value];
        }catch(ApiError $e){$out=['error'=>$e->errorCode];}
        catch(PDOException $e){$out=['sqlState'=>$e->getCode(),'message'=>$e->getMessage()];}
        catch(Throwable $e){$out=['exception'=>$e->getMessage()];}
        fwrite($child,json_encode($out)."\n");fclose($child);exit(0);
    }
    fclose($child);stream_set_timeout($parent,12);$backend=json_decode((string)fgets($parent),true,16,JSON_THROW_ON_ERROR)['pid'];
    return [$pid,$parent,$backend];
}
function waitForLock(int $backend): void {
    $db=db();$deadline=microtime(true)+5;
    do{$r=$db->one('SELECT wait_event_type,cardinality(pg_blocking_pids(pid)) AS blockers FROM pg_stat_activity WHERE pid=?',[$backend]);if(($r['wait_event_type']??null)==='Lock' && $r['blockers']>0){return;}usleep(10000);}while(microtime(true)<$deadline);
    throw new RuntimeException('Worker did not reach the controlled lock wait');
}
function finishWorker(array $worker): array {
    [$pid,$socket]=$worker;$line=fgets($socket);fclose($socket);pcntl_waitpid($pid,$status);same(0,pcntl_wexitstatus($status));
    return json_decode((string)$line,true,32,JSON_THROW_ON_ERROR);
}
function persistedState(array $fixture,bool $ordered): void {
    [$u,$a,$p]=$fixture;$db=db();
    same($ordered?1:0,$db->one('SELECT count(*) AS n FROM orders WHERE user_id=?',[$u])['n']);
    same($ordered?1:0,$db->one('SELECT count(*) AS n FROM order_items WHERE product_id=?',[$p])['n']);
    same($ordered?1:0,$db->one('SELECT count(*) AS n FROM payments WHERE user_id=?',[$u])['n']);
    same($ordered?8:10,$db->one('SELECT stock FROM products WHERE id=?',[$p])['stock']);
    same($ordered?0:2,$db->one('SELECT COALESCE(sum(quantity),0)::int AS n FROM cart_items WHERE user_id=?',[$u])['n']);
    same($ordered?1:0,$db->one('SELECT count(*) AS n FROM idempotency_keys WHERE user_id=? AND endpoint=\'orders\'',[$u])['n']);
    same($ordered?1:0,$db->one('SELECT count(*) AS n FROM addresses WHERE id=?',[$a])['n']);
}
test('checkout holding address completes before concurrent delete and preserves one atomic order',function(){
    $fixture=addressFixture();$checkout=startWorker('checkout',$fixture,'FROM addresses WHERE id=? AND user_id=? FOR KEY SHARE');
    same('locked',trim((string)fgets($checkout[1])));$delete=startWorker('delete',$fixture);
    try{waitForLock($delete[2]);}finally{fwrite($checkout[1],"continue\n");}
    $created=finishWorker($checkout);$deleted=finishWorker($delete);
    truth(isset($created['ok']));same(['error'=>'ADDRESS_IN_USE'],$deleted);persistedState($fixture,true);
});
test('delete holding user completes before concurrent checkout and leaves no partial checkout state',function(){
    $fixture=addressFixture();$delete=startWorker('delete',$fixture,'FROM users WHERE id=? FOR UPDATE');
    same('locked',trim((string)fgets($delete[1])));$checkout=startWorker('checkout',$fixture);
    try{waitForLock($checkout[2]);}finally{fwrite($delete[1],"continue\n");}
    $deleted=finishWorker($delete);$created=finishWorker($checkout);
    same(['ok'=>true],$deleted);same(['error'=>'ADDRESS_NOT_FOUND'],$created);persistedState($fixture,false);
});
runTests();
