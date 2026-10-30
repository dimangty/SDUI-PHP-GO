<?php
declare(strict_types=1);

/** Test-only raw HTTP server; permits deliberately incomplete/slow response headers. */
final class SocketUpstream {
    public readonly string $origin;
    private int $pid;
    private $control;
    public function __construct(Closure $response) {
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
        if(!$listener){throw new RuntimeException($message);}
        $this->origin='http://'.stream_socket_get_name($listener,false);
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
        if($pid===0){
            fclose($parent);$attempts=0;
            while($connection=@stream_socket_accept($listener,$attempts===0?3:0.4)){
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
        $count=(int)stream_get_contents($this->control);fclose($this->control);pcntl_waitpid($this->pid,$status);
        same(0,pcntl_wexitstatus($status));return $count;
    }
    public static function dripHeaders($connection,int $count,int $microseconds): void {
        @fwrite($connection,"HTTP/1.1 200 OK\r\n");
        for($i=0;$i<$count;$i++){if(@fwrite($connection,'X-Drip-'.$i.": alive\r\n")===false){return;}usleep($microseconds);}
        @fwrite($connection,"Content-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    }
}
