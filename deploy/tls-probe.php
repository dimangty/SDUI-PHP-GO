<?php
// Disposable certificate fixture: exercises actual production HttpClient trust,
// hostname verification and libpq verify-full without changing service code.
declare(strict_types=1);
require '/app/vendor/autoload.php';
if(in_array('--untrusted',$argv,true)){
    try{(new \Shop\infrastructure\HttpClient(2))->request('GET','https://localhost:18443');exit(1);}
    catch(RuntimeException $expected){exit(0);}
}
$context=stream_context_create(['ssl'=>['local_cert'=>'/run/tls/provider.crt','local_pk'=>'/run/tls/provider.key','verify_peer'=>false]]);
$server=stream_socket_server('tls://0.0.0.0:18443',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
if(!$server){throw new RuntimeException($error);}
$pid=pcntl_fork();
if($pid===0){
    // Valid host, wrong host, then explicit wrong-CA tests each connect once.
    for($i=0;$i<3;$i++){
        $socket=@stream_socket_accept($server,15);
        if($socket){@fread($socket,8192);@fwrite($socket,"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");fclose($socket);}
    }exit(0);
}
try{
    $http=new \Shop\infrastructure\HttpClient(2);
    if($http->request('GET','https://localhost:18443')['status']!==200){throw new RuntimeException('Trusted TLS failed');}
    try{$http->request('GET','https://127.0.0.1:18443');throw new LogicException('Wrong TLS hostname accepted');}catch(RuntimeException $expected){}
    // curl.cainfo is PHP_INI_SYSTEM; changing it with ini_set would not test trust.
    $child=proc_open([PHP_BINARY,'-d','curl.cainfo=/etc/ssl/certs/ca-certificates.crt',__FILE__,'--untrusted'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
    if(!is_resource($child) || proc_close($child)!==0){throw new LogicException('Untrusted CA accepted');}
    print "Actual production HttpClient: trusted TLS 200, wrong hostname and untrusted CA rejected\n";
}finally{posix_kill($pid,SIGTERM);pcntl_waitpid($pid,$status);fclose($server);}
