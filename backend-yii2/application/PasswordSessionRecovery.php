<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Keycloak};

/** Durable fail-closed boundary around a non-transactional identity provider.
 * Recovery retries revocation by a stored subject only; it never retries a reset.
 */
final class PasswordSessionRecovery
{
    public function __construct(private Database $db,private SessionService $sessions,private Keycloak $provider) {}
    public function change(string $user,string $password,int $verifiedVersion): void
    {
        $operation=Ids::uuid();$scope='password-session-recovery:'.$operation;
        $this->db->one('SELECT pg_advisory_lock(hashtextextended(?,0))',[$scope]);
        try{
            // Acquire the recovery lock before publishing the job. Recovery can
            // never finish an active, still-in-flight remote reset.
            $this->begin($user,$operation,$verifiedVersion);
            try{$this->provider->resetPassword($user,$password);$this->resetAccepted($operation);$this->recover($operation);}
            catch(\Throwable $e){throw $this->signal($operation,$e);}
        }finally{$this->unlock($scope);}
    }
    private function begin(string $user,string $id,int $verifiedVersion): void
    {
        if($this->db->pdo()->inTransaction()){throw new \LogicException('Password fence must commit before remote work');}
        $this->db->transaction(function()use($user,$id,$verifiedVersion){
            $u=$this->sessions->lockUser($user);
            if($u['sessions_blocked'] || $u['session_security_version']!==$verifiedVersion){throw new ApiError('PASSWORD_CHANGED','password verification is stale or recovery is pending');}
            $this->sessions->revokeUser($user);
            $this->db->execute('UPDATE users SET sessions_blocked=TRUE WHERE id=?',[$user]);
            $this->db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) SELECT ?,id,session_security_version,'RESET_PENDING' FROM users WHERE id=?",[$id,$user]);
        });
    }
    private function resetAccepted(string $operation): void
    {
        $this->db->execute("UPDATE password_session_operations SET state='REVOKE_PENDING',updated_at=clock_timestamp() WHERE id=? AND state='RESET_PENDING'",[$operation]);
    }
    private function signal(string $operation,\Throwable $error): ApiError
    {
        $code=$error instanceof ApiError && in_array($error->errorCode,['IDENTITY_PROVIDER_ERROR','IDENTITY_PROVIDER_UNAVAILABLE'],true)?$error->errorCode:'IDENTITY_PROVIDER_ERROR';
        $stored=$error instanceof ApiError?$code:'LOCAL_COMPLETION_FAILED';
        try{$this->db->execute("UPDATE password_session_operations SET last_error=?,updated_at=clock_timestamp() WHERE id=? AND state<>'COMPLETE'",[$stored,$operation]);}catch(\Throwable){/* The committed fence/job still survives. */}
        // ErrorEnvelope.details and existing CMP readers require string values.
        return new ApiError($code,'password change requires session recovery',['recoveryRequired'=>'true','recoveryOperationId'=>$operation]);
    }
    /** RESET_PENDING requires an operator to independently resolve the password
     * outcome. This flag is for trusted recovery tooling, never request input.
     */
    public function recover(string $operation,bool $passwordOutcomeConfirmed=false): void
    {
        if(!Ids::valid($operation)){throw new ApiError('IDENTITY_PROVIDER_ERROR','invalid recovery operation');}
        $operation=Ids::canonical($operation);
        if($this->db->pdo()->inTransaction()){throw new \LogicException('Recovery must run outside a transaction');}
        $scope='password-session-recovery:'.$operation;
        // Session-level lock serializes recovery without an open SQL transaction
        // over HTTP. A crashed connection releases it automatically.
        if(!$this->db->one('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired',[$scope])['acquired']){
            throw new ApiError('IDENTITY_PROVIDER_UNAVAILABLE','session recovery is already running');
        }
        try{
            $job=$this->db->required('SELECT * FROM password_session_operations WHERE id=?',[$operation],'IDENTITY_PROVIDER_ERROR','recovery operation not found');
            if($job['state']==='COMPLETE'){return;}
            if($job['state']==='RESET_PENDING' && !$passwordOutcomeConfirmed){throw new ApiError('IDENTITY_PROVIDER_ERROR','password reset outcome requires operator confirmation');}
            try{
                $this->provider->logoutUser($job['user_id']);
                $this->db->transaction(function()use($operation,$job){
                    $this->sessions->lockUser($job['user_id']);
                    $this->db->execute("UPDATE password_session_operations SET state='COMPLETE',last_error=NULL,updated_at=clock_timestamp() WHERE id=?",[$operation]);
                    // The active recovery job owns this fence. Ordinary logout
                    // also advances security_version but creates no newer fence.
                    // The user lock serializes this predicate with begin().
                    $this->db->execute("UPDATE users SET sessions_blocked=FALSE WHERE id=? AND NOT EXISTS (SELECT 1 FROM password_session_operations op WHERE op.user_id=users.id AND op.state<>'COMPLETE')",[$job['user_id']]);
                });
            }catch(\Throwable $e){throw $this->signal($operation,$e);}
        }finally{$this->unlock($scope);}
    }
    private function unlock(string $scope): void
    {
        // A disconnected backend has already released its session locks. Do not
        // replace the explicit durable-recovery signal with a cleanup exception.
        try{$this->db->one('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$scope]);}catch(\Throwable){}
    }
}
