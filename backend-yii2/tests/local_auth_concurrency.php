<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\domain\{ApiError,Ids,PasswordHasher,Secret};

/** Deterministic process barrier around real hashing, never a production hook. */
class ConcurrentPasswordHasher extends PasswordHasher {
    public function __construct(private string $phase){parent::__construct();}
    private function pause(): void {print "barrier\n";flush();if(fgets(STDIN)!=="go\n"){throw new RuntimeException('barrier closed');}}
    public function hash(string $password): string {$hash=parent::hash($password);if($this->phase==='hash'){$this->pause();}return $hash;}
    public function verify(string $password,string $hash): bool {$valid=parent::verify($password,$hash);if($this->phase==='verify'){$this->pause();}return $valid;}
}
if(($argv[1]??'')==='worker'){
    $input=json_decode(fgets(STDIN),true,32,JSON_THROW_ON_ERROR);[, , ,$auth]=localServices(new ConcurrentPasswordHasher($input['phase']));
    try{$result=$input['operation']==='register'?$auth->register($input['body']):$auth->login($input['body']);print json_encode(['ok'=>$result]);}
    catch(ApiError $e){print json_encode(['error'=>$e->errorCode]);}exit;
}
function hashingWorker(array $input): array {
    $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    foreach($pipes as $pipe){stream_set_timeout($pipe,15);}fwrite($pipes[0],json_encode($input)."\n");same("barrier\n",fgets($pipes[1]));return [$process,$pipes];
}
function finishHashingWorker(array $worker): array {
    [$process,$pipes]=$worker;fwrite($pipes[0],"go\n");fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);same(0,proc_close($process));same('',$errors);return json_decode($output,true,32,JSON_THROW_ON_ERROR);
}
test('real PostgreSQL concurrent registration has one normalized-email owner and no orphan cart or family',function(){
    [$db]=localServices();$email=Ids::uuid().'@test.example';$body=['email'=>$email,'password'=>'StrongPass7395','fullName'=>'Race Buyer','phone'=>'+79991234567'];
    $a=hashingWorker(['operation'=>'register','phase'=>'hash','body'=>$body]);$b=hashingWorker(['operation'=>'register','phase'=>'hash','body'=>array_replace($body,['email'=>' '.strtoupper($email).' '])]);
    // Release both before collecting either result: genuine competing SQL inserts.
    foreach([$a,$b] as [, $pipes]){fwrite($pipes[0],"go\n");}
    $results=[];foreach([$a,$b] as [$process,$pipes]){fclose($pipes[0]);$results[]=json_decode(stream_get_contents($pipes[1]),true,32,JSON_THROW_ON_ERROR);same('',stream_get_contents($pipes[2]));fclose($pipes[1]);fclose($pipes[2]);same(0,proc_close($process));}
    $winners=array_values(array_filter($results,fn($r)=>isset($r['ok'])));$losers=array_values(array_filter($results,fn($r)=>isset($r['error'])));same(1,count($winners));same([['error'=>'EMAIL_ALREADY_EXISTS']],$losers);
    $u=$winners[0]['ok']['userId'];same([['id'=>$u]],$db->all('SELECT id FROM users WHERE lower(btrim(email))=?',[$email]));
    same(1,$db->one('SELECT count(*) AS n FROM cart_metadata WHERE user_id=?',[$u])['n']);same(1,$db->one('SELECT count(*) AS n FROM session_families WHERE user_id=?',[$u])['n']);
});
foreach(['native','legacy-rehash'] as $kind){
    test('real PostgreSQL '.$kind.' login verified before password change cannot issue or overwrite new hash',function()use($kind){
        [$db,$sessions,,$auth]=localServices();$hash=$kind==='native'?(new PasswordHasher())->hash('StrongPass7395'):Secret::hash('StrongPass7395');$u=localUser($db,$hash);$email=$u.'@test.example';
        $worker=hashingWorker(['operation'=>'login','phase'=>'verify','body'=>['email'=>$email,'password'=>'StrongPass7395']]);
        $auth->changePassword($u,['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);$changed=$db->one('SELECT password_hash,session_security_version FROM users WHERE id=?',[$u]);
        same(['error'=>'INVALID_CREDENTIALS'],finishHashingWorker($worker));same($changed,$db->one('SELECT password_hash,session_security_version FROM users WHERE id=?',[$u]));
        same(0,$db->one('SELECT count(*) AS n FROM session_families WHERE user_id=?',[$u])['n']);same($u,$auth->login(['email'=>$email,'password'=>'ChangedPass8395'])['userId']);
    });
}
runTests();
