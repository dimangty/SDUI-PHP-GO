<?php
declare(strict_types=1);

// Separate PHP process: no inherited PDO connection and no credentials in a journal.
if (PHP_SAPI === 'cli-server') {
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    preg_match('~/realms/([^/]+)~',$path,$realm);$mode=$realm[1]??'';
    $body=file_get_contents('php://input');
    $statsPath=(string)getenv('SESSION_FIXTURE_STATS');
    if($statsPath!==''){
        $stats=json_decode(file_get_contents($statsPath),true);
        if(str_ends_with($path,'/token')){$stats['grants']++;}
        elseif(str_starts_with($path,'/admin/')){
            $stats['admin']++;
            if($_SERVER['REQUEST_METHOD']==='POST' && str_ends_with($path,'/logout')){$stats['logout']++;}
            if($_SERVER['REQUEST_METHOD']==='DELETE' && str_contains($path,'/consents/')){$stats['consent']++;}
        }
        file_put_contents($statsPath,json_encode($stats),LOCK_EX);
    }
    if(str_ends_with($path,'/token')){
        parse_str($body,$form);$user=strstr($form['username']??'','@',true)?:getenv('SESSION_FIXTURE_USER');
        if(str_starts_with($mode,'grant-')){
            $service=($form['grant_type']??'')==='client_credentials';
            if($service && (($form['client_id']??'')!=='shopkmp-backend' || ($form['client_secret']??'')!=='fixture-secret')){http_response_code(400);print '{"error":"invalid_client"}';return;}
            if(in_array($mode,['grant-400','grant-401','grant-locked400','grant-locked401'],true)){
                http_response_code(str_ends_with($mode,'401')?401:400);header('Content-Type: application/json');
                print json_encode(['error'=>$service?'invalid_client':'invalid_grant','error_description'=>'provider-body-not-for-logs'.(str_contains($mode,'locked')?' account disabled':' invalid credentials')]);return;
            }
            if($mode==='grant-503'){http_response_code(503);print 'provider-body-not-for-logs';return;}
            if($mode==='grant-timeout'){usleep(700000);http_response_code(503);return;}
            if($mode==='grant-malformed'){print '{"provider-body-not-for-logs":';return;}
            if($mode==='grant-invalid-fields'){header('Content-Type: application/json');print '{"access_token":17,"expires_in":"300","error_description":"provider-body-not-for-logs"}';return;}
            if($service){header('Content-Type: application/json');print '{"access_token":"service-token-not-for-logs","expires_in":300}';return;}
        }
        if($mode==='verification-race'){
            require dirname(__DIR__).'/vendor/autoload.php';
            (new \Shop\infrastructure\Database(getenv('DATABASE_URL')))->execute('UPDATE users SET session_security_version=session_security_version+1 WHERE id=?',[$user]);
        }
        if(($form['password']??'')==='wrong'){http_response_code(401);print '{"error":"invalid_grant"}';return;}
        $jwt='eyJhbGciOiJSUzI1NiJ9.'.rtrim(strtr(base64_encode(json_encode(['sub'=>$user])),'+/','-_'),'=').'.fixture';
        header('Content-Type: application/json');print json_encode(['access_token'=>$jwt,'refresh_token'=>'provider-secret-not-for-storage','expires_in'=>300]);return;
    }
    $fixtureUser=(string)getenv('SESSION_FIXTURE_USER');
    if(str_starts_with($mode,'grant-') && str_starts_with($path,'/admin/') && ($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer service-token-not-for-logs'){http_response_code(401);return;}
    if(str_starts_with($path,'/admin/') && $fixtureUser!=='' && !str_contains($path,'/users/'.$fixtureUser.'/')){http_response_code(409);return;}
    if(str_contains($path,'reset-password') && in_array($mode,['probe','recovery-lock-probe'],true)){
        require dirname(__DIR__).'/vendor/autoload.php';
        $db=new \Shop\infrastructure\Database(getenv('DATABASE_URL'));$repo=new \Shop\repositories\ShopRepository($db);$s=new \Shop\application\SessionService($db,$repo);
        $blocked=false;try{$s->issue(getenv('SESSION_FIXTURE_USER'));}catch(\Shop\domain\ApiError $e){$blocked=$e->errorCode==='PASSWORD_CHANGED';}
        if(!$blocked){http_response_code(409);return;}
        if($mode==='recovery-lock-probe'){
            $job=$db->one("SELECT id FROM password_session_operations WHERE user_id=? AND state<>'COMPLETE'",[getenv('SESSION_FIXTURE_USER')]);
            $scope='password-session-recovery:'.$job['id'];
            $acquired=$db->one('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired',[$scope])['acquired'];
            if($acquired){$db->one('SELECT pg_advisory_unlock(hashtextextended(?,0))',[$scope]);http_response_code(409);return;}
        }
    }
    if(str_contains($path,'reset-password') && $mode==='disconnect'){
        require dirname(__DIR__).'/vendor/autoload.php';
        (new \Shop\infrastructure\Database(getenv('DATABASE_URL')))->one("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=current_database() AND application_name=?",['session-disconnect-'.$fixtureUser]);
    }
    if(str_contains($path,'reset-password') && $mode==='reset-invalid'){http_response_code(202);print '{"queued":true}';return;}
    if(($mode==='reset-timeout' && str_contains($path,'reset-password')) || ($mode==='timeout' && str_ends_with($path,'/logout'))){usleep(700000);http_response_code(204);return;}
    if($mode==='revoke503' && str_ends_with($path,'/logout')){http_response_code(503);print 'provider-secret-not-for-storage';return;}
    if($mode==='consent503' && str_contains($path,'/consents/')){http_response_code(503);return;}
    if($mode==='invalid' && str_ends_with($path,'/logout')){http_response_code(200);print '{"ok":true}';return;}
    if($mode==='invalid-token' && str_ends_with($path,'/logout')){http_response_code(400);header('Content-Type: application/json');print '{"error":"invalid_grant","error_description":"Invalid refresh token"}';return;}
    if($mode==='forbidden' && str_ends_with($path,'/logout')){http_response_code(403);return;}
    // Reject any caller that forwards an arbitrary refresh token through AuthService.
    if(str_contains($body,'victim-provider-refresh')){http_response_code(409);return;}
    if(str_contains($path,'/consents/') && $mode==='no-consent'){http_response_code(404);return;}
    http_response_code(204);return;
}

final class SessionProviderFixture
{
    public readonly string $origin;
    private $process;
    public function __construct(string $mode='success',string $user='',bool $adminOverride=true,?string $statsPath=null)
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        if(!$socket){throw new RuntimeException($error);}
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $this->origin='http://'.$address;
        $env=getenv();$env['SESSION_FIXTURE_USER']=$user;$env['SESSION_FIXTURE_STATS']=$statsPath??'';
        $this->process=proc_open([PHP_BINARY,'-S',$address,__FILE__],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,$env);
        for($i=0;$i<100;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$error,.02);if($ready){fclose($ready);break;}usleep(10000);}
        putenv('KEYCLOAK_ISSUER='.$this->origin.'/realms/'.$mode);putenv('KEYCLOAK_CLIENT_SECRET=fixture-secret');putenv($adminOverride?'KEYCLOAK_ADMIN_TOKEN=fixture-admin':'KEYCLOAK_ADMIN_TOKEN');
    }
    public function close(): void {proc_terminate($this->process);proc_close($this->process);}
}
