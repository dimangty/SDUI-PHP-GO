<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids,Rules,PasswordHasher};
use Shop\infrastructure\Database;
use Shop\repositories\{ShopRepository,UserCredentialsRepository};

final class AuthService
{
    private UserCredentialsRepository $credentials;
    private LoginThrottle $throttle;
    public function __construct(private Database $db,private ShopRepository $repo,private SessionService $sessions,private PasswordHasher $hasher=new PasswordHasher())
    {$this->credentials=new UserCredentialsRepository($db);$this->throttle=new LoginThrottle($db);}
    public function register(array $input,string $bootstrap=''): array
    {
        $email=strtolower(trim($input['email']??''));$password=$input['password']??'';
        Rules::email($email);Rules::password($password);$this->passwordPolicy($password,$email);
        [$name,$phone]=Rules::profile($input['fullName']??'',$input['phone']??'');
        $this->throttle->admit($email);
        // Work before the transaction; never claim an existing account on retry.
        $hash=$this->hasher->hash($password);$id=Ids::uuid();
        try{return $this->db->transaction(function()use($id,$email,$hash,$name,$phone){
            $this->credentials->create($id,$email,$hash,$name,$phone);
            return $this->sessions->issue($id,'password');
        });}catch(\PDOException $e){if($e->getCode()==='23505'){throw new ApiError('EMAIL_ALREADY_EXISTS','email already exists');}throw $e;}
    }
    public function login(array $input): array
    {
        $email=strtolower(trim($input['email']??''));$password=$input['password']??'';
        if(strlen($email)>254 || strlen($password)>128 || $password===''){throw new ApiError('INVALID_CREDENTIALS','invalid email or password');}
        $this->throttle->admit($email);$snapshot=$this->credentials->byEmail($email);
        if($snapshot===null){$this->hasher->dummy($password);throw new ApiError('INVALID_CREDENTIALS','invalid email or password');}
        $this->throttle->check($snapshot['id']);
        $valid=$this->hasher->verify($password,$snapshot['password_hash']);
        $rehash=$valid && $this->hasher->needsRehash($snapshot['password_hash'])?$this->hasher->hash($password):null;
        $result=$this->db->transaction(function()use($snapshot,$valid,$rehash){
            $u=$this->sessions->lockUser($snapshot['id']);
            if(!UserCredentialsRepository::sameSnapshot($snapshot,$u)){return new ApiError('INVALID_CREDENTIALS','invalid email or password');}
            $this->throttle->check($u['id']);
            if(!$valid){return $this->throttle->failure($u['id']);}
            $this->sessions->assertAllowed($u);
            if($rehash!==null){$this->credentials->setHash($u['id'],$rehash);}
            $this->throttle->success($u['id']);
            return $this->sessions->issue($u['id'],'password');
        });
        if($result instanceof ApiError){throw $result;}return $result;
    }
    public function refresh(array $input): array {return $this->sessions->refresh($input['refreshToken']??'');}
    public function logout(string $user,array $input): void {$this->sessions->logout($user);}
    public function authenticate(string $raw): array {return $this->sessions->authenticate($raw);}
    public function changePassword(string $user,array $input): void
    {
        $new=$input['newPassword']??'';Rules::password($new);$snapshot=$this->repo->user($user);$this->passwordPolicy($new,$snapshot['email']);
        $this->sessions->assertAllowed($snapshot);$this->throttle->admit($snapshot['email']);$this->throttle->check($user);
        $valid=$this->hasher->verify($input['oldPassword']??'',$snapshot['password_hash']);
        $hash=$valid?$this->hasher->hash($new):null;
        $result=$this->db->transaction(function()use($user,$snapshot,$hash){
            $u=$this->sessions->lockUser($user);
            if(!UserCredentialsRepository::sameSnapshot($snapshot,$u)){return new ApiError('PASSWORD_CHANGED','password verification is stale');}
            $this->sessions->assertAllowed($u);$this->throttle->check($user);
            if($hash===null){return $this->throttle->failure($user);}
            $this->credentials->setHash($user,$hash);$this->sessions->revokeUser($user);$this->throttle->success($user);return null;
        });
        if($result instanceof ApiError){throw $result;}
    }
    private function passwordPolicy(string $password,string $email): void
    {
        // Retain the previous provider's notUsername rule as well as Rules' bounds.
        if(strcasecmp($password,$email)===0){throw new ApiError('WEAK_PASSWORD','password must differ from the account email');}
    }
}
