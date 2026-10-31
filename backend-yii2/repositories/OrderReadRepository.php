<?php
declare(strict_types=1);
namespace Shop\repositories;

use Shop\domain\{ApiError,Rules};
use Shop\infrastructure\Database;

final class OrderReadRepository
{
    public function __construct(private Database $db) {}
    public static function projected(array $query): bool
    {
        if(!array_key_exists('projection',$query)){return false;}
        if($query['projection']!=='sdui-order-summary-v1'){throw new ApiError('INVALID_PROJECTION','unsupported order projection');}
        return true;
    }
    private function loadDtos(array $rows): array
    {
        if(!$rows){return [];}$ids=array_column($rows,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$items=[];
        foreach($this->db->all('SELECT * FROM order_items WHERE order_id IN ('.$marks.') ORDER BY order_id,id',$ids) as $item){$items[$item['order_id']][]=$item;}
        return array_map(static fn(array $row): array=>OrderDtoMapper::map($row,$items[$row['id']]??[]),$rows);
    }
    public function order(string $id,?string $user=null): array
    {
        $row=$this->db->required('SELECT * FROM orders WHERE id=?'.($user===null?'':' AND user_id=?'),$user===null?[$id]:[$id,$user],'ORDER_NOT_FOUND','order not found');
        return $this->loadDtos([$row])[0];
    }
    public function page(string $user,string $status,int $page,int $size,bool $summary=false): array
    {
        $n=$this->db->one('SELECT count(*) AS n FROM orders WHERE user_id=? AND (?=\'\' OR status=?)',[$user,$status,$status])['n'];
        $columns=$summary?'id,number,user_id,status,total_amount,delivery_address,payment_method,created_at':'*';
        $rows=$this->db->all('SELECT '.$columns.' FROM orders WHERE user_id=? AND (?=\'\' OR status=?) ORDER BY created_at DESC,id LIMIT ? OFFSET ?',[$user,$status,$status,$size,$page*$size]);
        $items=$summary?array_map(OrderDtoMapper::summary(...),$rows):$this->loadDtos($rows);
        return Rules::pageDto($items,$page,$size,$n)+($summary?['projection'=>'sdui-order-summary-v1']:[]);
    }
}
