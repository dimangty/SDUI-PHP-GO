<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids,Rules};
use Shop\infrastructure\{Database,Env,Keycloak};
use Shop\repositories\ShopRepository;

final class AuthService
{
    public function __construct(private Database $db,private ShopRepository $repo,private SessionService $sessions) {}
    public function register(array $input,string $bootstrap=''): array
    {
        $email=strtolower(trim($input['email']??''));$password=$input['password']??'';Rules::email($email);Rules::password($password);
        [$name,$phone]=Rules::profile($input['fullName']??'',$input['phone']??'');
        $admin=in_array($email,array_map('trim',explode(',',strtolower(Env::get('ADMIN_EMAILS')))),true);
        if($admin && Env::get('ADMIN_BOOTSTRAP_TOKEN')===''){throw new ApiError('ADMIN_BOOTSTRAP_NOT_CONFIGURED','admin registration is disabled');}
        if($admin && !hash_equals(Env::get('ADMIN_BOOTSTRAP_TOKEN'),$bootstrap)){throw new ApiError('ADMIN_BOOTSTRAP_REQUIRED','valid admin bootstrap token is required');}
        $keycloak=new Keycloak();$names=preg_split('/\s+/u',$name);$first=array_shift($names);
        $r=$keycloak->admin('POST','/users',['username'=>$email,'email'=>$email,'emailVerified'=>true,'enabled'=>true,'firstName'=>$first,'lastName'=>implode(' ',$names),'attributes'=>['phone'=>[$phone]],'credentials'=>[['type'=>'password','value'=>$password,'temporary'=>false]]]);
        if($r['status']===409){
            try{$tokens=$keycloak->password($email,$password);}catch(ApiError){throw new ApiError('EMAIL_ALREADY_EXISTS','email already exists');}
            if($this->db->one('SELECT id FROM users WHERE id=?',[$tokens['userId']])){throw new ApiError('EMAIL_ALREADY_EXISTS','email already exists');}
            $this->externalProfile($tokens['userId'],$email,$name,$phone,$admin);return $tokens;
        }
        if($r['status']!==201){throw new ApiError('IDENTITY_PROVIDER_ERROR','identity provider rejected registration');}
        $id=basename($r['headers']['location']??'');
        if(!Ids::valid($id)){throw new ApiError('IDENTITY_PROVIDER_ERROR','identity provider omitted the user id');}
        try{
            $tokens=$keycloak->password($email,$password);$claims=$keycloak->verifier()->verify($tokens['accessToken']);
            if($claims['sub']!==$id){throw new ApiError('IDENTITY_PROVIDER_ERROR','identity subject mismatch');}
            $this->externalProfile($id,$email,$name,$phone,$admin);$tokens['userId']=$id;return $tokens;
        }catch(\Throwable $e){
            try{$keycloak->admin('POST','/users/'.rawurlencode($id).'/logout');$keycloak->admin('DELETE','/users/'.rawurlencode($id));}catch(\Throwable $cleanup){error_log('Registration compensation failed: '.$cleanup->getMessage());}
            throw $e;
        }
    }
    private function externalProfile(string $id,string $email,string $name,string $phone,bool $admin): void
    {
        try{$this->db->transaction(function()use($id,$email,$name,$phone,$admin){
            $this->db->execute('INSERT INTO users(id,email,password_hash,full_name,phone,is_admin) VALUES(?,?,\'\',?,?,?)',[$id,$email,$name,$phone,$admin]);
            $this->db->execute('INSERT INTO cart_metadata(user_id,updated_at) VALUES(?,to_timestamp(0))',[$id]);
        });}catch(\PDOException $e){if($e->getCode()==='23505'){throw new ApiError('EMAIL_ALREADY_EXISTS','email already exists');}throw $e;}
    }
    public function login(array $input): array {return (new Keycloak())->password($input['email']??'',$input['password']??'');}
    public function refresh(array $input): array
    {
        $token=$input['refreshToken']??'';
        if(str_starts_with($token,'app_refresh_')){return $this->sessions->refresh($token);}
        if(str_starts_with($token,'app_')){throw new ApiError('INVALID_REFRESH_TOKEN','invalid refresh token');}
        return (new Keycloak())->tokens(['grant_type'=>'refresh_token','refresh_token'=>$token]);
    }
    public function logout(string $user,array $input): void
    {
        $token=$input['refreshToken']??'';
        if($token!=='' && !str_starts_with($token,'app_refresh_')){try{(new Keycloak())->logout($token);}catch(\Throwable $e){error_log('Identity logout failed: '.$e->getMessage());}}
        $this->sessions->logout($user,str_starts_with($token,'app_refresh_')?$token:'');
    }
    public function authenticate(string $raw): array
    {
        if(str_starts_with($raw,'app_access_')){return $this->sessions->authenticate($raw);}
        if(str_starts_with($raw,'app_')){throw new ApiError('UNAUTHORIZED','invalid or expired access token');}
        $claims=(new Keycloak())->verifier()->verify($raw);
        try{$user=$this->repo->user($claims['sub']);}catch(ApiError){throw new ApiError('UNAUTHORIZED','token subject has no application profile');}
        if(isset($claims['email']) && is_string($claims['email']) && $claims['email']!==''){$user['email']=$claims['email'];}
        $realm=$claims['realm_access']??null;$roles=is_object($realm)?($realm->roles??[]):($realm['roles']??[]);
        if(is_array($roles) && array_intersect(['admin','shopkmp-admin'],$roles)){$user['is_admin']=true;}
        return $user;
    }
    public function changePassword(string $user,array $input): void
    {
        $new=$input['newPassword']??'';Rules::password($new);$u=$this->repo->user($user);$kc=new Keycloak();
        try{$kc->password($u['email'],$input['oldPassword']??'');}catch(ApiError){throw new ApiError('INVALID_CREDENTIALS','old password is incorrect');}
        foreach([
            ['PUT','/users/'.rawurlencode($user).'/reset-password',['type'=>'password','value'=>$new,'temporary'=>false]],
            ['POST','/users/'.rawurlencode($user).'/logout',null],
            ['DELETE','/users/'.rawurlencode($user).'/consents/'.rawurlencode(Env::get('KEYCLOAK_CLIENT_ID','shopkmp-backend')),null],
        ] as [$method,$path,$body]){
            $r=$kc->admin($method,$path,$body);
            if(($r['status']<200 || $r['status']>=300) && !($method==='DELETE' && $r['status']===404)){throw new ApiError('IDENTITY_PROVIDER_ERROR','identity provider rejected password change or session revocation');}
        }
    }
}
