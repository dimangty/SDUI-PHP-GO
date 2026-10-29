<?php
declare(strict_types=1);
$files=['lint.php','run.php','round3_devices_unit.php','round3_reads.php','round3_devices.php','network_cidr.php','durations.php','cors.php','integration.php','auth.php','signed_oidc.php','security.php','throttling.php','trusted_clients.php','trusted_rate_limits.php','session_revocation.php','session_password_recovery.php','session_concurrency.php','logout_regression.php','session_upgrade.php','concurrency.php','address_race.php','api.php','search.php','adapters.php','readiness.php','deadlines.php'];
foreach($files as $file){
    print "\n=== $file ===\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file),$status);
    if($status!==0){exit($status);}
}
