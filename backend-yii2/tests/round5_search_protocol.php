<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SocketUpstream.php';
require __DIR__.'/SearchFixture.php';

use Shop\infrastructure\{Database,OpenSearch};
use Shop\repositories\ShopRepository;

// Removing any completeness check must turn its corresponding negative into a success.
$bad=[
    'timeout'=>fn(&$v)=>$v['timed_out']=true,
    'missing-timeout'=>function(&$v){unset($v['timed_out']);},
    'coerced-timeout'=>fn(&$v)=>$v['timed_out']=0,
    'missing-shards'=>function(&$v){unset($v['_shards']);},
    'missing-successful'=>function(&$v){unset($v['_shards']['successful']);},
    'missing-shard-total'=>function(&$v){unset($v['_shards']['total']);},
    'missing-failed'=>function(&$v){unset($v['_shards']['failed']);},
    'failed-shard'=>fn(&$v)=>$v['_shards']['failed']=1,
    'incomplete-shards'=>fn(&$v)=>$v['_shards']['total']=2,
    'negative-shards'=>fn(&$v)=>$v['_shards']['total']=-1,
    'coerced-shards'=>fn(&$v)=>$v['_shards']['successful']='1',
    'invalid-skipped'=>fn(&$v)=>$v['_shards']['skipped']=2,
    'failure-details'=>fn(&$v)=>$v['_shards']['failures']=[['reason'=>'fixture']],
    'missing-total'=>function(&$v){unset($v['hits']['total']);},
    'negative-total'=>fn(&$v)=>$v['hits']['total']['value']=-1,
    'coerced-total'=>fn(&$v)=>$v['hits']['total']['value']='0',
    'floating-total'=>fn(&$v)=>$v['hits']['total']['value']=0.5,
    'inexact-total'=>fn(&$v)=>$v['hits']['total']['relation']='gte',
    'short-exact-page'=>fn(&$v)=>$v['hits']['total']['value']=1,
    'missing-relation'=>function(&$v){unset($v['hits']['total']['relation']);},
    'terminated'=>fn(&$v)=>$v['terminated_early']=true,
    'null-termination'=>fn(&$v)=>$v['terminated_early']=null,
    'coerced-termination'=>fn(&$v)=>$v['terminated_early']='false',
    'object-hit-list'=>fn(&$v)=>$v['hits']['hits']=new stdClass(),
    'scalar-hit'=>fn(&$v)=>$v['hits']['hits']=[42],
    'error-envelope'=>fn(&$v)=>$v['error']=['type'=>'fixture'],
];
foreach($bad as $case=>$mutate){
    test("search rejects incomplete semantics before SQL: $case",function()use($mutate){
        $value=SearchFixture::page();$mutate($value);
        $server=SearchFixture::server(function($c,$h,$first)use($value){SearchFixture::respond($c,str_contains($first,'_search')?$value:SearchFixture::metadata());});
        putenv('OPENSEARCH_URL='.$server->origin);$db=new Database('postgres://unused');
        try{errorCode('SEARCH_UNAVAILABLE',fn()=>(new OpenSearch($db,new ShopRepository($db)))->search('needle','','',['priceMin'=>null,'priceMax'=>null],0,20));}
        finally{$server->finish();}
    });
}
foreach(['absent','false'] as $termination){
    test("complete empty search is read-only and refuses partial results: $termination",function()use($termination){
        $server=SearchFixture::server(function($c,$h,$first,$body)use($termination){
            if(str_contains($first,'_search')){
                truth(str_starts_with($first,'POST /shopkmp-products/_search?allow_partial_search_results=false '));
                $query=json_decode($body,true,64,JSON_THROW_ON_ERROR);same(false,$query['_source']);same(true,$query['track_total_hits']);
                $reply=SearchFixture::page();if($termination==='false'){$reply['terminated_early']=false;}
            }else{truth(str_starts_with($first,'GET /shopkmp-products '));$reply=SearchFixture::metadata();}
            SearchFixture::respond($c,$reply);
        });
        putenv('OPENSEARCH_URL='.$server->origin);$db=new Database('postgres://unused');
        try{$page=(new OpenSearch($db,new ShopRepository($db)))->search('needle','','',['priceMin'=>null,'priceMax'=>null],0,20,true);same(0,$page['totalItems']);same('sdui-card-v1',$page['projection']);same([],$page['items']);}
        finally{same(2,$server->finish());}
    });
}
foreach(['missing','building','unmarked','wrong-uuid'] as $state){
    test("search never creates or adopts a target: $state",function()use($state){
        $server=SearchFixture::server(function($c,$h,$first)use($state){
            if(str_starts_with($first,'PUT ') || str_starts_with($first,'DELETE ')){throw new RuntimeException('Read attempted a search mutation');}
            if(str_contains($first,'_search')){SearchFixture::respond($c,SearchFixture::page());return;}
            $value=SearchFixture::metadata(state:$state);
            if($state==='unmarked'){unset($value['shopkmp-products']['mappings']['_meta']);}
            if($state==='wrong-uuid'){$value['shopkmp-products']['mappings']['_meta']['shopkmp_products']['uuid']='different';}
            SearchFixture::respond($c,$value,$state==='missing'?404:200);
        });
        putenv('OPENSEARCH_URL='.$server->origin);$db=new Database('postgres://unused');
        try{errorCode('SEARCH_UNAVAILABLE',fn()=>(new OpenSearch($db,new ShopRepository($db)))->search('needle','','',['priceMin'=>null,'priceMax'=>null],0,20));}
        finally{$server->finish();}
    });
}
test('readiness rejects an existing building target without creating it',function(){
    $server=SearchFixture::server(fn($c)=>SearchFixture::respond($c,SearchFixture::metadata(state:'building')));
    putenv('OPENSEARCH_URL='.$server->origin);$db=new Database('postgres://unused');$rejected=false;
    try{try{(new OpenSearch($db,new ShopRepository($db)))->ready();}catch(UnexpectedValueException){$rejected=true;}truth($rejected);}
    finally{same(1,$server->finish());}
});
runTests();
