<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\infrastructure\{ApiKernel,Database,Env};
test('readiness honors configured identity-provider timeout',function(){
    putenv('KEYCLOAK_ISSUER='.Env::get('UPSTREAM_FIXTURE_ORIGIN','http://shopkmp-yii2-task1-upstream:8080').'/realms/slow');
    putenv('KEYCLOAK_CLIENT_SECRET=readiness_fixture_secret');putenv('READINESS_TIMEOUT=1s');
    $kernel=new ApiKernel(new Database(Env::get('DATABASE_URL')));$start=microtime(true);
    $r=$kernel->handle('GET','/health/ready',[],'','192.0.2.94');same(503,$r['status']);
    truth(microtime(true)-$start<2.5);
});
runTests();
