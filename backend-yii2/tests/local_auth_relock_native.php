<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';

use Shop\application\LoginThrottle;

test('PostgreSQL commits all five failures in each of three lockout cycles', function () {
    [$db, , , $auth] = localServices();
    [$pair, $email] = localRegister($auth);
    $user = $pair['userId'];
    for ($cycle = 1; $cycle <= 3; $cycle++) {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            errorCode($attempt === 5 ? 'ACCOUNT_LOCKED' : 'INVALID_CREDENTIALS',
                fn() => $auth->login(['email' => $email, 'password' => 'WrongPass7395']));
            same($attempt, $db->one('SELECT failed_attempts FROM login_attempts WHERE user_id=?', [$user])['failed_attempts']);
        }
        $before = $db->one('SELECT * FROM login_attempts WHERE user_id=?', [$user]);
        errorCode('ACCOUNT_LOCKED', fn() => $auth->login(['email' => $email, 'password' => 'StrongPass7395']));
        errorCode('ACCOUNT_LOCKED', fn() => $auth->login(['email' => $email, 'password' => 'WrongPass7395']));
        same($before, $db->one('SELECT * FROM login_attempts WHERE user_id=?', [$user]));
        same(1, $db->one('SELECT count(*) AS n FROM session_families WHERE user_id=?', [$user])['n']);
        // Only this fixture's timestamps change; account-slot admission is intact.
        $db->execute("UPDATE login_attempts SET locked_until=clock_timestamp()-interval '1 second' WHERE user_id=?", [$user]);
        // Simulate elapsed network-admission time only for this fixture's fixed slot.
        $slot = hexdec(substr(hash('sha256', $email), 0, 3));
        $db->execute("UPDATE rate_limit_hits SET occurred_at=clock_timestamp()-interval '61 seconds' WHERE bucket_key=?", ['password-account-slot:'.$slot]);
    }
    same($user, $auth->login(['email' => $email, 'password' => 'StrongPass7395'])['userId']);
    same(null, $db->one('SELECT * FROM login_attempts WHERE user_id=?', [$user]));
});

test('PostgreSQL password-change failures share and rearm the password-login budget', function () {
    [$db, , , $auth] = localServices();
    [$pair, $email] = localRegister($auth);
    $user = $pair['userId'];
    for ($cycle = 1; $cycle <= 3; $cycle++) {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $operation = $attempt % 2 === 0
                ? fn() => $auth->login(['email' => $email, 'password' => 'wrong'])
                : fn() => $auth->changePassword($user, ['oldPassword' => 'wrong', 'newPassword' => 'ChangedPass8395']);
            errorCode($attempt === 5 ? 'ACCOUNT_LOCKED' : 'INVALID_CREDENTIALS', $operation);
            same($attempt, $db->one('SELECT failed_attempts FROM login_attempts WHERE user_id=?', [$user])['failed_attempts']);
        }
        errorCode('ACCOUNT_LOCKED', fn() => $auth->changePassword($user, ['oldPassword' => 'StrongPass7395', 'newPassword' => 'ChangedPass8395']));
        $db->execute("UPDATE login_attempts SET locked_until=clock_timestamp()-interval '1 second' WHERE user_id=?", [$user]);
        $slot = hexdec(substr(hash('sha256', $email), 0, 3));
        $db->execute("UPDATE rate_limit_hits SET occurred_at=clock_timestamp()-interval '61 seconds' WHERE bucket_key=?", ['password-account-slot:'.$slot]);
    }
    $auth->changePassword($user, ['oldPassword' => 'StrongPass7395', 'newPassword' => 'ChangedPass8395']);
    same(null, $db->one('SELECT * FROM login_attempts WHERE user_id=?', [$user]));
    errorCode('UNAUTHORIZED', fn() => $auth->authenticate($pair['accessToken']));
    same($user, $auth->login(['email' => $email, 'password' => 'ChangedPass8395'])['userId']);
});

test('PostgreSQL rolling window without a prior lock resets once then accumulates', function () {
    [$db, $sessions, , $auth] = localServices();
    [$pair] = localRegister($auth);
    $user = $pair['userId'];
    $db->execute("INSERT INTO login_attempts(user_id,failed_attempts,last_attempt) VALUES(?,4,clock_timestamp()-interval '15 minutes')", [$user]);
    $throttle = new LoginThrottle($db);
    foreach ([1, 2, 3, 4, 5] as $attempt) {
        $error = $db->transaction(function () use ($sessions, $throttle, $user) {
            $sessions->lockUser($user);
            return $throttle->failure($user);
        });
        same($attempt === 5 ? 'ACCOUNT_LOCKED' : 'INVALID_CREDENTIALS', $error->errorCode);
        same($attempt, $db->one('SELECT failed_attempts FROM login_attempts WHERE user_id=?', [$user])['failed_attempts']);
    }
});

runTests();
