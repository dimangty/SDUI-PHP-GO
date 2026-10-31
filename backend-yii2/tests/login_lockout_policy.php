<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\domain\LoginLockoutPolicy;

test('exact microsecond lock and window boundaries reset once and preserve active lock deadlines', function () {
    $policy = new LoginLockoutPolicy();
    $now = new DateTimeImmutable('2026-10-31T10:00:00.123456Z');
    $last = $now->modify('-1 minute');
    $expired = $policy->failure(5, $last, $now, $now);
    same(1, $expired['failed_attempts']);
    same(null, $expired['locked_until']);
    same(2, $policy->failure(1, $now, null, $now)['failed_attempts']);
    $window = $now->modify('-15 minutes');
    same(1, $policy->failure(4, $window, null, $now)['failed_attempts']);
    same(5, $policy->failure(4, $window->modify('+1 microsecond'), null, $now)['failed_attempts']);
    $active = $now->modify('+1 microsecond');
    same(['failed_attempts' => 5, 'last_attempt' => $last, 'locked_until' => $active], $policy->failure(5, $last, $active, $now));
});

runTests();
