<?php
declare(strict_types=1);
namespace Shop\domain;

/** Framework-independent transition; all timestamps come from one PostgreSQL read. */
final class LoginLockoutPolicy
{
    public const MAX_FAILURES = 5;
    public const INTERVAL = 'PT15M';

    /** @return array{failed_attempts:int,last_attempt:\DateTimeImmutable,locked_until:?\DateTimeImmutable} */
    public function failure(int $failures, ?\DateTimeImmutable $last, ?\DateTimeImmutable $locked, \DateTimeImmutable $now): array
    {
        // A queued failure must neither extend nor replace an active lock.
        if ($locked !== null && $locked > $now) {
            return ['failed_attempts' => $failures, 'last_attempt' => $last ?? $now, 'locked_until' => $locked];
        }
        $interval = new \DateInterval(self::INTERVAL);
        $expired = ($locked !== null && $locked <= $now) || ($last !== null && $last <= $now->sub($interval));
        $next = $expired ? 1 : $failures + 1;
        return [
            'failed_attempts' => $next,
            'last_attempt' => $now,
            'locked_until' => $next >= self::MAX_FAILURES ? $now->add($interval) : null,
        ];
    }
}
