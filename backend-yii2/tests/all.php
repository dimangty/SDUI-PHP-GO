<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
foreach (['login_lockout_policy.php', 'runtime_compatibility.php', 'local_auth_relock_native.php'] as $securitySuite) {
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$securitySuite), $status);
    if ($status !== 0) { exit($status); }
}
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/auth_metrics.php'),$status);
if($status!==0){exit($status);}
$files=['lint.php','run.php','round4_transactions.php','round4_search_query.php','round5_search_protocol.php','round4_catalog.php','round3_devices_unit.php','round3_reads.php','r9_order_summary.php','round3_devices.php','network_cidr.php','durations.php','cors.php','integration.php','auth.php','local_auth_core.php','local_auth_concurrency.php','local_recovery.php','opaque_authorization.php','security.php','throttling.php','trusted_clients.php','trusted_rate_limits.php','session_revocation.php','session_password_recovery.php','session_concurrency.php','logout_regression.php','session_upgrade.php','concurrency.php','address_race.php','api.php','search.php','adapters.php','readiness.php','deadlines.php'];
foreach($files as $file){
    print "\n=== $file ===\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file),$status);
    if($status!==0){exit($status);}
}
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/local_auth_migration.php'),$status);
if($status!==0){exit($status);}
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/session_retention.php'),$status);
if($status!==0){exit($status);}
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/search_reconcile.php'),$status);
if($status!==0){exit($status);}
// Native R9 lifecycle jobs require the same explicit owned-disposable opt-in
// as their standalone invocations; no accidental index mutations in unit jobs.
if(preg_match('/^shopkmp-[a-z0-9-]+-e2e$/D',getenv('ROUND5_SEARCH_DISPOSABLE')?:'')){
    foreach(['round5_search_native.php','round5_search_regressions.php','maintenance_search.php'] as $file){
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file),$status);
        if($status!==0){exit($status);}
    }
}
foreach(['round4_inventory_query.php','round4_index.php'] as $file){
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file),$status);
    if($status!==0){exit($status);}
}
