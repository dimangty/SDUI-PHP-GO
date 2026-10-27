<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

final class SessionService
{
    public function __construct(private Database $db,private ShopRepository $repo) {}
    /** Reuse the credential transaction so validation and issuance commit together. */
    private function atomic(\Closure $work): mixed {return $this->db->pdo()->inTransaction()?$work():$this->db->transaction($work);}
    /** Global lock order: user -> credential -> family/token/challenge. */
    public function lockUser(string $user): array
    {
        if(!$this->db->pdo()->inTransaction()){throw new \LogicException('Session lock requires a transaction');}
        return $this->db->required('SELECT * FROM users WHERE id=? FOR UPDATE',[$user],'USER_NOT_FOUND','user not found');
    }
    /** The optional linkage is a server credential UUID, never a supplied deviceId.
     * User-only calls are trusted internal issuance and still receive a fenced family.
     */
    public function issue(string $user,string $kind='internal',?string $credential=null): array
    {
        return $this->atomic(function()use($user,$kind,$credential){
            $u=$this->lockUser($user);
            if($u['sessions_blocked']){throw new ApiError('PASSWORD_CHANGED','password change recovery is pending');}
            if(!in_array($kind,['internal','pin','biometric'],true) || (($kind==='internal')!==($credential===null))){throw new \InvalidArgumentException('Invalid session credential');}
            if($credential!==null){
                $table=$kind==='pin'?'device_pins':'device_biometrics';
                $this->db->required('SELECT id FROM '.$table.' WHERE id=? AND user_id=? FOR UPDATE',[$credential,$user],'UNAUTHORIZED','session credential was revoked');
            }
            $family=Ids::uuid();
            $this->db->execute('INSERT INTO session_families(id,user_id,security_version,credential_kind,credential_id) VALUES(?,?,?,?,?)',[$family,$user,$u['session_security_version'],$kind,$credential]);
            return $this->pair($user,$family);
        });
    }
    private function pair(string $user,string $family): array
    {
        $ttl=Env::duration('ACCESS_TOKEN_TTL',300);
        $tokens=['userId'=>$user,'accessToken'=>'app_access_'.Ids::opaque(),'refreshToken'=>'app_refresh_'.Ids::opaque(),'expiresIn'=>(int)$ttl];
        foreach(['access'=>$ttl,'refresh'=>Env::duration('REFRESH_TOKEN_TTL',2592000)] as $type=>$seconds){
            $this->db->execute('INSERT INTO '.$type.'_sessions(token_hash,user_id,family_id,expires_at) VALUES(decode(?,\'hex\'),?,?,clock_timestamp()+?*interval \'1 second\')',[hash('sha256',$tokens[$type.'Token']),$user,$family,$seconds]);
        }
        return $tokens;
    }
    public function authenticate(string $token): array
    {
        if(!str_starts_with($token,'app_access_')){throw new ApiError('UNAUTHORIZED','invalid or expired access token');}
        $r=$this->db->one('SELECT s.user_id FROM access_sessions s JOIN session_families f ON f.id=s.family_id AND f.user_id=s.user_id JOIN users u ON u.id=s.user_id WHERE s.token_hash=decode(?,\'hex\') AND s.expires_at>clock_timestamp() AND f.revoked_at IS NULL AND f.security_version=u.session_security_version AND NOT u.sessions_blocked',[hash('sha256',$token)]);
        if(!$r){throw new ApiError('UNAUTHORIZED','invalid or expired access token');}
        return $this->repo->user($r['user_id']);
    }
    public function refresh(string $token): array
    {
        if(!str_starts_with($token,'app_refresh_')){throw new ApiError('INVALID_REFRESH_TOKEN','invalid refresh token');}
        return $this->db->transaction(function()use($token){
            // First read locates the lock owner only. Revalidate after acquiring it.
            $hash=hash('sha256',$token);$r=$this->db->one('SELECT user_id FROM refresh_sessions WHERE token_hash=decode(?,\'hex\')',[$hash]);
            if(!$r){throw new ApiError('INVALID_REFRESH_TOKEN','invalid or expired refresh token');}
            $u=$this->lockUser($r['user_id']);
            if($u['sessions_blocked']){throw new ApiError('INVALID_REFRESH_TOKEN','invalid or expired refresh token');}
            $r=$this->db->one('DELETE FROM refresh_sessions s USING session_families f WHERE s.token_hash=decode(?,\'hex\') AND s.family_id=f.id AND s.user_id=f.user_id AND f.user_id=? AND f.security_version=? AND f.revoked_at IS NULL AND s.expires_at>clock_timestamp() RETURNING s.user_id,s.family_id',[$hash,$u['id'],$u['session_security_version']]);
            if(!$r){throw new ApiError('INVALID_REFRESH_TOKEN','invalid or expired refresh token');}
            return $this->pair($r['user_id'],$r['family_id']);
        });
    }
    public function logout(string $user,string $refresh=''): void
    {
        $this->revokeUser($user);
    }
    public function revokeUser(string $user): void
    {
        $this->atomic(function()use($user){
            $this->lockUser($user);
            $this->db->execute('UPDATE users SET session_security_version=session_security_version+1 WHERE id=?',[$user]);
            $this->db->execute('UPDATE session_families SET revoked_at=clock_timestamp() WHERE user_id=? AND revoked_at IS NULL',[$user]);
            foreach(['access','refresh'] as $kind){$this->db->execute('DELETE FROM '.$kind.'_sessions WHERE user_id=?',[$user]);}
        });
    }
    public function revokeCredential(string $user,string $kind,string $credential): void
    {
        $this->atomic(function()use($user,$kind,$credential){
            $this->lockUser($user);
            $this->db->execute('UPDATE session_families SET revoked_at=clock_timestamp() WHERE user_id=? AND credential_kind=? AND credential_id=? AND revoked_at IS NULL',[$user,$kind,$credential]);
            foreach(['access','refresh'] as $type){$this->db->execute('DELETE FROM '.$type.'_sessions s USING session_families f WHERE s.family_id=f.id AND f.user_id=? AND f.credential_kind=? AND f.credential_id=?',[$user,$kind,$credential]);}
        });
    }
}
