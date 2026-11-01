<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** A single owned, nonpersistent libpq session; no retries or shared pool. */
final class ReadinessSql
{
    public function __construct(private readonly string $url,private readonly ReadinessBudget $budget=new ReadinessBudget()) {}

    /**
     * @param callable(): float $clock
     * @param callable(): bool $cancel
     * @return list<array<array-key,string|null>>
     */
    public function run(string $sql,float $deadline,callable $clock,callable $cancel): array
    {
        $connection=null;$complete=false;
        // libpq warnings may include credentials/hostnames. Promote every warning
        // to a finite failure, even when the caller uses a strict Yii handler.
        set_error_handler(static function(int $severity,string $message): never {
            throw new \RuntimeException('Readiness SQL transport failed');
        });
        try {
            $this->check($deadline,$clock,$cancel);
            $connection=pg_connect($this->connectionString($deadline,$clock,$cancel),PGSQL_CONNECT_ASYNC|PGSQL_CONNECT_FORCE_NEW);
            if ($connection===false) { throw new \RuntimeException('Readiness SQL connect failed'); }
            // PQconnectStart leaves the first poll waiting for write readiness.
            // Polling a pending TCP connect early can enter a synchronous libpq
            // startup-packet flush, despite PGSQL_CONNECT_ASYNC.
            $this->wait($connection,true,$deadline,$clock,$cancel);
            do {
                $this->check($deadline,$clock,$cancel);
                $state=pg_connect_poll($connection);
                if ($state===PGSQL_POLLING_FAILED) { throw new \RuntimeException('Readiness SQL connect failed'); }
                if ($state===PGSQL_POLLING_OK) { break; }
                $this->wait($connection,$state===PGSQL_POLLING_WRITING,$deadline,$clock,$cancel);
            } while (true);
            $this->check($deadline,$clock,$cancel);
            $stream=pg_socket($connection);
            // ext-pgsql's stream option forwards this boolean directly to
            // PQsetnonblocking (PHP 8.3+), despite its inverted PHP name.
            // Without it pg_send_query contains an unbounded flush loop.
            if ($stream===false || !stream_set_blocking($stream,true)) { throw new \RuntimeException('Readiness SQL nonblocking mode failed'); }
            if (pg_send_query($connection,$sql)===false) { throw new \RuntimeException('Readiness SQL send failed'); }
            do {
                $this->check($deadline,$clock,$cancel);$flushed=pg_flush($connection);
                if ($flushed===false) { throw new \RuntimeException('Readiness SQL send failed'); }
                // PHP maps libpq's 1 (pending) to integer 0, 0 to true.
                if ($flushed===0) { $this->wait($connection,true,$deadline,$clock,$cancel); }
            } while ($flushed===0);
            $rows=[];
            while (true) {
                $this->check($deadline,$clock,$cancel);
                if (!pg_consume_input($connection)) { throw new \RuntimeException('Readiness SQL read failed'); }
                if (pg_connection_busy($connection)) {
                    $this->wait($connection,false,$deadline,$clock,$cancel);continue;
                }
                $result=pg_get_result($connection);
                if ($result===false) { break; }
                try {
                    if (!in_array(pg_result_status($result),[PGSQL_COMMAND_OK,PGSQL_TUPLES_OK],true)) {
                        throw new \RuntimeException('Readiness SQL statement failed');
                    }
                    while (($row=pg_fetch_assoc($result))!==false) { $rows[]=$row; }
                } finally { pg_free_result($result); }
            }
            $complete=true;return $rows;
        } finally {
            // Do not use synchronous PQcancel/pg_cancel_query: its separate TCP
            // exchange can itself blackhole. Close the session; server-side
            // statement/lock limits bound work even if FIN cannot reach PostgreSQL.
            try {
                if ($connection instanceof \PgSql\Connection) {
                    try {
                        // PHP pg_close drains pending PQgetResult synchronously.
                        // Abort the underlying socket first on every incomplete
                        // operation so draining cannot wait on a blackholed peer.
                        if (!$complete && ($stream=pg_socket($connection))!==false) {
                            $socket=socket_import_stream($stream);
                            if ($socket===false || !socket_shutdown($socket,2)) { throw new \RuntimeException('Readiness SQL cleanup failed'); }
                        }
                    } finally { pg_close($connection); }
                }
            }
            finally { restore_error_handler(); }
        }
    }

    /** @param callable(): float $clock @param callable(): bool $cancel */
    private function check(float $deadline,callable $clock,callable $cancel): void
    {
        $now=$clock();
        if (!is_finite($deadline) || !is_finite($now) || $now>=$deadline || $cancel()) {
            throw new \RuntimeException('Readiness SQL deadline or cancellation');
        }
    }

    /** @param callable(): float $clock @param callable(): bool $cancel */
    private function wait(\PgSql\Connection $connection,bool $writing,float $deadline,callable $clock,callable $cancel): void
    {
        $this->check($deadline,$clock,$cancel);
        $socket=pg_socket($connection);
        if ($socket===false) { throw new \RuntimeException('Readiness SQL socket failed'); }
        // Recheck caller cancellation at least every 10ms; never sleep through
        // the remaining absolute deadline. A select timeout is NOT readiness:
        // libpq may only advance after the requested socket event occurs.
        do {
            $this->check($deadline,$clock,$cancel);
            $read=$writing?[]:[$socket];$write=$writing?[$socket]:[];$except=null;
            $microseconds=(int)max(1,min(10000,ceil(($deadline-$clock())*1000000)));
            $selected=stream_select($read,$write,$except,0,$microseconds);
            if ($selected===false) { throw new \RuntimeException('Readiness SQL socket failed'); }
            $this->check($deadline,$clock,$cancel);
        } while ($selected===0);
    }

    /** @param callable(): float $clock @param callable(): bool $cancel */
    private function connectionString(float $deadline,callable $clock,callable $cancel): string
    {
        $url=parse_url($this->url);
        if ($url===false || !in_array($url['scheme']??'',['postgres','postgresql'],true)) {
            throw new \RuntimeException('Readiness SQL configuration invalid');
        }
        parse_str($url['query']??'',$query);
        $sslmode=$query['sslmode']??'prefer';
        if (!is_string($sslmode) || !in_array($sslmode,['disable','allow','prefer','require','verify-ca','verify-full'],true)) {
            throw new \RuntimeException('Readiness SQL configuration invalid');
        }
        $values=[
            'host'=>$url['host']??'localhost','port'=>(string)($url['port']??5432),
            'dbname'=>rawurldecode(ltrim($url['path']??'','/')),
            'user'=>rawurldecode($url['user']??''),'password'=>rawurldecode($url['pass']??''),
            'sslmode'=>$sslmode,'application_name'=>'shopkmp-core-readiness',
            'options'=>'-c statement_timeout='.$this->budget->statementMilliseconds
                .' -c lock_timeout='.$this->budget->lockMilliseconds.' -c default_transaction_read_only=on',
        ];
        foreach (['hostaddr','sslrootcert','sslcert','sslkey'] as $key) {
            if (isset($query[$key])) {
                if (!is_string($query[$key]) || str_contains($query[$key],"\0")) { throw new \RuntimeException('Readiness SQL configuration invalid'); }
                $values[$key]=$query[$key];
            }
        }
        $host=trim($values['host'],'[]');
        if (isset($values['hostaddr'])) {
            if (filter_var($values['hostaddr'],FILTER_VALIDATE_IP)===false) { throw new \RuntimeException('Readiness SQL configuration invalid'); }
        } else {
            $values['hostaddr']=filter_var($host,FILTER_VALIDATE_IP)!==false?$host:
                $this->address($host,(int)$values['port'],$deadline,$clock,$cancel);
        }
        $parts=[];
        foreach ($values as $key=>$value) {
            if (str_contains($value,"\0")) { throw new \RuntimeException('Readiness SQL configuration invalid'); }
            $parts[]=$key."='".str_replace(['\\',"'"],['\\\\',"\\'"],$value)."'";
        }
        return implode(' ',$parts);
    }

    /** libpq async connect still calls blocking getaddrinfo unless hostaddr is
     * supplied. Existing c-ares/libcurl resolves + connects without application
     * bytes, inside the SAME deadline. Close that preflight socket, then pin its
     * numeric address for libpq while preserving host for verify-full TLS.
     * @param callable(): float $clock
     * @param callable(): bool $cancel
     */
    private function address(string $host,int $port,float $deadline,callable $clock,callable $cancel): string
    {
        $version=curl_version();
        if (($version['ares']??'')==='' || !preg_match('/^[a-zA-Z0-9._-]+$/D',$host)) {
            // Threaded/synchronous resolver cleanup may block. Such runtimes
            // must provide numeric hostaddr rather than claim a false bound.
            throw new \RuntimeException('Readiness SQL bounded resolver required');
        }
        $this->check($deadline,$clock,$cancel);
        $curl=curl_init('http://'.$host.':'.$port);
        if ($curl===false) { throw new \RuntimeException('Readiness SQL resolver failed'); }
        $multi=curl_multi_init();$added=false;
        try {
            $milliseconds=(int)max(1,ceil(($deadline-$clock())*1000));
            if (!curl_setopt_array($curl,[
                CURLOPT_CONNECT_ONLY=>true,CURLOPT_PROXY=>'',CURLOPT_NOSIGNAL=>true,
                CURLOPT_TIMEOUT_MS=>$milliseconds,CURLOPT_CONNECTTIMEOUT_MS=>$milliseconds,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_FOLLOWLOCATION=>false,
            ]) || curl_multi_add_handle($multi,$curl)!==CURLM_OK) { throw new \RuntimeException('Readiness SQL resolver failed'); }
            $added=true;
            do {
                $this->check($deadline,$clock,$cancel);
                if (curl_multi_exec($multi,$running)!==CURLM_OK) { throw new \RuntimeException('Readiness SQL resolver failed'); }
                if ($running>0) { curl_multi_select($multi,min(.01,max(.000001,$deadline-$clock()))); }
            } while ($running>0);
            $this->check($deadline,$clock,$cancel);
            $message=curl_multi_info_read($multi);
            $address=curl_getinfo($curl,CURLINFO_PRIMARY_IP);
            if ($message===false || $message['result']!==CURLE_OK
                || filter_var($address,FILTER_VALIDATE_IP)===false) { throw new \RuntimeException('Readiness SQL resolver failed'); }
            return $address;
        } finally {
            if ($added) { curl_multi_remove_handle($multi,$curl); }
            // PHP 8.5 curl_close is deprecated; releasing both objects closes
            // the connection-only preflight without sending an HTTP request.
            unset($curl,$multi);
        }
    }
}
