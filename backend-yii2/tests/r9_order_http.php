<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient};

if(Env::production() || !preg_match('/^shopkmp-[a-z0-9-]+-e2e$/D',Env::get('ROUND5_SEARCH_DISPOSABLE'))){throw new RuntimeException('Explicit disposable opt-in required');}
$origin=rtrim(Env::get('API_ORIGIN'),'/');if($origin===''){throw new RuntimeException('Shared edge API_ORIGIN required');}
test('native committed historical orders cross actual PHP HTTP and Go SDUI at the unchanged budgets',function()use($origin){
    $db=new Database(Env::get('DATABASE_URL'));$http=new HttpClient(15,8388608);$users=[];$category=Ids::uuid();$product=Ids::uuid();$address=Ids::uuid();$orders=[];
    $call=function(string $method,string $path,?array $body=null,string $token='',int $status=200)use($http,$origin): array {
        $headers=['Content-Type'=>'application/json'];if($token!==''){$headers['Authorization']='Bearer '.$token;}
        $r=$http->request($method,$origin.$path,$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));same($status,$r['status']);
        return [json_decode($r['body'],true,64,JSON_THROW_ON_ERROR),strlen($r['body'])];
    };
    try{
        foreach([0,1] as $i){[$pair]=$call('POST','/api/v1/auth/register',['email'=>Ids::uuid().'@example.invalid','password'=>'HistoricalPass7395','fullName'=>'HTTP order buyer','phone'=>'+79991234567'],'',201);$users[]=$pair['userId'];$pairs[]=$pair;}
        $user=$users[0];$token=$pairs[0]['accessToken'];
        $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'City','Street','1')",[$address,$user]);
        $db->execute("INSERT INTO categories(id,name) VALUES(?,'Native HTTP historical fixture')",[$category]);
        $db->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Current different name','1.00',1)",[$product,$category]);
        foreach([0,1,10,20,100] as $count){
            while(count($orders)<$count){
                $id=Ids::uuid();$orders[]=$id;
                $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method,comment) VALUES(?,?,'COMPLETED','9999999999.99',?,?,'CARD',?)",[$id,$user,$address,str_repeat('😀',255).', '.str_repeat('😀',255).', '.str_repeat('😀',64).', '.str_repeat('😀',64),str_repeat('😀',2000)]);
                $marks=[];$args=[];for($j=0;$j<200;$j++){$marks[]='(?,?,?,1,\'50.01\')';array_push($args,$id,$product,str_repeat('😀',255));}
                $db->execute('INSERT INTO order_items(order_id,product_id,product_name,quantity,price_snapshot) VALUES'.implode(',',$marks),$args);
            }
            [$summary,$summaryBytes]=$call('GET','/api/v1/orders?projection=sdui-order-summary-v1&size=100',null,$token);
            same('sdui-order-summary-v1',$summary['projection']);same($count,$summary['totalItems']);same($count,count($summary['items']));truth($summaryBytes<327680);
            [$screen,$screenBytes]=$call('GET','/api/v1/screens/orders?size=100',null,$token);truth(isset($screen['schemaVersion']));truth($screenBytes<360448);
            print "HTTP_BUDGET orders=$count summary_bytes=$summaryBytes sdui_bytes=$screenBytes upstream_limit=2097152\n";
            if($count===20){[$full,$bytes]=$call('GET','/api/v1/orders?size=20',null,$token);truth($bytes>2097152);same(200,count($full['items'][0]['items']));truth(!isset($full['projection']));
                [$detail]=$call('GET','/api/v1/orders/'.strtoupper($orders[0]),null,$token);same(200,count($detail['items']));
                print "HTTP_FULL_CONTROL orders=20 items_per_order=200 full_bytes=$bytes\n";
            }
        }
        [$foreign]=$call('GET','/api/v1/orders?projection=sdui-order-summary-v1&size=100',null,$pairs[1]['accessToken']);same(0,$foreign['totalItems']);same([],$foreign['items']);
        $call('GET','/api/v1/orders/'.$orders[0],null,$pairs[1]['accessToken'],404);
    }finally{
        foreach($users as $user){$db->execute('DELETE FROM orders WHERE user_id=?',[$user]);$db->execute('DELETE FROM users WHERE id=?',[$user]);}
        $db->execute('DELETE FROM products WHERE id=?',[$product]);$db->execute('DELETE FROM categories WHERE id=?',[$category]);
    }
});
runTests();
