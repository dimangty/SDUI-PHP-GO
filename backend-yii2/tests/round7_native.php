<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Shop\infrastructure\{Database,Env};

// The full native lane already requires this explicit owned-project opt-in.
// Readiness fault injection uses a separate DB; no main-fixture schema is changed.
$project=Env::get('ROUND5_SEARCH_DISPOSABLE');
if(Env::production() || !preg_match('/^shopkmp-[a-z0-9-]+-e2e$/D',$project)){
    throw new RuntimeException('Round-7 native suites require an owned disposable development project');
}
$db=new Database(Env::get('DATABASE_URL'));
if(!$db->one("SELECT 1 FROM pg_database WHERE datname='readiness_test'")){
    $db->execute('CREATE DATABASE readiness_test');
}
$url=preg_replace('~(/)[^/?]+(\?|$)~','$1readiness_test$2',Env::get('DATABASE_URL'));
if(!is_string($url) || parse_url($url,PHP_URL_PATH)!=='/readiness_test'){
    throw new RuntimeException('Cannot derive dedicated readiness database');
}
$env=getenv();
$env['READINESS_TEST_DATABASE']='readiness_test';
$env['READINESS_DATABASE_URL']=$url;
$env['DEMO_PAYMENT_TEST_DISPOSABLE']=$project;
foreach(['readiness_policy.php','readiness.php','demo_payment_identity.php'] as $suite){
    $process=proc_open([PHP_BINARY,__DIR__.'/'.$suite],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__),$env);
    if(!is_resource($process)){throw new RuntimeException('Cannot start round-7 native suite');}
    $exit=proc_close($process);
    if($exit!==0){exit($exit);}
}
