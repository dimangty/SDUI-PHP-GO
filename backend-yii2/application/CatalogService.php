<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids,Money,Rules};
use Shop\infrastructure\{Database,Env,OpenSearch};
use Shop\repositories\{CatalogReadRepository,ShopRepository};

final class CatalogService
{
    public function __construct(private Database $db,private ShopRepository $repo) {}
    public function categories(?string $parent=null): array
    {return array_map($this->repo->categoryDto(...),$this->db->all('SELECT * FROM categories WHERE '.($parent===null?'parent_id IS NULL':'parent_id=?').' ORDER BY sort_order,id',$parent===null?[]:[$parent]));}
    public function category(string $id): array
    {return $this->repo->categoryDto($this->db->required('SELECT * FROM categories WHERE id=?',[$id],'CATEGORY_NOT_FOUND','category not found'));}
    public function children(string $id): array {$this->category($id);return $this->categories($id);}
    private function reads(): CatalogReadRepository {return new CatalogReadRepository($this->db,$this->repo);}
    public function product(string $id): array {return $this->reads()->loadDtos([$this->repo->product($id)])[0];}
    public function products(array $q,bool $search=false): array
    {
        $card=CatalogReadRepository::projected($q);[$page,$size]=Rules::page($q);$query=$search?($q['q']??''):'';$sort=$q['sort']??'';$category=$q['categoryId']??'';
        if(strlen($query)>200){throw new ApiError('INVALID_SEARCH_QUERY','q must not exceed 200 bytes');}
        if(!in_array($sort,['','price_asc','price_desc','rating','new'],true)){throw new ApiError('INVALID_SORT','unsupported product sort');}
        $prices=[];
        foreach(['priceMin','priceMax'] as $key){$raw=$q[$key]??'';$prices[$key]=null;if($raw!==''){try{$value=Money::parse($raw);}catch(ApiError){throw new ApiError('INVALID_PRICE_FILTER',"$key must be a non-negative money value");}if($value<0){throw new ApiError('INVALID_PRICE_FILTER',"$key must be a non-negative money value");}$prices[$key]=$value;}}
        if($prices['priceMin']!==null && $prices['priceMax']!==null && $prices['priceMin']>$prices['priceMax']){throw new ApiError('INVALID_PRICE_FILTER','priceMin cannot exceed priceMax');}
        if($query!=='' && Env::get('OPENSEARCH_URL')!==''){return (new OpenSearch($this->db,$this->repo))->search($query,$category,$sort,$prices,$page,$size,$card);}
        return $this->reads()->page($query,$category,$sort,$prices,$page,$size,$card);
    }
    public function popular(array $query): array
    {
        $card=CatalogReadRepository::projected($query);$limit=min(100,Rules::queryInt($query,'limit',10));if($limit<1){$limit=10;}
        return $this->reads()->popular($limit,$card);
    }
    private function validateProduct(array $input,int $reserved=0): array
    {
        $input['name']=trim($input['name']??'');$input['description']=trim($input['description']??'');
        $price=Money::parse($input['price']??'0');$discount=isset($input['discountPrice'])?Money::parse($input['discountPrice']):null;$stock=$input['stock']??0;
        if($input['name']==='' || mb_strlen($input['name'])>255 || mb_strlen($input['description'])>10000 || $price<=0 || $price>Money::MAX || $stock<0 || $stock>1000000000-$reserved || $discount!==null && ($discount<=0 || $discount>=$price)){throw new ApiError('INVALID_PRODUCT','invalid product fields');}
        $images=$input['imageUrls']??[];if(count($images)>20){throw new ApiError('INVALID_PRODUCT','imageUrls contain an invalid value');}
        foreach($images as $url){if(trim($url)==='' || strlen($url)>2048){throw new ApiError('INVALID_PRODUCT','imageUrls contain an invalid value');}}
        $this->category($input['categoryId']??'');
        return $input+['stock'=>0,'discountPrice'=>null,'imageUrls'=>[],'isActive'=>true];
    }
    public function createProduct(array $input): array
    {
        $input=$this->validateProduct($input);$id=Ids::uuid();
        $dto=$this->db->transaction(function()use($input,$id){$this->writeProduct($id,$input,true);return $this->reads()->loadDtos([$this->repo->product($id,true)])[0];});
        $this->sync($id);return $dto;
    }
    public function updateProduct(string $id,array $changes): array
    {
        $dto=$this->db->transaction(function()use($id,$changes){
            $p=$this->reads()->loadDtos([$this->repo->product($id,true,true)])[0];$next=array_replace($p,$changes);
            $reserved=$this->reserved($id);$next=$this->validateProduct($next,$reserved);$this->writeProduct($id,$next,false);return $this->reads()->loadDtos([$this->repo->product($id,true)])[0];
        });$this->sync($id);return $dto;
    }
    private function writeProduct(string $id,array $p,bool $new): void
    {
        if($new){$this->db->execute('INSERT INTO products(id,category_id,name,description,price,discount_price,stock,is_active) VALUES(?,?,?,?,?,?,?,true)',[$id,$p['categoryId'],$p['name'],$p['description'],$p['price'],$p['discountPrice'],$p['stock']]);}
        else{$this->db->execute('UPDATE products SET category_id=?,name=?,description=?,price=?,discount_price=?,stock=?,is_active=? WHERE id=?',[$p['categoryId'],$p['name'],$p['description'],$p['price'],$p['discountPrice'],$p['stock'],$p['isActive'],$id]);}
        $this->db->execute('DELETE FROM product_images WHERE product_id=?',[$id]);
        foreach($p['imageUrls'] as $i=>$url){$this->db->execute('INSERT INTO product_images(product_id,url,sort_order) VALUES(?,?,?)',[$id,$url,$i]);}
    }
    private function reserved(string $id): int
    {return $this->db->one('SELECT COALESCE(sum(oi.quantity),0)::bigint AS n FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=? AND o.status NOT IN (\'COMPLETED\',\'CANCELLED\')',[$id])['n'];}
    public function patchStock(string $id,array $input): array
    {
        $dto=$this->db->transaction(function()use($id,$input){
            $p=$this->repo->product($id,true,true);$delta=$input['delta']??0;
            if($delta<-$p['stock']){throw new ApiError('INSUFFICIENT_STOCK','stock cannot become negative');}
            if($delta>1000000000-$p['stock']-$this->reserved($id)){throw new ApiError('INVALID_STOCK','stock exceeds the supported limit');}
            $this->db->execute('UPDATE products SET stock=stock+? WHERE id=?',[$delta,$id]);return $this->reads()->loadDtos([$this->repo->product($id,true)])[0];
        });$this->sync($id);return $dto;
    }
    public function deleteProduct(string $id): void
    {if($this->db->execute('UPDATE products SET is_active=false WHERE id=?',[$id])===0){throw new ApiError('PRODUCT_NOT_FOUND','product not found');}$this->sync($id);}
    private function reviewDto(array $r): array
    {return ['id'=>$r['id'],'userId'=>$r['user_id'],'authorName'=>$r['author_name'],'productId'=>$r['product_id'],'rating'=>$r['rating'],'text'=>$r['text'],'createdAt'=>Ids::timestamp($r['created_at'])];}
    public function reviews(string $product,array $q): array
    {
        $this->product($product);[$page,$size]=Rules::page($q);$n=$this->db->one('SELECT count(*) AS n FROM reviews WHERE product_id=?',[$product])['n'];
        $rows=$this->db->all('SELECT r.*,u.full_name AS author_name FROM reviews r JOIN users u ON u.id=r.user_id WHERE product_id=? ORDER BY r.created_at DESC,r.id LIMIT ? OFFSET ?',[$product,$size,$page*$size]);
        return Rules::pageDto(array_map($this->reviewDto(...),$rows),$page,$size,$n);
    }
    public function createReview(string $user,string $product,array $input): array
    {
        $rating=$input['rating']??0;$text=Rules::plain($input['text']??'');
        if($rating<1 || $rating>5 || $text==='' || mb_strlen($text)>2000){throw new ApiError('INVALID_REVIEW','rating must be 1..5 and text at most 2000 characters');}
        $dto=$this->db->transaction(function()use($user,$product,$rating,$text){
            $this->repo->product($product,false,true);$u=$this->repo->user($user);
            if(!$this->db->one('SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.user_id=? AND oi.product_id=? AND o.status=\'COMPLETED\' LIMIT 1',[$user,$product])){throw new ApiError('PURCHASE_REQUIRED','completed purchase is required');}
            $id=Ids::uuid();
            try{$r=$this->db->one('INSERT INTO reviews(id,user_id,product_id,rating,text) VALUES(?,?,?,?,?) RETURNING *',[$id,$user,$product,$rating,$text]);}
            catch(\PDOException $e){if($e->getCode()==='23505'){throw new ApiError('REVIEW_EXISTS','review already exists');}throw $e;}
            $this->rating($product);return $this->reviewDto($r+['author_name'=>$u['full_name']]);
        });$this->sync($product);return $dto;
    }
    public function deleteReview(array $actor,string $id): void
    {
        $product=$this->db->transaction(function()use($actor,$id){
            $r=$this->db->required('SELECT * FROM reviews WHERE id=?',[$id],'REVIEW_NOT_FOUND','review not found');
            if($r['user_id']!==$actor['id'] && !$actor['is_admin']){throw new ApiError('FORBIDDEN','review belongs to another user');}
            // All rating mutations lock product first, including deletion.
            $this->repo->product($r['product_id'],true,true);
            $this->db->required('SELECT id FROM reviews WHERE id=? FOR UPDATE',[$id],'REVIEW_NOT_FOUND','review not found');
            $this->db->execute('DELETE FROM reviews WHERE id=?',[$id]);$this->rating($r['product_id']);return $r['product_id'];
        });$this->sync($product);
    }
    private function rating(string $product): void
    {$this->db->execute('UPDATE products SET rating=(SELECT round(avg(rating)::numeric,1)::double precision FROM reviews WHERE product_id=?) WHERE id=?',[$product,$product]);}
    public function sync(string $id): void
    {
        if(Env::get('OPENSEARCH_URL')===''){return;}
        try{(new OpenSearch($this->db,$this->repo))->index($id);}catch(\Throwable $e){error_log('Product index update failed: '.$e->getMessage());}
    }
}
