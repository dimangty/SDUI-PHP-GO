<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,OpenSearch};
use Shop\repositories\ShopRepository;
use Shop\application\CatalogService;
$db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$search=new OpenSearch($db,$repo);$catalog=new CatalogService($db,$repo);
test('real OpenSearch indexes SQL products and applies name boost descendants and exact price filters',function()use($db,$search,$catalog){
    $root=Ids::uuid();$child=Ids::uuid();$p1=Ids::uuid();$p2=Ids::uuid();$word='ranking'.str_replace('-','',Ids::uuid());
    $db->execute('INSERT INTO categories(id,name) VALUES(?,\'Search root\')',[$root]);$db->execute('INSERT INTO categories(id,name,parent_id) VALUES(?,\'Search child\',?)',[$child,$root]);
    foreach([[$p1,$word,'other','0.10'],[$p2,'other',$word,'0.20']] as [$id,$name,$description,$price]){$db->execute('INSERT INTO products(id,category_id,name,description,price,stock) VALUES(?,?,?,?,?,5)',[$id,$child,$name,$description,$price]);$search->index($id);}
    $result=$catalog->products(['q'=>$word,'categoryId'=>$root],true);same(2,$result['totalItems']);same($p1,$result['items'][0]['id']);
    $all=$catalog->products(['q'=>" \t ",'categoryId'=>$root,'sort'=>'price_asc','priceMin'=>'0.10','priceMax'=>'0.20'],true);
    same(2,$all['totalItems']);same([$p1,$p2],array_column($all['items'],'id'));
    $result=$catalog->products(['q'=>$word,'categoryId'=>$root,'priceMin'=>'0.20','sort'=>'price_desc'],true);same(1,$result['totalItems']);same($p2,$result['items'][0]['id']);same('0.20',$result['items'][0]['price']);
    errorCode('PAGE_OUT_OF_RANGE',fn()=>$catalog->products(['q'=>$word,'page'=>'500','size'=>'100'],true));
    $catalog->deleteProduct($p2);same(1,$catalog->products(['q'=>$word],true)['totalItems']);
});
test('operator-configured OpenSearch basic-auth URL is supported without exposing credentials in request URLs',function()use($search){
    $original=Env::get('OPENSEARCH_URL');$url=parse_url($original);
    putenv('OPENSEARCH_URL='.$url['scheme'].'://admin:test-secret@'.$url['host'].':'.($url['port']??9200));
    try{$search->ready();}finally{putenv('OPENSEARCH_URL='.$original);}
});
runTests();
