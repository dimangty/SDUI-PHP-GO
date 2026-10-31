<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,PasswordHasher,Rules};
use Shop\infrastructure\Database;
use Shop\repositories\UserCredentialsRepository;

/** Console-only, selected-user trust decisions. Never import/infer external roles or bans. */
final class LocalIdentityMigration
{
    public function __construct(private Database $db,private SessionService $sessions,private PasswordHasher $hasher=new PasswordHasher()) {}

    /** Counts only, including on schema013/014 before normalized-email uniqueness is installed. */
    public function inventory(): array
    {
        return $this->db->transaction(function(){
            $this->db->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $columns=array_column($this->db->all("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='users'"),'column_name');
            $ready=in_array('local_identity_ready',$columns,true);$disabled=in_array('account_disabled',$columns,true);
            $counts=$this->db->one('SELECT count(*)::int AS users,count(*) FILTER (WHERE is_admin)::int AS admin,count(*) FILTER (WHERE sessions_blocked)::int AS blocked,'
                .($ready?'count(*) FILTER (WHERE NOT local_identity_ready)':'count(*)').'::int AS unready,'
                .($disabled?'count(*) FILTER (WHERE account_disabled)':'0').'::int AS disabled FROM users');
            $conflicts=$this->db->one('SELECT count(*)::int AS conflicting_normalized_email_groups,coalesce(sum(n),0)::int AS conflicting_normalized_email_users FROM (SELECT count(*) AS n FROM users GROUP BY lower(btrim(email)) HAVING count(*)>1) c');
            $passwords=['native'=>0,'legacy'=>0,'empty'=>0,'unknown'=>0];
            // Stream verifiers internally; never return addresses, UUIDs, hashes or samples.
            $s=$this->db->pdo()->query('SELECT password_hash FROM users');
            while(($hash=$s->fetchColumn())!==false){$passwords[$this->hasher->kind($hash)]++;}$s->closeCursor();
            $extra=$this->db->one("SELECT (SELECT count(*)::int FROM password_session_operations WHERE state<>'COMPLETE') AS pending_recovery,"
                .'(SELECT count(*)::int FROM session_families) AS families,'
                .'(SELECT count(*)::int FROM session_families WHERE revoked_at IS NULL) AS unrevoked_families,'
                .'(SELECT count(*)::int FROM device_pins) AS pin,(SELECT count(*)::int FROM device_biometrics) AS biometric');
            return $counts+$conflicts+$extra+['passwords'=>$passwords];
        });
    }

    public function attest(string $user,string $ticket,bool $admin,bool $disabled,bool $apply=false): array
    {
        $user=$this->authorize($user,$ticket,$apply);
        $request=['admin'=>$admin,'disabled'=>$disabled];
        return $this->apply($user,$ticket,'attest',$request,function(array $u)use($admin,$disabled){
            // An existing ban can only be lifted by the separate reviewed state command.
            if(($u['account_disabled'] && !$disabled) || ($u['local_identity_ready'] && $u['account_disabled']!==$disabled)){
                throw new ApiError('MIGRATION_STATE_REVIEW_REQUIRED','Use a separate reviewed account-state ticket');
            }
            $this->db->execute('UPDATE users SET is_admin=?,account_disabled=?,local_identity_ready=TRUE WHERE id=?',[$admin,$disabled,$u['id']]);
            $this->sessions->revokeUser($u['id']);
        });
    }

    public function setDisabled(string $user,string $ticket,bool $disabled,bool $apply=false): array
    {
        $user=$this->authorize($user,$ticket,$apply);
        return $this->apply($user,$ticket,'set-disabled',['disabled'=>$disabled],function(array $u)use($disabled){
            $this->db->execute('UPDATE users SET account_disabled=? WHERE id=?',[$disabled,$u['id']]);
            $this->sessions->revokeUser($u['id']);
        });
    }

    /** No journal fingerprint: a retry must match the current, unsuperseded verifier. */
    public function resetPassword(string $user,string $ticket,#[\SensitiveParameter] string $password,bool $ownershipConfirmed,bool $apply=false,?string $operation=null,?string $outcome=null,bool $identityConfirmed=false,bool $orphanConfirmed=false): array
    {
        $user=$this->authorize($user,$ticket,$apply);
        if(!$ownershipConfirmed){throw new ApiError('MIGRATION_OWNERSHIP_REQUIRED','Out-of-application ownership verification is required');}
        if($orphanConfirmed && ($operation!==null || $outcome!==null || $identityConfirmed)){throw new ApiError('MIGRATION_OPERATION_MISMATCH','Orphan investigation and selected-operation resolution are separate decisions');}
        if($operation!==null){
            $operation=$this->uuid($operation);
            if(!$identityConfirmed){throw new ApiError('MIGRATION_RECOVERY_CONFIRMATION_REQUIRED','Confirm the selected operation identity independently');}
            if(!in_array($outcome,['confirmed-reset','unknown-reset'],true)){throw new ApiError('MIGRATION_RECOVERY_OUTCOME_REQUIRED','Record the independently reviewed historical reset outcome');}
        }elseif($outcome!==null || $identityConfirmed){throw new ApiError('MIGRATION_OPERATION_MISMATCH','Select the historical operation explicitly');}
        $request=['ownershipConfirmed'=>true,'operation'=>$operation,'outcome'=>$outcome,'identityConfirmed'=>$identityConfirmed];
        if($orphanConfirmed){$request['orphanConfirmed']=true;}
        if($this->replay($user,$ticket,'reset-password',$request)){return $this->resetReceipt($user,$ticket,$password);}
        $snapshot=$this->db->required('SELECT * FROM users WHERE id=?',[$user],'USER_NOT_FOUND','user not found');
        Rules::password($password);
        if(strcasecmp($password,trim($snapshot['email']))===0){throw new ApiError('WEAK_PASSWORD','password must differ from the account email');}
        // Hash before user locks/SQL transaction. A concurrent credential change must win.
        $hash=$this->hasher->hash($password);
        $result=$this->apply($user,$ticket,'reset-password',$request,function(array $u)use($snapshot,$hash,$operation,$outcome,$ticket,$orphanConfirmed){
            if(!UserCredentialsRepository::sameSnapshot($snapshot,$u) || $snapshot['email']!==$u['email'] || $snapshot['is_admin']!==$u['is_admin'] || $snapshot['password_changed_at']!==$u['password_changed_at']){
                throw new ApiError('MIGRATION_STALE_SNAPSHOT','Account changed during password preparation; review again with a fresh attempt');
            }
            if($operation!==null){
                $op=$this->db->one('SELECT * FROM password_session_operations WHERE id=? AND user_id=? FOR UPDATE',[$operation,$u['id']]);
                if($op===null || $op['state']==='COMPLETE' || $op['security_version']>$u['session_security_version']){throw new ApiError('MIGRATION_OPERATION_MISMATCH','Selected unresolved operation does not match this identity');}
                if($op['state']==='REVOKE_PENDING' && $outcome!=='confirmed-reset'){throw new ApiError('MIGRATION_RECOVERY_OUTCOME_REQUIRED','The recorded reset outcome requires explicit confirmation');}
                $this->db->execute("UPDATE password_session_operations SET state='COMPLETE',operator_evidence_ref=?,operator_confirmed_at=clock_timestamp(),updated_at=clock_timestamp(),last_error=NULL,recovery_claim_until=NULL WHERE id=? AND user_id=?",[$ticket,$operation,$u['id']]);
            }
            if($orphanConfirmed && (!$u['sessions_blocked'] || $this->db->one("SELECT id FROM password_session_operations WHERE user_id=? AND state<>'COMPLETE' LIMIT 1",[$u['id']])!==null)){
                throw new ApiError('MIGRATION_OPERATION_MISMATCH','An orphan fence requires no unresolved historical operation');
            }
            (new UserCredentialsRepository($this->db))->setHash($u['id'],$hash);
            $this->sessions->revokeUser($u['id']);
            $this->db->execute('DELETE FROM login_attempts WHERE user_id=?',[$u['id']]);
            if($operation!==null){
                // This operation alone was reviewed. Never complete/unblock a whole backlog.
                $this->db->execute("UPDATE users SET sessions_blocked=FALSE WHERE id=? AND NOT EXISTS (SELECT 1 FROM password_session_operations WHERE user_id=? AND state<>'COMPLETE')",[$u['id'],$u['id']]);
            }
            if($orphanConfirmed && $this->db->execute("UPDATE users SET sessions_blocked=FALSE WHERE id=? AND NOT EXISTS (SELECT 1 FROM password_session_operations WHERE user_id=? AND state<>'COMPLETE')",[$u['id'],$u['id']])!==1){
                throw new ApiError('MIGRATION_OPERATION_MISMATCH','Historical state changed during orphan resolution');
            }
        });
        // A competing same-ticket reset may have won while we computed the hash.
        return $result['status']==='already_applied'?$this->resetReceipt($user,$ticket,$password):$result;
    }

    private function resetReceipt(string $user,string $ticket,#[\SensitiveParameter] string $password): array
    {
        $entry=$this->db->required('SELECT security_version FROM local_identity_migration_journal WHERE ticket=?',[$ticket],'MIGRATION_TICKET_CONFLICT','Ticket receipt is unavailable');
        $snapshot=$this->db->required('SELECT * FROM users WHERE id=?',[$user],'USER_NOT_FOUND','user not found');
        if($snapshot['session_security_version']!==$entry['security_version']){throw new ApiError('MIGRATION_TICKET_SUPERSEDED','Account security state changed after this ticket; review its receipt');}
        // Verify against the normal credential, never a password-derived journal key.
        $valid=$this->hasher->verify($password,$snapshot['password_hash']);
        return $this->db->transaction(function()use($user,$snapshot,$valid){
            $u=$this->sessions->lockUser($user);
            if(!UserCredentialsRepository::sameSnapshot($snapshot,$u) || $snapshot['password_changed_at']!==$u['password_changed_at'] || $snapshot['email']!==$u['email'] || $snapshot['is_admin']!==$u['is_admin']){
                throw new ApiError('MIGRATION_TICKET_SUPERSEDED','Account security state changed after this ticket; review its receipt');
            }
            if(!$valid){throw new ApiError('MIGRATION_TICKET_CONFLICT','Ticket does not match the supplied password');}
            return ['status'=>'already_applied'];
        });
    }

    private function authorize(string $user,string $ticket,bool $apply): string
    {
        if(PHP_SAPI!=='cli'){throw new ApiError('MIGRATION_CONSOLE_ONLY','Operator actions require the console');}
        if(!$apply){throw new ApiError('MIGRATION_APPLY_REQUIRED','Explicit apply is required');}
        if($this->db->pdo()->inTransaction()){throw new \LogicException('Operator actions must own the transaction');}
        $user=$this->uuid($user);
        if(!preg_match('/^[A-Z][A-Z0-9_]{1,31}-[1-9][0-9]{0,11}$/D',$ticket)){throw new ApiError('MIGRATION_TICKET_REQUIRED','A ticket reference is required');}
        if(!$this->db->one("SELECT has_table_privilege(current_user,'local_identity_migration_journal','INSERT') AND has_table_privilege(current_user,'local_identity_migration_journal','SELECT') AS allowed")['allowed']){
            throw new ApiError('MIGRATION_OPERATOR_PERMISSION_REQUIRED','Operator database capability is required');
        }
        return $user;
    }
    private function uuid(string $value): string
    {
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',$value)){throw new ApiError('MIGRATION_UUID_REQUIRED','Select one UUID');}
        return strtolower($value);
    }
    private function replay(string $user,string $ticket,string $action,array $request): bool
    {
        $entry=$this->db->one('SELECT user_id,action,request FROM local_identity_migration_journal WHERE ticket=?',[$ticket]);
        if($entry===null){return false;}
        if($entry['user_id']!==$user || $entry['action']!==$action || json_decode($entry['request'],true,512,JSON_THROW_ON_ERROR)!=$request){
            throw new ApiError('MIGRATION_TICKET_CONFLICT','Ticket already records a different reviewed action');
        }
        return true;
    }
    /** Common order: user first, then ticket/operation, then families/tokens. */
    private function apply(string $user,string $ticket,string $action,array $request,\Closure $work): array
    {
        return $this->db->transaction(function()use($user,$ticket,$action,$request,$work){
            $u=$this->sessions->lockUser($user);
            $this->db->advisory('local-identity-ticket:'.$ticket);
            if($this->replay($user,$ticket,$action,$request)){return ['status'=>'already_applied'];}
            $work($u);
            $u=$this->db->required('SELECT * FROM users WHERE id=?',[$user],'USER_NOT_FOUND','user not found');
            $this->db->execute('INSERT INTO local_identity_migration_journal(ticket,user_id,action,request,security_version,is_admin,account_disabled,local_identity_ready,sessions_blocked) VALUES(?,?,?,?::jsonb,?,?,?,?,?)',[
                $ticket,$user,$action,json_encode($request,JSON_THROW_ON_ERROR),$u['session_security_version'],$u['is_admin'],$u['account_disabled'],$u['local_identity_ready'],$u['sessions_blocked'],
            ]);
            return ['status'=>'applied'];
        });
    }
}
