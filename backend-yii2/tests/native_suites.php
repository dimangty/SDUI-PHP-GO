<?php
declare(strict_types=1);
// Disposable native test orchestration. Provider journals and business operations
// remain real HTTP/PostgreSQL; no runtime dependency is replaced by a DB model.
$root=dirname(__DIR__);$processes=[];
function fixtureServer(string $router,array $env): string {
    global $processes,$root;
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-S',$address,$router],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,$root,$env);
    if(!is_resource($process)){throw new RuntimeException('Cannot start native HTTP fixture');}$processes[]=$process;
    for($i=0;$i<100;$i++){$s=@stream_socket_client('tcp://'.$address,$errno,$error,.02);if($s){fclose($s);return 'http://'.$address;}usleep(10000);}
    throw new RuntimeException('Native HTTP fixture failed startup');
}
register_shutdown_function(function()use(&$processes){foreach($processes as $p){proc_terminate($p);proc_close($p);}});
$mode=$argv[1]??'core';$env=getenv();$env['APP_ENV']='development';$env['PAYMENT_WEBHOOK_SECRET']='payment-test-secret';
$env['OPENSEARCH_URL']=$mode==='payment'?'':($env['OPENSEARCH_URL']??'');
if($mode==='payment'){
    $env['PAYMENT_GATEWAY_API_KEY']='payment-fixture-key';
    // Select the callback listener first; the gateway needs its URL and vice versa.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
    $env['PAYMENT_TEST_HTTP_ORIGIN']='http://'.$address;
    $env['PAYMENT_GATEWAY_URL']=fixtureServer(__DIR__.'/payment-provider.php',$env);
    $process=proc_open([PHP_BINARY,'-S',$address,$root.'/web/index.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,$root,$env);$processes[]=$process;
    $suites=array_slice($argv,2)?:['payment-migration.php','payments.php','payment-http.php','uuid_identity.php','uuid_recovery.php','recovery.php','recovery-scheduling.php'];
}else{
    $env['PAYMENT_GATEWAY_URL']='';$env['UPSTREAM_FIXTURE_ORIGIN']=fixtureServer(__DIR__.'/upstream-router.php',$env);
    $suites=array_slice($argv,2)?:['all.php'];
}
$status=0;
foreach($suites as $suite){
    if(!preg_match('/^[a-z0-9_-]+\.php$/D',$suite)){throw new InvalidArgumentException('Invalid suite');}
    print "\n=== native $suite ===\n";$p=proc_open([PHP_BINARY,__DIR__.'/'.$suite],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$root,$env);$result=proc_close($p);if($result!==0){$status=1;}
}
exit($status);
