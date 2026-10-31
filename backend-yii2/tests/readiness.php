<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\infrastructure\{ApiKernel,Database,Env};
test('readiness requires local identity schema and optional search cannot block core readiness',function(){
    $db=new Database(Env::get('DATABASE_URL'));$kernel=new ApiKernel($db);$prior=getenv('OPENSEARCH_URL');putenv('OPENSEARCH_URL=http://127.0.0.1:1');
    try{
        $start=microtime(true);$r=$kernel->handle('GET','/health/ready',[],'','192.0.2.94');same(200,$r['status']);truth(microtime(true)-$start<2.5);
        foreach(['ALTER TABLE users RENAME COLUMN local_identity_ready TO hidden_local_ready','ALTER TABLE users RENAME COLUMN account_disabled TO hidden_disabled','ALTER TABLE session_families RENAME COLUMN expires_at TO hidden_family_expiry'] as $sql){
            $db->pdo()->beginTransaction();try{$db->execute($sql);same(503,$kernel->handle('GET','/health/ready',[],'','192.0.2.94')['status']);}finally{$db->pdo()->rollBack();}
        }
    }finally{putenv($prior===false?'OPENSEARCH_URL':'OPENSEARCH_URL='.$prior);}
});
runTests();
