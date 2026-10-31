<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\PasswordHasher;
use Shop\infrastructure\AuthMetrics;

test('auth metrics have finite labels and never expose credentials or arbitrary errors',function(){
    $file=tempnam(sys_get_temp_dir(),'auth-metrics-');$old=ini_get('error_log');ini_set('error_log',$file);
    try{
        AuthMetrics::request('Auth.login','INVALID_CREDENTIALS',.01);
        AuthMetrics::request('Auth.refresh','DATABASE_UNAVAILABLE',.02);
        AuthMetrics::request('Credential.deletePin','ok',.03);
        AuthMetrics::request('user@example.com','secret-access-token',.04);
        AuthMetrics::hash('verify',.05);AuthMetrics::hash('secret-password',.06);
        $text=file_get_contents($file);truth(!str_contains($text,'user@example.com'));truth(!str_contains($text,'secret-'));
        truth(str_contains($text,'invalid_credentials'));truth(str_contains($text,'storage_error'));truth(str_contains($text,'revoke_credential'));
        same(4,substr_count($text,'"metric":'));
    }finally{ini_set('error_log',$old);unlink($file);}
});
test('native hasher reports only finite work stages through the injected observer',function(){
    $work=[];$h=new PasswordHasher(null,function(string $stage,float $seconds)use(&$work){truth($seconds>=0);$work[]=$stage;});
    $hash=$h->hash('SentinelPassword7395');truth($h->verify('SentinelPassword7395',$hash));same(false,$h->verify('SentinelPassword7395',''));
    same(['hash','verify','verify'],$work);
});
runTests();
