<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Shop\infrastructure\Database;
$db=new Database((string)getenv('DATABASE_URL'));
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/realms/worker/.well-known/openid-configuration'){
    header('Content-Type: application/json');$issuer='http://'.$_SERVER['HTTP_HOST'].'/realms/worker';
    print json_encode(['issuer'=>$issuer,'jwks_uri'=>$issuer.'/keys']);return;
}
if($path==='/realms/worker/keys'){header('Content-Type: application/json');print '{"keys":[]}';return;}
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer fixture-admin'){http_response_code(401);return;}
if(preg_match('~^/admin/realms/worker/users/[a-f0-9-]+/consents/shopkmp-backend$~D',$path) && $_SERVER['REQUEST_METHOD']==='DELETE'){http_response_code(204);return;}
if(!preg_match('~^/admin/realms/worker/users/([a-f0-9-]+)/logout$~D',$path,$m) || $_SERVER['REQUEST_METHOD']!=='POST' || file_get_contents('php://input')!==''){http_response_code(405);return;}
$user=$m[1];$job=$db->one('SELECT * FROM recovery_test_provider WHERE user_id=?',[$user]);
if(!$job){http_response_code(409);return;}
$active=$db->one("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name LIKE 'recovery-worker-%' AND xact_start IS NOT NULL AND pid<>pg_backend_pid()");
$db->execute('UPDATE recovery_test_provider SET calls=calls+1,open_transactions=open_transactions+? WHERE user_id=?',[$active['n'],$user]);
usleep($job['delay_ms']*1000);http_response_code($job['response_code']);
if($job['response_code']!==204){print 'provider-secret-must-not-be-logged';}
