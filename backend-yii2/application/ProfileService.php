<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids,Rules};
use Shop\infrastructure\Database;
use Shop\repositories\ShopRepository;

final class ProfileService
{
    public function __construct(private Database $db,private ShopRepository $repo) {}
    public function user(string $id): array {return $this->repo->userDto($this->repo->user($id));}
    public function update(string $user,array $input): array
    {
        [$name,$phone]=Rules::profile($input['fullName']??'',$input['phone']??'');$birthday=$input['dateOfBirth']??null;
        if($birthday!==null){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$birthday);if(!$d || $d->format('Y-m-d')!==$birthday || $d>new \DateTimeImmutable('today') || $d<new \DateTimeImmutable('-120 years')){throw new ApiError('INVALID_DATE_OF_BIRTH','dateOfBirth must be a real date in the past');}}
        return $this->db->transaction(function()use($user,$input,$name,$phone,$birthday){
            $u=$this->repo->user($user,true);
            if(isset($input['email']) && strtolower(trim($input['email']))!==$u['email']){throw new ApiError('EMAIL_CHANGE_NOT_ALLOWED','email cannot be changed');}
            $address=$u['default_address_id'];
            if(array_key_exists('defaultAddressId',$input)){
                $address=$input['defaultAddressId'];
                if($address!==null && (!Ids::valid($address) || !$this->db->one('SELECT id FROM addresses WHERE id=? AND user_id=? FOR KEY SHARE',[$address,$user]))){throw new ApiError('INVALID_DEFAULT_ADDRESS','address does not belong to user');}
            }
            $this->db->execute('UPDATE users SET full_name=?,phone=?,default_address_id=?,date_of_birth=COALESCE(?::date,date_of_birth) WHERE id=?',[$name,$phone,$address,$birthday,$user]);
            return $this->user($user);
        });
    }
    public function addresses(string $user): array {return array_map($this->repo->addressDto(...),$this->db->all('SELECT * FROM addresses WHERE user_id=? ORDER BY id',[$user]));}
    private function normalizeAddress(array $input): array
    {
        $r=[];
        foreach(['city'=>255,'street'=>255,'house'=>64,'apartment'=>64] as $key=>$limit){$r[$key]=trim($input[$key]??'');if(($key!=='apartment' && $r[$key]==='') || mb_strlen($r[$key])>$limit){throw new ApiError('INVALID_ADDRESS','address fields are missing or exceed their limits');}}
        $r['comment']=isset($input['comment'])?Rules::plain($input['comment']):null;
        if($r['comment']!==null && mb_strlen($r['comment'])>2000){throw new ApiError('INVALID_ADDRESS','address fields are missing or exceed their limits');}return $r;
    }
    public function createAddress(string $user,array $input): array
    {
        $r=$this->normalizeAddress($input);$id=Ids::uuid();
        return $this->db->transaction(function()use($user,$r,$id){
            $this->repo->user($user,true);
            $this->db->execute('INSERT INTO addresses(id,user_id,city,street,house,apartment,comment) VALUES(?,?,?,?,?,?,?)',[$id,$user,$r['city'],$r['street'],$r['house'],$r['apartment'],$r['comment']]);
            $this->db->execute('UPDATE users SET default_address_id=COALESCE(default_address_id,?::uuid) WHERE id=?',[$id,$user]);
            return ['id'=>$id,'userId'=>$user]+$r;
        });
    }
    public function updateAddress(string $user,string $id,array $input): array
    {
        $r=$this->normalizeAddress($input);
        return $this->db->transaction(function()use($user,$id,$r){
            $this->repo->user($user,true);
            if($this->db->execute('UPDATE addresses SET city=?,street=?,house=?,apartment=?,comment=? WHERE id=? AND user_id=?',[$r['city'],$r['street'],$r['house'],$r['apartment'],$r['comment'],$id,$user])===0){throw new ApiError('ADDRESS_NOT_FOUND','address not found');}
            return ['id'=>$id,'userId'=>$user]+$r;
        });
    }
    public function deleteAddress(string $user,string $id): void
    {
        $this->db->transaction(function()use($user,$id){
            // Lock the parent before the address, the same order as profile/create-address.
            $this->repo->user($user,true);
            $this->db->required('SELECT id FROM addresses WHERE id=? AND user_id=? FOR UPDATE',[$id,$user],'ADDRESS_NOT_FOUND','address not found');
            if($this->db->one('SELECT id FROM orders WHERE delivery_address_id=? LIMIT 1',[$id])){throw new ApiError('ADDRESS_IN_USE','address is used by an order');}
            $this->db->execute('DELETE FROM addresses WHERE id=? AND user_id=?',[$id,$user]);
        });
    }
}
