<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\application\{LocalIdentityMigration,SessionService};
use Shop\domain\{Ids,PasswordHasher,Secret};
use Shop\infrastructure\Database;
use Shop\repositories\ShopRepository;

if(\Shop\infrastructure\Env::production() || !preg_match('/^shopkmp-[a-z0-9-]+-e2e$/D',getenv('LOCAL_AUTH_DISPOSABLE')?:'')){throw new RuntimeException('Explicit disposable database opt-in required');}
$database=getenv('LOCAL_AUTH_TEST_DATABASE')?:'shopkmp';
if(!in_array($database,['shopkmp','local_auth016_fresh'],true)){throw new RuntimeException('Unrecognized disposable database');}
$url=getenv('DATABASE_URL')?:'postgres://shopkmp:'.rawurlencode((string)getenv('POSTGRES_PASSWORD')).'@postgres:5432/'.$database.'?sslmode=disable';
putenv('DATABASE_URL='.$url);
$db=new Database($url);$repo=new ShopRepository($db);$sessions=new SessionService($db,$repo);$hasher=new PasswordHasher();
function migrationService(?PasswordHasher $hasher=null,?Database $connection=null): LocalIdentityMigration {
    global $db,$sessions;$connection??=$db;
    return new LocalIdentityMigration($connection,new SessionService($connection,new ShopRepository($connection)),$hasher??new PasswordHasher());
}
function migrationUser(string $hash='',bool $admin=false,bool $disabled=false): string {
    global $db;$id=Ids::uuid();
    $db->execute('INSERT INTO users(id,email,password_hash,full_name,phone,is_admin,account_disabled,local_identity_ready) VALUES(?,?,?,?,?,?,?,FALSE)',[$id,$id.'@migration.example',$hash,'Migration fixture','+79991234567',$admin,$disabled]);
    $db->execute('INSERT INTO cart_metadata(user_id,updated_at) VALUES(?,to_timestamp(0))',[$id]);return $id;
}
function migrationTicket(): string {return 'MIG-'.random_int(100000000,999999999);}
function migrationPending(string $id,string $state='RESET_PENDING'): string {
    global $db;$op=Ids::uuid();$db->execute('UPDATE users SET sessions_blocked=TRUE WHERE id=?',[$id]);
    $db->execute('INSERT INTO password_session_operations(id,user_id,security_version,state) SELECT ?,id,session_security_version,? FROM users WHERE id=?',[$op,$state,$id]);return $op;
}
if(($argv[1]??'')==='create-fresh'){
    if(!$db->one("SELECT datname FROM pg_database WHERE datname='local_auth016_fresh'")){$db->execute('CREATE DATABASE local_auth016_fresh');}
    print "Disposable fresh database available\n";exit;
}
if(($argv[1]??'')==='worker-attest'){
    try{
        $db->one("SELECT set_config('application_name',?,false)",['migration-'.$argv[2]]);
        print json_encode(migrationService()->attest($argv[2],$argv[3],false,false,true),JSON_THROW_ON_ERROR);exit;
    }catch(\Shop\domain\ApiError $e){fwrite(STDERR,$e->errorCode."\n");exit(2);}
    catch(PDOException $e){fwrite(STDERR,'WORKER_SQLSTATE_'.(preg_match('/^[A-Z0-9]{5}$/D',(string)$e->getCode())?$e->getCode():'UNKNOWN')."\n");exit(2);}
}
if(($argv[1]??'')==='tty-probe'){
    exec('stty -g < /dev/tty',$before);$code=0;
    try{$password=\Shop\controllers\AuthPasswordInput::read();truth($password==='NativeTtyPass7395');print "TTY_OK\n";}
    catch(Throwable $e){print in_array($e->getMessage(),['TTY_REQUIRED','TTY_CANCELLED','TTY_PASSWORD_MISMATCH'],true)?$e->getMessage()."\n":"TTY_FAILED\n";$code=2;}
    exec('stty -g < /dev/tty',$after);same($before,$after);print "TTY_RESTORED\n";exit($code);
}
if(($argv[1]??'')==='tty-target'){
    print json_encode(['user'=>migrationUser(),'ticket'=>migrationTicket()],JSON_THROW_ON_ERROR);exit;
}
if(($argv[1]??'')==='tty-verify'){
    $u=$repo->user($argv[2]);truth(password_verify('NativeTtyPass7395',$u['password_hash']));
    $j=$db->one('SELECT action FROM local_identity_migration_journal WHERE user_id=? AND ticket=?',[$argv[2],$argv[3]]);same('reset-password',$j['action']);print "TTY_RESET_VERIFIED\n";exit;
}
// Populated-015 fixture is deliberately created BEFORE the existing migrator applies 016.
if(($argv[1]??'')==='seed015'){
    same(15,$db->one('SELECT max(version)::int AS n FROM schema_migrations')['n']);
    $db->execute("INSERT INTO users(id,email,password_hash,full_name,phone,local_identity_ready) VALUES('a063ef28-1000-4000-8000-000000000016','preserve016@migration.example','','Preserve UUID','+79991234567',FALSE) ON CONFLICT DO NOTHING");
    $db->execute("INSERT INTO cart_metadata(user_id,updated_at) VALUES('a063ef28-1000-4000-8000-000000000016',to_timestamp(0)) ON CONFLICT DO NOTHING");
    print "Populated schema015 fixture ready; no identity changed\n";exit;
}
test('an independently investigated orphan fence has an explicit owner-verified atomic reset path',function()use($db,$repo,$sessions){
    $id=migrationUser();$db->execute('UPDATE users SET sessions_blocked=TRUE WHERE id=?',[$id]);
    $db->execute("INSERT INTO login_attempts(user_id,failed_attempts,locked_until,last_attempt) VALUES(?,5,clock_timestamp()+interval '15 minutes',clock_timestamp())",[$id]);
    migrationService()->attest($id,migrationTicket(),false,false,true);
    $ticket=migrationTicket();
    migrationService()->resetPassword($id,$ticket,'VerifiedOwner7395',true,true,orphanConfirmed:true);
    $u=$repo->user($id);same(false,$u['sessions_blocked']);truth(password_verify('VerifiedOwner7395',$u['password_hash']));
    same('already_applied',migrationService()->resetPassword($id,$ticket,'VerifiedOwner7395',true,true,orphanConfirmed:true)['status']);
    same($id,$sessions->issue($id,'password')['userId']);
    same(0,$db->one('SELECT count(*) AS n FROM login_attempts WHERE user_id=?',[$id])['n']);
    $journal=$db->one('SELECT request,sessions_blocked FROM local_identity_migration_journal WHERE ticket=?',[$ticket]);
    same(true,json_decode($journal['request'],true,32,JSON_THROW_ON_ERROR)['orphanConfirmed']);same(false,$journal['sessions_blocked']);
});
test('orphan reset confirmation cannot bypass a pending operation or a ban',function()use($db,$repo){
    $id=migrationUser('',false,true);migrationPending($id);
    $before=$repo->user($id);errorCode('MIGRATION_OPERATION_MISMATCH',fn()=>migrationService()->resetPassword($id,migrationTicket(),'VerifiedOwner7395',true,true,orphanConfirmed:true));same($before,$repo->user($id));
    $orphan=migrationUser('',false,true);$db->execute('UPDATE users SET sessions_blocked=TRUE WHERE id=?',[$orphan]);
    migrationService()->resetPassword($orphan,migrationTicket(),'VerifiedOwner7395',true,true,orphanConfirmed:true);
    same(true,$repo->user($orphan)['account_disabled']);same(false,$repo->user($orphan)['local_identity_ready']);same(false,$repo->user($orphan)['sessions_blocked']);
});
test('016 upgrades populated schema and creates an append-only journal without changing existing identities',function()use($db){
    same(16,$db->one('SELECT max(version)::int AS n FROM schema_migrations')['n']);
    truth($db->one("SELECT to_regclass('local_identity_migration_journal') IS NOT NULL AS present")['present']);
    $u=$db->one("SELECT * FROM users WHERE id='a063ef28-1000-4000-8000-000000000016'");
    if($u!==null){same(false,$u['local_identity_ready']);same('',$u['password_hash']);truth($db->one('SELECT user_id FROM cart_metadata WHERE user_id=?',[$u['id']])!==null);}
});
test('inventory is count-only and runs with a read-only transaction including every hash and credential category',function()use($db,$hasher){
    $schema='migration_counts_'.bin2hex(random_bytes(6));$db->execute('CREATE SCHEMA '.$schema);
    try{
    foreach(['users','session_families','password_session_operations','device_pins','device_biometrics','cart_metadata'] as $table){$db->execute('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');}
    $db->execute('SET search_path TO '.$schema.',public');
    $before=migrationService()->inventory();
    foreach(['','unrecognized',$hasher->hash('StrongPass7395'),Secret::hash('StrongPass7395')] as $hash){migrationUser($hash);}
    $id=migrationUser('',true,true);migrationPending($id);
    $db->execute('INSERT INTO device_pins(id,user_id,device_id,device_name,pin_hash) VALUES(?,?,?,?,?)',[Ids::uuid(),$id,Ids::uuid(),'Fixture',Secret::hash('7395')]);
    $db->execute("INSERT INTO device_biometrics(id,user_id,device_id,device_name,public_key,key_algorithm) VALUES(?,?,?,?,?,'EdDSA')",[Ids::uuid(),$id,Ids::uuid(),'Fixture','public-test-key']);
    $db->execute("INSERT INTO session_families(id,user_id,security_version,credential_kind) VALUES(?,?,0,'password')",[Ids::uuid(),$id]);
    $db->execute('SET default_transaction_read_only=on');
    try{$after=migrationService()->inventory();}finally{$db->execute('SET default_transaction_read_only=off');}
    same(5,$after['users']-$before['users']);same(5,$after['unready']-$before['unready']);
    foreach(['native'=>1,'legacy'=>1,'empty'=>2,'unknown'=>1] as $kind=>$count){same($count,$after['passwords'][$kind]-$before['passwords'][$kind]);}
    foreach(['admin','disabled','pending_recovery','families','pin','biometric'] as $key){same(1,$after[$key]-$before[$key]);}
    same(0,$after['conflicting_normalized_email_groups']);same(0,$after['conflicting_normalized_email_users']);
    $walk=function(array $values)use(&$walk){foreach($values as $value){if(is_array($value)){$walk($value);}else{truth(is_int($value));}}};$walk($after);
    }finally{$db->execute('SET search_path TO public');$db->execute('DROP SCHEMA '.$schema.' CASCADE');}
});
test('pre015 inventory reports conflicting normalized addresses without exporting or changing them',function()use($db){
    $schema='migration_inventory_'.bin2hex(random_bytes(6));$db->execute('CREATE SCHEMA '.$schema);
    try{
        foreach(['users','session_families','password_session_operations','device_pins','device_biometrics'] as $table){$db->execute('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');}
        $db->execute('ALTER TABLE '.$schema.'.users DROP COLUMN account_disabled,DROP COLUMN local_identity_ready,DROP COLUMN password_changed_at');
        $db->execute('SET search_path TO '.$schema.',public');
        foreach(['Conflict@Migration.example',' conflict@migration.example '] as $email){$db->execute('INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,?,?,?)',[Ids::uuid(),$email,'','Fixture','+79991234567']);}
        $r=migrationService()->inventory();same(1,$r['conflicting_normalized_email_groups']);same(2,$r['conflicting_normalized_email_users']);same(2,$r['unready']);same(2,$r['users']);
        same(2,$db->one('SELECT count(*)::int AS n FROM users')['n']);truth(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'Migration.example'));
    }finally{$db->execute('SET search_path TO public');$db->execute('DROP SCHEMA '.$schema.' CASCADE');}
});
test('attestation requires apply and explicit states, preserves business UUID links, revokes sessions and replays once',function()use($db,$repo,$sessions){
    $id=migrationUser('',true);$ticket=migrationTicket();$before=$repo->user($id);
    errorCode('MIGRATION_APPLY_REQUIRED',fn()=>migrationService()->attest($id,$ticket,false,false));same($before,$repo->user($id));
    $r=migrationService()->attest($id,$ticket,false,false,true);same('applied',$r['status']);
    $u=$repo->user($id);same($id,$u['id']);same(false,$u['is_admin']);same(false,$u['account_disabled']);same(true,$u['local_identity_ready']);same($before['session_security_version']+1,$u['session_security_version']);
    truth($db->one('SELECT user_id FROM cart_metadata WHERE user_id=?',[$id])!==null);
    same('already_applied',migrationService()->attest($id,$ticket,false,false,true)['status']);same($u,$repo->user($id));
    errorCode('MIGRATION_TICKET_CONFLICT',fn()=>migrationService()->attest($id,$ticket,true,false,true));
    errorCode('MIGRATION_TICKET_CONFLICT',fn()=>migrationService()->setDisabled($id,$ticket,true,true));
    $other=migrationUser();errorCode('MIGRATION_TICKET_CONFLICT',fn()=>migrationService()->attest($other,$ticket,false,false,true));
    $pair=$sessions->issue($id,'password');migrationService()->attest($id,migrationTicket(),true,false,true);errorCode('UNAUTHORIZED',fn()=>$sessions->authenticate($pair['accessToken']));
});
test('operator selection rejects malformed UUIDs tickets weak passwords and caller-owned transactions without writing',function()use($db,$repo){
    $id=migrationUser();$ticket=migrationTicket();$before=$repo->user($id);
    errorCode('MIGRATION_UUID_REQUIRED',fn()=>migrationService()->attest('not-a-uuid',$ticket,false,false,true));
    foreach(['','MIG-0','free text','MIG-123 password'] as $bad){errorCode('MIGRATION_TICKET_REQUIRED',fn()=>migrationService()->attest($id,$bad,false,false,true));}
    errorCode('WEAK_PASSWORD',fn()=>migrationService()->resetPassword($id,$ticket,'weak',true,true));
    $db->pdo()->beginTransaction();
    try{try{migrationService()->resetPassword($id,$ticket,'StrongPass7395',true,true);throw new RuntimeException('caller transaction accepted');}catch(LogicException){}truth($db->pdo()->inTransaction());}
    finally{$db->pdo()->rollBack();}
    same($before,$repo->user($id));same(0,$db->one('SELECT count(*)::int AS n FROM local_identity_migration_journal WHERE ticket=?',[$ticket])['n']);
});
test('disabled identities remain disabled through attestation and password setup until separate reviewed state operation',function()use($repo){
    $id=migrationUser('',true,true);
    errorCode('MIGRATION_STATE_REVIEW_REQUIRED',fn()=>migrationService()->attest($id,migrationTicket(),false,false,true));
    migrationService()->attest($id,migrationTicket(),false,true,true);
    migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true);
    same(true,$repo->user($id)['account_disabled']);
    migrationService()->setDisabled($id,migrationTicket(),false,true);same(false,$repo->user($id)['account_disabled']);
});
test('trusted registration remains ordinary until a console operator attests the admin role and revokes its old pair',function()use($db,$repo,$sessions){
    $auth=new \Shop\application\AuthService($db,$repo,$sessions);$pair=$auth->register(['email'=>Ids::uuid().'@bootstrap.example','password'=>'StrongPass7395','fullName'=>'Operator','phone'=>'+79991234567']);
    same(false,$repo->user($pair['userId'])['is_admin']);
    migrationService()->attest($pair['userId'],migrationTicket(),true,false,true);
    same(true,$repo->user($pair['userId'])['is_admin']);errorCode('UNAUTHORIZED',fn()=>$sessions->authenticate($pair['accessToken']));
});
test('operator migration preserves nonempty order payment review cart and address links',function()use($db){
    $id=migrationUser();$category=Ids::uuid();$product=Ids::uuid();$address=Ids::uuid();$order=Ids::uuid();$payment=Ids::uuid();$review=Ids::uuid();
    $db->execute("INSERT INTO categories(id,name) VALUES(?,'Migration fixture')",[$category]);
    $db->execute("INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Migration product',10.01,5)",[$product,$category]);
    $db->execute("INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'City','Street','1')",[$address,$id]);
    $db->execute('UPDATE users SET default_address_id=? WHERE id=?',[$address,$id]);
    $db->execute("INSERT INTO orders(id,user_id,status,total_amount,delivery_address_id,delivery_address,payment_method) VALUES(?,?,'CREATED',10.01,?,'City Street 1','CARD')",[$order,$id,$address]);
    $db->execute("INSERT INTO payments(id,order_id,user_id,method,status,amount) VALUES(?,?,?,'CARD','PENDING',10.01)",[$payment,$order,$id]);
    $db->execute('UPDATE orders SET payment_id=? WHERE id=?',[$payment,$order]);
    $db->execute("INSERT INTO reviews(id,user_id,product_id,rating,text) VALUES(?,?,?,5,'Preserved')",[$review,$id,$product]);
    $db->execute('INSERT INTO cart_items(user_id,product_id,quantity,price_snapshot) VALUES(?,?,1,10.01)',[$id,$product]);
    $snapshot=function()use($db,$id): array {
        $out=[];foreach(['addresses','orders','payments','reviews','cart_items','cart_metadata'] as $table){$out[$table]=$db->all('SELECT row_to_json(t)::text AS data FROM '.$table.' t WHERE user_id=?',[$id]);}
        $out['defaultAddress']=$db->one('SELECT default_address_id FROM users WHERE id=?',[$id]);return $out;
    };
    $before=$snapshot();migrationService()->attest($id,migrationTicket(),false,false,true);migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true);same($before,$snapshot());
});
test('password reset hashes outside SQL and commits hash, all-family revocation and secret-free journal atomically',function()use($db,$repo,$sessions){
    $id=migrationUser();migrationService()->attest($id,migrationTicket(),false,false,true);$pair=$sessions->issue($id,'password');$ticket=migrationTicket();
    errorCode('MIGRATION_OWNERSHIP_REQUIRED',fn()=>migrationService()->resetPassword($id,$ticket,'StrongPass7395',false,true));
    $hasher=new class($db) extends PasswordHasher {
        public function __construct(private Database $db){parent::__construct();}
        public function hash(string $password): string {same(false,$this->db->pdo()->inTransaction());return parent::hash($password);}
        public function verify(string $password,string $hash): bool {same(false,$this->db->pdo()->inTransaction());return parent::verify($password,$hash);}
    };
    migrationService($hasher)->resetPassword($id,$ticket,'StrongPass7395',true,true);
    $u=$repo->user($id);truth(password_verify('StrongPass7395',$u['password_hash']));truth($u['password_changed_at']!==null);
    errorCode('UNAUTHORIZED',fn()=>$sessions->authenticate($pair['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$sessions->refresh($pair['refreshToken']));
    $journal=$db->one('SELECT row_to_json(j)::text AS data FROM local_identity_migration_journal j WHERE ticket=?',[$ticket])['data'];
    truth(!str_contains($journal,'StrongPass7395'));truth(!str_contains($journal,$u['password_hash']));truth(!str_contains($journal,'password_hash'));
    errorCode('MIGRATION_TICKET_CONFLICT',fn()=>migrationService()->resetPassword($id,$ticket,'DifferentPass8395',true,true));same($u,$repo->user($id));
    same('already_applied',migrationService($hasher)->resetPassword($id,$ticket,'StrongPass7395',true,true)['status']);same($u,$repo->user($id));
    migrationService()->resetPassword($id,migrationTicket(),'NewerPass8395',true,true);$newer=$repo->user($id);
    errorCode('MIGRATION_TICKET_SUPERSEDED',fn()=>migrationService()->resetPassword($id,$ticket,'StrongPass7395',true,true));same($newer,$repo->user($id));truth(password_verify('NewerPass8395',$newer['password_hash']));
    try{$db->execute('DELETE FROM local_identity_migration_journal WHERE ticket=?',[$ticket]);throw new RuntimeException('journal delete accepted');}catch(PDOException $e){same('P0001',$e->getCode());}
    try{$db->execute('UPDATE local_identity_migration_journal SET ticket=? WHERE ticket=?',[migrationTicket(),$ticket]);throw new RuntimeException('journal rewrite accepted');}catch(PDOException $e){same('P0001',$e->getCode());}
});
test('a concurrent reset winning the same ticket rejects a different secret without retaining a password fingerprint',function()use($db,$repo){
    $id=migrationUser();$ticket=migrationTicket();$other=new Database((string)getenv('DATABASE_URL'));
    $h=new class($other,$id,$ticket) extends PasswordHasher {
        public function __construct(private Database $other,private string $id,private string $ticket){parent::__construct();}
        public function hash(string $password): string {
            migrationService(new PasswordHasher(),$this->other)->resetPassword($this->id,$this->ticket,'WinningPass7395',true,true);
            return parent::hash($password);
        }
    };
    errorCode('MIGRATION_TICKET_CONFLICT',fn()=>migrationService($h)->resetPassword($id,$ticket,'LosingPass8395',true,true));
    truth(password_verify('WinningPass7395',$repo->user($id)['password_hash']));same(1,$db->one('SELECT count(*)::int AS n FROM local_identity_migration_journal WHERE ticket=?',[$ticket])['n']);
});
test('password reset detects concurrent credential and account changes rather than overwriting a stale snapshot',function()use($db,$repo){
    foreach(['password_hash','session_security_version','account_disabled','is_admin','email'] as $field){
        $id=migrationUser();$other=new Database((string)getenv('DATABASE_URL'));
        $h=new class($other,$id,$field) extends PasswordHasher {
            public function __construct(private Database $other,private string $id,private string $field){parent::__construct();}
            public function hash(string $password): string {
                $sql=match($this->field){'password_hash'=>"password_hash='concurrent-value'",'email'=>"email='concurrent-'||email",'session_security_version'=>'session_security_version=session_security_version+1',default=>$this->field.'=TRUE'};
                $this->other->execute('UPDATE users SET '.$sql.' WHERE id=?',[$this->id]);return parent::hash($password);
            }
        };
        $ticket=migrationTicket();errorCode('MIGRATION_STALE_SNAPSHOT',fn()=>migrationService($h)->resetPassword($id,$ticket,'StrongPass7395',true,true));
        same(0,$db->one('SELECT count(*)::int AS n FROM local_identity_migration_journal WHERE ticket=?',[$ticket])['n']);
        same(false,password_verify('StrongPass7395',$repo->user($id)['password_hash']));
    }
});
test('pending recovery is selected per operation and requires identity and outcome confirmation before clearing only its fence',function()use($db,$repo){
    $id=migrationUser('',false,true);$op=migrationPending($id);$other=migrationUser();$foreign=migrationPending($other);
    migrationService()->attest($id,migrationTicket(),false,true,true);same(true,$repo->user($id)['sessions_blocked']);
    migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true);same('RESET_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$op])['state']);same(true,$repo->user($id)['sessions_blocked']);
    errorCode('MIGRATION_RECOVERY_CONFIRMATION_REQUIRED',fn()=>migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true,$op,'unknown-reset',false));
    errorCode('MIGRATION_RECOVERY_OUTCOME_REQUIRED',fn()=>migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true,$op,'guess',true));
    errorCode('MIGRATION_OPERATION_MISMATCH',fn()=>migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true,$foreign,'unknown-reset',true));
    $ticket=migrationTicket();migrationService()->resetPassword($id,$ticket,'StrongPass7395',true,true,$op,'unknown-reset',true);
    same(false,$repo->user($id)['sessions_blocked']);same(true,$repo->user($id)['account_disabled']);
    $resolved=$db->one('SELECT * FROM password_session_operations WHERE id=?',[$op]);same('COMPLETE',$resolved['state']);same($ticket,$resolved['operator_evidence_ref']);truth($resolved['operator_confirmed_at']!==null);
    same('RESET_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$foreign])['state']);same(true,$repo->user($other)['sessions_blocked']);
    errorCode('MIGRATION_TICKET_CONFLICT',fn()=>migrationService()->resetPassword($id,$ticket,'StrongPass7395',true,true,$op,'confirmed-reset',true));
});
test('revoke-pending requires a confirmed historical outcome and an orphan fence is never implicitly cleared',function()use($db,$repo){
    $id=migrationUser();$op=migrationPending($id,'REVOKE_PENDING');
    errorCode('MIGRATION_RECOVERY_OUTCOME_REQUIRED',fn()=>migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true,$op,'unknown-reset',true));
    migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true,$op,'confirmed-reset',true);same(false,$repo->user($id)['sessions_blocked']);
    errorCode('MIGRATION_OPERATION_MISMATCH',fn()=>migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true,$op,'confirmed-reset',true));
    $orphan=migrationUser();$db->execute('UPDATE users SET sessions_blocked=TRUE WHERE id=?',[$orphan]);migrationService()->resetPassword($orphan,migrationTicket(),'StrongPass7395',true,true);same(true,$repo->user($orphan)['sessions_blocked']);
});
test('selected recovery leaves a second unresolved operation and the current fence intact',function()use($db){
    // Isolate a historical backlog with two operations; the current public schema's
    // partial unique index normally prevents this, so do not weaken that live index.
    $schema='migration_recovery_'.bin2hex(random_bytes(6));$db->execute('CREATE SCHEMA '.$schema);
    try{
        foreach(['users','cart_metadata','session_families','access_sessions','refresh_sessions','password_session_operations','local_identity_migration_journal'] as $table){$db->execute('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');}
        $db->execute('SET search_path TO '.$schema.',public');$id=migrationUser();$selected=migrationPending($id);$remaining=migrationPending($id,'REVOKE_PENDING');
        migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true,$selected,'unknown-reset',true);
        same('COMPLETE',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$selected])['state']);
        same('REVOKE_PENDING',$db->one('SELECT state FROM password_session_operations WHERE id=?',[$remaining])['state']);
        same(true,$db->one('SELECT sessions_blocked FROM users WHERE id=?',[$id])['sessions_blocked']);
    }finally{$db->execute('SET search_path TO public');$db->execute('DROP SCHEMA '.$schema.' CASCADE');}
});
test('concurrent identical apply requests serialize at the common user lock and append exactly one decision',function()use($db,$repo){
    $id=migrationUser();$ticket=migrationTicket();$before=$repo->user($id);$workers=[];
    $db->pdo()->beginTransaction();$db->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$id]);
    try{
        for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,__FILE__,'worker-attest',$id,$ticket],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$p,$pipes];}
        $deadline=microtime(true)+5;$waiting=0;
        do{
            $db->one('SELECT pg_stat_clear_snapshot()');
            $waiting=$db->one("SELECT count(*)::int AS n FROM pg_stat_activity WHERE datname=current_database() AND application_name=? AND wait_event_type='Lock'",['migration-'.$id])['n'];
            if($waiting===2){break;}usleep(20000);
        }while(microtime(true)<$deadline);
        same(2,$waiting);
        $db->pdo()->commit();$statuses=[];
        foreach($workers as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);if($code!==0){throw new RuntimeException('Concurrent worker failed: '.$err);}same('',$err);$statuses[]=json_decode($out,true,512,JSON_THROW_ON_ERROR)['status'];}
        sort($statuses);same(['already_applied','applied'],$statuses);same($before['session_security_version']+1,$repo->user($id)['session_security_version']);same(1,$db->one('SELECT count(*)::int AS n FROM local_identity_migration_journal WHERE ticket=?',[$ticket])['n']);
    }finally{if($db->pdo()->inTransaction()){$db->pdo()->rollBack();}}
});
test('audit failure rolls back password replacement and family revocation together',function()use($db,$repo,$sessions){
    $id=migrationUser();migrationService()->attest($id,migrationTicket(),false,false,true);$pair=$sessions->issue($id,'password');$before=$repo->user($id);
    $db->execute("CREATE FUNCTION pg_temp.reject_local_identity_audit() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test audit rejection''; END'");
    $db->execute('CREATE TRIGGER migration_test_reject BEFORE INSERT ON local_identity_migration_journal FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_local_identity_audit()');
    try{
        try{migrationService()->resetPassword($id,migrationTicket(),'StrongPass7395',true,true);throw new RuntimeException('audit failure ignored');}catch(PDOException $e){same('P0001',$e->getCode());}
    }finally{$db->execute('DROP TRIGGER migration_test_reject ON local_identity_migration_journal');}
    same($before,$repo->user($id));same($id,$sessions->authenticate($pair['accessToken'])['id']);
});
test('database permissions allow count inventory but reject apply without the operator journal capability',function()use($db){
    $role='migration_reader_'.bin2hex(random_bytes(6));$id=migrationUser();
    $db->execute('CREATE ROLE '.$role.' NOLOGIN');
    try{
        $db->execute('GRANT USAGE ON SCHEMA public TO '.$role);
        $db->execute('GRANT SELECT ON users,session_families,password_session_operations,device_pins,device_biometrics TO '.$role);
        $db->execute('SET ROLE '.$role);
        try{truth(migrationService()->inventory()['users']>0);errorCode('MIGRATION_OPERATOR_PERMISSION_REQUIRED',fn()=>migrationService()->attest($id,migrationTicket(),false,false,true));}
        finally{$db->execute('RESET ROLE');}
    }finally{$db->execute('DROP OWNED BY '.$role);$db->execute('DROP ROLE '.$role);}
});
test('real runtime and least-privileged operator roles enforce the documented reset boundary',function()use($db,$repo){
    $runtime='migration_runtime_'.bin2hex(random_bytes(6));$operator='migration_operator_'.bin2hex(random_bytes(6));
    $runtimeDb=new Database(getenv('DATABASE_URL'));$operatorDb=new Database(getenv('DATABASE_URL'));
    foreach([$runtime,$operator] as $role){$db->execute('CREATE ROLE '.$role.' NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE');$db->execute('GRANT USAGE ON SCHEMA public TO '.$role);}
    try{
        $db->execute('GRANT SELECT,INSERT,UPDATE,DELETE ON users,cart_metadata,session_families,access_sessions,refresh_sessions,login_attempts,rate_limit_hits TO '.$runtime);
        $db->execute('GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO '.$runtime);
        $runtimeDb->execute('SET SESSION AUTHORIZATION '.$runtime);$r=new ShopRepository($runtimeDb);$s=new SessionService($runtimeDb,$r);$a=new \Shop\application\AuthService($runtimeDb,$r,$s);
        $email=Ids::uuid().'@migration.example';$pair=$a->register(['email'=>$email,'password'=>'OriginalOwner7395','fullName'=>'Role fixture','phone'=>'+79991234567']);$id=$pair['userId'];
        errorCode('MIGRATION_OPERATOR_PERMISSION_REQUIRED',fn()=>(new LocalIdentityMigration($runtimeDb,$s))->attest($id,migrationTicket(),true,false,true));
        $db->execute('GRANT SELECT,UPDATE ON users,password_session_operations,session_families TO '.$operator);
        $db->execute('GRANT SELECT,DELETE ON access_sessions,refresh_sessions TO '.$operator);
        $db->execute('GRANT SELECT,INSERT ON local_identity_migration_journal TO '.$operator);
        $db->execute('GRANT DELETE ON login_attempts TO '.$operator);
        $operatorDb->execute('SET SESSION AUTHORIZATION '.$operator);$migration=migrationService(connection:$operatorDb);$ticket=migrationTicket();$before=$repo->user($id);
        try{$migration->resetPassword($id,$ticket,'ReplacementOwner8395',true,true);throw new RuntimeException('Missing SELECT(user_id) privilege was ignored');}catch(PDOException $error){same('42501',$error->getCode());}
        same($before,$repo->user($id));same($id,$s->authenticate($pair['accessToken'])['id']);
        $db->execute('GRANT SELECT(user_id) ON login_attempts TO '.$operator);
        same('applied',$migration->resetPassword($id,$ticket,'ReplacementOwner8395',true,true)['status']);
        same($operator,$db->one('SELECT applied_by FROM local_identity_migration_journal WHERE ticket=?',[$ticket])['applied_by']);
        errorCode('UNAUTHORIZED',fn()=>$s->authenticate($pair['accessToken']));same($id,$a->login(['email'=>$email,'password'=>'ReplacementOwner8395'])['userId']);
    }finally{
        $runtimeDb->execute('RESET SESSION AUTHORIZATION');$operatorDb->execute('RESET SESSION AUTHORIZATION');
        foreach([$runtime,$operator] as $role){$db->execute('DROP OWNED BY '.$role);$db->execute('DROP ROLE '.$role);}
    }
});
test('console entry point rejects missing explicit flags and piped password input without revealing input',function(){
    $run=function(array $args,string $stdin=''): array {
        $p=proc_open(array_merge([PHP_BINARY,dirname(__DIR__).'/yii'], $args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fwrite($pipes[0],$stdin);fclose($pipes[0]);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out];
    };
    [$code,$out]=$run(['auth/inventory']);same(0,$code);truth(is_array(json_decode($out,true,512,JSON_THROW_ON_ERROR)));
    [$code,$out]=$run(['auth/attest',Ids::uuid(),migrationTicket(),'--apply=yes']);truth($code!==0);truth(str_contains($out,'MIGRATION_EXPLICIT_STATE_REQUIRED'));
    [$code,$out]=$run(['auth/reset-password',Ids::uuid(),migrationTicket(),'--apply=yes','--ownership=verified-out-of-app'],"NeverLogPass7395\n");truth($code!==0);truth(str_contains($out,'TTY_REQUIRED'));truth(!str_contains($out,'NeverLogPass7395'));
});
runTests();
