<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/Round3HttpFixture.php';

use Shop\application\{CatalogService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{ApiKernel,Database,Env,OpenSearch};
use Shop\repositories\{CatalogReadRepository,ShopRepository};

final class Round4CountingStatement extends PDOStatement {
    public static int $executions=0;
    protected function __construct() {}
    public function execute(?array $params=null): bool {self::$executions++;return parent::execute($params);}
}
$db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$catalog=new CatalogService($db,$repo);
$db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[Round4CountingStatement::class]);
function mixedUuid(string $id): string {return substr(strtoupper($id),0,18).substr($id,18);}
function round4Category(): string {
    global $db;$id=Ids::uuid();$db->execute("INSERT INTO categories(id,name) VALUES(?,'Round4 category')",[$id]);return $id;
}
test('batch identity is by UUID value preserving first hit rank for full and card DTOs',function()use($db,$repo){
    $category=round4Category();$a=Ids::uuid();$b=Ids::uuid();$inactive=Ids::uuid();
    foreach([$a,$b,$inactive] as $id){$db->execute("INSERT INTO products(id,category_id,name,description,price,stock,is_active) VALUES(?,?,'Ranked','Unicode 😀','1.23',1,?)",[$id,$category,$id!==$inactive]);}
    foreach([false,true] as $card){
        Round4CountingStatement::$executions=0;
        $items=(new CatalogReadRepository($db))->byIds([strtoupper($b),mixedUuid($a),$a,strtoupper($inactive),Ids::uuid(),$b],$card);
        same([$b,$a],array_column($items,'id'));same(2,Round4CountingStatement::$executions);
        same('Unicode 😀',$items[0][$card?'descriptionSnippet':'description']);
        foreach([[$a,$b],[strtoupper($a),strtoupper($b)],[mixedUuid($a),mixedUuid($b)]] as $ids){
            same([$a,$b],array_column((new CatalogReadRepository($db))->byIds($ids,$card),'id'));
        }
    }
});
test('malformed search hit identity fails integration instead of disappearing or reaching SQL',function()use($db,$catalog){
    foreach([[['_id'=>"' OR true --"]],[['_id'=>Ids::uuid()],[]],[['_id'=>42]]] as $hits){
        $server=new Round3HttpFixture($hits);$old=Env::get('OPENSEARCH_URL');putenv('OPENSEARCH_URL='.$server->origin);
        try{Round4CountingStatement::$executions=0;errorCode('SEARCH_UNAVAILABLE',fn()=>$catalog->products(['q'=>'Ranked'],true));same(0,Round4CountingStatement::$executions);}
        finally{putenv('OPENSEARCH_URL='.$old);$server->close();}
    }
});
foreach(['absent'=>[],'true'=>['isActive'=>true],'false'=>['isActive'=>false]] as $label=>$flag){
test("native admin create respects $label with DB public and search visibility",function()use($db,$repo,$catalog,$flag){
    $user=Ids::uuid();$category=round4Category();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,is_admin,local_identity_ready) VALUES(?,?,'','Round4 admin','+79990000000',true,true)",[$user,$user.'@example.invalid']);
    $tokens=$db->transaction(fn()=>(new SessionService($db,$repo))->issue($user));$kernel=new ApiKernel($db);
    $headers=['authorization'=>'Bearer '.$tokens['accessToken'],'content-type'=>'application/json'];
        $word='visibility'.str_replace('-','',Ids::uuid());
        $result=$kernel->handle('POST','/api/v1/admin/products',$headers,json_encode($flag+['categoryId'=>$category,'name'=>$word,'price'=>'1.23','stock'=>3,'imageUrls'=>['https://example.invalid/first.png']]),'127.0.0.1');
        same(201,$result['status']);$dto=$result['data'];$id=$dto['id'];$active=$flag['isActive']??true;
        same($active,$dto['isActive']);same($active,$db->one('SELECT is_active FROM products WHERE id=?',[$id])['is_active']);
        foreach([false,true] as $card){$q=['q'=>$word,'categoryId'=>$category]+($card?['projection'=>'sdui-card-v1']:[]);same($active?1:0,$catalog->products($q,true)['totalItems']);}
        $details=$kernel->handle('GET','/api/v1/products/'.$id,[],'','127.0.0.1');same($active?200:404,$details['status']);
        truth(in_array($id,array_column($catalog->products(['categoryId'=>$category])['items'],'id'),true)===$active);
        // A real sale is retained while visibility changes.
        $address=Ids::uuid();$order=Ids::uuid();$payment=Ids::uuid();
        $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'X','Y','1')",[$address,$user]);
        $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method) VALUES(?,?,'COMPLETED','1.23',?,'Snapshot','CARD')",[$order,$user,$address]);
        $db->execute("INSERT INTO order_items(order_id,product_id,product_name,quantity,price_snapshot) VALUES(?,?,'Snapshot',1,'1.23')",[$order,$id]);
        $db->execute("INSERT INTO payments(id,order_id,user_id,method,status,amount) VALUES(?,?,?,'CARD','CAPTURED','1.23')",[$payment,$order,$user]);
        truth(in_array($id,array_column($catalog->popular(['limit'=>'100']),'id'),true)===$active);
        foreach([true,false] as $next){
            $updated=$kernel->handle('PUT','/api/v1/admin/products/'.strtoupper($id),$headers,json_encode(['isActive'=>$next]),'127.0.0.1');
            same(200,$updated['status']);same($next,$updated['data']['isActive']);
            foreach([$category,strtoupper($category),mixedUuid($category)] as $filter){
                $result=$catalog->products(['q'=>$word,'categoryId'=>$filter],true);same($next?1:0,$result['totalItems']);same($next?[$id]:[],array_column($result['items'],'id'));
            }
        }
        $catalog->updateProduct(strtoupper($id),['isActive'=>true]);
        same(1,$catalog->products(['q'=>$word],true)['totalItems']);
        $deleted=$kernel->handle('DELETE','/api/v1/admin/products/'.mixedUuid($id),$headers,'','127.0.0.1');same(204,$deleted['status']);same(0,$catalog->products(['q'=>$word],true)['totalItems']);
});
}
test('native admin parser rejects non-boolean isActive',function()use($db,$repo){
    $user=Ids::uuid();$category=round4Category();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,is_admin,local_identity_ready) VALUES(?,?,'','Round4 admin','+79990000000',true,true)",[$user,$user.'@example.invalid']);
    $tokens=$db->transaction(fn()=>(new SessionService($db,$repo))->issue($user));$kernel=new ApiKernel($db);
    $headers=['authorization'=>'Bearer '.$tokens['accessToken'],'content-type'=>'application/json'];
    foreach([null,0,1,'false','true',[],new stdClass()] as $value){
        $result=$kernel->handle('POST','/api/v1/admin/products',$headers,json_encode(['categoryId'=>$category,'name'=>'Invalid flag','price'=>'1.23','isActive'=>$value]),'127.0.0.1');
        same(400,$result['status']);same('BAD_REQUEST',$result['data']['error']['code']);
    }
});
test('native PostgreSQL statement timeout preserves SQLSTATE and successful rollback keeps session usable',function(){
    $db=new Database(Env::get('DATABASE_URL'));$pdo=$db->pdo();
    try{$db->transaction(function()use($db){$db->execute("SET LOCAL statement_timeout='30ms'");$db->one('SELECT pg_sleep(1)');});throw new RuntimeException('expected cancellation');}
    catch(PDOException $error){same('57014',$error->getCode());same('57014',$error->errorInfo[0]);}
    truth($db->pdo()===$pdo);same(42,$db->one('SELECT 42 AS n')['n']);
});
test('native terminated transaction preserves cause and requires an explicitly fresh connection',function(){
    $db=new Database(Env::get('DATABASE_URL'));$observer=new Database(Env::get('DATABASE_URL'));$primary=null;
    try{$db->transaction(function()use($db,$observer,&$primary){
        $pid=$db->one('SELECT pg_backend_pid() AS pid')['pid'];$observer->one('SELECT pg_terminate_backend(?)',[$pid]);
        try{$db->one('SELECT 42');}catch(PDOException $error){$primary=$error;throw $error;}
    });throw new RuntimeException('expected disconnection');}
    catch(PDOException $error){truth($error===$primary);truth(in_array($error->errorInfo[0],['HY000','08006','57P01'],true));}
    try{$db->pdo();throw new RuntimeException('terminated connection reused');}catch(PDOException $error){truth($error===$primary);}
    same(42,(new Database(Env::get('DATABASE_URL')))->one('SELECT 42 AS n')['n']);
});
test('native server rejected deferred commit retains SQLSTATE and a usable session without replay',function(){
    $db=new Database(Env::get('DATABASE_URL'));$pdo=$db->pdo();$calls=0;
    $db->execute('CREATE TEMP TABLE round4_commit(value int UNIQUE DEFERRABLE INITIALLY DEFERRED)');
    try{$db->transaction(function()use($db,&$calls){$calls++;$db->execute('INSERT INTO round4_commit VALUES(1),(1)');});throw new RuntimeException('expected commit rejection');}
    catch(PDOException $error){same('23505',$error->getCode());same('23505',$error->errorInfo[0]);}
    same(1,$calls);truth($pdo===$db->pdo());same(0,$db->one('SELECT count(*) AS n FROM round4_commit')['n']);
});
runTests();
