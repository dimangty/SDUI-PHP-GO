<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient,OpenSearch};
use Shop\repositories\{CatalogReadRepository,ShopRepository};

if(PHP_SAPI!=='cli' || getenv('ROUND4_DISPOSABLE')!=='shopkmp-round4-e2e'){
    fwrite(STDERR,"Explicit round4 disposable project opt-in required\n");exit(2);
}
final class Round4PerfStatement extends PDOStatement {
    public static int $executions=0;
    protected function __construct() {}
    public function execute(?array $params=null): bool {self::$executions++;return parent::execute($params);}
}
$db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$http=new HttpClient(10,64*1024*1024);
$base=rtrim(Env::get('OPENSEARCH_URL'),'/');$action=$argv[1]??'';
if($action==='seed'){
    $category=Ids::uuid();$index='shopkmp-products-perf-'.str_replace('-','',Ids::uuid());$ids=[];
    $images=[];for($i=0;$i<20;$i++){$prefix='https://media.example.invalid/'.sprintf('%02d',$i).'/';$images[]=$prefix.str_repeat('a',2048-strlen($prefix));}
    $db->transaction(function()use($db,$category,$images,&$ids){
        $db->execute("INSERT INTO categories(id,name) VALUES(?,'Round4 performance')",[$category]);
        for($i=0;$i<100;$i++){
            $id=Ids::uuid();$ids[]=$id;
            $db->execute("INSERT INTO products(id,category_id,name,description,price,stock) VALUES(?,?,?,?,'12.34',7)",[$id,$category,'Round4Performance '.sprintf('%03d',$i),str_repeat('😀',10000)]);
            foreach($images as $order=>$url){$db->execute('INSERT INTO product_images(product_id,url,sort_order) VALUES(?,?,?)',[$id,$url,$order]);}
        }
    });
    (new OpenSearch($db,$repo,$index))->index($ids[0]);$bulk='';
    foreach((new CatalogReadRepository($db))->byIds($ids) as $dto){
        $bulk.=json_encode(['index'=>['_id'=>$dto['id']]],JSON_THROW_ON_ERROR)."\n";
        $bulk.=json_encode($dto+['salePriceCents'=>1234,'categoryPath'=>[$category],'createdAt'=>'2026-10-30T00:00:00Z'],JSON_THROW_ON_ERROR)."\n";
    }
    $result=$http->request('POST',$base.'/'.$index.'/_bulk?refresh=true',['Content-Type'=>'application/x-ndjson'],$bulk);
    $value=json_decode($result['body'],true,64,JSON_THROW_ON_ERROR);
    if($result['status']!==200 || ($value['errors']??true)){throw new RuntimeException('Synthetic bulk indexing failed');}
    print json_encode(['index'=>$index,'category'=>$category,'products'=>100,'descriptionCodePoints'=>10000,'imagesPerProduct'=>20,'imageURLBytes'=>2048],JSON_THROW_ON_ERROR)."\n";exit(0);
}
$index=$argv[2]??'';$category=$argv[3]??'';
if($action!=='measure' || !preg_match('/^shopkmp-products-perf-[a-f0-9]{32}$/D',$index) || !Ids::valid($category)){throw new InvalidArgumentException('Expected measure and synthetic fixture identifiers');}
$db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[Round4PerfStatement::class]);$samples=[];$expected=null;
// Alternation avoids always giving one variant the warmer cache. First samples
// are process/cache-first observations, NOT a claim of a cold OS/JVM cache.
for($iteration=0;$iteration<18;$iteration++){
    foreach($iteration%2===0?[true,false]:[false,true] as $source){
        $query=['_source'=>$source,'size'=>100,'track_total_hits'=>true,'query'=>['bool'=>['filter'=>[['term'=>['isActive'=>true]],['term'=>['categoryPath'=>$category]]]]],'sort'=>[['id.keyword'=>'asc']]];
        memory_reset_peak_usage();$memory=memory_get_usage(false);$start=hrtime(true);
        $response=$http->request('POST',$base.'/'.$index.'/_search',['Content-Type'=>'application/json'],json_encode($query,JSON_THROW_ON_ERROR));
        if($response['status']!==200){throw new RuntimeException('Synthetic search measurement failed');}
        $bytes=strlen($response['body']);$value=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
        $searchMS=(hrtime(true)-$start)/1e6;$decodePeak=max(0,memory_get_peak_usage(false)-$memory);
        $ids=array_column($value['hits']['hits'],'_id');
        if(count($ids)!==100 || $value['hits']['total']['value']!==100){throw new RuntimeException('Synthetic search lost rows');}
        unset($value,$response);Round4PerfStatement::$executions=0;$start=hrtime(true);
        $items=(new CatalogReadRepository($db))->byIds($ids,true);$sql=Round4PerfStatement::$executions;
        $page=['projection'=>'sdui-card-v1','items'=>$items,'page'=>0,'size'=>100,'totalItems'=>100,'totalPages'=>1];
        $body=json_encode($page,JSON_THROW_ON_ERROR);$hash=hash('sha256',$body);$expected??=$hash;
        if($hash!==$expected || $sql!==2){throw new RuntimeException('Projection equality or batch budget changed');}
        $samples[]=['variant'=>$source?'full-source-control':'metadata-only','iteration'=>$iteration,'phase'=>$iteration===0?'first':($iteration<3?'warmup':'measured'),'openSearchBytes'=>$bytes,'searchHTTPAndDecodeMS'=>$searchMS,'searchPeakDeltaBytes'=>$decodePeak,'phpToGoBytes'=>strlen($body),'rehydrateAndEncodeMS'=>(hrtime(true)-$start)/1e6,'sqlStatements'=>$sql];
        unset($items,$page,$body);
    }
}
print json_encode(['php'=>PHP_VERSION,'postgres'=>$db->one('SHOW server_version')['server_version'],'dataset'=>['products'=>100,'descriptionCodePoints'=>10000,'imagesPerProduct'=>20,'imageURLBytes'=>2048],'note'=>'64 MiB benchmark-only response ceiling permits measuring the full-source control; production limits are unchanged. Warm shared caches, three warmups and fifteen measured iterations per variant.','outputSHA256'=>$expected,'samples'=>$samples],JSON_THROW_ON_ERROR)."\n";
