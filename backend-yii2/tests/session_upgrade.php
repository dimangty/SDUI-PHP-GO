<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\application\SessionService;
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

foreach([false,true] as $populated){
    test('migration 013 independently upgrades 001-011 '.($populated?'with legacy tokens':'empty database'),function()use($populated){
        $db=new Database(Env::get('DATABASE_URL'));$schema='session_upgrade_'.str_replace('-','',Ids::uuid());
        $db->execute('CREATE SCHEMA '.$schema);$db->execute('SET search_path TO '.$schema.', public');
        try{
            foreach(glob('/migrations/*.up.sql') as $file){if((int)basename($file)>11){continue;}$db->pdo()->exec(file_get_contents($file));}
            $u=Ids::uuid();$access='app_access_'.Ids::opaque();$refresh='app_refresh_'.Ids::opaque();
            $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Legacy Buyer','+79991234567')",[$u,$u.'@test.example']);
            if($populated){foreach(['access'=>$access,'refresh'=>$refresh] as $kind=>$token){$db->execute('INSERT INTO '.$kind.'_sessions(token_hash,user_id,expires_at) VALUES(decode(?,\'hex\'),?,now()+interval \'1 day\')',[hash('sha256',$token),$u]);}}
            $db->transaction(fn()=>$db->pdo()->exec(file_get_contents('/migrations/013_session_revocation.up.sql')));
            // Verify 013 independently before exercising the current local reader.
            same(0,$db->one('SELECT count(*) AS n FROM session_families')['n']);
            foreach(['access','refresh'] as $kind){same(0,$db->one('SELECT count(*) AS n FROM '.$kind.'_sessions')['n']);}
            try{$db->execute('INSERT INTO refresh_sessions(token_hash,user_id,expires_at) VALUES(decode(?,\'hex\'),?,now()+interval \'1 day\')',[hash('sha256','legacy-writer'),$u]);throw new RuntimeException('legacy writer accepted');}catch(PDOException $e){same('23502',$e->getCode());}
            $db->execute("INSERT INTO password_session_operations(id,user_id,security_version,state) VALUES(?,?,0,'RESET_PENDING')",[Ids::uuid(),$u]);
            try{$db->transaction(fn()=>$db->pdo()->exec(file_get_contents('/migrations/013_session_revocation.down.sql')));throw new RuntimeException('unsafe downgrade accepted');}catch(PDOException $e){truth(str_contains($e->getMessage(),'pending password session recovery'));}
            $db->transaction(fn()=>$db->pdo()->exec(file_get_contents('/migrations/015_local_auth.up.sql')));
            $s=new SessionService($db,new ShopRepository($db));errorCode('UNAUTHORIZED',fn()=>$s->authenticate($access));errorCode('INVALID_REFRESH_TOKEN',fn()=>$s->refresh($refresh));
            // Existing users require independent local review after the upgrade.
            errorCode('UNAUTHORIZED',fn()=>$s->issue($u));$db->execute('UPDATE users SET local_identity_ready=true WHERE id=?',[$u]);same($u,$s->issue($u)['userId']);
        }finally{$db->execute('SET search_path TO public');$db->execute('DROP SCHEMA '.$schema.' CASCADE');}
    });
}
runTests();
