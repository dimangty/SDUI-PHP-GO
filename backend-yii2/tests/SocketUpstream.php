<?php
declare(strict_types=1);

/** Test-only raw HTTP server; permits deliberately incomplete/slow response headers. */
final class SocketUpstream {
    public readonly string $origin;
    private int $pid;
    private $control;
    public function __construct(Closure $response, ?array $tls = null) {
        $context=stream_context_create($tls===null?[]:['ssl'=>$tls]);
        $listener=stream_socket_server(($tls===null?'tcp':'tls').'://127.0.0.1:0',$errno,$message,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
        if(!$listener){throw new RuntimeException($message);}
        $address=stream_socket_get_name($listener,false);
        $this->origin=$tls===null?'http://'.$address:'https://localhost:'.substr(strrchr($address,':'),1);
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
        if($pid===0){
            fclose($parent);$attempts=0;
            // Parent ownership, not inter-request idle time, defines lifetime.
            // Keep the existing per-connection I/O/handshake budget unchanged.
            while(true){
                $read=[$listener,$child];$write=null;$except=null;
                if(@stream_select($read,$write,$except,3)===false){continue;}
                if(in_array($child,$read,true)){break;}
                if(!in_array($listener,$read,true)){continue;}
                $connection=@stream_socket_accept($listener,3);if(!$connection){continue;}
                $attempts++;stream_set_timeout($connection,3);$headers=[];$first=fgets($connection);
                while(($line=fgets($connection))!==false && trim($line)!==''){
                    if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$headers[strtolower($name)]=trim($value);}
                }
                $body='';$remaining=(int)($headers['content-length']??0);while($remaining>0){$chunk=fread($connection,$remaining);if($chunk===false || $chunk===''){break;}$body.=$chunk;$remaining-=strlen($chunk);}
                $response($connection,$headers,$first,$body);fclose($connection);
            }
            fwrite($child,(string)$attempts);fclose($child);fclose($listener);exit(0);
        }
        fclose($listener);fclose($child);$this->pid=$pid;$this->control=$parent;stream_set_timeout($parent,20);
    }
    public function finish(): int {
        // A crashed peer can already have closed its end; its exit status below
        // remains authoritative rather than a secondary broken-pipe warning.
        @fwrite($this->control,'stop');
        $count=(int)stream_get_contents($this->control);fclose($this->control);pcntl_waitpid($this->pid,$status);
        same(0,pcntl_wexitstatus($status));return $count;
    }
    public static function dripHeaders($connection,int $count,int $microseconds): void {
        @fwrite($connection,"HTTP/1.1 200 OK\r\n");
        for($i=0;$i<$count;$i++){if(@fwrite($connection,'X-Drip-'.$i.": alive\r\n")===false){return;}usleep($microseconds);}
        @fwrite($connection,"Content-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    }
}
