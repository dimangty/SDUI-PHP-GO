<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SessionProviderFixture.php';
require __DIR__.'/SocketUpstream.php';
use Shop\application\{AuthService,SessionService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env,HttpClient,Keycloak};
use Shop\repositories\ShopRepository;

foreach(['success'=>null,'revoke503'=>'IDENTITY_PROVIDER_ERROR','invalid'=>'IDENTITY_PROVIDER_ERROR','invalid-token'=>'IDENTITY_PROVIDER_ERROR','timeout'=>'IDENTITY_PROVIDER_UNAVAILABLE'] as $mode=>$error){
    test('refresh logout exact Keycloak 26.3.3 contract '.$mode,function()use($mode,$error){
        $fixture=new SessionProviderFixture($mode);
        $kc=new Keycloak(new HttpClient(.2));
        try{if($error===null){$kc->logout('provider-refresh');$kc->logout('provider-refresh');}else{errorCode($error,fn()=>$kc->logout('provider-refresh'));}}
        finally{$fixture->close();}
    });
}
foreach(['success'=>null,'no-consent'=>null,'revoke503'=>'IDENTITY_PROVIDER_ERROR','invalid'=>'IDENTITY_PROVIDER_ERROR','forbidden'=>'IDENTITY_PROVIDER_ERROR','timeout'=>'IDENTITY_PROVIDER_UNAVAILABLE'] as $mode=>$error){
    test('authenticated logout always cleans local tokens and never forwards arbitrary provider refresh '.$mode,function()use($mode,$error){
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$s=new SessionService($db,$repo);$auth=new AuthService($db,$repo,$s);$u=Ids::uuid();$v=Ids::uuid();
        foreach([$u,$v] as $id){$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Logout Buyer','+79991234567')",[$id,$id.'@test.example']);}
        $t=$s->issue($u);$safe=$s->issue($v);$fixture=new SessionProviderFixture($mode,$u);$auth=new AuthService($db,$repo,$s,new Keycloak(new HttpClient(.2)));
        try{
            $op=fn()=>$auth->logout($u,['refreshToken'=>'victim-provider-refresh']);if($error===null){$op();}else{errorCode($error,$op);}
            errorCode('UNAUTHORIZED',fn()=>$s->authenticate($t['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($t['refreshToken']));same($v,$s->authenticate($safe['accessToken'])['id']);
        }finally{$fixture->close();}
    });
}
test('opaque-only logout needs no identity provider and preserves the public local contract',function(){
    $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$s=new SessionService($db,$repo);$u=Ids::uuid();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Local Buyer','+79991234567')",[$u,$u.'@test.example']);$t=$s->issue($u);
    putenv('KEYCLOAK_ISSUER');putenv('KEYCLOAK_CLIENT_SECRET');putenv('KEYCLOAK_ADMIN_TOKEN');
    (new AuthService($db,$repo,$s))->logout($u,['refreshToken'=>$t['refreshToken']]);
    errorCode('UNAUTHORIZED',fn()=>$s->authenticate($t['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($t['refreshToken']));
});
test('network or invalid provider configuration failures keep local logout committed and return compatible errors',function(){
    foreach(['network','configuration'] as $mode){
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$s=new SessionService($db,$repo);$u=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Offline Buyer','+79991234567')",[$u,$u.'@test.example']);$t=$s->issue($u);
        $fixture=new SessionProviderFixture();$kc=new Keycloak(new HttpClient(.2));$fixture->close();
        if($mode==='configuration'){putenv('KEYCLOAK_ISSUER');$kc=null;}
        errorCode('IDENTITY_PROVIDER_UNAVAILABLE',fn()=>(new AuthService($db,$repo,$s,$kc))->logout($u,['refreshToken'=>'provider-refresh-secret']));
        errorCode('UNAUTHORIZED',fn()=>$s->authenticate($t['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($t['refreshToken']));
    }
});
test('provider logout failures do not expose tokens or provider bodies in errors or application logs',function(){
    $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$s=new SessionService($db,$repo);$u=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Private Buyer','+79991234567')",[$u,$u.'@test.example']);$t=$s->issue($u);
    $fixture=new SessionProviderFixture('revoke503',$u);$log=tempnam(sys_get_temp_dir(),'session-logout-log-');$previous=ini_get('error_log');ini_set('error_log',$log);
    try{
        try{(new AuthService($db,$repo,$s,new Keycloak(new HttpClient(.2))))->logout($u,['refreshToken'=>'victim-provider-refresh']);throw new RuntimeException('expected provider error');}
        catch(\Shop\domain\ApiError $e){same('IDENTITY_PROVIDER_ERROR',$e->errorCode);$out=$e->getMessage().json_encode($e->details).file_get_contents($log);foreach(['victim-provider-refresh','provider-secret-not-for-storage',$t['accessToken'],$t['refreshToken']] as $secret){truth(!str_contains($out,$secret));}}
        same(0,$db->one('SELECT count(*) AS n FROM refresh_sessions WHERE user_id=?',[$u])['n']);
    }finally{ini_set('error_log',$previous);unlink($log);$fixture->close();}
});
test('local logout revokes opaque tokens while offline signed JWT validation lasts until expiry',function(){
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);$details=openssl_pkey_get_details($key);$realm=Ids::uuid();
    $jwk=['kty'=>'RSA','alg'=>'RS256','use'=>'sig','kid'=>'logout-fixture','n'=>\Firebase\JWT\JWT::urlsafeB64Encode($details['rsa']['n']),'e'=>\Firebase\JWT\JWT::urlsafeB64Encode($details['rsa']['e'])];
    $server=new SocketUpstream(function($connection,$headers,$first)use($realm,$jwk){
        $origin='http://'.$headers['host'];$body=json_encode(str_contains($first,'.well-known')?['issuer'=>$origin.'/realms/'.$realm,'jwks_uri'=>$origin.'/keys']:['keys'=>[$jwk]]);
        fwrite($connection,"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    });
    try{
        $issuer=$server->origin.'/realms/'.$realm;putenv('KEYCLOAK_ISSUER='.$issuer);putenv('KEYCLOAK_CLIENT_SECRET=offline-fixture');putenv('KEYCLOAK_CLIENT_ID=shopkmp-backend');
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$s=new SessionService($db,$repo);$auth=new AuthService($db,$repo,$s);$u=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','JWT Buyer','+79991234567')",[$u,$u.'@test.example']);
        $claims=['iss'=>$issuer,'aud'=>['shopkmp-backend'],'azp'=>'shopkmp-backend','typ'=>'Bearer','sub'=>$u,'iat'=>time()-1,'exp'=>time()+300];$jwt=\Firebase\JWT\JWT::encode($claims,$key,'RS256','logout-fixture');$opaque=$s->issue($u);
        same($u,$auth->authenticate($jwt)['id']);$auth->logout($u,['refreshToken'=>$opaque['refreshToken']]);
        errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($opaque['accessToken']));same($u,$auth->authenticate($jwt)['id']);
        $expired=\Firebase\JWT\JWT::encode(array_replace($claims,['exp'=>time()-1]),$key,'RS256','logout-fixture');errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($expired));
    }finally{$server->finish();}
});
foreach(['grant-400'=>'IDENTITY_PROVIDER_ERROR','grant-401'=>'IDENTITY_PROVIDER_ERROR','grant-locked400'=>'IDENTITY_PROVIDER_ERROR','grant-locked401'=>'IDENTITY_PROVIDER_ERROR','grant-503'=>'IDENTITY_PROVIDER_ERROR','grant-timeout'=>'IDENTITY_PROVIDER_UNAVAILABLE','grant-malformed'=>'IDENTITY_PROVIDER_ERROR','grant-invalid-fields'=>'IDENTITY_PROVIDER_ERROR','grant-success'=>null] as $mode=>$expected){
    test('admin service-account grant has provider error semantics without an override '.$mode,function()use($mode,$expected){
        $statsPath=tempnam(sys_get_temp_dir(),'session-grant-stats-');file_put_contents($statsPath,json_encode(['grants'=>0,'admin'=>0,'logout'=>0,'consent'=>0]));
        $u=Ids::uuid();putenv('KEYCLOAK_CLIENT_ID=shopkmp-backend');$fixture=new SessionProviderFixture($mode,$u,false,$statsPath);$kc=new Keycloak(new HttpClient(.2));
        try{
            same('',Env::get('KEYCLOAK_ADMIN_TOKEN'));
            if($expected===null){same(204,$kc->admin('POST','/users/'.$u.'/logout')['status']);}
            else{errorCode($expected,fn()=>$kc->admin('POST','/users/'.$u.'/logout'));}
            same(['grants'=>1,'admin'=>$expected===null?1:0,'logout'=>$expected===null?1:0,'consent'=>0],json_decode(file_get_contents($statsPath),true));
        }finally{$fixture->close();unlink($statsPath);}
    });
    test('logout keeps local revocation committed through service-account acquisition '.$mode,function()use($mode,$expected){
        $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$s=new SessionService($db,$repo);$u=Ids::uuid();$v=Ids::uuid();
        foreach([$u,$v] as $id){$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Service Grant Buyer','+79991234567')",[$id,$id.'@test.example']);}
        $one=$s->issue($u);$two=$s->issue($u);$safe=$s->issue($v);
        $statsPath=tempnam(sys_get_temp_dir(),'session-grant-stats-');file_put_contents($statsPath,json_encode(['grants'=>0,'admin'=>0,'logout'=>0,'consent'=>0]));
        putenv('KEYCLOAK_CLIENT_ID=shopkmp-backend');$fixture=new SessionProviderFixture($mode,$u,false,$statsPath);$log=tempnam(sys_get_temp_dir(),'session-grant-log-');$previous=ini_get('error_log');ini_set('error_log',$log);
        try{
            same('',Env::get('KEYCLOAK_ADMIN_TOKEN'));$error=null;
            try{(new AuthService($db,$repo,$s,new Keycloak(new HttpClient(.2))))->logout($u,['refreshToken'=>'victim-provider-refresh']);}
            catch(\Shop\domain\ApiError $e){$error=$e;}
            foreach([$one,$two] as $tokens){errorCode('UNAUTHORIZED',fn()=>$s->authenticate($tokens['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($tokens['refreshToken']));}
            foreach(['access','refresh'] as $kind){same(0,$db->one('SELECT count(*) AS n FROM '.$kind.'_sessions WHERE user_id=?',[$u])['n']);}
            same($v,$s->authenticate($safe['accessToken'])['id']);same($v,$s->refresh($safe['refreshToken'])['userId']);
            $stats=json_decode(file_get_contents($statsPath),true);
            if($expected===null){truth($stats['grants']>=1);same(['admin'=>2,'logout'=>1,'consent'=>1],array_diff_key($stats,['grants'=>0]));}
            else{same(['grants'=>1,'admin'=>0,'logout'=>0,'consent'=>0],$stats);}
            $out=($error?->getMessage()??'').json_encode($error?->details??[]).file_get_contents($log).file_get_contents($statsPath);
            foreach(['fixture-secret','fixture-admin','provider-body-not-for-logs','service-token-not-for-logs','victim-provider-refresh',$one['accessToken'],$one['refreshToken'],$two['accessToken'],$two['refreshToken']] as $secret){truth(!str_contains($out,$secret));}
            if($expected===null){same(null,$error);}else{truth($error!==null);same($expected,$error->errorCode);same($expected==='IDENTITY_PROVIDER_UNAVAILABLE'?503:502,$error->status());}
        }finally{ini_set('error_log',$previous);unlink($log);$fixture->close();unlink($statsPath);}
    });
}
foreach(['password','refresh_token'] as $grant){foreach(['grant-400'=>'INVALID_CREDENTIALS','grant-401'=>'INVALID_CREDENTIALS','grant-locked400'=>'ACCOUNT_LOCKED','grant-locked401'=>'ACCOUNT_LOCKED'] as $mode=>$expected){
    test('user '.$grant.' grant retains credential error semantics '.$mode,function()use($grant,$mode,$expected){
        $fixture=new SessionProviderFixture($mode,'',false);$kc=new Keycloak(new HttpClient(.2));
        try{same('',Env::get('KEYCLOAK_ADMIN_TOKEN'));errorCode($expected,fn()=>$grant==='password'?$kc->password('buyer@test.example','wrong-password'):$kc->tokens(['grant_type'=>'refresh_token','refresh_token'=>'user-refresh-not-for-logs']));}
        finally{$fixture->close();}
    });
}}
runTests();
