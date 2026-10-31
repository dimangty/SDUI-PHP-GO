<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SearchNativeFixture.php';

use Shop\infrastructure\{SearchIndexState,SearchResponse};

SearchNativeFixture::configure();
final class SearchDelayedReadStatement extends PDOStatement
{
    protected function __construct(private PDO $connection) {}
    public function execute(?array $params=null): bool
    {
        // A real PostgreSQL statement, cancelled by the production remaining
        // statement_timeout. No sleep or synthetic PDO exception in the adapter.
        if(str_starts_with($this->queryString,'SELECT p.*')){$this->connection->query('SELECT pg_sleep(1)');}
        return parent::execute($params);
    }
}
final class SearchFailUnlockStatement extends PDOStatement
{
    protected function __construct(private PDO $connection) {}
    public function execute(?array $params=null): bool
    {
        if(str_contains($this->queryString,'pg_advisory_unlock(')){$this->connection->query('SELECT pg_sleep(2)');}
        return parent::execute($params);
    }
}
test('native empty bootstrap publishes verified metadata without a sentinel product',function(){
    $db=SearchNativeFixture::db(true);$name=SearchNativeFixture::name();$s=SearchNativeFixture::search($db,$name);
    same(false,$s->inventory()['exists']);$built=$s->bootstrap(100,30);
    same(0,$built['inventory']['documents']);same(true,$built['inventory']['exists']);$s->ready();
    $meta=SearchIndexState::inspect(SearchNativeFixture::http('GET','/'.$name),$name);same('ready',$meta['meta']['state']);same(0,$meta['meta']['documents']);
    $page=$s->search('needle','','',['priceMin'=>null,'priceMax'=>null],0,20,true);same(0,$page['totalItems']);same([],$page['items']);
});
test('native bootstrap and staged config target preserve SQL authority and old physical index',function(){
    $db=SearchNativeFixture::db(true);$id=SearchNativeFixture::product($db);$name=SearchNativeFixture::name();$target=SearchNativeFixture::name();$s=SearchNativeFixture::search($db,$name);
    $s->bootstrap(100,30);$before=$s->inventory();
    $db->execute("UPDATE products SET price='2.34',description='Current SQL' WHERE id=?",[$id]);
    $staged=$s->stage($target,100,30);same(false,$staged['switched']);same(1,$staged['inventory']['documents']);same($before,$s->inventory());
    $next=SearchNativeFixture::search($db,$target);$page=$next->search('needle','','price_asc',['priceMin'=>234,'priceMax'=>234],0,20);
    same([$id],array_column($page['items'],'id'));same('Current SQL',$page['items'][0]['description']);same('2.34',$page['items'][0]['price']);
    $refused=false;try{$s->stage($target,100,30);}catch(LogicException){$refused=true;}truth($refused);same($before,$s->inventory());
});
test('native normal writer refuses missing index before SQL or automatic creation',function(){
    $db=SearchNativeFixture::db(true);$id=SearchNativeFixture::product($db);$name=SearchNativeFixture::name();$s=SearchNativeFixture::search($db,$name);
    $refused=false;try{$s->index($id);}catch(UnexpectedValueException){$refused=true;}truth($refused);same(404,SearchNativeFixture::http('HEAD','/'.$name)['status']);
});
test('native staging row admission fails before creating an over-limit target',function(){
    $db=SearchNativeFixture::db(true);SearchNativeFixture::product($db);SearchNativeFixture::product($db);$name=SearchNativeFixture::name();
    $refused=false;try{SearchNativeFixture::search($db,$name)->bootstrap(1,30);}catch(RuntimeException){$refused=true;}truth($refused);same(404,SearchNativeFixture::http('HEAD','/'.$name)['status']);
});
test('native maintenance mutex refuses a concurrent build without adopting it',function(){
    $observer=SearchNativeFixture::db();$observer->one("SELECT pg_advisory_lock(hashtextextended('shopkmp-products:maintenance',0))");
    try{
        $db=SearchNativeFixture::db(true);$name=SearchNativeFixture::name();$start=hrtime(true);$refused=false;
        try{SearchNativeFixture::search($db,$name)->bootstrap(100,5);}catch(RuntimeException){$refused=true;}
        truth($refused);truth((hrtime(true)-$start)/1e9<2);same(404,SearchNativeFixture::http('HEAD','/'.$name)['status']);
    }finally{$observer->one("SELECT pg_advisory_unlock(hashtextextended('shopkmp-products:maintenance',0))");}
});
test('native reindex refuses absent and unready targets instead of initializing them',function(){
    $db=SearchNativeFixture::db(true);$name=SearchNativeFixture::name();$refused=false;
    try{SearchNativeFixture::search($db,$name)->reindex(100,10);}catch(UnexpectedValueException){$refused=true;}
    truth($refused);same(404,SearchNativeFixture::http('HEAD','/'.$name)['status']);
});
test('native maintenance cannot publish uncommitted caller data',function(){
    $db=SearchNativeFixture::db(true);$name=SearchNativeFixture::name();$db->pdo()->beginTransaction();
    try{$refused=false;try{SearchNativeFixture::search($db,$name)->bootstrap(100,10);}catch(LogicException){$refused=true;}truth($refused);same(404,SearchNativeFixture::http('HEAD','/'.$name)['status']);}
    finally{$db->pdo()->rollBack();}
});
test('native interrupted build stays unready and its SQL fence and mutex are released',function(){
    $db=SearchNativeFixture::db();$ids=[];$categories=[];$worker=null;$name=SearchNativeFixture::name();
    try{
        for($i=0;$i<8;$i++){$id=SearchNativeFixture::product($db);$ids[]=$id;$categories[]=$db->one('SELECT category_id FROM products WHERE id=?',[$id])['category_id'];}
        $worker=SearchNativeFixture::worker('bootstrap',$name,barrier:true);$end=hrtime(true)/1e9+15;$out='';
        do{
            $out.=stream_get_contents($worker[1][1]);if(str_contains($out,'BUILD_PAUSED')){break;}usleep(20000);
        }while(hrtime(true)/1e9<$end);
        truth(str_contains($out,'BUILD_PAUSED'));
        same(200,SearchNativeFixture::http('POST','/'.$name.'/_refresh')['status']);
        $v=json_decode(SearchNativeFixture::http('GET','/'.$name.'/_count')['body'],true);same(1,$v['count']);$s=SearchNativeFixture::search($db,$name);
        $rejected=false;try{$s->ready();}catch(UnexpectedValueException){$rejected=true;}truth($rejected);
        $rejected=false;try{$s->index($ids[0]);}catch(UnexpectedValueException){$rejected=true;}truth($rejected);
        errorCode('SEARCH_UNAVAILABLE',fn()=>$s->search('needle','','',['priceMin'=>null,'priceMax'=>null],0,20));
        $db->execute("SET lock_timeout='80ms'");$blocked=false;
        try{$db->execute('UPDATE products SET stock=stock WHERE id=?',[$ids[0]]);}catch(PDOException $error){same('55P03',$error->getCode());$blocked=true;}truth($blocked);
        $result=SearchNativeFixture::finish($worker,true);$worker=null;truth($result['exit']!==0);
        $meta=SearchIndexState::inspect(SearchNativeFixture::http('GET','/'.$name),$name,false);same('building',$meta['meta']['state']);
        $db->execute('UPDATE products SET stock=stock WHERE id=?',[$ids[0]]);
        $rejected=false;try{$s->bootstrap(100000,10);}catch(UnexpectedValueException){$rejected=true;}truth($rejected);
        $rejected=false;try{$s->reindex(100000,10);}catch(UnexpectedValueException){$rejected=true;}truth($rejected);
        // Fresh-target recovery succeeds with current SQL; the abandoned target remains.
        $private=SearchNativeFixture::db(true);SearchNativeFixture::search($private,SearchNativeFixture::name())->bootstrap(100,10);
    }finally{
        if($worker!==null){SearchNativeFixture::finish($worker,true);}
        foreach($ids as $id){$db->execute('DELETE FROM products WHERE id=?',[$id]);}
        foreach($categories as $id){$db->execute('DELETE FROM categories WHERE id=?',[$id]);}
    }
});
test('native concurrent writer waits before loading SQL and indexes the latest committed value',function(){
    $private=SearchNativeFixture::db(true);$name=SearchNativeFixture::name();SearchNativeFixture::search($private,$name)->bootstrap(100,10);
    $db=SearchNativeFixture::db();$id=SearchNativeFixture::product($db);$category=$db->one('SELECT category_id FROM products WHERE id=?',[$id])['category_id'];
    $scope='shopkmp-products:write:'.$name.':'.$id;$worker=null;$held=false;
    try{
        $db->one('SELECT pg_advisory_lock(hashtextextended(?,0))',[$scope]);$held=true;
        $worker=SearchNativeFixture::worker('index',$name,$id,true);$out='';$end=hrtime(true)/1e9+5;
        do{$out.=stream_get_contents($worker[1][1]);if(str_contains($out,'LOCK_ATTEMPT')){break;}usleep(10000);}while(hrtime(true)/1e9<$end);
        truth(str_contains($out,'LOCK_ATTEMPT'));
        $db->execute("UPDATE products SET price='9.87' WHERE id=?",[$id]);
        $db->one('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$scope]);$held=false;
        $result=SearchNativeFixture::finish($worker);$worker=null;same(0,$result['exit']);same('',$result['error']);
        $doc=json_decode(SearchNativeFixture::http('GET','/'.$name.'/_doc/'.$id)['body'],true)['_source'];same(987,$doc['salePriceCents']);same('9.87',$doc['price']);
    }finally{
        if($held){$db->one('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$scope]);}
        if($worker!==null){SearchNativeFixture::finish($worker,true);}
        $db->execute('DELETE FROM products WHERE id=?',[$id]);$db->execute('DELETE FROM categories WHERE id=?',[$category]);
    }
});
test('native reconciliation is bounded and restores SQL timeout settings',function(){
    $db=SearchNativeFixture::db(true);$id=SearchNativeFixture::product($db);$name=SearchNativeFixture::name();$s=SearchNativeFixture::search($db,$name);$s->bootstrap(100,20);
    $settings=$db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock");
    $db->execute('UPDATE products SET is_active=false WHERE id=?',[$id]);same(1,$s->reindex(100,20));same(0,$s->inventory()['documents']);
    SearchNativeFixture::product($db);$refused=false;try{$s->reindex(1,10);}catch(RuntimeException){$refused=true;}truth($refused);
    same($settings,$db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock"));$s->ready();
});
test('native early termination and lower-bound totals are rejected as incomplete',function(){
    $db=SearchNativeFixture::db(true);SearchNativeFixture::product($db);SearchNativeFixture::product($db);$name=SearchNativeFixture::name();SearchNativeFixture::search($db,$name)->bootstrap(100,20);
    foreach([
        ['terminate_after'=>1,'track_total_hits'=>true,'size'=>20],
        ['track_total_hits'=>1,'size'=>20],
    ] as $query){
        $reply=SearchNativeFixture::http('POST','/'.$name.'/_search',$query);$refused=false;
        try{SearchResponse::complete(SearchResponse::decode($reply));}catch(UnexpectedValueException){$refused=true;}truth($refused);
    }
});
test('native short build deadline cannot publish and a fresh attempt can recover',function(){
    $db=SearchNativeFixture::db(true);for($i=0;$i<3;$i++){SearchNativeFixture::product($db);}
    $name=SearchNativeFixture::name();$s=SearchNativeFixture::search($db,$name);$start=hrtime(true);$refused=false;
    $pdo=$db->pdo();$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SearchDelayedReadStatement::class,[$pdo]]);
    try{try{$s->bootstrap(100,.3);}catch(RuntimeException){$refused=true;}}
    finally{$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);}
    truth($refused);truth((hrtime(true)-$start)/1e9<4.5);
    $r=SearchNativeFixture::http('GET','/'.$name);
    if($r['status']===200){same('building',SearchIndexState::inspect($r,$name,false)['meta']['state']);}else{same(404,$r['status']);}
    SearchNativeFixture::search($db,SearchNativeFixture::name())->bootstrap(100,20);
});
test('native product auto-create policy refuses document resurrection but permits explicit bootstrap',function(){
    $settings=json_decode(SearchNativeFixture::http('GET','/_cluster/settings')['body'],true);
    $previous=$settings['transient']['action.auto_create_index']??null;
    try{
        same(200,SearchNativeFixture::http('PUT','/_cluster/settings',['transient'=>['action.auto_create_index'=>'-shopkmp-products*,+*']])['status']);
        $name=SearchNativeFixture::name();same(404,SearchNativeFixture::http('PUT','/'.$name.'/_doc/fixture',['name'=>'must not create'])['status']);
        same(404,SearchNativeFixture::http('HEAD','/'.$name)['status']);
        $db=SearchNativeFixture::db(true);SearchNativeFixture::search($db,$name)->bootstrap(100,20);
    }finally{same(200,SearchNativeFixture::http('PUT','/_cluster/settings',['transient'=>['action.auto_create_index'=>$previous]])['status']);}
});
test('native multi-document build uses one final refresh within its work budget',function(){
    $db=SearchNativeFixture::db(true);for($i=0;$i<8;$i++){SearchNativeFixture::product($db);}
    $s=SearchNativeFixture::search($db,SearchNativeFixture::name());$start=hrtime(true);$built=$s->bootstrap(100,4);
    print sprintf("NATIVE BUILD documents=8 elapsed=%.3fs work_budget=4s\n",(hrtime(true)-$start)/1e9);
    same(8,$built['inventory']['documents']);$s->ready();
});
test('native normal writer cannot leak search timeout settings into the business session',function(){
    $db=SearchNativeFixture::db(true);$id=SearchNativeFixture::product($db);$s=SearchNativeFixture::search($db,SearchNativeFixture::name());$s->bootstrap(100,20);
    $before=$db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock");
    $pdo=$db->pdo();$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SearchFailUnlockStatement::class,[$pdo]]);
    try{try{$s->index($id);}catch(RuntimeException){} }
    finally{$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);}
    same($before,$db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock"));
});
if(isset($argv[1])){
    $tests=array_filter($tests,fn($name)=>str_contains($name,$argv[1]),ARRAY_FILTER_USE_KEY);
    if(!$tests){throw new InvalidArgumentException('No matching native search test');}
}
runTests();
