<?php
declare(strict_types=1);

use Shop\application\{AuthService,SessionService,CredentialService};
use Shop\domain\{Ids,PasswordHasher};
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

function localServices(?PasswordHasher $hasher=null): array {
    $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$sessions=new SessionService($db,$repo);
    return [$db,$sessions,new CredentialService($db,$repo,$sessions),new AuthService($db,$repo,$sessions,$hasher??new PasswordHasher())];
}
/** Trusted SQL fixture: represents an individually reviewed ordinary local user. */
function localUser(Database $db,string $hash='',bool $admin=false): string {
    $id=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,local_identity_ready,is_admin) VALUES(?,?,?,'Local fixture','+79991234567',true,?)",[$id,$id.'@test.example',$hash,$admin]);return $id;
}
function localRegister(AuthService $auth): array {
    $email=Ids::uuid().'@test.example';return [$auth->register(['email'=>$email,'password'=>'StrongPass7395','fullName'=>'Local Buyer','phone'=>'+79991234567']),$email];
}
/** Real RS256 signature without a runtime JWT package or a discovery server. */
function historicalJwt(array $claims): string {
    static $key=null;$key??=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    $encode=fn(string $v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
    $body=$encode('{"alg":"RS256","typ":"JWT","kid":"retired-fixture"}').'.'.$encode(json_encode($claims,JSON_THROW_ON_ERROR));
    if(!openssl_sign($body,$signature,$key,OPENSSL_ALGO_SHA256)){throw new RuntimeException('Cannot sign historical fixture');}
    return $body.'.'.$encode($signature);
}
