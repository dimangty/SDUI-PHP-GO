<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\ApiError;
use Shop\infrastructure\{Database,RateLimiter};

final class LoginThrottle
{
    public function __construct(private Database $db) {}
    public function admit(string $email): void
    {
        // 4,096 fixed slots bound unknown-account cardinality. No email in logs,
        // metrics or rate-limit storage. Shared-slot contention is deliberately safe.
        $slot=hexdec(substr(hash('sha256',$email),0,3));
        (new RateLimiter($this->db))->check('password-account-slot:'.$slot,20);
    }
    public function check(string $user): void
    {
        $r=$this->db->one('SELECT locked_until FROM login_attempts WHERE user_id=? AND locked_until>clock_timestamp()',[$user]);
        if($r){throw new ApiError('ACCOUNT_LOCKED','account is temporarily locked');}
    }
    /** Called under user lock. Return, rather than throw, so failed counters commit. */
    public function failure(string $user): ApiError
    {
        $r=$this->db->one("INSERT INTO login_attempts(user_id,failed_attempts,last_attempt) VALUES(?,1,clock_timestamp()) ON CONFLICT(user_id) DO UPDATE SET failed_attempts=CASE WHEN login_attempts.locked_until<=clock_timestamp() OR login_attempts.last_attempt<clock_timestamp()-interval '15 minutes' THEN 1 ELSE login_attempts.failed_attempts+1 END,last_attempt=clock_timestamp() RETURNING failed_attempts",[$user]);
        if($r['failed_attempts']>=5){$this->db->execute("UPDATE login_attempts SET locked_until=clock_timestamp()+interval '15 minutes' WHERE user_id=?",[$user]);return new ApiError('ACCOUNT_LOCKED','account is temporarily locked');}
        return new ApiError('INVALID_CREDENTIALS','invalid email or password');
    }
    public function success(string $user): void {$this->db->execute('DELETE FROM login_attempts WHERE user_id=?',[$user]);}
}
