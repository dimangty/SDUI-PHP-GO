<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\domain\{ApiError,Ids,Money,Rules};
use Shop\repositories\{CatalogReadRepository,ShopRepository};

final class OpenSearch
{
    private string $indexName;
    public function __construct(private Database $db,private ShopRepository $repo,?string $indexName=null)
    {
        $this->indexName=$indexName??Env::get('OPENSEARCH_PRODUCTS_INDEX','shopkmp-products');
        if(!preg_match('/^shopkmp-products(?:-[a-z0-9][a-z0-9-]{0,79})?$/D',$this->indexName)){throw new \InvalidArgumentException('Invalid product index name');}
    }
    private function call(string $method,string $path,?array $body=null,int|float|null $timeout=null): array
    {
        $base=rtrim(Env::get('OPENSEARCH_URL'),'/');if($base===''){throw new \RuntimeException('OPENSEARCH_URL not configured');}
        $headers=['Content-Type'=>'application/json'];$parsed=parse_url($base);
        if(isset($parsed['user'])){
            $headers['Authorization']='Basic '.base64_encode(rawurldecode($parsed['user']).':'.rawurldecode($parsed['pass']??''));
            $base=$parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port'])?':'.$parsed['port']:'').($parsed['path']??'');
        }
        $r=(new HttpClient($timeout??5,8388608))->request($method,$base.$path,$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
        if($r['status']>=300 && !in_array($r['status'],[400,404],true)){throw new \RuntimeException('OpenSearch returned '.$r['status']);}return $r;
    }
    private function state(SearchBudget $budget,bool $requireReady=true): array
    {
        return SearchIndexState::inspect($this->call('GET','/'.$this->indexName,null,$budget->timeout()),$this->indexName,$requireReady);
    }
    /** Search capability only. Its failure must not gate identity/payment availability. */
    public function ready(): void
    {
        $budget=new SearchBudget((float)Env::duration('READINESS_TIMEOUT',2));$this->state($budget);
        SearchResponse::complete(SearchResponse::decode($this->call('POST','/'.$this->indexName.'/_search?allow_partial_search_results=false',
            ['_source'=>false,'size'=>0,'track_total_hits'=>true],$budget->timeout())));
    }
    /** A normal writer never creates, adopts or publishes an index. */
    public function index(string $id): void
    {
        $this->indexWithin(Ids::canonical($id),new SearchBudget(20));
    }
    private function indexWithin(string $id,SearchBudget $budget,bool $waitForRefresh=true): void
    {
        $before=$this->state($budget);
        $budget->locked($this->db,'shopkmp-products:write:'.$this->indexName.':'.$id,function()use($id,$budget,$before,$waitForRefresh){
            $current=$this->state($budget);
            if($current['uuid']!==$before['uuid']){throw new \UnexpectedValueException('Product index changed during update');}
            $this->writeProduct($id,$budget,$waitForRefresh);
        },true);
    }
    private function writeProduct(string $id,SearchBudget $budget,bool $waitForRefresh=true): void
    {
        $budget->sql($this->db);$p=$this->repo->product($id,true);
        if(!$p['is_active']){
            $r=$this->call('DELETE','/'.$this->indexName.'/_doc/'.rawurlencode($id).'?refresh='.($waitForRefresh?'wait_for':'false'),null,$budget->timeout());
            if(!in_array($r['status'],[200,404],true)){throw new \RuntimeException('Cannot remove indexed product');}return;
        }
        $budget->sql($this->db);$dto=(new CatalogReadRepository($this->db))->loadDtos([$p])[0];$path=[];$category=$p['category_id'];
        while($category!==null){
            if(count($path)>=256 || in_array($category,$path,true)){throw new \UnexpectedValueException('Invalid indexed category ancestry');}
            $path[]=$category;$budget->sql($this->db);$row=$this->db->one('SELECT parent_id FROM categories WHERE id=?',[$category]);
            if($row===null){throw new \UnexpectedValueException('Missing indexed category');}$category=$row['parent_id'];
        }
        $dto+=['salePriceCents'=>Money::parse($p['discount_price']??$p['price']),'categoryPath'=>$path,'createdAt'=>Ids::timestamp($p['created_at'])];
        $r=$this->call('PUT','/'.$this->indexName.'/_doc/'.rawurlencode($id).'?refresh='.($waitForRefresh?'wait_for':'false'),$dto,$budget->timeout());
        if(!in_array($r['status'],[200,201],true)){throw new \RuntimeException('Cannot index product');}
    }
    /** Bounded reconciliation of an already verified index; never initialization. */
    public function reindex(int $limit=100000,int|float $seconds=900): int
    {
        SearchBudget::limit($limit);$budget=new SearchBudget((float)$seconds);$this->state($budget);
        return $budget->maintenance($this->db,function()use($limit,$budget){
            $high=$budget->read($this->db,fn()=>$this->db->one('SELECT id FROM products ORDER BY id DESC LIMIT 1')['id']??null);
            if($high===null){return 0;}$last=null;$count=0;
            do{
                $rows=$budget->read($this->db,fn()=>$this->db->all('SELECT id FROM products WHERE id<=?::uuid AND (?::uuid IS NULL OR id>?::uuid) ORDER BY id LIMIT ?',[$high,$last,$last,min(100,$limit-$count+1)]));
                if(count($rows)>$limit-$count){throw new \RuntimeException('Product reconciliation limit exceeded');}
                foreach($rows as $row){$this->indexWithin($row['id'],$budget,false);$last=$row['id'];$count++;}
            }while($rows && $last!==$high);
            $this->refresh($budget);return $count;
        });
    }
    /** Explicit initialization. Existing verified targets are checked, never re-adopted. */
    public function bootstrap(int $limit=100000,int|float $seconds=900): array
    {
        SearchBudget::limit($limit);$budget=new SearchBudget((float)$seconds);
        return $budget->maintenance($this->db,function()use($limit,$budget){
            $head=$this->call('HEAD','/'.$this->indexName,null,$budget->timeout());
            if($head['status']===200){$this->state($budget);return ['target'=>$this->indexName,'inventory'=>$this->inventoryWithin($limit,$budget),'created'=>false,'switched'=>false];}
            if($head['status']!==404){throw new \RuntimeException('Cannot inspect product index');}
            return $this->build($limit,$budget);
        });
    }
    /** All writers must remain drained through subsequent coordinated config cutover. */
    public function stage(string $target,int $limit=100000,int|float $seconds=900): array
    {
        if($target===$this->indexName){throw new \LogicException('Replacement must differ from the live index');}
        SearchBudget::limit($limit);$budget=new SearchBudget((float)$seconds);$replacement=new self($this->db,$this->repo,$target);
        return $budget->maintenance($this->db,fn()=>['source'=>$this->indexName]+$replacement->build($limit,$budget));
    }
    private function build(int $limit,SearchBudget $budget): array
    {
        return $this->db->transaction(function()use($limit,$budget){
            $budget->sql($this->db);
            // NOWAIT avoids queuing behind undiscovered business/payment writers.
            $this->db->execute('LOCK TABLE categories,products,product_images,reviews IN SHARE MODE NOWAIT');
            $budget->sql($this->db);$expected=array_column($this->db->all('SELECT id FROM products WHERE is_active ORDER BY id LIMIT ?',[$limit+1]),'id');
            if(count($expected)>$limit){throw new \RuntimeException('Product staging limit exceeded');}
            $meta=['version'=>1,'state'=>'building','build'=>bin2hex(random_bytes(16))];
            $mapping=['settings'=>['analysis'=>['analyzer'=>['shop_text'=>['type'=>'standard']]]],'mappings'=>['_meta'=>['shopkmp_products'=>$meta],'properties'=>[
                'id'=>['type'=>'text','fields'=>['keyword'=>['type'=>'keyword']]],
                'name'=>['type'=>'text','analyzer'=>'shop_text','fields'=>['keyword'=>['type'=>'keyword']]],
                'description'=>['type'=>'text','analyzer'=>'shop_text'],'categoryPath'=>['type'=>'keyword'],'salePriceCents'=>['type'=>'long'],'rating'=>['type'=>'double'],'isActive'=>['type'=>'boolean'],'createdAt'=>['type'=>'date'],
            ]]];
            $created=$this->call('PUT','/'.$this->indexName,$mapping,$budget->timeout());
            if($created['status']===400){throw new \LogicException('Replacement index must be newly created');}
            $ack=SearchResponse::decode($created);
            if(($ack->acknowledged??null)!==true || ($ack->shards_acknowledged??null)!==true){throw new \RuntimeException('Product index creation was not acknowledged');}
            $initial=$this->state($budget,false);
            if(!SearchIndexState::same($initial['meta'],$meta)){throw new \UnexpectedValueException('Product index build identity changed');}
            foreach($expected as $id){$this->writeProduct($id,$budget,false);}
            // A private building index needs one bounded visibility barrier, not
            // one refresh wait per product. Search inventory below verifies all
            // searched primary shards; refresh totals also include idle replicas.
            $this->refresh($budget);
            $inventory=$this->inventoryWithin($limit,$budget);
            if(!$inventory['exists'] || $inventory['invalid']!==0 || $inventory['noncanonical']!==0 || $inventory['duplicates']!==0
                || $inventory['documents']!==count($expected) || $inventory['identitySHA256']!==hash('sha256',implode("\n",$expected))){
                throw new \RuntimeException('Staged product identities differ from PostgreSQL');
            }
            $current=$this->state($budget,false);
            if($current['uuid']!==$initial['uuid'] || !SearchIndexState::same($current['meta'],$initial['meta'])){throw new \UnexpectedValueException('Product index build identity changed');}
            $meta=array_replace($meta,['state'=>'ready','uuid'=>$initial['uuid'],'documents'=>$inventory['documents'],'identitySHA256'=>$inventory['identitySHA256']]);
            $reply=SearchResponse::decode($this->call('PUT','/'.$this->indexName.'/_mapping',['_meta'=>['shopkmp_products'=>$meta]],$budget->timeout()));
            if(($reply->acknowledged??null)!==true){throw new \RuntimeException('Product index publication was not acknowledged');}
            $ready=$this->state($budget);
            if($ready['uuid']!==$initial['uuid'] || !SearchIndexState::same($ready['meta'],$meta)){throw new \UnexpectedValueException('Product index publication changed');}
            return ['target'=>$this->indexName,'inventory'=>$inventory,'created'=>true,'switched'=>false];
        });
    }
    private function refresh(SearchBudget $budget): void
    {
        $refreshed=SearchResponse::decode($this->call('POST','/'.$this->indexName.'/_refresh',null,$budget->timeout()));
        if(($refreshed->_shards->failed??null)!==0 || !is_int($refreshed->_shards->successful??null) || $refreshed->_shards->successful<1){throw new \RuntimeException('Product refresh was incomplete');}
    }
    /** Read-only bounded scroll; no creation, document writes or raw IDs in reports. */
    public function inventory(int $limit=100000,int|float $seconds=120): array
    {
        SearchBudget::limit($limit);return $this->inventoryWithin($limit,new SearchBudget((float)$seconds));
    }
    private function inventoryWithin(int $limit,SearchBudget $budget): array
    {
        $report=['index'=>$this->indexName,'exists'=>false,'documents'=>0,'noncanonical'=>0,'duplicates'=>0,'invalid'=>0];
        $head=$this->call('HEAD','/'.$this->indexName,null,$budget->timeout());
        if($head['status']===404){return $report+['identitySHA256'=>hash('sha256','')];}
        if($head['status']!==200){throw new \RuntimeException('Cannot inspect product index');}
        $report['exists']=true;$seen=[];$rawSeen=[];$scroll=null;$total=null;
        try{
            $response=$this->call('POST','/'.$this->indexName.'/_search?scroll=1m&allow_partial_search_results=false',
                ['_source'=>false,'size'=>500,'sort'=>['_doc'],'track_total_hits'=>true],$budget->timeout());
            do{
                $budget->timeout();
                // Capture a usable context even in an HTTP/error envelope which
                // the common semantic validator must subsequently reject.
                $raw=json_decode($response['body'],false,64,JSON_THROW_ON_ERROR);
                if($raw instanceof \stdClass && is_string($raw->_scroll_id??null) && $raw->_scroll_id!==''){$scroll=$raw->_scroll_id;}
                else{throw new \UnexpectedValueException('Invalid inventory scroll identity');}
                unset($raw);$value=SearchResponse::decode($response);
                $parsed=SearchResponse::complete($value);$hits=$parsed['hits'];$count=$parsed['total'];
                if($total!==null && $count!==$total){throw new \RuntimeException('Partial product inventory response');}
                $total=$count;
                if($total>$limit || count($hits)>$total-$report['documents']){throw new \RuntimeException('Product inventory count bound exceeded');}
                foreach($hits as $hit){
                    $report['documents']++;$id=$hit['_id']??null;
                    if(is_string($id)){
                        $key=hash('sha256',$id);
                        if(isset($rawSeen[$key])){throw new \RuntimeException('Product inventory did not advance');}$rawSeen[$key]=true;
                    }
                    if(!is_string($id) || !Ids::valid($id)){$report['invalid']++;continue;}
                    $canonical=Ids::canonical($id);
                    if($canonical!==$id){$report['noncanonical']++;}
                    if(isset($seen[$canonical])){$report['duplicates']++;}$seen[$canonical]=true;
                }
                if(!$hits){if($report['documents']!==$total){throw new \RuntimeException('Product inventory was incomplete');}break;}
                $response=$this->call('POST','/_search/scroll',['scroll'=>'1m','scroll_id'=>$scroll],$budget->timeout());
            }while(true);
        }finally{
            if($scroll!==null){try{$r=$this->call('DELETE','/_search/scroll',['scroll_id'=>[$scroll]],1);if($r['status']!==200){throw new \RuntimeException('Scroll cleanup rejected');}}catch(\Throwable){error_log('Product inventory scroll cleanup failed');}}
        }
        $ids=array_keys($seen);sort($ids,SORT_STRING);
        return $report+['identitySHA256'=>hash('sha256',implode("\n",$ids))];
    }
    public function search(string $query,string $category,string $sort,array $prices,int $page,int $size,bool $card=false): array
    {
        if($size<1 || $size>100 || $page<0 || $page>intdiv(10000,$size) || $page*$size+$size>10000){throw new ApiError('PAGE_OUT_OF_RANGE','requested page exceeds the search result window');}
        try{
            $budget=new SearchBudget(5);$this->state($budget);
            $filters=[['term'=>['isActive'=>true]]];
            if($category!==''){$filters[]=['term'=>['categoryPath'=>Ids::canonical($category)]];}
            $range=[];if($prices['priceMin']!==null){$range['gte']=$prices['priceMin'];}if($prices['priceMax']!==null){$range['lte']=$prices['priceMax'];}if($range){$filters[]=['range'=>['salePriceCents'=>$range]];}
            $sorts=match($sort){'price_asc'=>[['salePriceCents'=>'asc']],'price_desc'=>[['salePriceCents'=>'desc']],'rating'=>[['rating'=>['order'=>'desc','missing'=>'_last']]],'new'=>[['createdAt'=>'desc']],default=>[['_score'=>'desc']]};$sorts[]=['id.keyword'=>'asc'];
            $trimmed=trim($query);$must=$trimmed===''?[['match_all'=>new \stdClass()]]:[['multi_match'=>['query'=>$trimmed,'fields'=>['name^3','description'],'type'=>'best_fields']]];
            $body=['_source'=>false,'from'=>$page*$size,'size'=>$size,'track_total_hits'=>true,'query'=>['bool'=>['must'=>$must,'filter'=>$filters]],'sort'=>$sorts];
            $v=SearchResponse::complete(SearchResponse::decode($this->call('POST','/'.$this->indexName.'/_search?allow_partial_search_results=false',$body,$budget->timeout())));
            $expected=max(0,min($size,$v['total']-$page*$size));
            if(count($v['hits'])!==$expected){throw new \UnexpectedValueException('Incomplete search page');}
            $ids=[];
            foreach($v['hits'] as $hit){
                if(!is_string($hit['_id']??null) || !Ids::valid($hit['_id'])){throw new \UnexpectedValueException('Invalid search hit identity');}$ids[]=$hit['_id'];
            }
            $items=(new CatalogReadRepository($this->db))->byIds($ids,$card);
            return Rules::pageDto($items,$page,$size,$v['total'])+($card?['projection'=>'sdui-card-v1']:[]);
        }catch(\Throwable){error_log('Product search integration failed');throw new ApiError('SEARCH_UNAVAILABLE','product search is temporarily unavailable');}
    }
}
