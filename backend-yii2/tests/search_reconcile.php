<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SearchFixture.php';
use Shop\infrastructure\{Database,OpenSearch,Env};
use Shop\repositories\ShopRepository;
use Shop\domain\Ids;

test('reconciliation uses non-waiting writes and one final visibility barrier on native SQL',function(){
    // Fork before opening PDO: the protocol child must not inherit a DB socket.
    $server=SearchFixture::server(function($connection,$headers,$first){
        static $writes=0,$refreshes=0;
        if(str_contains($first,'/_doc/')){
            truth(str_contains($first,'?refresh=false '));$writes++;
            SearchFixture::respond($connection,['result'=>'updated'],str_starts_with($first,'PUT ')?201:200);
        }elseif(str_contains($first,'/_refresh ')){
            same(3,$writes);same(1,++$refreshes);SearchFixture::respond($connection,['_shards'=>['total'=>2,'successful'=>1,'failed'=>0]]);
        }else{truth(str_starts_with($first,'GET /shopkmp-products '));SearchFixture::respond($connection,SearchFixture::metadata());}
    });
    $old=getenv('OPENSEARCH_URL');putenv('OPENSEARCH_URL='.$server->origin);
    try{
        $db=new Database(Env::get('DATABASE_URL'));
        foreach(['categories','products','product_images','reviews'] as $table){$db->execute('CREATE TEMP TABLE '.$table.' (LIKE public.'.$table.' INCLUDING DEFAULTS)');}
        $category=Ids::uuid();$db->execute("INSERT INTO categories(id,name) VALUES(?,'Reconcile fixture')",[$category]);
        foreach([true,true,false] as $active){$db->execute("INSERT INTO products(id,category_id,name,price,stock,is_active) VALUES(?,?,'Reconcile fixture','1.23',1,?)",[Ids::uuid(),$category,$active]);}
        same(3,(new OpenSearch($db,new ShopRepository($db)))->reindex());same(11,$server->finish());$server=null;
    }finally{putenv($old===false?'OPENSEARCH_URL':'OPENSEARCH_URL='.$old);if($server!==null){$server->finish();}}
});
runTests();
