<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\application\AuthService;
use Shop\domain\PasswordHasher;
use Shop\infrastructure\{Database,Env};
use Shop\repositories\ShopRepository;

class VerificationBarrierHasher extends PasswordHasher {
    public function __construct(private Closure $afterVerify){parent::__construct();}
    public function verify(string $password,string $hash): bool {$valid=parent::verify($password,$hash);($this->afterVerify)();return $valid;}
}
test('wrong old password neither revokes sessions nor creates a recovery job',function(){
    [$db,$sessions,,$auth]=localServices();[$pair]=localRegister($auth);
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->changePassword($pair['userId'],['oldPassword'=>'wrong','newPassword'=>'ChangedPass8395']));
    same($pair['userId'],$sessions->authenticate($pair['accessToken'])['id']);same(0,$db->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$pair['userId']])['n']);
});
test('password transaction completion failure rolls back hash and every local revocation',function(){
    [$db,$sessions,,$auth]=localServices();[$pair,$email]=localRegister($auth);$u=$pair['userId'];$before=$db->one('SELECT * FROM users WHERE id=?',[$u]);$fn='password_fail_'.str_replace('-','',$u);
    $db->execute("CREATE FUNCTION $fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.id='$u'::uuid THEN RAISE EXCEPTION 'injected password commit'; END IF; RETURN NEW; END $$");
    $db->execute("CREATE CONSTRAINT TRIGGER $fn AFTER UPDATE ON users DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION $fn()");
    try{
        try{$auth->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);throw new RuntimeException('commit failure hidden');}catch(PDOException $e){truth(str_contains($e->getMessage(),'injected password commit'));}
    }finally{$db->execute("DROP TRIGGER $fn ON users");$db->execute("DROP FUNCTION $fn()");}
    [$fresh,$s,,$a]=localServices();same($before,$fresh->one('SELECT * FROM users WHERE id=?',[$u]));same($u,$s->authenticate($pair['accessToken'])['id']);same($u,$s->refresh($pair['refreshToken'])['userId']);
    same($u,$a->login(['email'=>$email,'password'=>'StrongPass7395'])['userId']);errorCode('INVALID_CREDENTIALS',fn()=>$a->login(['email'=>$email,'password'=>'ChangedPass8395']));
    same(0,$fresh->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$u])['n']);
});
test('password verification snapshot cannot overwrite an intervening security generation',function(){
    [$db,$sessions,,$auth]=localServices();[$pair]=localRegister($auth);$u=$pair['userId'];$before=$db->one('SELECT password_hash FROM users WHERE id=?',[$u]);
    $hasher=new VerificationBarrierHasher(function()use($u){[, $other]=localServices();$other->logout($u);});
    $racing=new AuthService($db,new ShopRepository($db),$sessions,$hasher);
    errorCode('PASSWORD_CHANGED',fn()=>$racing->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']));
    same($before,$db->one('SELECT password_hash FROM users WHERE id=?',[$u]));same(0,$db->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$u])['n']);
});
test('lost SQL connection after verification leaves no partially applied password change after restart',function(){
    [$db,$sessions,,$auth]=localServices();[$pair,$email]=localRegister($auth);$pid=$db->one('SELECT pg_backend_pid() AS pid')['pid'];
    $hasher=new VerificationBarrierHasher(function()use($pid){$observer=new Database(Env::get('DATABASE_URL'));truth($observer->one('SELECT pg_terminate_backend(?) AS killed',[$pid])['killed']);});
    $racing=new AuthService($db,new ShopRepository($db),$sessions,$hasher);
    try{$racing->changePassword($pair['userId'],['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);throw new RuntimeException('connection loss hidden');}catch(PDOException){}
    [$fresh,$s,,$a]=localServices();same($pair['userId'],$s->authenticate($pair['accessToken'])['id']);same($pair['userId'],$a->login(['email'=>$email,'password'=>'StrongPass7395'])['userId']);
    same(0,$fresh->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$pair['userId']])['n']);
});
runTests();
