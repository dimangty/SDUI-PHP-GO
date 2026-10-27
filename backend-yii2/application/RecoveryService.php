<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env,Keycloak};
use Shop\repositories\ShopRepository;

/** Scheduler boundary: bounded safe retries, durable reservations, count-only output. */
final class RecoveryService
{
    // Four ten-second Keycloak calls, plus bounded local work. One job per
    // scan reserves progress for payments even with a full session backlog.
    private const SESSION_SECONDS=55;
    private const FINISH_SECONDS=3;
    public function __construct(private Database $db,private Keycloak $provider) {}
    private function sessions(): PasswordSessionRecovery
    {return new PasswordSessionRecovery($this->db,new SessionService($this->db,new ShopRepository($this->db)),$this->provider);}
    public function run(): array
    {
        $started=hrtime(true)/1e9;
        if($this->db->pdo()->inTransaction()){throw new \LogicException('Recovery must run outside a transaction');}
        $budget=Env::get('SCHEDULER_JOB_TIMEOUT_SECONDS','120');
        if($budget===''){$budget='120';}
        if(!ctype_digit($budget) || (int)$budget<90 || (int)$budget>86400){throw new \InvalidArgumentException('Recovery SCHEDULER_JOB_TIMEOUT_SECONDS must be 90..86400 seconds');}
        // Leave the parent five seconds for CLI startup/exit and scheduler polling.
        $deadline=$started+(int)$budget-5;
        $settings=$this->db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock,current_setting('transaction_timeout') AS transaction");
        // Per-statement limits alone cannot bound transactions containing loops
        // over stock or inbox rows. PostgreSQL 17's transaction timeout also
        // bounds those existing financial paths, without changing their logic.
        $this->db->one("SELECT set_config('statement_timeout','1s',false),set_config('lock_timeout','800ms',false),set_config('transaction_timeout','5s',false)");
        $failure=null;
        try{
            $completed=0;$failed=0;
            // Materialize a bounded due window before evaluating the volatile
            // lock function. LIMIT 1 above the filter acquires only the first
            // available lock, skipping busy live owners within ONE 1s statement.
            // No sort or other consuming node may sit above the lock filter.
            $jobs=hrtime(true)/1e9+self::SESSION_SECONDS+self::FINISH_SECONDS<=$deadline
                ?$this->db->all("WITH candidates AS MATERIALIZED (SELECT id FROM password_session_operations WHERE state='REVOKE_PENDING' AND recovery_attempts<5 AND next_retry_at<=clock_timestamp() AND (recovery_claim_until IS NULL OR recovery_claim_until<=clock_timestamp()) ORDER BY next_retry_at,id LIMIT 25) SELECT id FROM candidates WHERE pg_try_advisory_lock(hashtextextended('password-session-recovery:'||id::text,0)) LIMIT 1"):[];
            foreach($jobs as $job){
                $scope='password-session-recovery:'.$job['id'];
                try{
                    if(hrtime(true)/1e9+self::SESSION_SECONDS+self::FINISH_SECONDS>$deadline){break;}
                    // Autocommit reservation survives process death. No transaction spans HTTP.
                    $claim=$this->db->one("UPDATE password_session_operations SET recovery_attempts=recovery_attempts+1,next_retry_at=clock_timestamp()+(30*power(2,recovery_attempts))*interval '1 second',recovery_claim_until=clock_timestamp()+interval '120 seconds' WHERE id=? AND state='REVOKE_PENDING' AND recovery_attempts<5 AND next_retry_at<=clock_timestamp() AND (recovery_claim_until IS NULL OR recovery_claim_until<=clock_timestamp()) RETURNING id",[$job['id']]);
                    if(!$claim){continue;}
                    try{$this->sessions()->recover($job['id']);$completed++;}
                    catch(ApiError $e){if(!in_array($e->errorCode,['IDENTITY_PROVIDER_ERROR','IDENTITY_PROVIDER_UNAVAILABLE'],true)){throw $e;}$failed++;}
                    $this->db->execute('UPDATE password_session_operations SET recovery_claim_until=NULL WHERE id=?',[$job['id']]);
                }finally{$this->db->one('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$scope]);}
                break; // One actual attempt, including provider failure, per scan.
            }
            $repo=new ShopRepository($this->db);
            $payments=(new PaymentOperations($this->db,$repo,new CommerceService($this->db,$repo)))->recover(1,$deadline-self::FINISH_SECONDS,false);
            // Exact count-only output: no unbounded manual-review array or PHP loop.
            // All aggregate/table scans share the one-second statement deadline.
            $counts=$this->db->one("SELECT count(*) FILTER (WHERE state='RESET_PENDING' OR (state='REVOKE_PENDING' AND recovery_attempts>=5)) AS manual,count(*) FILTER (WHERE state='REVOKE_PENDING' AND recovery_attempts<5) AS pending,(SELECT count(*) FROM payment_operations WHERE state='UNKNOWN' OR (state='ABORTED' AND kind='refund' AND result->>'outcome'='NO_EFFECT')) AS payments_manual,(SELECT count(*) FROM payment_callback_inbox WHERE resolved_at IS NULL) AS callbacks_manual FROM password_session_operations");
            return ['paymentsDispatched'=>$payments['dispatched'],'paymentsManual'=>$counts['payments_manual'],
                'callbacksManual'=>$counts['callbacks_manual'],
                'sessionsCompleted'=>$completed,'sessionsFailed'=>$failed,'sessionsPending'=>$counts['pending'],'sessionsManual'=>$counts['manual']];
        }catch(\Throwable $e){$failure=$e;throw $e;}
        finally{
            try{$this->db->one("SELECT set_config('statement_timeout',?,false),set_config('lock_timeout',?,false),set_config('transaction_timeout',?,false)",[$settings['statement'],$settings['lock'],$settings['transaction']]);}
            catch(\Throwable $e){if($failure===null){throw $e;}} // Preserve the original error if a timeout disconnected SQL.
        }
    }
    /** Trusted console only. Evidence is a ticket reference, never free-form secrets. */
    public function recoverSession(string $operation,bool $confirmed,string $evidence): void
    {
        if(!$confirmed || !Ids::valid($operation) || !preg_match('/^[A-Z][A-Z0-9_]{1,31}-[1-9][0-9]{0,11}$/D',$evidence)){
            throw new ApiError('BAD_REQUEST','explicit independent outcome confirmation and an operator ticket reference are required');
        }
        if($this->db->pdo()->inTransaction()){throw new \LogicException('Recovery must run outside a transaction');}
        $scope='password-session-recovery:'.$operation;
        if(!$this->db->one('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired',[$scope])['acquired']){throw new ApiError('IDENTITY_PROVIDER_UNAVAILABLE','session recovery is already running');}
        try{
            // Independent confirmation resolves reset uncertainty before safe revocation.
            $this->db->execute("UPDATE password_session_operations SET state=CASE WHEN state='RESET_PENDING' THEN 'REVOKE_PENDING' ELSE state END,operator_evidence_ref=COALESCE(operator_evidence_ref,?),operator_confirmed_at=COALESCE(operator_confirmed_at,clock_timestamp()),updated_at=clock_timestamp() WHERE id=? AND state<>'COMPLETE'",[$evidence,$operation]);
            $this->sessions()->recover($operation);
        }finally{$this->db->one('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$scope]);}
    }
}
