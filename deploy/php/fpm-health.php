<?php
// A real FastCGI request to a live FPM worker; no dependency on curl/FastCGI tools.
declare(strict_types=1);
$s=@stream_socket_client('tcp://127.0.0.1:9000',$errno,$error,2);
if(!$s){exit(1);}stream_set_timeout($s,2);
function record(int $type,string $body): string {return pack('CCnnCC',1,$type,1,strlen($body),0,0).$body;}
$params='';foreach(['REQUEST_METHOD'=>'GET','SCRIPT_NAME'=>'/fpm-ping','SCRIPT_FILENAME'=>'/fpm-ping','SERVER_PROTOCOL'=>'HTTP/1.1'] as $k=>$v){$params.=chr(strlen($k)).chr(strlen($v)).$k.$v;}
fwrite($s,record(1,pack('nCxxxxx',1,0)).record(4,$params).record(4,'').record(5,''));
$out='';while(!feof($s)){$chunk=fread($s,8192);if($chunk===false || $chunk===''){break;}$out.=$chunk;if(str_contains($out,'pong')){fclose($s);exit(0);}}
fclose($s);exit(1);
