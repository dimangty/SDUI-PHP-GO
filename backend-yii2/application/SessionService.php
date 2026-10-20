<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

final class SessionService
{
    public function __construct(private Database $db,private ShopRepository $repo) {}
    /** Caller may already be in a credential transaction. */
    public function issue(string $user): array
    {
        $this->repo->user($user);
        $ttl=Env::duration('ACCESS_TOKEN_TTL',300);
        $tokens=['userId'=>$user,'accessToken'=>'app_access_'.Ids::opaque(),'refreshToken'=>'app_refresh_'.Ids::opaque(),'expiresIn'=>(int)$ttl];
        foreach(['access'=>$ttl,'refresh'=>Env::duration('REFRESH_TOKEN_TTL',2592000)] as $type=>$seconds){
            $this->db->execute('INSERT INTO '.$type.'_sessions(token_hash,user_id,expires_at) VALUES(decode(?,\'hex\'),?,now()+?*interval \'1 second\')',[hash('sha256',$tokens[$type.'Token']),$user,$seconds]);
        }
        return $tokens;
    }
    public function authenticate(string $token): array
    {
        if(!str_starts_with($token,'app_access_')){throw new ApiError('UNAUTHORIZED','invalid or expired access token');}
        $r=$this->db->one('SELECT user_id FROM access_sessions WHERE token_hash=decode(?,\'hex\') AND expires_at>now()',[hash('sha256',$token)]);
        if(!$r){throw new ApiError('UNAUTHORIZED','invalid or expired access token');}
        return $this->repo->user($r['user_id']);
    }
    public function refresh(string $token): array
    {
        if(!str_starts_with($token,'app_refresh_')){throw new ApiError('INVALID_REFRESH_TOKEN','invalid refresh token');}
        return $this->db->transaction(function()use($token){
            $r=$this->db->one('DELETE FROM refresh_sessions WHERE token_hash=decode(?,\'hex\') AND expires_at>now() RETURNING user_id',[hash('sha256',$token)]);
            if(!$r){throw new ApiError('INVALID_REFRESH_TOKEN','invalid or expired refresh token');}
            return $this->issue($r['user_id']);
        });
    }
    public function logout(string $user,string $refresh=''): void
    {
        $this->db->transaction(function()use($user,$refresh){
            $this->db->execute('DELETE FROM access_sessions WHERE user_id=?',[$user]);
            $this->db->execute('DELETE FROM refresh_sessions WHERE user_id=?'.($refresh===''?'':' AND token_hash=decode(?,\'hex\')'),$refresh===''?[$user]:[$user,hash('sha256',$refresh)]);
        });
    }
}
