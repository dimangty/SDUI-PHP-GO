<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids,Money,Rules};
use Shop\infrastructure\Database;
use Shop\repositories\{OrderReadRepository,ShopRepository};

final class CommerceService
{
    public function __construct(private Database $db,private ShopRepository $repo) {}
    public function cart(string $user): array {return $this->repo->cart($user);}
    public function addCart(string $user,array $input,string $key=''): array
    {
        $product=$input['productId']??'';$quantity=$input['quantity']??0;$key=Rules::key($key);
        if($quantity<1 || $quantity>1000000){throw new ApiError('INVALID_QUANTITY','quantity is outside the supported range');}
        $fingerprint=$product.':'.$quantity;
        return $this->db->transaction(function()use($user,$product,$quantity,$key,$fingerprint){
            $this->repo->lockCart($user);
            if($replay=$this->repo->replay($user,'cart',$key,$fingerprint)){return $replay;}
            $p=$this->repo->product($product,false,true);
            $current=$this->db->one('SELECT quantity FROM cart_items WHERE user_id=? AND product_id=?',[$user,$product])['quantity']??0;
            if($current>1000000-$quantity){throw new ApiError('INVALID_QUANTITY','quantity is outside the supported range');}
            if($p['stock']<$current+$quantity){throw new ApiError('OUT_OF_STOCK','requested quantity exceeds stock');}
            $this->db->execute('INSERT INTO cart_items(user_id,product_id,quantity,price_snapshot) VALUES(?,?,?,?) ON CONFLICT(user_id,product_id) DO UPDATE SET quantity=cart_items.quantity+EXCLUDED.quantity,price_snapshot=EXCLUDED.price_snapshot',[$user,$product,$quantity,$p['discount_price']??$p['price']]);
            $this->repo->touchCart($user);$cart=$this->repo->cart($user);
            $this->repo->saveReplay($user,'cart',$key,$fingerprint,$cart);return $cart;
        });
    }
    public function updateCart(string $user,string $product,array $input): array
    {
        $quantity=$input['quantity']??0;
        if($quantity<0 || $quantity>1000000){throw new ApiError('INVALID_QUANTITY','quantity is outside the supported range');}
        return $this->db->transaction(function()use($user,$product,$quantity){
            $this->repo->lockCart($user);
            $this->db->required('SELECT quantity FROM cart_items WHERE user_id=? AND product_id=?',[$user,$product],'CART_ITEM_NOT_FOUND','cart item not found');
            if($quantity===0){$this->db->execute('DELETE FROM cart_items WHERE user_id=? AND product_id=?',[$user,$product]);}
            else{
                $p=$this->repo->product($product,true,true);
                if(!$p['is_active']){throw new ApiError('PRODUCT_INACTIVE','product is not available');}
                if($p['stock']<$quantity){throw new ApiError('OUT_OF_STOCK','requested quantity exceeds stock');}
                $this->db->execute('UPDATE cart_items SET quantity=?,price_snapshot=? WHERE user_id=? AND product_id=?',[$quantity,$p['discount_price']??$p['price'],$user,$product]);
            }
            $this->repo->touchCart($user);return $this->repo->cart($user);
        });
    }
    public function removeCart(string $user,string $product): array {return $this->updateCart($user,$product,['quantity'=>0]);}
    public function clearCart(string $user): void
    {$this->db->transaction(function()use($user){$this->repo->lockCart($user);$this->db->execute('DELETE FROM cart_items WHERE user_id=?',[$user]);$this->repo->touchCart($user);});}
    public function createOrder(string $user,array $input,string $key=''): array
    {
        $address=$input['deliveryAddressId']??'';$method=$input['paymentMethod']??'';$comment=$input['comment']??null;$key=Rules::key($key);
        if(!in_array($method,['CARD','SBP','ON_DELIVERY'],true)){throw new ApiError('INVALID_PAYMENT_METHOD','invalid payment method');}
        if($comment!==null && mb_strlen($comment)>2000){throw new ApiError('BAD_REQUEST','comment exceeds 2000 characters');}
        $fingerprint=$address.':'.$method.':'.($comment??'');
        return $this->db->transaction(function()use($user,$address,$method,$comment,$key,$fingerprint){
            if($key!==''){$this->db->advisory($user.':orders:'.$key);}
            if($replay=$this->repo->replay($user,'orders',$key,$fingerprint)){return $replay;}
            // The same aggregate lock as all cart mutations also serializes an empty-cart checkout.
            $this->repo->lockCart($user);
            $a=$this->db->required('SELECT * FROM addresses WHERE id=? AND user_id=? FOR KEY SHARE',[$address,$user],'ADDRESS_NOT_FOUND','address not found');
            $rows=$this->db->all('SELECT ci.product_id,p.name,ci.quantity,COALESCE(p.discount_price,p.price) AS price,p.stock,p.is_active FROM cart_items ci JOIN products p ON p.id=ci.product_id WHERE ci.user_id=? ORDER BY ci.product_id FOR UPDATE OF ci,p',[$user]);
            if(!$rows){throw new ApiError('CART_IS_EMPTY','cart is empty');}
            $total=0;$items=[];$out=[];
            foreach($rows as $r){
                if(!$r['is_active']){throw new ApiError('PRODUCT_INACTIVE','product is not available');}
                if($r['quantity']<1 || $r['quantity']>1000000){throw new ApiError('INVALID_QUANTITY','cart quantity is outside the supported range');}
                if($r['stock']<$r['quantity']){$out[]=$r['product_id'];continue;}
                $total=Money::add($total,Money::line(Money::parse($r['price']),$r['quantity']));$items[]=$r;
            }
            if($out){sort($out);throw new ApiError('OUT_OF_STOCK','some products are out of stock',['items'=>implode(',',$out)]);}
            $order=Ids::uuid();$payment=Ids::uuid();
            $delivery=implode(', ',array_filter([$a['city'],$a['street'],$a['house'],$a['apartment']],fn($s)=>trim($s)!==''));
            $this->db->execute('INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method,comment) VALUES(?,?,\'CREATED\',?,?,?,?,?)',[$order,$user,Money::format($total),$address,$delivery,$method,$comment]);
            foreach($items as $r){
                $this->db->execute('INSERT INTO order_items(order_id,product_id,product_name,quantity,price_snapshot) VALUES(?,?,?,?,?)',[$order,$r['product_id'],$r['name'],$r['quantity'],$r['price']]);
                if($this->db->execute('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?',[$r['quantity'],$r['product_id'],$r['quantity']])!==1){throw new ApiError('OUT_OF_STOCK','product stock changed while creating the order');}
            }
            $this->db->execute('INSERT INTO payments(id,order_id,user_id,method,status,amount) VALUES(?,?,?,?,\'PENDING\',?)',[$payment,$order,$user,$method,Money::format($total)]);
            $this->db->execute('UPDATE orders SET payment_id=? WHERE id=?',[$payment,$order]);
            $this->db->execute('DELETE FROM cart_items WHERE user_id=?',[$user]);$this->repo->touchCart($user);
            $dto=$this->repo->order($order,$user);$this->repo->saveReplay($user,'orders',$key,$fingerprint,$dto);return $dto;
        });
    }
    public function orders(string $user,array $query): array
    {
        [$page,$size]=Rules::page($query);$status=$query['status']??'';
        return (new OrderReadRepository($this->db))->page($user,$status,$page,$size);
    }
    public function order(string $user,string $id): array {return $this->repo->order($id,$user);}
    public function transition(string $id,array $input): array
    {
        return $this->db->transaction(function()use($id,$input){
            $o=$this->lockOrderPayment($id);$next=$input['status']??'';
            $allowed=match($o['status']){'PAID'=>'ASSEMBLING','ASSEMBLING'=>'DELIVERING','DELIVERING'=>'COMPLETED',default=>($o['status']==='CREATED'&&$o['payment_method']==='ON_DELIVERY'&&$o['payment_status']==='AUTHORIZED'?'ASSEMBLING':'')};
            if($allowed==='' || $next!==$allowed){throw new ApiError('INVALID_ORDER_STATUS','invalid order status transition');}
            if($next==='COMPLETED' && $o['payment_method']==='ON_DELIVERY'){
                if($o['payment_status']!=='AUTHORIZED'){throw new ApiError('INVALID_PAYMENT_STATUS','on-delivery payment is not authorized');}
                $this->db->execute('UPDATE payments SET status=\'CAPTURED\',transaction_id=?,updated_at=now() WHERE id=?',['cash-'.Ids::uuid(),$o['payment_id']]);
            }
            $this->db->execute('UPDATE orders SET status=? WHERE id=?',[$next,$id]);return $this->repo->order($id);
        });
    }
    public function lockOrderPayment(string $id): array
    {
        // Explicit order -> payment -> operation -> sorted products everywhere.
        $o=$this->db->required('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id],'ORDER_NOT_FOUND','order not found');
        $p=$this->db->required('SELECT status FROM payments WHERE id=? FOR UPDATE',[$o['payment_id']],'PAYMENT_NOT_FOUND','payment not found');
        $o['payment_status']=$p['status'];return $o;
    }
    public function restoreStock(string $order): void
    {
        // Same transaction as product updates: a rollback rolls back this guard too.
        if($this->db->execute("UPDATE orders SET stock_restored_at=now() WHERE id=? AND stock_restored_at IS NULL AND status<>'COMPLETED'",[$order])!==1){return;}
        // Sorted locks match checkout, avoiding multi-product reversal deadlocks.
        $rows=$this->db->all('SELECT product_id,sum(quantity)::int AS quantity FROM order_items WHERE order_id=? GROUP BY product_id ORDER BY product_id',[$order]);
        foreach($rows as $r){$this->db->one('SELECT id FROM products WHERE id=? FOR UPDATE',[$r['product_id']]);$this->db->execute('UPDATE products SET stock=stock+? WHERE id=?',[$r['quantity'],$r['product_id']]);}
    }
}
