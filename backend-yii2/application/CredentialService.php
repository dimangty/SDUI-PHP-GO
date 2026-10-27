<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids,Rules,Secret};
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

final class CredentialService
{
    public function __construct(private Database $db,private ShopRepository $repo,private SessionService $sessions) {}
    public function setPin(string $user,array $input): void
    {
        $device=$input['deviceId']??'';Rules::device($device);$name=Rules::deviceName($input['deviceName']??'');$u=$this->repo->user($user);
        if($u['is_admin']){throw new ApiError('PIN_FORBIDDEN_FOR_ADMIN','PIN login is disabled for administrators');}
        Rules::pin($input['pin']??'',$u['date_of_birth']);$hash=Secret::hash($input['pin']);
        try{$this->db->execute('INSERT INTO device_pins(user_id,device_id,device_name,pin_hash) VALUES(?,?,?,?)',[$user,$device,$name,$hash]);}
        catch(\PDOException $e){if($e->getCode()==='23505'){throw new ApiError('PIN_ALREADY_SET','PIN already set');}throw $e;}
    }
    private function activeLock(array $row,string $kind): void
    {
        if($row['locked_until']!==null && Ids::epoch($row['locked_until'])>microtime(true)){
            throw new ApiError($kind.'_LOCKED',$kind==='PIN'?'PIN is temporarily locked':'biometric login is temporarily locked',['lockedUntil'=>Ids::timestamp($row['locked_until'])]);
        }
    }
    /** Return error after committing the failed attempt; throwing inside the transaction would lose the counter. */
    private function failure(array $row,string $table,string $kind): ApiError
    {
        $attempts=$row['locked_until']!==null && Ids::epoch($row['locked_until'])<=microtime(true)?0:$row['failed_attempts'];
        $attempts++;$locks=$row['lock_count'];$until=null;
        $maxAttempts=$kind==='PIN'?max(1,(int)Env::get('PIN_MAX_ATTEMPTS','5')):5;
        $maxLocks=max(1,(int)Env::get($kind==='PIN'?'PIN_MAX_LOCKS':'BIOMETRIC_MAX_LOCKS','3'));
        if($attempts>=$maxAttempts){$attempts=0;$locks++;
            if($locks>=$maxLocks){
                $this->sessions->revokeCredential($row['user_id'],$kind==='PIN'?'pin':'biometric',$row['id']);
                $this->db->execute('DELETE FROM '.$table.' WHERE id=?',[$row['id']]);
                return new ApiError($kind==='PIN'?'PIN_NOT_SET':'BIOMETRIC_REVOKED',$kind==='PIN'?'PIN was removed after repeated lockouts':'biometric credential was revoked after repeated lockouts; sign in with password to enroll again');
            }
            $duration=$kind==='PIN'?Env::duration('PIN_LOCK_DURATION',900):900;
            $until=Ids::timestamp('@'.sprintf('%.6F',microtime(true)+$duration));
        }
        $this->db->execute('UPDATE '.$table.' SET failed_attempts=?,lock_count=?,locked_until=? WHERE id=?',[$attempts,$locks,$until,$row['id']]);
        if($until!==null){return new ApiError($kind.'_LOCKED',$kind==='PIN'?'PIN is temporarily locked':'biometric login is temporarily locked',['lockedUntil'=>Ids::timestamp($until)]);}
        return new ApiError($kind==='PIN'?'INVALID_PIN':'INVALID_ASSERTION',$kind==='PIN'?'invalid PIN':'invalid biometric signature',['attemptsLeft'=>(string)($maxAttempts-$attempts)]);
    }
    public function loginPin(array $input): array
    {
        $device=$input['deviceId']??'';Rules::device($device);
        $result=$this->db->transaction(function()use($device,$input){
            $owner=$this->db->required('SELECT user_id FROM device_pins WHERE device_id=?',[$device],'PIN_NOT_SET','PIN is not set for this device');
            $this->sessions->lockUser($owner['user_id']);
            $r=$this->db->required('SELECT * FROM device_pins WHERE device_id=? FOR UPDATE',[$device],'PIN_NOT_SET','PIN is not set for this device');
            if($r['user_id']!==$owner['user_id']){throw new ApiError('PIN_NOT_SET','PIN credential changed; retry login');}
            $this->activeLock($r,'PIN');
            if(!Secret::verify($input['pin']??'',$r['pin_hash'])){return $this->failure($r,'device_pins','PIN');}
            $this->db->execute('UPDATE device_pins SET failed_attempts=0,lock_count=0,locked_until=NULL,last_used_at=now() WHERE id=?',[$r['id']]);
            return $this->sessions->issue($r['user_id'],'pin',$r['id']);
        });
        if($result instanceof ApiError){throw $result;}return $result;
    }
    public function changePin(string $user,array $input): void
    {
        $device=$input['deviceId']??'';Rules::device($device);$u=$this->repo->user($user);Rules::pin($input['newPin']??'',$u['date_of_birth']);
        $result=$this->db->transaction(function()use($user,$device,$input){
            $this->sessions->lockUser($user);
            $r=$this->db->required('SELECT * FROM device_pins WHERE user_id=? AND device_id=? FOR UPDATE',[$user,$device],'PIN_NOT_SET','PIN not set');$this->activeLock($r,'PIN');
            if(!Secret::verify($input['currentPin']??'',$r['pin_hash'])){return $this->failure($r,'device_pins','PIN');}
            $this->db->execute('UPDATE device_pins SET pin_hash=?,failed_attempts=0,lock_count=0,locked_until=NULL WHERE id=?',[Secret::hash($input['newPin']),$r['id']]);return null;
        });if($result instanceof ApiError){throw $result;}
    }
    public function deletePin(string $user,string $device): void {$this->deleteDevice($user,$device,false);}
    public function deleteBiometric(string $user,string $device): void {$this->deleteDevice($user,$device,true);}
    private function deleteDevice(string $user,string $device,bool $biometric): void
    {
        Rules::device($device);$table=$biometric?'device_biometrics':'device_pins';
        $this->db->transaction(function()use($user,$device,$biometric,$table){
            $this->sessions->lockUser($user);
            $r=$this->db->required('SELECT id FROM '.$table.' WHERE user_id=? AND device_id=? FOR UPDATE',[$user,$device],$biometric?'BIOMETRIC_NOT_SET':'PIN_NOT_SET',$biometric?'biometric login is not enabled for this device':'PIN not set');
            $this->sessions->revokeCredential($user,$biometric?'biometric':'pin',$r['id']);
            $this->db->execute('DELETE FROM '.$table.' WHERE id=?',[$r['id']]);
        });
    }
    public function pinDevices(string $user): array {return $this->devices($user,false);}
    public function biometricDevices(string $user): array {return $this->devices($user,true);}
    private function devices(string $user,bool $bio): array
    {
        return array_map(function($r)use($bio){$dto=['deviceId'=>$r['device_id'],'deviceName'=>$r['device_name'],'createdAt'=>Ids::timestamp($r['created_at']),'lastUsedAt'=>Ids::timestamp($r['last_used_at']),'lockedUntil'=>Ids::timestamp($r['locked_until'])];if($bio){$dto['keyAlgorithm']=$r['key_algorithm'];}return $dto;},$this->db->all('SELECT * FROM '.($bio?'device_biometrics':'device_pins').' WHERE user_id=? ORDER BY last_used_at DESC,device_id',[$user]));
    }
    public function enroll(string $user,array $input): array
    {
        $device=$input['deviceId']??'';Rules::device($device);$name=Rules::deviceName($input['deviceName']??'');
        $algorithm=trim($input['keyAlgorithm']??'');$raw=$input['publicKey']??'';
        $der=$this->parseKey($raw,$algorithm);$this->repo->user($user);$id=Ids::uuid();
        try{$this->db->execute('INSERT INTO device_biometrics(id,user_id,device_id,device_name,public_key,key_algorithm) VALUES(?,?,?,?,decode(?,\'hex\'),?)',[$id,$user,$device,$name,bin2hex($der),$algorithm]);}
        catch(\PDOException $e){if($e->getCode()==='23505'){throw new ApiError('BIOMETRIC_ALREADY_SET','biometric login is already enabled');}throw $e;}
        return ['credentialId'=>$id];
    }
    private function parseKey(string $raw,string $algorithm): string
    {
        if(!in_array($algorithm,['ES256','EdDSA'],true)){throw new ApiError('INVALID_KEY_ALGORITHM','keyAlgorithm must be ES256 or EdDSA');}
        if(strlen($raw)>16384){throw new ApiError('INVALID_PUBLIC_KEY','invalid public key');}
        $der=str_contains($raw,'-----BEGIN PUBLIC KEY-----')?base64_decode(preg_replace('/-----[^-]+-----|\s/','',$raw),true):$this->base64($raw);
        if($der===false){throw new ApiError('INVALID_PUBLIC_KEY','invalid public key');}
        if($algorithm==='EdDSA'){
            if(strlen($der)!==44 || substr($der,0,12)!==hex2bin('302a300506032b6570032100')){throw new ApiError('INVALID_PUBLIC_KEY','public key must be Ed25519 SPKI');}
        }else{
            $key=@openssl_pkey_get_public($this->pem($der));$details=$key?openssl_pkey_get_details($key):false;
            if(!$details || $details['type']!==OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name']??'')!=='prime256v1'){throw new ApiError('INVALID_PUBLIC_KEY','public key must use P-256');}
            $der=base64_decode(preg_replace('/-----[^-]+-----|\s/','',$details['key']),true);
        }
        return $der;
    }
    private function pem(string $der): string {return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";}
    private function base64(string $raw): string|false {return base64_decode(strtr($raw,'-_','+/'),true);}
    private function credential(string $id): void {if(!Ids::valid($id)){throw new ApiError('INVALID_CREDENTIAL_ID','credentialId must be a UUID');}}
    public function challenge(array $input): array
    {
        $id=$input['credentialId']??'';$this->credential($id);
        return $this->db->transaction(function()use($id){
            $owner=$this->db->required('SELECT user_id FROM device_biometrics WHERE id=?',[$id],'BIOMETRIC_NOT_SET','biometric credential not found');$this->sessions->lockUser($owner['user_id']);
            $r=$this->db->required('SELECT * FROM device_biometrics WHERE id=? FOR UPDATE',[$id],'BIOMETRIC_NOT_SET','biometric login is not enabled for this credential');$this->activeLock($r,'BIOMETRIC');
            $this->db->execute('DELETE FROM biometric_challenges WHERE credential_id=? OR used OR expires_at<=now()',[$id]);$nonce=Ids::opaque();
            $this->db->execute('INSERT INTO biometric_challenges(challenge,credential_id,expires_at) VALUES(?,?,now()+interval \'120 seconds\')',[$nonce,$id]);
            return ['challenge'=>$nonce,'expiresIn'=>120];
        });
    }
    public function loginBiometric(array $input): array
    {
        $id=$input['credentialId']??'';$this->credential($id);$nonce=$input['challenge']??'';
        $result=$this->db->transaction(function()use($id,$nonce,$input){
            $owner=$this->db->required('SELECT user_id FROM device_biometrics WHERE id=?',[$id],'BIOMETRIC_NOT_SET','biometric credential not found');$this->sessions->lockUser($owner['user_id']);
            $r=$this->db->required('SELECT *,encode(public_key,\'hex\') AS key_hex FROM device_biometrics WHERE id=? FOR UPDATE',[$id],'BIOMETRIC_NOT_SET','biometric login is not enabled for this credential');$this->activeLock($r,'BIOMETRIC');
            $c=$this->db->one('SELECT * FROM biometric_challenges WHERE challenge=? FOR UPDATE',[$nonce]);
            if(!$c || $c['credential_id']!==$id || $c['used'] || strtotime($c['expires_at'])<=time()){
                if($c && $c['credential_id']===$id){$this->db->execute('DELETE FROM biometric_challenges WHERE challenge=?',[$nonce]);}
                return new ApiError('CHALLENGE_EXPIRED','biometric challenge expired or was already used');
            }
            $sig=$this->base64($input['signature']??'');$der=hex2bin($r['key_hex']);$valid=false;
            if($sig!==false){
                if($r['key_algorithm']==='EdDSA'){$valid=strlen($sig)===64 && sodium_crypto_sign_verify_detached($sig,$nonce,substr($der,-32));}
                else{$valid=@openssl_verify($nonce,$sig,$this->pem($der),OPENSSL_ALGO_SHA256)===1;}
            }
            if(!$valid){$this->db->execute('DELETE FROM biometric_challenges WHERE challenge=?',[$nonce]);return $this->failure($r,'device_biometrics','BIOMETRIC');}
            $this->db->execute('UPDATE biometric_challenges SET used=TRUE WHERE challenge=?',[$nonce]);
            $this->db->execute('UPDATE device_biometrics SET failed_attempts=0,lock_count=0,locked_until=NULL,last_used_at=now() WHERE id=?',[$id]);
            return $this->sessions->issue($r['user_id'],'biometric',$r['id']);
        });if($result instanceof ApiError){throw $result;}return $result;
    }
    public function session(string $user,string $device): array
    {
        if(trim($device)===''){throw new ApiError('DEVICE_ID_REQUIRED','deviceId query parameter is required');}Rules::device($device);
        $bio=$this->db->one('SELECT id FROM device_biometrics WHERE user_id=? AND device_id=?',[$user,$device]);
        return ['user'=>$this->repo->userDto($this->repo->user($user)),'device'=>['pinEnabled'=>$this->db->one('SELECT id FROM device_pins WHERE user_id=? AND device_id=?',[$user,$device])!==null,'biometricEnabled'=>$bio!==null,'biometricCredentialId'=>$bio['id']??null],'cartItemsCount'=>$this->db->one('SELECT COALESCE(sum(quantity),0)::int AS n FROM cart_items WHERE user_id=?',[$user])['n']];
    }
}
