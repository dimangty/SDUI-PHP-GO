<?php
declare(strict_types=1);

/** A real pending TCP handshake, not an accepted connection that withholds data.
 * Linux's one-entry listen backlog admits two connections; a third remains
 * pending. No accept(), PostgreSQL packet, response replacement or external IP
 * is involved. Native libpq must not poll past a write-readiness timeout.
 */
final class ReadinessPendingConnect
{
    public readonly string $address;
    private $listener;
    private array $clients=[];

    public function __construct()
    {
        $context=stream_context_create(['socket'=>['backlog'=>1]]);
        $this->listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$message,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
        if($this->listener===false){throw new RuntimeException('Pending-connect fixture bind failed');}
        $this->address=stream_socket_get_name($this->listener,false);
        for($i=0;$i<3;$i++){
            $client=stream_socket_client('tcp://'.$this->address,$errno,$message,0,STREAM_CLIENT_CONNECT|STREAM_CLIENT_ASYNC_CONNECT);
            if($client===false){throw new RuntimeException('Pending-connect fixture socket failed');}
            $this->clients[]=$client;$read=null;$write=[$client];$except=null;
            // Prove the fixture: two completed handshakes fill the queue, then
            // an additional SYN does not produce a writable socket.
            same($i<2?1:0,stream_select($read,$write,$except,0,50000));
        }
    }

    public function close(): void
    {
        foreach($this->clients as $client){fclose($client);}
        fclose($this->listener);
    }
}
