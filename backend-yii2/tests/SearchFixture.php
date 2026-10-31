<?php
declare(strict_types=1);

/** Complete OpenSearch 2.x protocol envelopes, shared only by search tests. */
final class SearchFixture
{
    public static function server(Closure $response): SearchProtocolServer
    {
        return new SearchProtocolServer($response);
    }
    public static function page(array $hits=[],int $total=0): array
    {
        return ['took'=>1,'timed_out'=>false,'_shards'=>['total'=>1,'successful'=>1,'skipped'=>0,'failed'=>0],
            'hits'=>['total'=>['value'=>$total,'relation'=>'eq'],'max_score'=>null,'hits'=>$hits]];
    }
    public static function metadata(string $index='shopkmp-products',string $state='ready'): array
    {
        return [$index=>['aliases'=>new stdClass(),'settings'=>['index'=>['uuid'=>'fixture-physical-uuid']],
            'mappings'=>['_meta'=>['shopkmp_products'=>['version'=>1,'state'=>$state,'build'=>str_repeat('a',32),
                'uuid'=>'fixture-physical-uuid','documents'=>0,'identitySHA256'=>hash('sha256','')]]]]];
    }
    public static function respond($connection,array $value,int $status=200): void
    {
        $raw=json_encode($value,JSON_THROW_ON_ERROR);
        fwrite($connection,"HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nContent-Length: ".strlen($raw)."\r\nConnection: close\r\n\r\n".$raw);
    }
}

/** Explicit parent shutdown avoids SocketUpstream's 400ms idle-race under load. */
final class SearchProtocolServer
{
    public readonly string $origin;
    private int $pid;
    private $control;
    public function __construct(Closure $response)
    {
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
        if(!$listener){throw new RuntimeException($message);}
        $this->origin='http://'.stream_socket_get_name($listener,false);
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
        if($pid===0){
            fclose($parent);$requests=0;
            try{
                while(true){
                    $read=[$listener,$child];$write=null;$except=null;
                    if(@stream_select($read,$write,$except,3)===false){continue;}
                    if(in_array($child,$read,true)){break;}
                    if(!in_array($listener,$read,true)){continue;}
                    $connection=stream_socket_accept($listener,0);if(!$connection){continue;}
                    $requests++;stream_set_timeout($connection,3);$headers=[];$first=fgets($connection);
                    while(($line=fgets($connection))!==false && trim($line)!==''){
                        if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$headers[strtolower($name)]=trim($value);}
                    }
                    $body='';$remaining=(int)($headers['content-length']??0);
                    while($remaining>0){$chunk=fread($connection,$remaining);if($chunk===false || $chunk===''){break;}$body.=$chunk;$remaining-=strlen($chunk);}
                    $response($connection,$headers,$first,$body);fclose($connection);
                }
                fwrite($child,(string)$requests);fclose($child);fclose($listener);exit(0);
            }catch(Throwable $error){fwrite(STDERR,'Search fixture assertion: '.$error->getMessage()."\n");exit(1);}
        }
        if($pid<0){throw new RuntimeException('Cannot fork search protocol server');}
        fclose($listener);fclose($child);$this->pid=$pid;$this->control=$parent;stream_set_timeout($parent,20);
    }
    public function finish(): int
    {
        fwrite($this->control,'stop');$count=(int)stream_get_contents($this->control);fclose($this->control);pcntl_waitpid($this->pid,$status);
        truth(pcntl_wifexited($status));same(0,pcntl_wexitstatus($status));return $count;
    }
}
