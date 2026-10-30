<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\domain\{ApiError,Ids,Money,Rules};
use Shop\repositories\{CatalogReadRepository,ShopRepository};

final class OpenSearch
{
    private bool $ensured=false;
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
    public function ready(): void {if($this->call('GET','/',null,Env::duration('READINESS_TIMEOUT',2))['status']!==200){throw new \RuntimeException('OpenSearch unavailable');}}
    private function ensure(bool $requireNew=false): bool
    {
        if($this->ensured){return false;}
        $r=$this->call('HEAD','/'.$this->indexName);$created=false;
        if($r['status']===404){
            $mapping=['settings'=>['analysis'=>['analyzer'=>['shop_text'=>['type'=>'standard']]]],'mappings'=>['properties'=>[
                'id'=>['type'=>'text','fields'=>['keyword'=>['type'=>'keyword']]],
                'name'=>['type'=>'text','analyzer'=>'shop_text','fields'=>['keyword'=>['type'=>'keyword']]],
                'description'=>['type'=>'text','analyzer'=>'shop_text'],'categoryPath'=>['type'=>'keyword'],'salePriceCents'=>['type'=>'long'],'rating'=>['type'=>'double'],'isActive'=>['type'=>'boolean'],'createdAt'=>['type'=>'date'],
            ]]];
            $r=$this->call('PUT','/'.$this->indexName,$mapping);
            if($requireNew && $r['status']>=300){throw new \LogicException('Replacement index must be newly created');}
            if($r['status']>=300 && !str_contains($r['body'],'resource_already_exists_exception')){throw new \RuntimeException('Cannot create search index');}$created=$r['status']<300;
        }elseif($r['status']!==200){throw new \RuntimeException('Cannot inspect search index');}
        elseif($requireNew){throw new \LogicException('Replacement index already exists');}
        $this->ensured=true;return $created;
    }
    public function index(string $id): void
    {
        $id=Ids::canonical($id);
        $this->ensure();$p=$this->repo->product($id,true);
        if(!$p['is_active']){$r=$this->call('DELETE','/'.$this->indexName.'/_doc/'.rawurlencode($id).'?refresh=wait_for');if(!in_array($r['status'],[200,404],true)){throw new \RuntimeException('Cannot remove indexed product');}return;}
        $dto=(new CatalogReadRepository($this->db))->loadDtos([$p])[0];$path=[];$category=$p['category_id'];
        while($category!==null && !in_array($category,$path,true)){$path[]=$category;$row=$this->db->one('SELECT parent_id FROM categories WHERE id=?',[$category]);$category=$row['parent_id']??null;}
        $dto+=['salePriceCents'=>Money::parse($p['discount_price']??$p['price']),'categoryPath'=>$path,'createdAt'=>\Shop\domain\Ids::timestamp($p['created_at'])];
        $r=$this->call('PUT','/'.$this->indexName.'/_doc/'.rawurlencode($id).'?refresh=wait_for',$dto);
        if($r['status']>=300){throw new \RuntimeException('Cannot index product');}
    }
    /** Explicit maintenance reconciliation; SQL is canonical and is never seeded or overwritten. */
    public function reindex(): int
    {
        $this->ensure();$last=null;$count=0;
        do{
            $rows=$this->db->all('SELECT id FROM products WHERE (?::uuid IS NULL OR id>?::uuid) ORDER BY id LIMIT 100',[$last,$last]);
            foreach($rows as $row){$this->index($row['id']);$last=$row['id'];$count++;}
        }while(count($rows)===100);
        return $count;
    }
    /** Read-only bounded scroll; no index creation or product writes. No raw IDs in diagnostics. */
    public function inventory(int $limit=100000): array
    {
        if($limit<1 || $limit>1000000){throw new \InvalidArgumentException('Invalid inventory limit');}
        $report=['index'=>$this->indexName,'exists'=>false,'documents'=>0,'noncanonical'=>0,'duplicates'=>0,'invalid'=>0];
        $head=$this->call('HEAD','/'.$this->indexName);
        if($head['status']===404){return $report+['identitySHA256'=>hash('sha256','')];}
        if($head['status']!==200){throw new \RuntimeException('Cannot inspect product index');}
        $report['exists']=true;$seen=[];$scroll=null;$total=null;
        try{
            $response=$this->call('POST','/'.$this->indexName.'/_search?scroll=1m',['_source'=>false,'size'=>500,'sort'=>['_doc'],'track_total_hits'=>true]);
            do{
                $value=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);
                if($response['status']!==200 || !is_array($value['hits']['hits']??null) || !is_string($value['_scroll_id']??null)){throw new \RuntimeException('Invalid inventory response');}
                $scroll=$value['_scroll_id'];$hits=$value['hits']['hits'];
                $count=$value['hits']['total']['value']??null;
                if(($value['timed_out']??true)!==false || ($value['_shards']['failed']??-1)!==0
                    || !is_int($count) || $count<0 || ($value['hits']['total']['relation']??null)!=='eq'
                    || $total!==null && $count!==$total){throw new \RuntimeException('Partial product inventory response');}
                $total=$count;
                if($total>$limit){throw new \RuntimeException('Product inventory limit exceeded; no complete inventory');}
                foreach($hits as $hit){
                    if(++$report['documents']>$limit){throw new \RuntimeException('Product inventory limit exceeded; no complete inventory');}
                    $id=$hit['_id']??null;
                    if(!is_string($id) || !Ids::valid($id)){$report['invalid']++;continue;}
                    $canonical=Ids::canonical($id);
                    if($canonical!==$id){$report['noncanonical']++;}
                    if(isset($seen[$canonical])){$report['duplicates']++;}
                    $seen[$canonical]=true;
                }
                if(!$hits){
                    if($report['documents']!==$total){throw new \RuntimeException('Product inventory was incomplete');}
                    break;
                }
                $response=$this->call('POST','/_search/scroll',['scroll'=>'1m','scroll_id'=>$scroll]);
            }while(true);
        }finally{
            if($scroll!==null){try{$this->call('DELETE','/_search/scroll',['scroll_id'=>[$scroll]]);}catch(\Throwable){error_log('Product inventory scroll cleanup failed');}}
        }
        $ids=array_keys($seen);sort($ids,SORT_STRING);
        return $report+['identitySHA256'=>hash('sha256',implode("\n",$ids))];
    }
    /** Operator must drain all writers first; this creates/verifies but NEVER switches/deletes an index. */
    public function stage(string $target,int $limit=100000): array
    {
        if($target===$this->indexName){throw new \LogicException('Replacement must differ from the live index');}
        if($limit<1 || $limit>1000000){throw new \InvalidArgumentException('Invalid staging limit');}
        $expected=array_column($this->db->all('SELECT id FROM products WHERE is_active ORDER BY id LIMIT ?',[$limit+1]),'id');
        if(count($expected)>$limit){throw new \RuntimeException('Product staging limit exceeded');}
        $replacement=new self($this->db,$this->repo,$target);$replacement->ensure(true);
        foreach($expected as $id){$replacement->index($id);}
        $inventory=$replacement->inventory($limit);
        if($inventory['invalid']!==0 || $inventory['noncanonical']!==0 || $inventory['duplicates']!==0 || $inventory['documents']!==count($expected) || $inventory['identitySHA256']!==hash('sha256',implode("\n",$expected))){throw new \RuntimeException('Staged product identities differ from PostgreSQL');}
        return ['source'=>$this->indexName,'target'=>$target,'inventory'=>$inventory,'switched'=>false];
    }
    public function search(string $query,string $category,string $sort,array $prices,int $page,int $size,bool $card=false): array
    {
        if($page>intdiv(10000,$size) || $page*$size+$size>10000){throw new ApiError('PAGE_OUT_OF_RANGE','requested page exceeds the search result window');}
        try{
            if($this->ensure()){$this->reindex();}
            $filters=[['term'=>['isActive'=>true]]];
            if($category!==''){$filters[]=['term'=>['categoryPath'=>Ids::canonical($category)]];}
            $range=[];if($prices['priceMin']!==null){$range['gte']=$prices['priceMin'];}if($prices['priceMax']!==null){$range['lte']=$prices['priceMax'];}if($range){$filters[]=['range'=>['salePriceCents'=>$range]];}
            $sorts=match($sort){'price_asc'=>[['salePriceCents'=>'asc']],'price_desc'=>[['salePriceCents'=>'desc']],'rating'=>[['rating'=>['order'=>'desc','missing'=>'_last']]],'new'=>[['createdAt'=>'desc']],default=>[['_score'=>'desc']]};$sorts[]=['id.keyword'=>'asc'];
            $trimmed=trim($query);
            $must=$trimmed===''?[['match_all'=>new \stdClass()]]:[['multi_match'=>['query'=>$trimmed,'fields'=>['name^3','description'],'type'=>'best_fields']]];
            $body=['_source'=>false,'from'=>$page*$size,'size'=>$size,'track_total_hits'=>true,'query'=>['bool'=>['must'=>$must,'filter'=>$filters]],'sort'=>$sorts];
            $r=$this->call('POST','/'.$this->indexName.'/_search',$body);$v=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);
            if($r['status']!==200 || !is_array($v['hits']['hits']??null) || !is_int($v['hits']['total']['value']??null)){throw new \RuntimeException('Invalid search response');}
            // Rehydrate current values in rank order and exclude stale inactive products.
            $ids=[];
            foreach($v['hits']['hits'] as $hit){
                if(!is_array($hit) || !is_string($hit['_id']??null) || !Ids::valid($hit['_id'])){throw new \UnexpectedValueException('Invalid search hit identity');}
                $ids[]=$hit['_id'];
            }
            $items=(new CatalogReadRepository($this->db))->byIds($ids,$card);
            return Rules::pageDto($items,$page,$size,$v['hits']['total']['value'])+($card?['projection'=>'sdui-card-v1']:[]);
        }catch(\Throwable){error_log('Product search integration failed');throw new ApiError('SEARCH_UNAVAILABLE','product search is temporarily unavailable');}
    }
}
