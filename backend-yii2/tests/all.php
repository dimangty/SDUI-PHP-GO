<?php
declare(strict_types=1);
$files=['lint.php','run.php','durations.php','cors.php','integration.php','auth.php','signed_oidc.php','security.php','throttling.php','concurrency.php','address_race.php','api.php','search.php','adapters.php','readiness.php','deadlines.php'];
foreach($files as $file){
    print "\n=== $file ===\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file),$status);
    if($status!==0){exit($status);}
}
