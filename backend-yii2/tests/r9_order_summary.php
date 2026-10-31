<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\application\CommerceService;
use Shop\domain\Ids;
use Shop\infrastructure\Database;
use Shop\repositories\{OrderDtoMapper,ShopRepository};

// Native production PDO, SQL and mappers. All synthetic business writes roll back.
final class R9OrderStatement extends PDOStatement
{
    public static array $sql=[];
    protected function __construct() {}
    public function execute(?array $params=null): bool
    {self::$sql[]=$this->queryString;return parent::execute($params);}
}
$db=new Database(getenv('DATABASE_URL')?:throw new RuntimeException('Disposable DATABASE_URL required'));
$db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[R9OrderStatement::class]);
$repo=new ShopRepository($db);$commerce=new CommerceService($db,$repo);
const R9_SUMMARY_KEYS=['id','number','userId','status','totalAmount','deliveryAddress','paymentMethod','createdAt'];
function r9JSON(mixed $value): string
{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function r9Fixture(int $count,Closure $test): mixed
{
    global $db;$db->pdo()->beginTransaction();
    try{
        $user=Ids::uuid();$foreign=Ids::uuid();$address=Ids::uuid();$category=Ids::uuid();$product=Ids::uuid();$orders=[];
        foreach([$user,$foreign] as $u){$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Order summary buyer','+79991234567')",[$u,$u.'@example.invalid']);}
        $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'City','Street','1')",[$address,$user]);
        $db->execute("INSERT INTO categories(id,name) VALUES(?,'Order summary category')",[$category]);
        $db->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Current product differs','1.00',1000)",[$product,$category]);
        $delivery=str_repeat('😀',255).', '.str_repeat('😀',255).', '.str_repeat('😀',64).', '.str_repeat('😀',64);
        for($i=0;$i<$count;$i++){
            $order=Ids::uuid();$orders[]=$order;
            $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method,comment,created_at) VALUES(?,?,'COMPLETED','9999999999.99',?,?,'CARD',?,'2026-10-30T00:00:00Z'::timestamptz-?*interval '1 second')",[$order,$user,$address,$delivery,str_repeat('😀',2000),intdiv($i,2)]);
            $marks=[];$args=[];
            for($j=0;$j<200;$j++){$marks[]='(?,?,?,1,\'50.01\')';array_push($args,$order,$product,str_repeat('😀',255));}
            $db->execute('INSERT INTO order_items(order_id,product_id,product_name,quantity,price_snapshot) VALUES'.implode(',',$marks),$args);
        }
        // Newer wrong-status and foreign rows must not consume the filtered LIMIT.
        foreach([[$user,'CANCELLED'],[$foreign,'COMPLETED']] as [$u,$status]){
            $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method,created_at) VALUES(?,?,?,'1.00',?,'Distractor','SBP','2026-11-01T00:00:00Z')",[Ids::uuid(),$u,$status,$address]);
        }
        // Each timestamp has a pair; the UUID tie-break is ascending, not insertion order.
        $expected=[];foreach(array_chunk($orders,2) as $pair){sort($pair,SORT_STRING);array_push($expected,...$pair);}
        return $test($user,$address,$product,$expected,$delivery);
    }finally{$db->pdo()->rollBack();}
}
function r9Summary(string $user,array $query): array
{
    global $commerce;R9OrderStatement::$sql=[];
    $page=$commerce->orders($user,$query+['projection'=>'sdui-order-summary-v1']);
    same('sdui-order-summary-v1',$page['projection']??null);
    same(2,count(R9OrderStatement::$sql));
    foreach(R9OrderStatement::$sql as $sql){truth((bool)preg_match('/^SELECT\b/i',$sql));truth(!preg_match('/\border_items\b/i',$sql));}
    foreach($page['items'] as $item){same(R9_SUMMARY_KEYS,array_keys($item));}
    return $page;
}
if(($argv[1]??'')==='--export'){
    $cases=[];$full=null;
    foreach([0,1,10,20,100] as $count){$cases[(string)$count]=r9Fixture($count,function($u)use($count,&$full,$commerce){
        $summary=r9Summary($u,['size'=>'100','status'=>'COMPLETED']);
        if($count===20){$full=$commerce->orders($u,['size'=>'100','status'=>'COMPLETED']);}
        return $summary;
    });}
    print r9JSON(['cases'=>$cases,'maxFull'=>$full]);exit(0);
}
foreach([0,1,10,20,100] as $count){
    test("summary $count orders x 200 maximum-Unicode items uses two SELECTs without order_items",function()use($count,$commerce){r9Fixture($count,function($u,$a,$p,$ids,$delivery)use($count,$commerce){
        $query=['size'=>'100','status'=>'COMPLETED'];$summary=r9Summary(strtoupper($u),$query);
        same($count,$summary['totalItems']);same($count===0?0:1,$summary['totalPages']);same($ids,array_column($summary['items'],'id'));
        foreach($summary['items'] as $item){same($u,$item['userId']);same($delivery,$item['deliveryAddress']);same('9999999999.99',$item['totalAmount']);same('CARD',$item['paymentMethod']);}
        $full=$commerce->orders($u,$query);truth(!array_key_exists('projection',$full));same($count,count($full['items']));
        foreach($full['items'] as $i=>$item){same(200,count($item['items']));same(str_repeat('😀',255),$item['items'][0]['productName']);same('50.01',$item['items'][199]['priceSnapshot']);same(str_repeat('😀',2000),$item['comment']);
            same($summary['items'][$i],array_intersect_key($item,array_flip(R9_SUMMARY_KEYS)));
        }
        $summaryBytes=strlen(r9JSON($summary));$fullBytes=strlen(r9JSON($full));truth($summaryBytes<2*1024*1024);
        if($count===20){truth($fullBytes>2*1024*1024);}
        print "BUDGET orders=$count items_per_order=200 name_codepoints=255 name_utf8_bytes=1020 summary_selects=2 order_items_selects=0 summary_bytes=$summaryBytes full_bytes=$fullBytes\n";
        $past=r9Summary($u,['page'=>'1','size'=>'100','status'=>'COMPLETED']);same([],$past['items']);same($count,$past['totalItems']);
        $empty=r9Summary(Ids::uuid(),$query);same([],$empty['items']);same(0,$empty['totalItems']);
        if($ids){$detail=$commerce->order(strtoupper($u),strtoupper($ids[0]));same($full['items'][0],$detail);errorCode('ORDER_NOT_FOUND',fn()=>$commerce->order(Ids::uuid(),$ids[0]));}
    });});
}
test('projection filtering pagination UUID order and request-local mode preserve complete defaults',function()use($commerce){r9Fixture(10,function($u,$a,$p,$ids)use($commerce){
    $page=r9Summary($u,['page'=>'1','size'=>'3','status'=>'COMPLETED']);same(array_slice($ids,3,3),array_column($page['items'],'id'));same(10,$page['totalItems']);same(4,$page['totalPages']);
    $all=r9Summary($u,['size'=>'100']);same(11,$all['totalItems']);same('CANCELLED',$all['items'][0]['status']);
    $cancelled=r9Summary($u,['size'=>'1','status'=>'CANCELLED']);same(1,$cancelled['totalItems']);same('SBP',$cancelled['items'][0]['paymentMethod']);
    $full=$commerce->orders($u,['size'=>'3','status'=>'COMPLETED']);truth(isset($full['items'][0]['items'],$full['items'][0]['deliveryAddressId']));truth(array_key_exists('paymentId',$full['items'][0]));truth(!isset($full['projection']));
});});
test('every present unsupported projection fails before native SQL',function()use($commerce){
    foreach(['',null,false,0,[],'other','SDUI-order-summary-v1',' ','sdui-order-summary-v1 '] as $projection){
        R9OrderStatement::$sql=[];
        try{$commerce->orders(Ids::uuid(),['projection'=>$projection]);}
        catch(\Shop\domain\ApiError $e){same('INVALID_PROJECTION',$e->errorCode);same(400,$e->status());same([],R9OrderStatement::$sql);continue;}
        throw new RuntimeException('Unsupported projection accepted');
    }
});
test('summary mapper is pure',function()use($db){r9Fixture(1,function($u,$a,$p,$ids)use($db){
    $row=$db->one('SELECT * FROM orders WHERE id=?',[$ids[0]]);R9OrderStatement::$sql=[];
    $summary=OrderDtoMapper::summary($row);same([],R9OrderStatement::$sql);same(R9_SUMMARY_KEYS,array_keys($summary));
});});
test('create and idempotent replay remain full after a summary read',function()use($db,$repo,$commerce){
    // Production commands own transactions. Clean up only this test's exact
    // synthetic user graph after verifying the replay.
    $u=Ids::uuid();$c=Ids::uuid();$p=Ids::uuid();$a=Ids::uuid();$order=null;
    try{
        $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Checkout summary buyer','+79991234567')",[$u,$u.'@example.invalid']);
        $db->execute("INSERT INTO categories(id,name) VALUES(?,'Checkout summary category')",[$c]);
        $db->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Historical checkout','50.01',2)",[$p,$c]);
        $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'City','Street','1')",[$a,$u]);
        $commerce->addCart($u,['productId'=>$p,'quantity'=>1]);r9Summary($u,['size'=>'20']);
        $input=['deliveryAddressId'=>$a,'paymentMethod'=>'CARD','comment'=>'Historical comment'];
        $order=$commerce->createOrder($u,$input,'r9-order-replay');same('Historical checkout',$order['items'][0]['productName']);same('Historical comment',$order['comment']);truth(array_key_exists('paymentId',$order));truth(!isset($order['projection']));
        r9Summary($u,['size'=>'20']);$db->execute("UPDATE products SET name='Changed current name' WHERE id=?",[$p]);
        same($order,$commerce->createOrder($u,$input,'r9-order-replay'));same($order,$commerce->order($u,$order['id']));
    }finally{
        $db->execute('DELETE FROM idempotency_keys WHERE user_id=?',[$u]);
        $db->execute('DELETE FROM payments WHERE user_id=?',[$u]);$db->execute('DELETE FROM orders WHERE user_id=?',[$u]);
        $db->execute('DELETE FROM cart_items WHERE user_id=?',[$u]);$db->execute('DELETE FROM cart_metadata WHERE user_id=?',[$u]);
        $db->execute('DELETE FROM addresses WHERE user_id=?',[$u]);$db->execute('DELETE FROM products WHERE id=?',[$p]);$db->execute('DELETE FROM categories WHERE id=?',[$c]);$db->execute('DELETE FROM users WHERE id=?',[$u]);
    }
});
runTests();
