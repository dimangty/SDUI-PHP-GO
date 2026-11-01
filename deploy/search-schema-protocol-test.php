<?php
declare(strict_types=1);
require __DIR__.'/search-security-provision.php';

// Actual curl + verified mTLS on an isolated container's loopback. The child is a
// deterministic HTTP peer, not a replacement implementation of schema policy.
function schemaPeer(array $replies, bool $clientCertificate = true): array
{
    $context = stream_context_create(['ssl'=>[
        'local_cert'=>'/run/tls/opensearch.crt','local_pk'=>'/run/tls/opensearch.key',
        'cafile'=>'/run/tls/ca.crt','verify_peer'=>$clientCertificate,'verify_peer_name'=>false,
    ]]);
    $server = stream_socket_server('tcp://127.0.0.1:9200', $errno, $error, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
    if ($server === false) { throw new RuntimeException('Cannot start TLS protocol peer'); }
    [$parent,$child] = stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);
    $pid = pcntl_fork();
    if ($pid === -1) { throw new RuntimeException('Cannot fork TLS protocol peer'); }
    if ($pid === 0) {
        fclose($parent); $calls=[]; $positions=[];
        while (true) {
            $read=[$server,$child]; $write=$except=[];
            if (stream_select($read,$write,$except,20) !== 1) { $calls[]='fixture deadline'; break; }
            if (in_array($child,$read,true)) { break; }
            $socket=stream_socket_accept($server,3);
            if ($socket === false) { $calls[]='fixture accept failed'; break; }
            stream_set_timeout($socket,3);
            if (stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                $calls[]='fixture TLS failed'; fclose($socket); break;
            }
            $first=fgets($socket); $length=0;
            while (($line=fgets($socket)) !== false && trim($line)!=='') {
                if (stripos($line,'Content-Length:')===0) { $length=(int)trim(substr($line,15)); }
            }
            if ($length>65536) { $calls[]='fixture body limit'; fclose($socket); break; }
            while ($length>0) { $chunk=fread($socket,$length); if ($chunk===false || $chunk==='') { break; } $length-=strlen($chunk); }
            $parts=explode(' ',trim((string)$first)); $key=($parts[0]??'').' '.($parts[1]??'');
            $calls[]=$key;
            $position=$positions[$key]??0; $positions[$key]=$position+1;
            [$status,$body]=$replies[$key][$position]??[500,'{"error":"unexpected fixture request"}'];
            fwrite($socket,'HTTP/1.1 '.$status." fixture\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".(($parts[0]??'')==='HEAD'?'':$body));
            fclose($socket);
        }
        fwrite($child,json_encode($calls,JSON_THROW_ON_ERROR)); fclose($child); fclose($server); exit(0);
    }
    fclose($child); fclose($server); return [$pid,$parent];
}

function schemaScenario(string $name,array $replies,array $expectedCalls,bool $expectedSuccess): bool
{
    [$pid,$control]=schemaPeer($replies); $success=false;
    ob_start();
    try { searchCatalogSchema(); $success=true; } catch (Throwable) { }
    $output=ob_get_clean();
    fwrite($control,'stop'); stream_socket_shutdown($control,STREAM_SHUT_WR);
    stream_set_timeout($control,5); $calls=json_decode(stream_get_contents($control),true);
    fclose($control); pcntl_waitpid($pid,$status);
    $passed=$success===$expectedSuccess && $calls===$expectedCalls
        && ($expectedSuccess || $output==='') && pcntl_wexitstatus($status)===0;
    print ($passed?'PASS ':'FAIL ').$name.'; completed='.($success?'yes':'no').'; calls='.json_encode($calls)."\n";
    return $passed;
}

$template='/_index_template/shopkmp-catalog-writer-policy'; $index='/shopkmp-products';
$spec=['index_patterns'=>['shopkmp-products','shopkmp-products-*'],'priority'=>100,
    'template'=>['mappings'=>['dynamic'=>false]]];
$templateReply=['index_templates'=>[['name'=>'shopkmp-catalog-writer-policy','index_template'=>$spec]]];
$before=['shopkmp-products'=>['settings'=>['index'=>['uuid'=>'protocol-uuid']],
    'mappings'=>['_meta'=>['preserve'=>'operator-fixture'],'properties'=>['id'=>['type'=>'keyword']]]]];
$after=$before; $after['shopkmp-products']['mappings']['dynamic']='false';
$json=static fn(array $value): string=>json_encode($value,JSON_THROW_ON_ERROR);
$base=[
    'PUT '.$template=>[[200,'{"acknowledged":true}']],
    'GET '.$template=>[[200,$json($templateReply)]],
    'HEAD '.$index=>[[200,'']],
    'GET '.$index=>[[200,$json($before)],[200,$json($after)]],
    'PUT '.$index.'/_mapping'=>[[200,'{"acknowledged":true}']],
];
$prefix=['PUT '.$template,'GET '.$template];
$existing=[...$prefix,'HEAD '.$index,'GET '.$index,'PUT '.$index.'/_mapping','GET '.$index];
$failures=0; $count=0;
$run=static function(string $name,array $replies,array $calls,bool $success)use(&$failures,&$count):void {
    $count++; if(!schemaScenario($name,$replies,$calls,$success)){$failures++;}
};
$run('acknowledged writes require template and existing-index readbacks',$base,$existing,true);
$absent=$base; $absent['HEAD '.$index]=[[404,'']];
$run('absent target still verifies installed template',$absent,[...$prefix,'HEAD '.$index],true);
foreach (['false'=>'{"acknowledged":false}','missing'=>'{}','coerced'=>'{"acknowledged":"true"}',
    'numeric'=>'{"acknowledged":1}','error'=>'{"acknowledged":true,"error":{}}','malformed'=>'{',
    'scalar'=>'true','array'=>'[]'] as $name=>$body) {
    $bad=$base; $bad['PUT '.$template]=[[200,$body]];
    $run('template '.$name.' stops without write retry',$bad,['PUT '.$template],false);
    $bad=$base; $bad['PUT '.$index.'/_mapping']=[[200,$body]];
    $run('mapping '.$name.' stops without write retry',$bad,array_slice($existing,0,5),false);
}
foreach (['dynamic','priority','missing','malformed'] as $name) {
    $bad=$base; $reply=$templateReply;
    if($name==='dynamic'){$reply['index_templates'][0]['index_template']['template']['mappings']['dynamic']=true;}
    if($name==='priority'){$reply['index_templates'][0]['index_template']['priority']=101;}
    if($name==='missing'){$reply['index_templates']=[];}
    $bad['GET '.$template]=[[200,$name==='malformed'?'{':$json($reply)]];
    $run('template readback '.$name.' stops before live-index write',$bad,$prefix,false);
}
foreach (['dynamic','uuid','metadata','properties','malformed'] as $name) {
    $bad=$base; $reply=$after;
    if($name==='dynamic'){$reply['shopkmp-products']['mappings']['dynamic']=true;}
    if($name==='uuid'){$reply['shopkmp-products']['settings']['index']['uuid']='changed-uuid';}
    if($name==='metadata'){$reply['shopkmp-products']['mappings']['_meta']=[];}
    if($name==='properties'){$reply['shopkmp-products']['mappings']['properties']=[];}
    $bad['GET '.$index]=[[200,$json($before)],[200,$name==='malformed'?'{':$json($reply)]];
    $run('mapping readback '.$name.' is unknown outcome, no retry',$bad,$existing,false);
}
// OpenSearch's Basic-auth denial is HTTP 401 with a non-JSON body. It is a
// denial, not a malformed successful acknowledgement or a successful login.
[$pid,$control]=schemaPeer(['GET /_plugins/_security/authinfo'=>[[401,'Unauthorized']]],false);
$denied=false;
try{[$status,$body]=searchRequest('GET','/_plugins/_security/authinfo',null,['fixture-user','fixture-password']);$denied=$status===401 && $body===null;}
catch(Throwable){}
fwrite($control,'stop');stream_socket_shutdown($control,STREAM_SHUT_WR);
$calls=json_decode(stream_get_contents($control),true);fclose($control);pcntl_waitpid($pid,$exit);
$count++;if(!$denied || $calls!==['GET /_plugins/_security/authinfo']){$failures++;}
print ($denied?'PASS ':'FAIL ')."non-JSON 401 remains an explicit authentication denial\n";
print "$count protocol scenarios, $failures failures\n"; exit($failures===0?0:1);
