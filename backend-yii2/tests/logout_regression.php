<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\infrastructure\ApiKernel;

foreach(['absent','own','other','malformed','historical'] as $mode){
    test('authenticated local logout revokes all caller families and preserves other user: '.$mode,function()use($mode){
        [$db,$sessions,,$auth]=localServices();$u=localUser($db);$v=localUser($db);$one=$sessions->issue($u,'password');$two=$sessions->issue($u);$safe=$sessions->issue($v);
        $input=match($mode){'absent'=>[],'own'=>['refreshToken'=>$one['refreshToken']],'other'=>['refreshToken'=>$safe['refreshToken']],'historical'=>['refreshToken'=>'retired.refresh.secret'],default=>['refreshToken'=>'not-a-token']};
        $auth->logout($u,$input);$auth->logout($u,$input);
        foreach([$one,$two] as $pair){errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($pair['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$sessions->refresh($pair['refreshToken']));}
        foreach(['access','refresh'] as $kind){same(0,$db->one('SELECT count(*) AS n FROM '.$kind.'_sessions WHERE user_id=?',[$u])['n']);}
        same($v,$auth->authenticate($safe['accessToken'])['id']);same($v,$sessions->refresh($safe['refreshToken'])['userId']);
    });
}
test('logout SQL failure rolls back revocation and logs no tokens or credentials at HTTP boundary',function(){
    [$db,$sessions]=localServices();$u=localUser($db);$pair=$sessions->issue($u);$suffix=str_replace('-','',$u);$fn='logout_fail_'.$suffix;
    $db->execute("CREATE FUNCTION $fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id='$u'::uuid THEN RAISE EXCEPTION 'logout failure'; END IF; RETURN NEW; END $$");
    $db->execute("CREATE TRIGGER $fn BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION $fn()");
    $log=tempnam(sys_get_temp_dir(),'local-logout-');$prior=ini_get('error_log');ini_set('error_log',$log);
    try{
        $result=(new ApiKernel($db))->handle('POST','/api/v1/auth/logout',['authorization'=>'Bearer '.$pair['accessToken'],'content-type'=>'application/json'],json_encode(['refreshToken'=>$pair['refreshToken']]),'203.0.113.45');
        same(503,$result['status']);same('DATABASE_UNAVAILABLE',$result['data']['error']['code']);same($u,$sessions->authenticate($pair['accessToken'])['id']);
        $out=json_encode($result).file_get_contents($log);foreach([$pair['accessToken'],$pair['refreshToken'],'StrongPass7395'] as $secret){truth(!str_contains($out,$secret));}
    }finally{ini_set('error_log',$prior);unlink($log);$db->execute("DROP TRIGGER $fn ON users");$db->execute("DROP FUNCTION $fn()");}
});
test('logout cannot revive stale signed JWTs before or after local revocation',function(){
    [$db,$sessions,,$auth]=localServices();$u=localUser($db);$pair=$sessions->issue($u);$jwt=historicalJwt(['sub'=>$u,'iat'=>time()-1,'exp'=>time()+300]);
    errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($jwt));$auth->logout($u,[]);errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($jwt));errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($pair['accessToken']));
});
runTests();
