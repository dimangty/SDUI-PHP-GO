<?php
// Test-only provider protocol fixture. Never loaded by the application.
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/redirect'){http_response_code(302);header('Location: http://unreachable.invalid/');exit;}
if($path==='/oversize'){print str_repeat('x',2048);exit;}
if($path==='/slow'){sleep(3);print '{}';exit;}
if(preg_match('~^/attempts/([a-f0-9-]+)$~D',$path,$m)){header('Content-Type: application/json');print json_encode(['attempts'=>(int)@file_get_contents(sys_get_temp_dir().'/task1-gateway-'.$m[1])]);exit;}
$body=json_decode(file_get_contents('php://input'),true);$id=$body['paymentId']??'';
if(!preg_match('/^[a-f0-9-]{36}$/D',$id) || ($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer task1_gateway_key' || !is_int($body['amountCents']??null) || !isset($_SERVER['HTTP_IDEMPOTENCY_KEY'])){http_response_code(400);exit;}
$file=sys_get_temp_dir().'/task1-gateway-'.$id;$attempt=(int)@file_get_contents($file)+1;file_put_contents($file,(string)$attempt,LOCK_EX);
if(str_starts_with($path,'/fail/')){http_response_code(503);print '{}';exit;}
if(str_ends_with($path,'/authorize')){header('Content-Type: application/json');print json_encode(['status'=>'AUTHORIZED','paymentUrl'=>'https://payments.example.test/session/'.$id,'transactionId'=>'cents-'.$body['amountCents']]);exit;}
http_response_code(204);
