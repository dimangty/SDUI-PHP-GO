<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\infrastructure\{ApiKernel,Database,Env,ReadinessAdmission,ReadinessPolicy,ReadinessSql,CoreReadinessProbe};
require __DIR__.'/SocketUpstream.php';
require __DIR__.'/ReadinessFixture.php';
require __DIR__.'/ReadinessPendingConnect.php';
// tests/all.php launches this suite in its own PHP process. A dedicated URL
// keeps committed readiness faults away from every other suite's database.
if(($dedicated=getenv('READINESS_DATABASE_URL'))!==false){putenv('DATABASE_URL='.$dedicated);}
// Ensure the dedicated database is migrated before preserving the original RED.
new ReadinessFixture();
test('genuinely pending TCP connect stays inside the core deadline',function(){
    $peer=new ReadinessPendingConnect();
    try{ReadinessFixture::watchdog(function()use($peer){
        ReadinessFixture::environment(['DATABASE_URL'=>'postgres://connect-test:unused@'.$peer->address.'/unused?sslmode=prefer'],function(){
            $kernel=new ApiKernel(new Database(Env::get('DATABASE_URL')));$start=hrtime(true);
            $result=$kernel->handle('GET','/health/ready',[],'','192.0.2.94');$seconds=(hrtime(true)-$start)/1e9;
            print 'pending TCP seconds='.round($seconds,3)."\n";
            same(503,$result['status']);truth($seconds<.7);
        });
    });}finally{$peer->close();}
});
test('cancellation interrupts a genuinely pending TCP connect',function(){
    $peer=new ReadinessPendingConnect();
    try{ReadinessFixture::watchdog(function()use($peer){
        $clock=static fn()=>hrtime(true)/1e9;$started=$clock();
        $result=(new ReadinessPolicy())->evaluate(new CoreReadinessProbe('postgres://connect-test:unused@'.$peer->address.'/unused?sslmode=prefer'),$clock,static fn()=>$clock()-$started>.05,false);
        print 'cancel pending TCP seconds='.round($clock()-$started,3)."\n";
        same(503,$result['status']);truth($clock()-$started<.2);
    });}finally{$peer->close();}
});
test('core readiness never contacts optional search even on an established blackhole',function(){
    $server=new SocketUpstream(static function($connection){usleep(2200000);});
    $prior=getenv('OPENSEARCH_URL');putenv('OPENSEARCH_URL='.$server->origin);
    try{
        $kernel=new ApiKernel(new Database(Env::get('DATABASE_URL')));
        $start=microtime(true);$result=$kernel->handle('GET','/health/ready',[],'','192.0.2.94');
        $elapsed=microtime(true)-$start;
        print 'core probe seconds='.round($elapsed,3)."\n";
        same(200,$result['status']);
        truth($elapsed<.7);
        same('unknown',$result['data']['components']['opensearch']);
    }finally{putenv($prior===false?'OPENSEARCH_URL':'OPENSEARCH_URL='.$prior);$attempts=$server->finish();}
    same(0,$attempts);
});
test('optional healthy, fastfail, slow, TLS, read and blackhole endpoints get zero TCP and HTTP I/O',function(){
    $fixture=new ReadinessFixture();
    foreach(['close','blackhole','tls','read'] as $mode){
        $peer=new ReadinessPeer($mode);
        try{
            ReadinessFixture::environment(['OPENSEARCH_URL'=>($mode==='tls'?'https://':'http://').$peer->address],function()use($fixture){
                for($i=0;$i<3;$i++){$result=$fixture->ready();same('unknown',$result['data']['components']['opensearch']);same(['catalog-cards-v1'],$result['data']['capabilities']);}
            });
        }finally{same(['attempts'=>0,'bytes'=>0],$peer->finish());}
    }
    foreach(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}",'slow'] as $response){
        $server=new SocketUpstream(static function($peer)use($response){if($response==='slow'){SocketUpstream::dripHeaders($peer,30,100000);}else{fwrite($peer,$response);}});
        try{ReadinessFixture::environment(['OPENSEARCH_URL'=>$server->origin],fn()=>$fixture->ready());}
        finally{same(0,$server->finish());}
    }
    ReadinessFixture::environment(['OPENSEARCH_URL'=>'http://127.0.0.1:1'],fn()=>$fixture->ready());
    ReadinessFixture::environment(['OPENSEARCH_URL'=>''],function()use($fixture){same(false,isset($fixture->ready()['data']['components']['opensearch']));});
});
test('committed missing required columns and legacy marker remain unavailable',function(){
    $fixture=new ReadinessFixture();
    foreach([['users','local_identity_ready'],['users','account_disabled'],['session_families','expires_at'],['payments','refund_requested_at']] as [$table,$column]){
        $fixture->db->execute("ALTER TABLE $table RENAME COLUMN $column TO readiness_hidden");
        try{$fixture->ready(503);}finally{$fixture->db->execute("ALTER TABLE $table RENAME COLUMN readiness_hidden TO $column");}
        $fixture->ready();
    }
    $prior=$fixture->db->one("SELECT value FROM repository_metadata WHERE key='legacy_import_status'");
    $fixture->db->execute("INSERT INTO repository_metadata(key,value) VALUES('legacy_import_status','required') ON CONFLICT(key) DO UPDATE SET value='required'");
    try{$fixture->ready(503);}finally{
        if($prior){$fixture->db->execute("UPDATE repository_metadata SET value=? WHERE key='legacy_import_status'",[$prior['value']]);}
        else{$fixture->db->execute("DELETE FROM repository_metadata WHERE key='legacy_import_status'");}
    }
});
test('required DB refusal, startup blackhole, TLS blackhole and established read are bounded',function(){
    $fixture=new ReadinessFixture();
    foreach(['close','blackhole','tls','read'] as $mode){
        $peer=new ReadinessPeer($mode);
        try{
            $start=hrtime(true);
            ReadinessFixture::environment(['DATABASE_URL'=>'postgres://readiness:private@'.$peer->address.'/unused?sslmode='.($mode==='tls'?'require':'disable')],fn()=>$fixture->ready(503));
            $seconds=(hrtime(true)-$start)/1e9;
            if($mode!=='close'){truth($seconds>.5);}
            print 'required '.$mode.' seconds='.round($seconds,3)."\n";
        }finally{$counts=$peer->finish();same(1,$counts['attempts']);truth($counts['bytes']>0 || $mode==='close');}
    }
    foreach(['postgres://unused:private@127.0.0.1:1/unused','invalid://private','postgres://unused:private@127.0.0.1/unused?sslmode[]=bad'] as $url){
        ReadinessFixture::environment(['DATABASE_URL'=>$url],fn()=>$fixture->ready(503));
    }
});
test('liveness has zero required and optional I/O even with invalid configuration',function(){
    $peer=new ReadinessPeer();
    try{ReadinessFixture::environment(['DATABASE_URL'=>'postgres://bad@'.$peer->address.'/unused','OPENSEARCH_URL'=>$peer->origin,'PAYMENT_WEBHOOK_ALLOWED_CIDRS'=>'invalid'],function(){
        $kernel=new ApiKernel(new Database(Env::get('DATABASE_URL')));
        same(['status'=>'ok','components'=>['process'=>'up']],$kernel->handle('GET','/health/live',[],'','192.0.2.94')['data']);
    });}finally{same(['attempts'=>0,'bytes'=>0],$peer->finish());}
});
test('required DNS blackhole shares the core deadline rather than blocking libpq resolution',function(){
    $fixture=new ReadinessFixture();
    ReadinessFixture::dnsBlackhole(function()use($fixture){
        $start=hrtime(true);
        ReadinessFixture::environment(['DATABASE_URL'=>'postgres://unused:private@readiness-blackhole.example/unused'],fn()=>$fixture->ready(503));
        print 'required DNS seconds='.round((hrtime(true)-$start)/1e9,3)."\n";
    });
});
test('production requires actual runtime identity, schema grants and runtime configuration',function(){
    $fixture=new ReadinessFixture();$url=$fixture->runtimeUrl();
    $configuration=['APP_ENV'=>'production','DATABASE_URL'=>$url,'PAYMENT_GATEWAY_URL'=>'https://provider.invalid','PAYMENT_GATEWAY_API_KEY'=>'readiness-test-key','PAYMENT_WEBHOOK_SECRET'=>'readiness-test-secret','PAYMENT_WEBHOOK_ALLOWED_CIDRS'=>'192.0.2.0/24'];
    ReadinessFixture::environment($configuration,function()use($fixture){
        $fixture->ready();
        same(false,$fixture->db->one("SELECT rolcreatedb FROM pg_roles WHERE rolname='shopkmp_runtime'")['rolcreatedb']);
        $fixture->db->execute('ALTER ROLE shopkmp_runtime CREATEDB');
        try{$fixture->ready(503);}finally{$fixture->db->execute('ALTER ROLE shopkmp_runtime NOCREATEDB');}
        foreach(['PAYMENT_GATEWAY_URL','PAYMENT_GATEWAY_API_KEY','PAYMENT_WEBHOOK_SECRET','PAYMENT_WEBHOOK_ALLOWED_CIDRS'] as $key){
            ReadinessFixture::environment([$key=>''],fn()=>$fixture->ready(503));
        }
        ReadinessFixture::environment(['PAYMENT_WEBHOOK_ALLOWED_CIDRS'=>'invalid'],fn()=>$fixture->ready(503));
        $fixture->db->execute('REVOKE SELECT ON session_families FROM shopkmp_runtime');
        try{$fixture->ready(503);}finally{$fixture->db->execute('GRANT SELECT ON session_families TO shopkmp_runtime');}
        $fixture->ready();
    });
    // Owner/superuser credentials cannot masquerade as a healthy production runtime.
    unset($configuration['DATABASE_URL']);
    ReadinessFixture::environment($configuration,fn()=>$fixture->ready(503));
});
test('native failures are finite and restore the outer strict diagnostic handler',function(){
    $seen=[];set_error_handler(static function(int $severity,string $message)use(&$seen){$seen[]=$message;return true;});
    try{
        $clock=static fn()=>hrtime(true)/1e9;
        try{(new ReadinessSql('postgres://hidden:private@127.0.0.1:1/unused'))->run('SELECT 1',$clock()+.6,$clock,static fn()=>false);throw new RuntimeException('Expected refusal');}
        catch(RuntimeException $error){truth(str_starts_with($error->getMessage(),'Readiness SQL '));truth(!str_contains($error->getMessage(),'private'));}
        trigger_error('readiness-handler-restored',E_USER_WARNING);same(['readiness-handler-restored'],$seen);
    }finally{restore_error_handler();}
});
test('SQL write backpressure cannot escape the shared deadline',function(){
    $peer=new ReadinessPeer('write');$clock=static fn()=>hrtime(true)/1e9;
    $query='SELECT 1 /*'.str_repeat('x',8*1024*1024).'*/';
    try{ReadinessFixture::watchdog(function()use($peer,$clock,$query){
        $start=$clock();
        try{(new ReadinessSql('postgres://unused@'.$peer->address.'/unused?sslmode=disable'))->run($query,$start+.6,$clock,static fn()=>false);throw new LogicException('Expected deadline');}
        catch(RuntimeException $error){same('Readiness SQL deadline or cancellation',$error->getMessage());}
        truth($clock()-$start<.7);
    });}finally{same(1,$peer->finish()['attempts']);}
});
test('required statement and table locks time out and leave no readiness sessions',function(){
    $fixture=new ReadinessFixture();$clock=static fn()=>hrtime(true)/1e9;$cancel=static fn()=>false;
    $sql=new ReadinessSql(Env::get('DATABASE_URL'));
    $start=$clock();try{$sql->run('SELECT pg_sleep(5)',$start+.6,$clock,$cancel);throw new RuntimeException('Expected statement timeout');}
    catch(RuntimeException $e){same('Readiness SQL statement failed',$e->getMessage());}
    truth($clock()-$start<.7);print 'statement seconds='.round($clock()-$start,3)."\n";$fixture->noSessions();
    $fixture->db->pdo()->beginTransaction();
    try{$fixture->db->execute('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');$fixture->ready(503);$fixture->noSessions();}
    finally{$fixture->db->pdo()->rollBack();}
    $fixture->ready();
});
test('cancellation during SQL and connect closes owned session without blocking cancel exchange',function(){
    $fixture=new ReadinessFixture();$clock=static fn()=>hrtime(true)/1e9;$start=$clock();
    $cancel=static fn()=>$clock()-$start>.05;
    try{(new ReadinessSql(Env::get('DATABASE_URL')))->run('SELECT pg_sleep(5)',$start+.6,$clock,$cancel);throw new RuntimeException('Expected cancellation');}
    catch(RuntimeException $e){same('Readiness SQL deadline or cancellation',$e->getMessage());}
    truth($clock()-$start<.2);print 'cancel SQL seconds='.round($clock()-$start,3)."\n";$fixture->noSessions();
    $peer=new ReadinessPeer();$start=$clock();
    try{
        $result=(new ReadinessPolicy())->evaluate(new CoreReadinessProbe('postgres://test@'.$peer->address.'/unused?sslmode=disable'),$clock,static fn()=>$clock()-$start>.05,false);
        same(503,$result['status']);truth($clock()-$start<.2);
    }finally{same(1,$peer->finish()['attempts']);}
});
test('saturated kernel readiness returns 503 with zero required or optional transport and live stays healthy',function(){
    $admission=new ReadinessAdmission();$leases=[];$peer=new ReadinessPeer();
    try{
        for($i=0;$i<2;$i++){$leases[]=$admission->acquire();truth(is_resource($leases[$i]));}
        ReadinessFixture::environment(['DATABASE_URL'=>'postgres://unused@'.$peer->address.'/unused?sslmode=disable','OPENSEARCH_URL'=>$peer->origin],function(){
            $kernel=new ApiKernel(new Database(Env::get('DATABASE_URL')));$started=hrtime(true);
            same(503,$kernel->handle('GET','/health/ready',[],'','192.0.2.94')['status']);
            truth((hrtime(true)-$started)/1e9<.1);
            same(200,$kernel->handle('GET','/health/live',[],'','192.0.2.94')['status']);
        });
    }finally{foreach($leases as $lease){if(is_resource($lease)){fclose($lease);}}same(['attempts'=>0,'bytes'=>0],$peer->finish());}
    (new ReadinessFixture())->ready();
});
test('repeated and concurrent probes do not retain or exhaust connections',function(){
    $fixture=new ReadinessFixture();$times=[];
    for($i=0;$i<20;$i++){$start=hrtime(true);$fixture->ready();$times[]=(hrtime(true)-$start)/1e6;}
    $fixture->noSessions();$peer=new ReadinessPeer();
    try{ReadinessFixture::environment(['OPENSEARCH_URL'=>$peer->origin],function(){
        $pids=[];
        // Two admitted probes remain healthy. Higher concurrency is deliberate
        // overload (503), not a promise that every arbitrary fan-out gets 200.
        for($i=0;$i<2;$i++){
            $pid=pcntl_fork();if($pid===0){try{for($j=0;$j<5;$j++){(new ReadinessFixture())->ready();}exit(0);}catch(Throwable){exit(1);}}
            truth($pid>0);$pids[]=$pid;
        }
        foreach($pids as $pid){pcntl_waitpid($pid,$status);same(0,pcntl_wexitstatus($status));}
    });}finally{same(['attempts'=>0,'bytes'=>0],$peer->finish());}
    // Children inherited the observer PDO; reconnect after their normal exits.
    $fixture=new ReadinessFixture();$fixture->noSessions();sort($times);
    print 'healthy samples=20 p50ms='.round($times[9],2).' p95ms='.round($times[18],2).' maxms='.round(max($times),2)." admitted concurrent=2x5\n";
});
test('repeated concurrent cancellations cannot exhaust PostgreSQL sessions',function(){
    $pids=[];
    for($i=0;$i<4;$i++){
        $pid=pcntl_fork();truth($pid>=0);
        if($pid===0){
            try{
                $clock=static fn()=>hrtime(true)/1e9;
                for($j=0;$j<3;$j++){
                    $started=$clock();$cancel=static fn()=>$clock()-$started>.04;
                    try{(new ReadinessSql(Env::get('DATABASE_URL')))->run('SELECT pg_sleep(5)',$started+.6,$clock,$cancel);throw new LogicException('Expected cancellation');}
                    catch(RuntimeException $error){same('Readiness SQL deadline or cancellation',$error->getMessage());}
                    truth($clock()-$started<.2);
                }
                exit(0);
            }catch(Throwable){exit(1);}
        }
        $pids[]=$pid;
    }
    foreach($pids as $pid){pcntl_waitpid($pid,$status);same(0,pcntl_wexitstatus($status));}
    $fixture=new ReadinessFixture();$fixture->noSessions();$fixture->ready();
});
runTests();
