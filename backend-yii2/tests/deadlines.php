<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SocketUpstream.php';
use Shop\domain\{ApiError,Ids};
use Shop\infrastructure\{ApiKernel,Database,Env,HttpClient,PaymentGateway};

function deadlineEvidence(string $name,float $elapsed,float $bound): void {
    print sprintf("DEADLINE %s: %.3fs (asserted < %.2fs)\n",$name,$elapsed,$bound);
    if($elapsed>=$bound){throw new RuntimeException(sprintf('%s exceeded absolute deadline bound: %.3fs',$name,$elapsed));}
}

test('one-second whole-request deadline interrupts continuously arriving response headers',function(){
    $server=new SocketUpstream(fn($c)=>SocketUpstream::dripHeaders($c,8,300000));$start=hrtime(true);
    try{(new HttpClient(1))->request('GET',$server->origin.'/headers');throw new LogicException('Expected timeout');}catch(RuntimeException $e){truth(str_contains(strtolower($e->getMessage()),'timeout'));}
    $elapsed=(hrtime(true)-$start)/1e9;$attempts=$server->finish();same(1,$attempts);deadlineEvidence('headers',$elapsed,1.65);
});
test('headers and body share one budget rather than separate blocking-read timeouts',function(){
    $server=new SocketUpstream(function($c){@fwrite($c,"HTTP/1.1 200 OK\r\nX-Test: yes\r\n");usleep(700000);@fwrite($c,"Content-Length: 2\r\nConnection: close\r\n\r\n");usleep(800000);@fwrite($c,'{}');});$start=hrtime(true);
    try{(new HttpClient(1))->request('GET',$server->origin.'/body');throw new LogicException('Expected timeout');}catch(RuntimeException $e){truth(str_contains(strtolower($e->getMessage()),'timeout'));}
    $elapsed=(hrtime(true)-$start)/1e9;same(1,$server->finish());deadlineEvidence('headers + body',$elapsed,1.35);
});
test('500ms readiness budget interrupts slow headers with HTTP 503',function(){
    $server=new SocketUpstream(fn($c)=>SocketUpstream::dripHeaders($c,8,150000));$old=[];
    foreach(['READINESS_TIMEOUT'=>'500ms','KEYCLOAK_ISSUER'=>$server->origin.'/realms/slow','KEYCLOAK_CLIENT_SECRET'=>'deadline-fixture'] as $key=>$value){$old[$key]=getenv($key);putenv($key.'='.$value);}
    try{
        $kernel=new ApiKernel(new Database(Env::get('DATABASE_URL')));$start=hrtime(true);$r=$kernel->handle('GET','/health/ready',[],'','192.0.2.50');$elapsed=(hrtime(true)-$start)/1e9;
        same(503,$r['status']);deadlineEvidence('500ms readiness',$elapsed,0.95);
    }finally{foreach($old as $key=>$value){putenv($value===false?$key:$key.'='.$value);}$server->finish();}
});
test('payment slow-header timeout is one provider mutation and retains inherited HTTP 400 mapping',function(){
    $server=new SocketUpstream(fn($c)=>SocketUpstream::dripHeaders($c,20,600000));$old=[];
    foreach(['PAYMENT_GATEWAY_URL'=>$server->origin,'PAYMENT_GATEWAY_API_KEY'=>'deadline-fixture-key'] as $key=>$value){$old[$key]=getenv($key);putenv($key.'='.$value);}
    try{
        $start=hrtime(true);
        try{(new PaymentGateway())->call('authorize',['id'=>Ids::uuid(),'order_id'=>Ids::uuid(),'method'=>'CARD','amount'=>'1.25']);throw new LogicException('Expected payment timeout');}
        catch(ApiError $e){same('PAYMENT_GATEWAY_UNAVAILABLE',$e->errorCode);same(400,$e->status());}
        $elapsed=(hrtime(true)-$start)/1e9;deadlineEvidence('payment single attempt',$elapsed,10.9);
    }finally{foreach($old as $key=>$value){putenv($value===false?$key:$key.'='.$value);}same(1,$server->finish());}
});
runTests();
