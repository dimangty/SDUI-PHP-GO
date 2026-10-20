<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Shop\domain\{ApiError,Money,Rules};
use Shop\repositories\ShopRepository;

final class OpenSearch
{
    private bool $ensured=false;
    public function __construct(private Database $db,private ShopRepository $repo) {}
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
    private function ensure(): bool
    {
        if($this->ensured){return false;}
        $r=$this->call('HEAD','/shopkmp-products');$created=false;
        if($r['status']===404){
            $mapping=['settings'=>['analysis'=>['analyzer'=>['shop_text'=>['type'=>'standard']]]],'mappings'=>['properties'=>[
                'id'=>['type'=>'text','fields'=>['keyword'=>['type'=>'keyword']]],
                'name'=>['type'=>'text','analyzer'=>'shop_text','fields'=>['keyword'=>['type'=>'keyword']]],
                'description'=>['type'=>'text','analyzer'=>'shop_text'],'categoryPath'=>['type'=>'keyword'],'salePriceCents'=>['type'=>'long'],'rating'=>['type'=>'double'],'isActive'=>['type'=>'boolean'],'createdAt'=>['type'=>'date'],
            ]]];
            $r=$this->call('PUT','/shopkmp-products',$mapping);
            if($r['status']>=300 && !str_contains($r['body'],'resource_already_exists_exception')){throw new \RuntimeException('Cannot create search index');}$created=$r['status']<300;
        }elseif($r['status']!==200){throw new \RuntimeException('Cannot inspect search index');}
        $this->ensured=true;return $created;
    }
    public function index(string $id): void
    {
        $this->ensure();$p=$this->repo->product($id,true);
        if(!$p['is_active']){$r=$this->call('DELETE','/shopkmp-products/_doc/'.rawurlencode($id).'?refresh=wait_for');if(!in_array($r['status'],[200,404],true)){throw new \RuntimeException('Cannot remove indexed product');}return;}
        $dto=$this->repo->productDto($p);$path=[];$category=$p['category_id'];
        while($category!==null && !in_array($category,$path,true)){$path[]=$category;$row=$this->db->one('SELECT parent_id FROM categories WHERE id=?',[$category]);$category=$row['parent_id']??null;}
        $dto+=['salePriceCents'=>Money::parse($p['discount_price']??$p['price']),'categoryPath'=>$path,'createdAt'=>\Shop\domain\Ids::timestamp($p['created_at'])];
        $r=$this->call('PUT','/shopkmp-products/_doc/'.rawurlencode($id).'?refresh=wait_for',$dto);
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
    public function search(string $query,string $category,string $sort,array $prices,int $page,int $size): array
    {
        if($page>intdiv(10000,$size) || $page*$size+$size>10000){throw new ApiError('PAGE_OUT_OF_RANGE','requested page exceeds the search result window');}
        try{
            if($this->ensure()){$this->reindex();}
            $filters=[['term'=>['isActive'=>true]]];
            if($category!==''){$filters[]=['term'=>['categoryPath'=>$category]];}
            $range=[];if($prices['priceMin']!==null){$range['gte']=$prices['priceMin'];}if($prices['priceMax']!==null){$range['lte']=$prices['priceMax'];}if($range){$filters[]=['range'=>['salePriceCents'=>$range]];}
            $sorts=match($sort){'price_asc'=>[['salePriceCents'=>'asc']],'price_desc'=>[['salePriceCents'=>'desc']],'rating'=>[['rating'=>['order'=>'desc','missing'=>'_last']]],'new'=>[['createdAt'=>'desc']],default=>[['_score'=>'desc']]};$sorts[]=['id.keyword'=>'asc'];
            $trimmed=trim($query);
            $must=$trimmed===''?[['match_all'=>new \stdClass()]]:[['multi_match'=>['query'=>$trimmed,'fields'=>['name^3','description'],'type'=>'best_fields']]];
            $body=['from'=>$page*$size,'size'=>$size,'track_total_hits'=>true,'query'=>['bool'=>['must'=>$must,'filter'=>$filters]],'sort'=>$sorts];
            $r=$this->call('POST','/shopkmp-products/_search',$body);$v=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);
            if($r['status']!==200 || !is_array($v['hits']['hits']??null) || !is_int($v['hits']['total']['value']??null)){throw new \RuntimeException('Invalid search response');}
            $items=[];
            foreach($v['hits']['hits'] as $hit){
                // Rehydrate current stock/rating and never expose inactive products from a stale index.
                try{$items[]=$this->repo->productDto($this->repo->product($hit['_id']));}catch(ApiError $e){if($e->errorCode!=='PRODUCT_NOT_FOUND'){throw $e;}}
            }
            return Rules::pageDto($items,$page,$size,$v['hits']['total']['value']);
        }catch(\Throwable $e){error_log('Product search failed: '.$e->getMessage());throw new ApiError('SEARCH_UNAVAILABLE','product search is temporarily unavailable');}
    }
}
