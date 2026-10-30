<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient,OpenSearch};
use Shop\repositories\ShopRepository;

final class StageBudgetStatement extends PDOStatement {
    public static int $executions=0;
    protected function __construct() {}
    public function execute(?array $params=null): bool {
        if(++self::$executions>4){throw new RuntimeException('Staging exceeded its four-statement fixture budget');}
        return parent::execute($params);
    }
}

test('native staged rebuild inventories legacy IDs and duplicates without changing the live index',function(){
    $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);
    $db->pdo()->beginTransaction();
    try{
    $db->execute('UPDATE products SET is_active=false');
    $name='shopkmp-products-round4-'.str_replace('-','',Ids::uuid());$target=$name.'-next';
    $source=new OpenSearch($db,$repo,$name);
    $category=Ids::uuid();$id=Ids::uuid();
    $db->execute("INSERT INTO categories(id,name) VALUES(?,'Stage category')",[$category]);
    $db->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Staged identity','1.23',3)",[$id,$category]);
    // Method existence is exercised before seeding the test-owned search index.
    same(0,$source->inventory()['documents']);
    $source->index($id);
    $http=new HttpClient();$base=rtrim(Env::get('OPENSEARCH_URL'),'/');
    $r=$http->request('GET',$base.'/'.$name.'/_doc/'.$id);same(200,$r['status']);$document=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR)['_source'];
    foreach([strtoupper($id),'invalid-legacy-id'] as $legacy){
        same(201,$http->request('PUT',$base.'/'.$name.'/_doc/'.$legacy.'?refresh=wait_for',['Content-Type'=>'application/json'],json_encode($document,JSON_THROW_ON_ERROR))['status']);
    }
    $before=$source->inventory();same(3,$before['documents']);same(1,$before['noncanonical']);same(1,$before['duplicates']);same(1,$before['invalid']);
    $db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[StageBudgetStatement::class]);StageBudgetStatement::$executions=0;
    $staged=$source->stage($target);same($target,$staged['target']);same(0,$staged['inventory']['duplicates']);same(0,$staged['inventory']['invalid']);
    $db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);
    same($before,$source->inventory());
    $next=new OpenSearch($db,$repo,$target);$result=$next->search('Staged identity',strtoupper($category),'',['priceMin'=>null,'priceMax'=>null],0,20);
    same(1,$result['totalItems']);same([$id],array_column($result['items'],'id'));
    // A rerun refuses to adopt or overwrite any existing replacement.
    try{$source->stage($target);throw new RuntimeException('existing stage overwritten');}catch(LogicException){}
    same($before,$source->inventory());
    }finally{$db->pdo()->rollBack();}
});
test('staging budget counts active rows and never scans inactive product history',function(){
    $db=new Database(Env::get('DATABASE_URL'));$db->pdo()->beginTransaction();
    try{
        $db->execute('UPDATE products SET is_active=false');$category=Ids::uuid();$id=Ids::uuid();
        $db->execute("INSERT INTO categories(id,name) VALUES(?,'Stage budget')",[$category]);
        foreach([true,false,false] as $active){$db->execute("INSERT INTO products(id,category_id,name,price,stock,is_active) VALUES(?,?,'Stage budget','1.23',1,?)",[$active?$id:Ids::uuid(),$category,$active]);}
        $db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[StageBudgetStatement::class]);StageBudgetStatement::$executions=0;
        $target='shopkmp-products-budget-'.str_replace('-','',Ids::uuid());
        $result=(new OpenSearch($db,new ShopRepository($db)))->stage($target,1);
        same(1,$result['inventory']['documents']);truth(StageBudgetStatement::$executions<=4);
    }finally{$db->pdo()->rollBack();}
});
runTests();
