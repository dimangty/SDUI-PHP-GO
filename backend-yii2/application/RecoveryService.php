<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\ApiError;
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

/** Scheduler boundary: bounded safe retries, durable reservations, count-only output. */
final class RecoveryService
{
    private const FINISH_SECONDS=3;
    public function __construct(private Database $db) {}
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
            $repo=new ShopRepository($this->db);
            $payments=(new PaymentOperations($this->db,$repo,new CommerceService($this->db,$repo)))->recover(1,$deadline-self::FINISH_SECONDS,false);
            // Exact count-only output: no unbounded manual-review array or PHP loop.
            // All aggregate/table scans share the one-second statement deadline.
            // Historical identity uncertainty cannot be resolved by retrying remote
            // revocation. Only the trusted, per-user local migration can resolve it.
            $counts=$this->db->one("SELECT count(*) FILTER (WHERE state<>'COMPLETE') AS manual,(SELECT count(*) FROM payment_operations WHERE state='UNKNOWN' OR (state='ABORTED' AND kind='refund' AND result->>'outcome'='NO_EFFECT')) AS payments_manual,(SELECT count(*) FROM payment_callback_inbox WHERE resolved_at IS NULL) AS callbacks_manual FROM password_session_operations");
            return ['paymentsDispatched'=>$payments['dispatched'],'paymentsManual'=>$counts['payments_manual'],
                'callbacksManual'=>$counts['callbacks_manual'],
                'sessionsCompleted'=>0,'sessionsFailed'=>0,'sessionsPending'=>0,'sessionsManual'=>$counts['manual']];
        }catch(\Throwable $e){$failure=$e;throw $e;}
        finally{
            try{$this->db->one("SELECT set_config('statement_timeout',?,false),set_config('lock_timeout',?,false),set_config('transaction_timeout',?,false)",[$settings['statement'],$settings['lock'],$settings['transaction']]);}
            catch(\Throwable $e){if($failure===null){throw $e;}} // Preserve the original error if a timeout disconnected SQL.
        }
    }
    /** Retained CLI contract; an old remote-outcome confirmation is insufficient. */
    public function recoverSession(string $operation,bool $confirmed,string $evidence): void
    {
        throw new ApiError('BAD_REQUEST','Legacy session resolution is unsafe; use the trusted auth/ local identity tool with an individually audited reset.');
    }
}
