<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SocketUpstream.php';

use Shop\infrastructure\{Database,OpenSearch};
use Shop\repositories\ShopRepository;

foreach(['timeout','failed-shard','missing-total','inexact-total','short-scan','later-partial','complete'] as $case){
    test("inventory rejects partial or incomplete scrolls: $case",function()use($case){
        $page=0;$server=new SocketUpstream(function($connection,$headers,$first,$body)use($case,&$page){
            $reply=[];
            if(str_contains($first,'_search') && !str_starts_with($first,'DELETE')){
                $page++;
                $hits=match($page){1=>[['_id'=>'abcdef01-abcd-4abc-8abc-abcdef012345'],['_id'=>'ABCDEF01-ABCD-4ABC-8ABC-ABCDEF012345']],2=>[['_id'=>'invalid-legacy']],default=>[]};
                $reply=['_scroll_id'=>'fixture-scroll','timed_out'=>false,'_shards'=>['total'=>1,'successful'=>1,'failed'=>0],'hits'=>['total'=>['value'=>3,'relation'=>'eq'],'hits'=>$hits]];
                if($case==='timeout'){$reply['timed_out']=true;}
                if($case==='failed-shard'){$reply['_shards']['failed']=1;}
                if($case==='missing-total'){unset($reply['hits']['total']);}
                if($case==='inexact-total'){$reply['hits']['total']['relation']='gte';}
                if($case==='short-scan'){$reply['hits']['total']['value']=4;}
                if($case==='later-partial' && $page===2){$reply['_shards']['failed']=1;}
            }
            $raw=json_encode($reply,JSON_THROW_ON_ERROR);
            fwrite($connection,"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($raw)."\r\nConnection: close\r\n\r\n".$raw);
        });
        putenv('OPENSEARCH_URL='.$server->origin);$db=new Database('postgres://unused');
        try{
            try{$result=(new OpenSearch($db,new ShopRepository($db)))->inventory();}
            catch(RuntimeException $error){if($case==='complete'){throw $error;}return;}
            if($case!=='complete'){throw new RuntimeException('Partial inventory was reported as complete');}
            same(3,$result['documents']);same(1,$result['duplicates']);same(1,$result['noncanonical']);same(1,$result['invalid']);
        }finally{$server->finish();}
    });
}
runTests();
