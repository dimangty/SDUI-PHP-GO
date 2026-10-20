<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

final class MaintenanceService
{
    public function __construct(private Database $db,private ShopRepository $repo,private CommerceService $commerce) {}
    public function run(): int
    {
        $pending=Env::duration('ORDER_PENDING_TTL',900);$authorized=Env::duration('ORDER_AUTHORIZED_TTL',7200);$count=0;
        $rows=$this->db->all('SELECT o.id FROM orders o JOIN payments p ON p.id=o.payment_id WHERE o.status=\'CREATED\' AND ((p.status=\'PENDING\' AND o.created_at<=now()-?*interval \'1 second\') OR (p.status=\'AUTHORIZED\' AND p.updated_at<=now()-?*interval \'1 second\')) ORDER BY o.created_at,o.id LIMIT 1000',[$pending,$authorized]);
        foreach($rows as $row){
            $cancelled=$this->db->transaction(function()use($row,$pending,$authorized){
                $o=$this->commerce->lockOrderPayment($row['id']);$p=$this->repo->payment($o['payment_id']);
                $now=microtime(true);
                if($o['status']!=='CREATED' || !($p['status']==='PENDING' && Ids::epoch($o['created_at'])<=$now-$pending || $p['status']==='AUTHORIZED' && Ids::epoch($p['updated_at'])<=$now-$authorized)){return false;}
                $this->db->execute('UPDATE orders SET status=\'CANCELLED\' WHERE id=?',[$o['id']]);
                $this->db->execute('UPDATE payments SET status=\'CANCELLED\',updated_at=now() WHERE id=?',[$p['id']]);$this->commerce->restoreStock($o['id']);return true;
            });if($cancelled){$count++;}
        }
        $retention=Env::duration('IDEMPOTENCY_RETENTION',86400);
        $this->db->execute('DELETE FROM idempotency_keys WHERE created_at<now()-?*interval \'1 second\'',[$retention]);
        $this->db->execute('DELETE FROM processed_webhooks WHERE processed_at<now()-?*interval \'1 second\'',[$retention]);
        foreach(['access_sessions','refresh_sessions'] as $table){$this->db->execute('DELETE FROM '.$table.' WHERE expires_at<=now()');}
        $this->db->execute('DELETE FROM biometric_challenges WHERE used OR expires_at<=now()');
        $this->db->execute('DELETE FROM rate_limit_hits WHERE occurred_at<=now()-?*interval \'1 second\'',[Env::duration('RATE_LIMIT_WINDOW',60)]);
        $this->db->execute('DELETE FROM login_attempts WHERE last_attempt<now()-?*interval \'1 second\' AND (locked_until IS NULL OR locked_until<now())',[$retention]);
        return $count;
    }
}
