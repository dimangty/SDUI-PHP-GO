<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\application\{SessionService,MaintenanceService,CommerceService};
use Shop\domain\Ids;
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

test('expired family retention cannot cascade past the token batch budget or delete an active family',function(){
    $db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$sessions=new SessionService($db,$repo);$user=Ids::uuid();
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Retention fixture','+79991234567')",[$user,$user.'@example.invalid']);
    try{
        $old=$sessions->issue($user,'password');$active=$sessions->issue($user,'password');
        $family=$db->one("SELECT family_id FROM access_sessions WHERE token_hash=decode(?,'hex')",[hash('sha256',$old['accessToken'])])['family_id'];
        $db->execute("UPDATE session_families SET expires_at=clock_timestamp()-interval '1 second' WHERE id=?",[$family]);
        $db->execute("UPDATE access_sessions SET expires_at=clock_timestamp()-interval '1 second' WHERE family_id=?",[$family]);
        $db->execute("UPDATE refresh_sessions SET expires_at=clock_timestamp()-interval '1 second' WHERE family_id=?",[$family]);
        $db->execute("INSERT INTO access_sessions(token_hash,user_id,family_id,expires_at) SELECT decode(md5(?||n::text)||md5(n::text||?),'hex'),?::uuid,?::uuid,clock_timestamp()-interval '1 second' FROM generate_series(1,2001) n",[$user,$family,$user,$family]);
        (new MaintenanceService($db,$repo,new CommerceService($db,$repo)))->run();
        truth($db->one('SELECT id FROM session_families WHERE id=?',[$family])!==null);
        truth($db->one('SELECT count(*) AS n FROM access_sessions WHERE family_id=?',[$family])['n']>=1002);
        same($user,$sessions->authenticate($active['accessToken'])['id']);
    }finally{$db->execute('DELETE FROM users WHERE id=?',[$user]);}
});
runTests();
