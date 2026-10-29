<?php
declare(strict_types=1);

// Native PG only. Orchestrated by scripts/backend_round3_performance.py.
require '/app/vendor/autoload.php';
use Shop\application\{CatalogService,CommerceService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

if(PHP_SAPI!=='cli' || getenv('ROUND3_DISPOSABLE')!=='shopkmp-round3-final-e2e') {
    fwrite(STDERR,"Explicit disposable project opt-in required\n");exit(2);
}
$parts=parse_url(Env::get('DATABASE_URL'));$dbname=ltrim($parts['path']??'','/');
if(($parts['host']??'')!=='postgres' || !preg_match('/^round3_perf_[a-f0-9]{16}$/D',$dbname)) {
    fwrite(STDERR,"Benchmark requires a uniquely named round3_perf database on project postgres\n");exit(2);
}
$action=$argv[1]??'';
if(!in_array($action,['seed','measure'],true)){fwrite(STDERR,"Expected seed|measure\n");exit(2);}

final class Round3TimingStatement extends PDOStatement {
    public static int $executions=0;
    public static float $elapsedMS=0;
    public static bool $capture=false;
    public static array $queries=[];
    private array $bindings=[];
    protected function __construct() {}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR): bool {
        $this->bindings[$param]=[$value,$type];return parent::bindValue($param,$value,$type);
    }
    public function execute(?array $params=null): bool {
        self::$executions++;$start=hrtime(true);
        try{return parent::execute($params);}
        finally{
            self::$elapsedMS+=(hrtime(true)-$start)/1e6;
            if(self::$capture){self::$queries[]=['sql'=>$this->queryString,'bindings'=>$this->bindings];}
        }
    }
    public static function reset(): void {self::$executions=0;self::$elapsedMS=0;self::$queries=[];}
}
function perfId(string $label): string {
    $h=hash('sha256','round3-performance/'.$label);
    return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
}
function perfJSON(mixed $v): string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function percentile(array $values,float $p): float {sort($values,SORT_NUMERIC);return $values[(int)ceil(count($values)*$p)-1];}
$db=new Database(Env::get('DATABASE_URL'));$pdo=$db->pdo();$repo=new ShopRepository($db);
putenv('OPENSEARCH_URL=');
try{
    if($action==='seed') {
        // This is a fresh isolated DB. Refuse to adopt any pre-existing business data.
        foreach(['users','products','orders','categories'] as $table){if($db->one('SELECT count(*) AS n FROM '.$table)['n']!==0){throw new RuntimeException('Benchmark database is not empty');}}
        $db->transaction(function()use($db){
            foreach([0,1,20,100] as $n){
                $user=perfId('user-'.$n);$category=perfId('category-'.$n);$address=perfId('address-'.$n);
                $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Round3 performance buyer','+79990000000')",[$user,'round3-perf-'.$n.'@example.invalid']);
                $db->execute('INSERT INTO categories(id,name) VALUES(?,?)',[$category,'Round3 performance '.$n]);
                $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'Synthetic city','Synthetic street','1')",[$address,$user]);
                for($i=0;$i<$n;$i++){
                    $id=perfId('product-'.$n.'-'.$i);$order=perfId('order-'.$n.'-'.$i);$payment=perfId('payment-'.$n.'-'.$i);
                    $db->execute("INSERT INTO products(id,category_id,name,description,price,stock,created_at) VALUES(?,?,?,?,'50.01',7,'2026-10-29T00:00:00Z'::timestamptz-?*interval '1 second')",[$id,$category,'Round3 product '.$i,str_repeat('😀',10000),$i]);
                    foreach([['https://media.example.invalid/first.png',0],['https://media.example.invalid/tie.png',0],['https://media.example.invalid/last.png',9]] as [$url,$sort]){$db->execute('INSERT INTO product_images(product_id,url,sort_order) VALUES(?,?,?)',[$id,$url,$sort]);}
                    $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method,comment,created_at) VALUES(?,?,'COMPLETED','100.02',?,'Immutable synthetic address','CARD','Round3 snapshot','2026-10-29T00:00:00Z'::timestamptz-?*interval '1 second')",[$order,$user,$address,$i]);
                    foreach(['First snapshot','Second snapshot'] as $label){$db->execute("INSERT INTO order_items(order_id,product_id,product_name,quantity,price_snapshot) VALUES(?,?,?,1,'50.01')",[$order,$id,$label]);}
                    $db->execute("INSERT INTO payments(id,order_id,user_id,method,status,amount) VALUES(?,?,?,'CARD','CAPTURED','100.02')",[$payment,$order,$user]);
                    $db->execute('UPDATE orders SET payment_id=? WHERE id=?',[$payment,$order]);
                }
            }
        });
        // Statistics on this isolated dataset, not the live application database.
        $pdo->exec('ANALYZE');print perfJSON(['seeded'=>true,'products'=>121,'orders'=>121,'images'=>363,'orderItems'=>242])."\n";exit(0);
    }
    $n=(int)($argv[2]??-1);$variant=$argv[3]??'';
    if(!in_array($n,[0,1,20,100],true) || !in_array($variant,['baseline','current'],true)){throw new RuntimeException('Invalid measurement case');}
    $category=perfId('category-'.$n);$user=perfId('user-'.$n);
    // Popular has no category argument: activate only this case, in an isolated DB.
    $db->execute('UPDATE products SET is_active=(category_id=?)',[$category]);
    $pdo->exec('ANALYZE products');
    $catalog=new CatalogService($db,$repo);$commerce=new CommerceService($db,$repo);
    $reads=[
        'catalog-full'=>fn()=>$catalog->products(['categoryId'=>$category,'size'=>'100']),
        'orders'=>fn()=>$commerce->orders($user,['size'=>'100','status'=>'COMPLETED']),
        'popular-full'=>fn()=>$catalog->popular(['limit'=>'100']),
    ];
    if($variant==='current'){
        $reads['catalog-card']=fn()=>$catalog->products(['categoryId'=>$category,'size'=>'100','projection'=>'sdui-card-v1']);
        $reads['popular-card']=fn()=>$catalog->popular(['limit'=>'100','projection'=>'sdui-card-v1']);
    }
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[Round3TimingStatement::class]);
    $results=[];$plans=[];$outputs=[];
    foreach($reads as $name=>$read){
        Round3TimingStatement::reset();Round3TimingStatement::$capture=true;$output=$read();Round3TimingStatement::$capture=false;
        $queries=Round3TimingStatement::$queries;$encoded=perfJSON($output);$outputHash=hash('sha256',$encoded);
        $items=in_array($name,['popular-full'],true)?$output:$output['items'];
        if(count($items)!==$n || (isset($output['totalItems']) && $output['totalItems']!==$n)){throw new RuntimeException('Measurement lost fixture rows');}
        // Capture one EXPLAIN for every distinct actually executed SQL shape.
        $seen=[];
        foreach($queries as $query){
            if(isset($seen[$query['sql']])){continue;}$seen[$query['sql']]=true;
            if(!str_starts_with(strtoupper(ltrim($query['sql'])),'SELECT')){throw new RuntimeException('Unexpected measured mutation');}
            $stmt=$pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['sql']);
            foreach($query['bindings'] as $key=>[$value,$type]){$stmt->bindValue($key,$value,$type);}
            $stmt->execute();$plan=json_decode($stmt->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            $plans[]=['operation'=>$name,'sql'=>$query['sql'],'bindings'=>$query['bindings'],'explain'=>$plan];
        }
        for($i=0;$i<5;$i++){$read();}
        memory_reset_peak_usage();$memoryStart=memory_get_usage(false);$allocatedStart=memory_get_usage(true);
        $samples=[];
        for($i=0;$i<30;$i++){
            Round3TimingStatement::reset();memory_reset_peak_usage();$sampleMemory=memory_get_usage(false);
            $start=hrtime(true);$value=$read();$wall=(hrtime(true)-$start)/1e6;
            $samples[]=['wallMS'=>$wall,'pdoExecuteMS'=>Round3TimingStatement::$elapsedMS,'pdoExecutions'=>Round3TimingStatement::$executions,'servicePeakDeltaBytes'=>max(0,memory_get_peak_usage(false)-$sampleMemory)];
            // Serialization/hash checking is intentionally outside the timed service call.
            if(hash('sha256',perfJSON($value))!==$outputHash){throw new RuntimeException('Output changed during measurement');}
            unset($value);
        }
        $wall=array_column($samples,'wallMS');$sql=array_column($samples,'pdoExecuteMS');$counts=array_values(array_unique(array_column($samples,'pdoExecutions')));
        $serviceMemory=array_column($samples,'servicePeakDeltaBytes');
        $results[$name]=['items'=>$n,'warmups'=>5,'measuredIterations'=>30,'wallP50MS'=>percentile($wall,.5),'wallP95MS'=>percentile($wall,.95),'pdoExecuteP50MS'=>percentile($sql,.5),'pdoExecuteP95MS'=>percentile($sql,.95),'pdoExecutionCounts'=>$counts,'outputBytes'=>strlen($encoded),'outputSHA256'=>$outputHash,'memoryStartBytes'=>$memoryStart,'memoryPeakBytes'=>memory_get_peak_usage(false),'memoryPeakDeltaBytes'=>max(0,memory_get_peak_usage(false)-$memoryStart),'servicePeakDeltaP50Bytes'=>percentile($serviceMemory,.5),'servicePeakDeltaMaxBytes'=>max($serviceMemory),'allocatedStartBytes'=>$allocatedStart,'allocatedPeakBytes'=>memory_get_peak_usage(true),'samples'=>$samples];
        $outputs[$name]=$output;
        unset($output,$encoded);
    }
    $version=$pdo->query('SHOW server_version')->fetchColumn();
    print perfJSON(['variant'=>$variant,'n'=>$n,'php'=>PHP_VERSION,'architecture'=>php_uname('m'),'postgres'=>$version,'pdoNativePrepares'=>!$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),'results'=>$results,'plans'=>$plans,'outputs'=>$outputs])."\n";
}catch(Throwable $e){
    fwrite(STDERR,'Round3 performance failed: '.get_class($e)."\n");exit(1);
}
