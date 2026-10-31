<?php
declare(strict_types=1);
namespace Shop\application;

use Shop\domain\{ApiError,LoginLockoutPolicy};
use Shop\infrastructure\{Database,RateLimiter};

final class LoginThrottle
{
    public function __construct(private Database $db, private LoginLockoutPolicy $policy = new LoginLockoutPolicy()) {}
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
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Password failure requires the user-lock transaction');
        }
        // User-first locking serializes login and password change. Read the DB
        // clock AFTER acquiring that lock; never use transaction-start or host time.
        $row = $this->db->one(
            'SELECT clock_timestamp() AS observed_at, a.failed_attempts, a.last_attempt, a.locked_until '
            .'FROM (SELECT ?::uuid AS id) u LEFT JOIN login_attempts a ON a.user_id=u.id',
            [$user]
        );
        $state = $this->policy->failure(
            $row['failed_attempts'] ?? 0,
            $row['last_attempt'] === null ? null : new \DateTimeImmutable($row['last_attempt']),
            $row['locked_until'] === null ? null : new \DateTimeImmutable($row['locked_until']),
            new \DateTimeImmutable($row['observed_at'])
        );
        $timestamp = static fn(?\DateTimeImmutable $time): ?string => $time?->format('Y-m-d H:i:s.uP');
        // One state write, inside the same user-lock transaction. Clearing an
        // expired marker is part of the transition, not an unlocked cleanup.
        $this->db->execute(
            'INSERT INTO login_attempts(user_id,failed_attempts,last_attempt,locked_until) VALUES(?,?,?,?) '
            .'ON CONFLICT(user_id) DO UPDATE SET failed_attempts=EXCLUDED.failed_attempts, '
            .'last_attempt=EXCLUDED.last_attempt, locked_until=EXCLUDED.locked_until',
            [$user, $state['failed_attempts'], $timestamp($state['last_attempt']), $timestamp($state['locked_until'])]
        );
        return $state['locked_until'] !== null
            ? new ApiError('ACCOUNT_LOCKED', 'account is temporarily locked')
            : new ApiError('INVALID_CREDENTIALS', 'invalid email or password');
    }
    public function success(string $user): void {$this->db->execute('DELETE FROM login_attempts WHERE user_id=?',[$user]);}
}
