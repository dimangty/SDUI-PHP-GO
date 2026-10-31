<?php
declare(strict_types=1);
require_once __DIR__.'/SearchFixture.php';
if(PHP_SAPI==='cli-server'){
    header('Content-Type: application/json');$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $origin='http://'.$_SERVER['HTTP_HOST'];
    $hits=json_decode(getenv('ROUND3_SEARCH_HITS')?:'[]',true);
    $reply=SearchFixture::page($hits,count($hits));
    if(preg_match('~^/(shopkmp-products(?:-[a-z0-9-]+)?)$~D',$path,$m)){$reply=SearchFixture::metadata($m[1]);}
    print json_encode(str_contains($path,'.well-known')?['issuer'=>$origin.'/realms/round3','jwks_uri'=>$origin.'/keys']:($path==='/keys'?['keys'=>[]]:$reply));return;
}
// Separate process deliberately avoids sharing a live PDO socket with fork children.
final class Round3HttpFixture {
    public readonly string $origin;
    private $process;
    public function __construct(array $hits=[]){
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);if(!$socket){throw new RuntimeException($message);}
        $address=stream_socket_get_name($socket,false);fclose($socket);$this->origin='http://'.$address;
        $this->process=proc_open([PHP_BINARY,'-S',$address,__FILE__],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,array_merge(getenv(),['ROUND3_SEARCH_HITS'=>json_encode($hits)]));
        for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$message,.05);if($probe){fclose($probe);return;}usleep(10000);}throw new RuntimeException('fixture failed to listen');
    }
    public function close(): void {proc_terminate($this->process);proc_close($this->process);}
}
