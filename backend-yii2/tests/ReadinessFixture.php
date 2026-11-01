<?php
declare(strict_types=1);

use Shop\infrastructure\{ApiKernel,Database,Env};

/** All committed faults are restricted to a dedicated, explicitly opted-in DB. */
final class ReadinessFixture
{
    public readonly Database $db;
    public readonly ApiKernel $kernel;
    public function __construct()
    {
        if (Env::get('READINESS_TEST_DATABASE')!=='readiness_test'
            || (parse_url(Env::get('DATABASE_URL'),PHP_URL_PATH))!=='/readiness_test') {
            throw new RuntimeException('Readiness faults require the dedicated readiness_test database');
        }
        $this->db=new Database(Env::get('DATABASE_URL'));
        if (!$this->db->one("SELECT to_regclass('public.users') AS name")['name']) {
            $files=glob('/migrations/*.up.sql');sort($files);
            foreach($files as $file){$this->db->pdo()->exec(file_get_contents($file));}
        }
        $this->kernel=new ApiKernel($this->db);
    }
    public static function environment(array $values,Closure $work): mixed
    {
        $prior=[];foreach($values as $key=>$value){$prior[$key]=getenv($key);putenv($key.'='.$value);}
        try{return $work();}
        finally{foreach($prior as $key=>$value){putenv($value===false?$key:$key.'='.$value);}}
    }
    /** Independent native sessions cannot observe a caller's private schema or
     * uncommitted DDL. Reuse the guarded, dedicated DB for committed faults.
     */
    public static function inOwnedDatabase(string $ownedSchema,Closure $work): mixed
    {
        if(Env::production() || !preg_match('/^payment_legacy_[a-f0-9]{32}$/D',$ownedSchema)){
            throw new RuntimeException('Committed readiness faults require an owned recovery fixture');
        }
        // The canonical recovery command does not export the search lane's
        // ROUND5 opt-in. Verify its real, uniquely named, committed fixture
        // namespace and ownership instead of adding a new invocation requirement.
        $admin=new Database(Env::get('DATABASE_URL'));
        $owner=$admin->one('SELECT current_schema() AS active,nspowner=(SELECT oid FROM pg_roles WHERE rolname=current_user) AS owned FROM pg_namespace WHERE nspname=?',[$ownedSchema]);
        if(($owner['active']??null)!==$ownedSchema || ($owner['owned']??false)!==true){
            throw new RuntimeException('Committed readiness faults require an owned recovery fixture');
        }
        $url=preg_replace('~(/)[^/?]+(\?|$)~','$1readiness_test$2',Env::get('DATABASE_URL'));
        if(!is_string($url) || parse_url($url,PHP_URL_PATH)!=='/readiness_test'){
            throw new RuntimeException('Cannot derive dedicated readiness database');
        }
        if(!$admin->one("SELECT 1 FROM pg_database WHERE datname='readiness_test'")){$admin->execute('CREATE DATABASE readiness_test');}
        return self::environment(['DATABASE_URL'=>$url,'PGOPTIONS'=>'-c search_path=public',
            'READINESS_TEST_DATABASE'=>'readiness_test','OPENSEARCH_URL'=>''],static fn()=>$work(new self()));
    }
    public static function watchdog(Closure $work): void
    {
        $pid=pcntl_fork();truth($pid>=0);
        if($pid===0){try{$work();exit(0);}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}}
        $until=microtime(true)+3;
        do{
            if(pcntl_waitpid($pid,$status,WNOHANG)===$pid){same(0,pcntl_wexitstatus($status));return;}
            usleep(10000);
        }while(microtime(true)<$until);
        posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);
        throw new RuntimeException('Readiness test watchdog: blocking native operation');
    }
    public function ready(int $expected=200): array
    {
        $started=hrtime(true);$result=$this->kernel->handle('GET','/health/ready',[],'','192.0.2.94');
        $elapsed=(hrtime(true)-$started)/1e9;
        same($expected,$result['status']);truth($elapsed<.7);return $result;
    }
    public function noSessions(): void
    {
        $until=microtime(true)+.65;
        do{
            $count=(int)$this->db->one("SELECT count(*) AS n FROM pg_stat_activity WHERE datname=current_database() AND application_name='shopkmp-core-readiness'")['n'];
            if($count===0){return;}usleep(10000);
        }while(microtime(true)<$until);
        same(0,$count);
    }
    public function runtimeUrl(): string
    {
        if(!$this->db->one("SELECT 1 FROM pg_roles WHERE rolname='shopkmp_runtime'")){
            $this->db->execute("CREATE ROLE shopkmp_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD 'readiness-runtime-test-only'");
        }
        $this->db->execute('GRANT USAGE ON SCHEMA public TO shopkmp_runtime');
        $this->db->execute('GRANT SELECT ON ALL TABLES IN SCHEMA public TO shopkmp_runtime');
        $parts=parse_url(Env::get('DATABASE_URL'));
        return 'postgres://shopkmp_runtime:readiness-runtime-test-only@'.$parts['host'].':'.($parts['port']??5432).'/readiness_test';
    }
    public static function dnsBlackhole(Closure $work): void
    {
        // This runs only in the short-lived dedicated CLI fixture container.
        // Never change the coordinator PHP container or host resolver.
        if(Env::get('READINESS_TEST_DATABASE')!=='readiness_test'){throw new RuntimeException('Dedicated readiness fixture required');}
        $socket=stream_socket_server('udp://127.0.0.1:53',$errno,$message,STREAM_SERVER_BIND);
        if(!$socket){throw new RuntimeException('Readiness DNS fixture bind failed');}
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);$pid=pcntl_fork();
        if($pid===0){
            fclose($parent);$queries=0;
            while(true){$read=[$socket,$child];$write=null;$except=null;stream_select($read,$write,$except,2);
                if(in_array($child,$read,true)){break;}
                if(in_array($socket,$read,true)){stream_socket_recvfrom($socket,4096);$queries++;}
            }
            fwrite($child,(string)$queries);fclose($child);fclose($socket);exit(0);
        }
        truth($pid>0);fclose($child);fclose($socket);$prior=file_get_contents('/etc/resolv.conf');
        try{file_put_contents('/etc/resolv.conf',"nameserver 127.0.0.1\noptions timeout:2 attempts:1\n");$work();}
        finally{
            file_put_contents('/etc/resolv.conf',$prior);fwrite($parent,'stop');$queries=(int)stream_get_contents($parent);
            fclose($parent);pcntl_waitpid($pid,$status);same(0,pcntl_wexitstatus($status));truth($queries>0);
        }
    }
}

/** Raw TCP fault peer counts accepts, including pre-HTTP and TLS handshakes.
 * Forking is fixture-only; production readiness never starts a process.
 */
final class ReadinessPeer
{
    public readonly string $address;
    public readonly string $origin;
    private int $pid;
    private $control;
    public function __construct(private string $mode='blackhole')
    {
        $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);
        if(!$listener){throw new RuntimeException('Readiness fixture bind failed');}
        $this->address=stream_socket_get_name($listener,false);$this->origin='http://'.$this->address;
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
        $pid=pcntl_fork();
        if($pid===-1){throw new RuntimeException('Readiness fixture fork failed');}
        if($pid===0){
            fclose($parent);$peers=[];$authenticated=[];$attempts=0;$bytes=0;
            while(true){
                $read=[$listener,$child];foreach($peers as $id=>$peer){if($mode!=='write' || !isset($authenticated[$id])){$read[]=$peer;}}$write=null;$except=null;
                if(stream_select($read,$write,$except,2)===false){exit(2);}
                if(in_array($child,$read,true)){break;}
                if(in_array($listener,$read,true)){
                    $peer=stream_socket_accept($listener,0);$attempts++;
                    if($mode==='close'){fclose($peer);}
                    else{stream_set_blocking($peer,false);$peers[(int)$peer]=$peer;}
                }
                foreach($peers as $id=>$peer){
                    if(!in_array($peer,$read,true)){continue;}
                    $data=fread($peer,65536);$bytes+=strlen($data);
                    if($mode==='tls' && strlen($data)===8){fwrite($peer,'S');}
                    if(in_array($mode,['read','write'],true) && $data!=='' && !isset($authenticated[$id])){
                        // PostgreSQL trust AuthenticationOk + ReadyForQuery.
                        fwrite($peer,"R".pack('NN',8,0)."Z".pack('N',5).'I');
                        $authenticated[$id]=true;
                    }
                    if(feof($peer)){fclose($peer);unset($peers[$id]);}
                }
            }
            foreach($peers as $peer){fclose($peer);}
            fwrite($child,json_encode(['attempts'=>$attempts,'bytes'=>$bytes],JSON_THROW_ON_ERROR));
            fclose($child);fclose($listener);exit(0);
        }
        fclose($child);fclose($listener);$this->control=$parent;$this->pid=$pid;
    }
    public function finish(): array
    {
        fwrite($this->control,'stop');$counts=json_decode(stream_get_contents($this->control),true,8,JSON_THROW_ON_ERROR);
        fclose($this->control);pcntl_waitpid($this->pid,$status);same(0,pcntl_wexitstatus($status));return $counts;
    }
}
