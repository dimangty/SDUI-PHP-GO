<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/Round3HttpFixture.php';

use Shop\application\{CatalogService,CommerceService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

// Count actual native PDO executions, including prepared statements, never a DB model.
final class ReadCountingStatement extends PDOStatement {
    public static int $executions=0;
    protected function __construct() {}
    public function execute(?array $params=null): bool {self::$executions++;return parent::execute($params);}
}
$db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);
$db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ReadCountingStatement::class]);
$catalog=new CatalogService($db,$repo);$commerce=new CommerceService($db,$repo);
putenv('OPENSEARCH_URL=');
function measured(string $label,Closure $read): array {
    ReadCountingStatement::$executions=0;$value=$read();$n=ReadCountingStatement::$executions;
    $bytes=strlen(json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    print "MEASURE $label native_pdo_executions=$n utf8_json_bytes=$bytes\n";
    if($n>4){throw new RuntimeException("$label executed $n statements; maximum 4");}return $value;
}
function readFixture(int $n,Closure $test): void {
    global $db;$db->pdo()->beginTransaction();
    try{
        // Exclude earlier suites' sales only inside this rolled-back fixture transaction.
        $db->execute("UPDATE payments SET updated_at=now()-interval '31 days' WHERE status='CAPTURED'");
        $user=Ids::uuid();$category=Ids::uuid();$address=Ids::uuid();$ids=[];$orders=[];
        $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Read Buyer','+79991234567')",[$user,$user.'@test.example']);
        $db->execute("INSERT INTO categories(id,name) VALUES(?,'Read category')",[$category]);
        $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'City','Street','1')",[$address,$user]);
        for($i=0;$i<$n;$i++){
            $id=Ids::uuid();$ids[]=$id;$order=Ids::uuid();$orders[]=$order;$payment=Ids::uuid();
            $db->execute("INSERT INTO products(id,category_id,name,description,price,discount_price,stock,created_at) VALUES(?,?,?,?,'12.34','10.01',7,now()-?*interval '1 second')",[$id,$category,'Read '.$i,str_repeat('😀яé',2500),$i]);
            foreach([['late',9],['first',0],['tie',0]] as [$url,$sort]){$db->execute('INSERT INTO product_images(product_id,url,sort_order) VALUES(?,?,?)',[$id,$url,$sort]);}
            $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method,comment,created_at) VALUES(?,?,'COMPLETED','20.02',?,'Snapshot address','CARD','snapshot comment',now()-?*interval '1 second')",[$order,$user,$address,$i]);
            foreach(['Snapshot one','Snapshot two'] as $name){$db->execute("INSERT INTO order_items(order_id,product_id,product_name,quantity,price_snapshot) VALUES(?,?,?,1,'10.01')",[$order,$id,$name]);}
            $db->execute("INSERT INTO payments(id,order_id,user_id,method,status,amount) VALUES(?,?,?,'CARD','CAPTURED','20.02')",[$payment,$order,$user]);
            $db->execute('UPDATE orders SET payment_id=? WHERE id=?',[$payment,$order]);
        }
        $test($user,$category,$ids,$orders);
    }finally{$db->pdo()->rollBack();}
}
foreach([0,1,20,100] as $n){
    test("catalog full page $n has bounded native PG reads and full ordered images",function()use($n,$catalog){readFixture($n,function($u,$c,$ids)use($n,$catalog){
        $page=measured("catalog-full-$n",fn()=>$catalog->products(['categoryId'=>$c,'size'=>'100']));
        same($n,$page['totalItems']);same($ids,array_column($page['items'],'id'));truth(!isset($page['projection']));
        foreach($page['items'] as $item){same(str_repeat('😀яé',2500),$item['description']);same(['first','tie','late'],$item['imageUrls']);}
    });});
    test("catalog projection $n has bounded reads and card envelope",function()use($n,$catalog){readFixture($n,function($u,$c,$ids)use($n,$catalog){
        $page=measured("catalog-card-$n",fn()=>$catalog->products(['categoryId'=>$c,'size'=>'100','projection'=>'sdui-card-v1']));
        same('sdui-card-v1',$page['projection']??null);same($n,$page['totalItems']);same($ids,array_column($page['items'],'id'));
        foreach($page['items'] as $item){same('first',$item['imageUrl']);same(257,mb_strlen($item['descriptionSnippet'],'UTF-8'));same(false,$item['descriptionComplete']);truth(!isset($item['description'],$item['imageUrls']));}
    });});
    test("orders $n preserve snapshots and owner predicates with bounded native PG reads",function()use($n,$commerce){readFixture($n,function($u,$c,$ids,$orders)use($n,$commerce){
        $page=measured("orders-$n",fn()=>$commerce->orders($u,['size'=>'100','status'=>'COMPLETED']));
        same($n,$page['totalItems']);same($orders,array_column($page['items'],'id'));
        foreach($page['items'] as $item){same(['Snapshot one','Snapshot two'],array_column($item['items'],'productName'));same(['10.01','10.01'],array_column($item['items'],'priceSnapshot'));same('Snapshot address',$item['deliveryAddress']);same('snapshot comment',$item['comment']);}
        same([],$commerce->orders(Ids::uuid(),['size'=>'100'])['items']);
        if($orders){errorCode('ORDER_NOT_FOUND',fn()=>$commerce->order(Ids::uuid(),$orders[0]));}
    });});
    test("popular $n has bounded reads and opt-in projection",function()use($n,$catalog){readFixture($n,function($u,$c,$ids)use($n,$catalog){
        $full=measured("popular-full-$n",fn()=>$catalog->popular(['limit'=>'100']));same($n,count($full));
        sort($ids,SORT_STRING);same($ids,array_column($full,'id'));
        $card=measured("popular-card-$n",fn()=>$catalog->popular(['limit'=>'100','projection'=>'sdui-card-v1']));
        same('sdui-card-v1',$card['projection']??null);same(array_column($full,'id'),array_column($card['items'],'id'));
    });});
}
foreach(['catalog','search','popular'] as $route){
    test("$route rejects every present unsupported projection before database or search I/O",function()use($catalog,$route){
        // An accidental search dispatch to this closed port would produce
        // SEARCH_UNAVAILABLE instead of the required validation error.
        putenv('OPENSEARCH_URL=http://127.0.0.1:1');
        try{foreach(['',null,false,0,[],'other','SDUI-card-v1',' ','sdui-card-v1 '] as $projection){
            ReadCountingStatement::$executions=0;
            try{
                if($route==='popular'){$catalog->popular(['projection'=>$projection]);}
                else{$catalog->products(['projection'=>$projection,'q'=>'x'],$route==='search');}
            }catch(\Shop\domain\ApiError $e){same('INVALID_PROJECTION',$e->errorCode);same(400,$e->status());same(0,ReadCountingStatement::$executions);continue;}
            throw new RuntimeException('Expected INVALID_PROJECTION for '.var_export($projection,true));
        }}finally{putenv('OPENSEARCH_URL=');}
    });
}
test('card Unicode completeness boundaries null image full detail and pagination filters',function()use($db,$catalog){readFixture(1,function($u,$c,$ids)use($db,$catalog){
    $id=$ids[0];$db->execute('DELETE FROM product_images WHERE product_id=?',[$id]);
    foreach(['',str_repeat('я',256),str_repeat('😀',257),str_repeat('é',129)] as $description){
        $db->execute('UPDATE products SET description=? WHERE id=?',[$description,$id]);
        $card=$catalog->products(['categoryId'=>$c,'projection'=>'sdui-card-v1'])['items'][0];
        same(['id','categoryId','name','price','discountPrice','stock','rating','isActive','reviewsCount','imageUrl','descriptionSnippet','descriptionComplete'],array_keys($card));
        same(null,$card['imageUrl']);same(mb_substr($description,0,257,'UTF-8'),$card['descriptionSnippet']);same(mb_strlen($description,'UTF-8')<=257,$card['descriptionComplete']);
        same($description,$catalog->product($id)['description']);same([],$catalog->product($id)['imageUrls']);
        truth(isset($catalog->products(['categoryId'=>$c])['items'][0]['description']));
    }
    $query=['categoryId'=>$c,'priceMin'=>'10.01','priceMax'=>'10.01','sort'=>'price_desc','q'=>'Read'];
    same(1,$catalog->products($query+['projection'=>'sdui-card-v1'],true)['totalItems']);
    same(0,$catalog->products(['categoryId'=>$c,'priceMin'=>'10.02','projection'=>'sdui-card-v1'])['totalItems']);
    $page=$catalog->products($query+['projection'=>'sdui-card-v1','page'=>'1','size'=>'1'],true);same([],$page['items']);same(1,$page['totalItems']);same(1,$page['totalPages']);
});});
test('product and order snapshot mappings perform zero native PDO executions',function()use($db,$repo){readFixture(1,function($u,$c,$ids,$orders)use($db,$repo){
    $product=$repo->product($ids[0]);$order=$db->one('SELECT * FROM orders WHERE id=?',[$orders[0]]);$items=$db->all('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$orders[0]]);
    ReadCountingStatement::$executions=0;
    $dto=$repo->productDto($product,['first','tie','late']);$snapshot=$repo->orderDto($order,$items);
    same(0,ReadCountingStatement::$executions);same(['first','tie','late'],$dto['imageUrls']);same(['Snapshot one','Snapshot two'],array_column($snapshot['items'],'productName'));
});});
test('OpenSearch batch rehydration preserves rank current values inactive exclusion and projection',function()use($db,$catalog){readFixture(20,function($u,$c,$ids)use($db,$catalog){
    // External search is a protocol fixture; SQL rehydration/counting is native PG.
    $db->execute('UPDATE products SET is_active=false WHERE id=?',[$ids[0]]);
    $hits=array_map(fn($id)=>['_id'=>$id],array_reverse($ids));
    $server=new Round3HttpFixture($hits);
    putenv('OPENSEARCH_URL='.$server->origin);
    try{
        $page=measured('opensearch-card-20',fn()=>$catalog->products(['q'=>'Read','projection'=>'sdui-card-v1'],true));
        same('sdui-card-v1',$page['projection']??null);same(20,$page['totalItems']);same(array_reverse(array_slice($ids,1)),array_column($page['items'],'id'));same('first',$page['items'][0]['imageUrl']);
        $full=measured('opensearch-full-20',fn()=>$catalog->products(['q'=>'Read'],true));same(str_repeat('😀яé',2500),$full['items'][0]['description']);same(['first','tie','late'],$full['items'][0]['imageUrls']);
    }finally{putenv('OPENSEARCH_URL=');$server->close();}
});});
test('parent LIMIT retains every child and exact page totals',function()use($catalog,$commerce){readFixture(20,function($u,$c,$ids,$orders)use($catalog,$commerce){
    $page=$catalog->products(['categoryId'=>$c,'size'=>'1','page'=>'1']);same(20,$page['totalItems']);same(20,$page['totalPages']);same($ids[1],$page['items'][0]['id']);same(['first','tie','late'],$page['items'][0]['imageUrls']);
    $page=$commerce->orders($u,['size'=>'1','page'=>'1']);same(20,$page['totalItems']);same(20,$page['totalPages']);same($orders[1],$page['items'][0]['id']);same(['Snapshot one','Snapshot two'],array_column($page['items'][0]['items'],'productName'));
});});
test('popular ranks unequal captured recent sales before LIMIT and excludes distractors',function()use($db,$catalog){readFixture(6,function($u,$c,$ids,$orders)use($db,$catalog){
    // Fixture rows each have two order items: recent captured totals are 4,18,10.
    // The other three would win if date, payment status or active filters moved
    // after LIMIT or disappeared. UUID ordering cannot explain this ranking.
    foreach([2,9,5,100,200,300] as $i=>$quantity){$db->execute('UPDATE order_items SET quantity=? WHERE order_id=?',[$quantity,$orders[$i]]);}
    $db->execute("UPDATE payments SET updated_at=now()-interval '31 days' WHERE order_id=?",[$orders[3]]);
    $db->execute("UPDATE payments SET status='PENDING' WHERE order_id=?",[$orders[4]]);
    $db->execute('UPDATE products SET is_active=false WHERE id=?',[$ids[5]]);
    foreach([false,true] as $card){
        $query=['limit'=>'2']+($card?['projection'=>'sdui-card-v1']:[]);
        $ranked=measured('popular-unequal-'.($card?'card':'full'),fn()=>$catalog->popular($query));
        $items=$card?$ranked['items']:$ranked;same([$ids[1],$ids[2]],array_column($items,'id'));
        if($card){same('sdui-card-v1',$ranked['projection']);same('first',$items[0]['imageUrl']);}
        else{same(['first','tie','late'],$items[0]['imageUrls']);}
        $all=$catalog->popular(['limit'=>'100']+($card?['projection'=>'sdui-card-v1']:[]));
        same([$ids[1],$ids[2],$ids[0]],array_column($card?$all['items']:$all,'id'));
    }
});});
test('orders apply owner and status filters before small LIMIT despite newer foreign orders',function()use($db,$commerce){readFixture(4,function($u,$c,$ids,$orders)use($db,$commerce){
    $foreign=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Foreign buyer','+79991234568')",[$foreign,$foreign.'@test.example']);
    $db->execute("UPDATE orders SET user_id=?,created_at=now()+interval '1 hour' WHERE id IN (?,?)",[$foreign,$orders[0],$orders[1]]);
    $db->execute('UPDATE payments SET user_id=? WHERE order_id IN (?,?)',[$foreign,$orders[0],$orders[1]]);
    foreach([0,1] as $pageNumber){
        $page=measured('orders-owner-limit-page-'.$pageNumber,fn()=>$commerce->orders($u,['size'=>'1','page'=>(string)$pageNumber,'status'=>'COMPLETED']));
        same(2,$page['totalItems']);same(2,$page['totalPages']);same([$orders[$pageNumber+2]],array_column($page['items'],'id'));
        same($u,$page['items'][0]['userId']);same(['Snapshot one','Snapshot two'],array_column($page['items'][0]['items'],'productName'));
    }
    same([],$commerce->orders($u,['size'=>'1','page'=>'2','status'=>'COMPLETED'])['items']);
    errorCode('ORDER_NOT_FOUND',fn()=>$commerce->order($u,$orders[0]));
    // A newer same-owner order with the wrong status must not consume LIMIT.
    $db->execute("UPDATE orders SET status='CANCELLED',created_at=now()+interval '2 hours' WHERE id=?",[$orders[2]]);
    $page=$commerce->orders($u,['size'=>'1','status'=>'COMPLETED']);same(1,$page['totalItems']);same([$orders[3]],array_column($page['items'],'id'));
});});
test('OpenSearch empty projected page retains marker and pagination',function()use($catalog){
    $server=new Round3HttpFixture();putenv('OPENSEARCH_URL='.$server->origin);
    try{$page=$catalog->products(['q'=>'absent','projection'=>'sdui-card-v1'],true);same(['projection'=>'sdui-card-v1','items'=>[],'page'=>0,'size'=>20,'totalItems'=>0,'totalPages'=>0],$page);}
    finally{putenv('OPENSEARCH_URL=');$server->close();}
});
test('healthy readiness advertises catalog card capability',function()use($db){
    putenv('KEYCLOAK_CLIENT_SECRET=fixture');
    $server=new Round3HttpFixture();$old=getenv('KEYCLOAK_ISSUER');putenv('KEYCLOAK_ISSUER='.$server->origin.'/realms/round3');
    try{$result=(new \Shop\infrastructure\ApiKernel($db))->handle('GET','/health/ready',[],'','127.0.0.1');same(200,$result['status']);same(['catalog-cards-v1'],$result['data']['capabilities']??null);}
    finally{putenv($old===false?'KEYCLOAK_ISSUER':'KEYCLOAK_ISSUER='.$old);$server->close();}
});
runTests();
