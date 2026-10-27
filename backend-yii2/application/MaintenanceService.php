<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

final class MaintenanceService
{
    public function __construct(private Database $db,private ShopRepository $repo,private CommerceService $commerce) {}
    public function run(): int
    {
        $started=hrtime(true)/1e9;
        if($this->db->pdo()->inTransaction()){throw new \LogicException('Maintenance must run outside a transaction');}
        $budget=Env::get('SCHEDULER_JOB_TIMEOUT_SECONDS','120');if($budget===''){$budget='120';}
        if(!ctype_digit($budget) || (int)$budget<40 || (int)$budget>86400){throw new \InvalidArgumentException('Maintenance SCHEDULER_JOB_TIMEOUT_SECONDS must be 40..86400 seconds');}
        $deadline=$started+(int)$budget-5; // Parent startup/exit/polling headroom.
        $settings=$this->db->one("SELECT current_setting('statement_timeout') AS statement,current_setting('lock_timeout') AS lock,current_setting('transaction_timeout') AS transaction");
        $this->db->one("SELECT set_config('statement_timeout','1s',false),set_config('lock_timeout','800ms',false),set_config('transaction_timeout','5s',false)");
        $failure=null;
        try{
            $retention=Env::duration('IDEMPOTENCY_RETENTION',86400);
            // One committed, <=1000-row slice per retention table FIRST. Payment
            // backlog cannot starve cleanup. All identifiers/predicates are local.
            foreach([
                ['idempotency_keys','created_at<now()-?*interval \'1 second\'',[$retention]],
                ['processed_webhooks','processed_at<now()-?*interval \'1 second\'',[$retention]],
                ['access_sessions','expires_at<=now()',[]],['refresh_sessions','expires_at<=now()',[]],
                ['biometric_challenges','used OR expires_at<=now()',[]],
                ['rate_limit_hits','occurred_at<=now()-?*interval \'1 second\'',[Env::duration('RATE_LIMIT_WINDOW',60)]],
                ['login_attempts','last_attempt<now()-?*interval \'1 second\' AND (locked_until IS NULL OR locked_until<now())',[$retention]],
            ] as [$table,$predicate,$args]){
                $this->db->execute('DELETE FROM '.$table.' WHERE ctid IN (SELECT ctid FROM '.$table.' WHERE '.$predicate.' LIMIT 1000)',$args);
            }
            $pending=Env::duration('ORDER_PENDING_TTL',900);$authorized=Env::duration('ORDER_AUTHORIZED_TTL',7200);$count=0;
            // One 1s selection and at most 100 local 5s transactions. Admission
            // reserves the whole next transaction plus 2s to restore/return.
            $rows=hrtime(true)/1e9+8<=$deadline?$this->db->all('SELECT o.id FROM orders o JOIN payments p ON p.id=o.payment_id WHERE o.status=\'CREATED\' AND ((p.status=\'PENDING\' AND o.created_at<=now()-?*interval \'1 second\') OR (p.status=\'AUTHORIZED\' AND p.updated_at<=now()-?*interval \'1 second\')) ORDER BY o.created_at,o.id LIMIT 100',[$pending,$authorized]):[];
            $operations=new PaymentOperations($this->db,$this->repo,$this->commerce);
            foreach($rows as $row){
                if(hrtime(true)/1e9+7>$deadline){break;}
                if($operations->expire($row['id'],$pending,$authorized,false)){$count++;}
            }
            // No recovery/HTTP here: committed READY intents belong to recovery-worker.
            return $count;
        }catch(\Throwable $e){$failure=$e;throw $e;}
        finally{
            try{$this->db->one("SELECT set_config('statement_timeout',?,false),set_config('lock_timeout',?,false),set_config('transaction_timeout',?,false)",[$settings['statement'],$settings['lock'],$settings['transaction']]);}
            catch(\Throwable $e){if($failure===null){throw $e;}}
        }
    }
}
