<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SocketUpstream.php';
require __DIR__.'/SearchFixture.php';

use Shop\domain\Ids;
use Shop\infrastructure\{Database,OpenSearch};
use Shop\repositories\ShopRepository;

test('search requests metadata only and canonical category terms preserving page sort and exact total',function(){
    $server=SearchFixture::server(function($connection,$headers,$first,$body){
        if(str_starts_with($first,'POST ')){
            $query=json_decode($body,true,64,JSON_THROW_ON_ERROR);
            same(false,$query['_source']??null);same(40,$query['from']);same(20,$query['size']);same(true,$query['track_total_hits']);
            same([['salePriceCents'=>'desc'],['id.keyword'=>'asc']],$query['sort']);
            same([['term'=>['isActive'=>true]],['term'=>['categoryPath'=>'abcdef01-abcd-4abc-8abc-abcdef012345']],['range'=>['salePriceCents'=>['gte'=>123,'lte'=>456]]]],$query['query']['bool']['filter']);
        }
        SearchFixture::respond($connection,str_contains($first,'_search')?SearchFixture::page([],40):SearchFixture::metadata());
    });
    putenv('OPENSEARCH_URL='.$server->origin);$db=new Database('postgres://unused');
    try{
        $page=(new OpenSearch($db,new ShopRepository($db)))->search('rank','ABCDEF01-ABCD-4ABC-8ABC-ABCDEF012345','price_desc',['priceMin'=>123,'priceMax'=>456],2,20,true);
        same(['projection'=>'sdui-card-v1','items'=>[],'page'=>2,'size'=>20,'totalItems'=>40,'totalPages'=>2],$page);
    }finally{same(2,$server->finish());}
});
runTests();
