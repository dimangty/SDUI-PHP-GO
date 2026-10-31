<?php
declare(strict_types=1);

use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient,OpenSearch};
use Shop\repositories\ShopRepository;

final class SearchNativeFixture
{
    public static function configure(): void
    {
        if(Env::production() || !preg_match('/^shopkmp-[a-z0-9-]+-e2e$/D',getenv('ROUND5_SEARCH_DISPOSABLE')?:'')){throw new RuntimeException('Explicit owned disposable search opt-in required');}
        if(Env::get('DATABASE_URL')===''){
            putenv('DATABASE_URL=postgres://shopkmp:'.rawurlencode(Env::get('POSTGRES_PASSWORD')).'@postgres:5432/shopkmp?sslmode=disable');
        }
    }
    public static function db(bool $private=false): Database
    {
        $db=new Database(Env::get('DATABASE_URL'));
        if($private){
            // Native temporary tables isolate whole-catalog fixtures from other
            // workers on this shared disposable DB. No public row is deactivated.
            foreach(['categories','products','product_images','reviews'] as $table){$db->execute('CREATE TEMP TABLE '.$table.' (LIKE public.'.$table.' INCLUDING DEFAULTS)');}
        }
        return $db;
    }
    public static function name(): string {return 'shopkmp-products-r5-'.str_replace('-','',Ids::uuid());}
    public static function search(Database $db,string $name): OpenSearch {return new OpenSearch($db,new ShopRepository($db),$name);}
    public static function product(Database $db,?string $id=null): string
    {
        $category=Ids::uuid();$id??=Ids::uuid();
        $db->execute("INSERT INTO categories(id,name) VALUES(?,'Search native fixture')",[$category]);
        $db->execute("INSERT INTO products(id,category_id,name,description,price,stock) VALUES(?,?,'Round5Native needle','SQL authoritative','1.23',3)",[$id,$category]);
        return $id;
    }
    public static function http(string $method,string $path,?array $body=null): array
    {
        return (new HttpClient(5,8388608))->request($method,rtrim(Env::get('OPENSEARCH_URL'),'/').$path,['Content-Type'=>'application/json'],$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
    }
    public static function worker(string $action,string $name,string $id='',bool $barrier=false): array
    {
        $process=proc_open([PHP_BINARY,__DIR__.'/round5_search_worker.php',$action,$name,$id],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_replace(getenv(),['ROUND5_SEARCH_BARRIER'=>$barrier?($action==='bootstrap'?'build':'1'):'0']));
        if(!is_resource($process)){throw new RuntimeException('Cannot start native search worker');}
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);return [$process,$pipes];
    }
    public static function finish(array $worker,bool $kill=false): array
    {
        [$process,$pipes]=$worker;if($kill){proc_terminate($process,SIGTERM);}
        // Normal index work has a 20s production budget plus bounded cleanup;
        // the harness must not kill a correct worker at an invented 5s SLA.
        $end=hrtime(true)/1e9+($kill?5:25);
        do{$status=proc_get_status($process);if(!$status['running']){break;}usleep(10000);}while(hrtime(true)/1e9<$end);
        if($status['running']){proc_terminate($process,SIGKILL);throw new RuntimeException('Native search worker exceeded its work and cleanup budget');}
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        return ['exit'=>$status['signaled']?128+$status['termsig']:$status['exitcode'],'out'=>$out,'error'=>$error];
    }
}
