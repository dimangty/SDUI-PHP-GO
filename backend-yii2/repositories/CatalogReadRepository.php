<?php
declare(strict_types=1);
namespace Shop\repositories;

use Shop\domain\{ApiError,Money,Rules};
use Shop\infrastructure\Database;

/** Parent pagination always precedes the single ordered image load. */
final class CatalogReadRepository
{
    public function __construct(private Database $db,private ShopRepository $repo) {}
    public static function projected(array $query): bool
    {
        if(!array_key_exists('projection',$query)){return false;}
        if($query['projection']!=='sdui-card-v1'){throw new ApiError('INVALID_PROJECTION','unsupported product projection');}
        return true;
    }
    private function columns(bool $card): string
    {
        return ($card?'p.id,p.category_id,p.name,p.price,p.discount_price,p.stock,p.rating,p.is_active,left(p.description,257) AS description,char_length(p.description)<=257 AS description_complete':'p.*').', (SELECT count(*) FROM reviews r WHERE r.product_id=p.id) AS reviews_count';
    }
    private function images(array $ids,bool $card=false): array
    {
        if(!$ids){return [];}$marks=implode(',',array_fill(0,count($ids),'?'));
        $rows=$this->db->all('SELECT '.($card?'DISTINCT ON (product_id) ':'').'product_id,url FROM product_images WHERE product_id IN ('.$marks.') ORDER BY product_id,sort_order,id',$ids);
        $images=[];foreach($rows as $row){$images[$row['product_id']][]=$row['url'];}return $images;
    }
    public function loadDtos(array $rows,bool $card=false): array
    {
        $images=$this->images(array_column($rows,'id'),$card);
        return array_map(fn($row)=>$this->repo->productDto($row,$images[$row['id']]??[],$card),$rows);
    }
    public function byIds(array $ids,bool $card=false): array
    {
        if(!$ids){return [];}$ids=array_values(array_unique($ids));$marks=implode(',',array_fill(0,count($ids),'?'));
        $rows=$this->db->all('SELECT '.$this->columns($card).' FROM products p WHERE p.is_active AND p.id IN ('.$marks.')',$ids);
        $mapped=array_column($this->loadDtos($rows,$card),null,'id');$ordered=[];
        foreach($ids as $id){if(isset($mapped[$id])){$ordered[]=$mapped[$id];}}return $ordered;
    }
    public function page(string $query,string $category,string $sort,array $prices,int $page,int $size,bool $card): array
    {
        $filter='p.is_active';$args=[];
        if($category!==''){$filter.=' AND p.category_id IN (WITH RECURSIVE tree AS (SELECT id FROM categories WHERE id=? UNION SELECT c.id FROM categories c JOIN tree t ON c.parent_id=t.id) SELECT id FROM tree)';$args[]=$category;}
        if($query!==''){$filter.=' AND (p.name ILIKE ? OR p.description ILIKE ?)';$args[]='%'.trim($query).'%';$args[]='%'.trim($query).'%';}
        foreach(['priceMin'=>'>=','priceMax'=>'<='] as $key=>$op){if($prices[$key]!==null){$filter.=' AND COALESCE(p.discount_price,p.price)'.$op.'?::numeric';$args[]=Money::format($prices[$key]);}}
        $order=match($sort){'price_asc'=>'COALESCE(p.discount_price,p.price),p.id','price_desc'=>'COALESCE(p.discount_price,p.price) DESC,p.id','rating'=>'p.rating DESC NULLS LAST,p.id',default=>'p.created_at DESC,p.id'};
        $total=$this->db->one('SELECT count(*) AS n FROM products p WHERE '.$filter,$args)['n'];
        $rows=$this->db->all('SELECT '.$this->columns($card).' FROM products p WHERE '.$filter.' ORDER BY '.$order.' LIMIT ? OFFSET ?',[...$args,$size,$page*$size]);
        return Rules::pageDto($this->loadDtos($rows,$card),$page,$size,$total)+($card?['projection'=>'sdui-card-v1']:[]);
    }
    public function popular(int $limit,bool $card): array
    {
        $rows=$this->db->all('SELECT oi.product_id FROM order_items oi JOIN payments p ON p.order_id=oi.order_id JOIN products product ON product.id=oi.product_id WHERE p.status=\'CAPTURED\' AND p.updated_at>=now()-interval \'30 days\' AND product.is_active GROUP BY oi.product_id ORDER BY sum(oi.quantity) DESC,oi.product_id LIMIT ?',[$limit]);
        $items=$this->byIds(array_column($rows,'product_id'),$card);
        return $card?['projection'=>'sdui-card-v1','items'=>$items]:$items;
    }
}
