<?php
declare(strict_types=1);
namespace Shop\repositories;

use Shop\domain\{ApiError,Ids,Money};
use Shop\infrastructure\Database;

final class ShopRepository
{
    public function __construct(public readonly Database $db) {}
    public function user(string $id,bool $lock=false): array
    { return $this->db->required('SELECT * FROM users WHERE id=?'.($lock?' FOR UPDATE':''),[$id],'USER_NOT_FOUND','user not found'); }
    public function userDto(array $u): array
    {return ['id'=>$u['id'],'email'=>$u['email'],'fullName'=>$u['full_name'],'phone'=>$u['phone'],'avatarUrl'=>$u['avatar_url'],'defaultAddressId'=>$u['default_address_id'],'dateOfBirth'=>Ids::timestamp($u['date_of_birth']),'createdAt'=>Ids::timestamp($u['created_at'])];}
    public function categoryDto(array $r): array {return ['id'=>$r['id'],'name'=>$r['name'],'parentId'=>$r['parent_id'],'iconUrl'=>$r['icon_url'],'sortOrder'=>$r['sort_order']];}
    public function product(string $id,bool $includeInactive=false,bool $lock=false): array
    {
        return $this->db->required('SELECT p.*, (SELECT count(*) FROM reviews r WHERE r.product_id=p.id) AS reviews_count FROM products p WHERE p.id=?'.($includeInactive?'':' AND p.is_active').($lock?' FOR UPDATE OF p':''),[$id],'PRODUCT_NOT_FOUND','product not found');
    }
    public function lockCart(string $user): void
    {
        // All cart writers and checkout acquire the parent before the aggregate/address.
        // This also covers the user FK locks taken later by cart/order/payment inserts.
        // KEY SHARE is sufficient to serialize against profile/address parent UPDATE
        // locks, and remains compatible with user-FK checks by product/review writers.
        $this->db->required('SELECT id FROM users WHERE id=? FOR KEY SHARE',[$user],'USER_NOT_FOUND','user not found');
        $this->db->execute('INSERT INTO cart_metadata(user_id,updated_at) VALUES(?,to_timestamp(0)) ON CONFLICT(user_id) DO NOTHING',[$user]);
        $this->db->one('SELECT updated_at FROM cart_metadata WHERE user_id=? FOR UPDATE',[$user]);
    }
    public function touchCart(string $user): void { $this->db->execute('UPDATE cart_metadata SET updated_at=clock_timestamp() WHERE user_id=?',[$user]); }
    public function cart(string $user): array
    {
        $rows=$this->db->all('SELECT ci.*,p.name,p.stock,p.is_active,(SELECT url FROM product_images WHERE product_id=p.id ORDER BY sort_order,id LIMIT 1) AS image_url FROM cart_items ci JOIN products p ON p.id=ci.product_id WHERE ci.user_id=? ORDER BY p.name,p.id',[$user]);
        $items=[];$total=0;
        foreach($rows as $r){$available=$r['is_active'] && $r['stock']>=$r['quantity'];$price=Money::parse($r['price_snapshot']);
            $items[]=['productId'=>$r['product_id'],'productName'=>$r['name'],'quantity'=>$r['quantity'],'priceSnapshot'=>Money::format($price),'imageUrl'=>$r['image_url'],'isAvailable'=>$available,'stock'=>$r['stock']];
            if($available){$total=Money::add($total,Money::line($price,$r['quantity']));}
        }
        $meta=$this->db->one('SELECT updated_at FROM cart_metadata WHERE user_id=?',[$user]);
        return ['id'=>'cart-'.$user,'userId'=>$user,'items'=>$items,'totalAmount'=>Money::format($total),'updatedAt'=>Ids::timestamp($meta['updated_at']??'1970-01-01T00:00:00Z')];
    }
    public function order(string $id,?string $user=null): array
    {
        return (new OrderReadRepository($this->db))->order($id,$user);
    }
    public function payment(string $id,?string $user=null): array
    {
        return $this->db->required('SELECT * FROM payments WHERE id=?'.($user===null?'':' AND user_id=?'),$user===null?[$id]:[$id,$user],'PAYMENT_NOT_FOUND','payment not found');
    }
    public function paymentDto(array $p): array
    {return ['id'=>$p['id'],'orderId'=>$p['order_id'],'method'=>$p['method'],'status'=>$p['status'],'amount'=>Money::format(Money::parse($p['amount'])),'transactionId'=>$p['transaction_id']];}
    public function addressDto(array $a): array
    {return ['id'=>$a['id'],'userId'=>$a['user_id'],'city'=>$a['city'],'street'=>$a['street'],'house'=>$a['house'],'apartment'=>$a['apartment'],'comment'=>$a['comment']];}
    public function replay(string $user,string $endpoint,string $key,string $fingerprint): ?array
    {
        if($key===''){return null;}
        $row=$this->db->one('SELECT response FROM idempotency_keys WHERE user_id=? AND endpoint=? AND key=?',[$user,$endpoint,$key]);
        if($row===null){return null;}$entry=json_decode($row['response'],true,32,JSON_THROW_ON_ERROR);
        if(($entry['fingerprint']??null)!==$fingerprint){throw new ApiError('IDEMPOTENCY_KEY_REUSED','Idempotency-Key was already used for another request');}
        return $entry[$endpoint==='cart'?'cart':'order']??throw new ApiError('DATABASE_UNAVAILABLE','invalid replay record');
    }
    public function saveReplay(string $user,string $endpoint,string $key,string $fingerprint,array $dto): void
    {
        if($key===''){return;}
        $entry=['fingerprint'=>$fingerprint,$endpoint==='cart'?'cart':'order'=>$dto,'createdAt'=>Ids::now()];
        $this->db->execute('INSERT INTO idempotency_keys(user_id,endpoint,key,response) VALUES(?,?,?,?::jsonb)',[$user,$endpoint,$key,json_encode($entry,JSON_THROW_ON_ERROR)]);
    }
}
