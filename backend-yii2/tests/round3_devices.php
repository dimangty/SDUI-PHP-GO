<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\application\{CredentialService,SessionService};
use Shop\domain\{Ids,Secret};
use Shop\infrastructure\{ApiKernel,Database,Env};
use Shop\repositories\ShopRepository;
$db=new Database(Env::get('DATABASE_URL'));$repo=new ShopRepository($db);$sessions=new SessionService($db,$repo);$credentials=new CredentialService($db,$repo,$sessions);
putenv('READ_RATE_LIMIT=10000');putenv('MUTATION_RATE_LIMIT=10000');
function deviceUser(): string {global $db;$u=Ids::uuid();$db->execute("INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Device Buyer','+79991234567')",[$u,$u.'@test.example']);return $u;}
function bioInput(string $device): array {return ['deviceId'=>$device,'deviceName'=>'Device','keyAlgorithm'=>'EdDSA','publicKey'=>base64_encode(hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))];}
function legacyDevice(string $user,string $kind,string $device): string {
    global $db;$id=Ids::uuid();
    // Intentionally bypass enrollment to represent existing production dot records.
    if($kind==='pin'){$db->execute("INSERT INTO device_pins(id,user_id,device_id,device_name,pin_hash) VALUES(?,?,?,'Legacy',?)",[$id,$user,$device,Secret::hash('7395')]);}
    else{$db->execute("INSERT INTO device_biometrics(id,user_id,device_id,device_name,public_key,key_algorithm) VALUES(?,?,?,'Legacy',decode(?,'hex'),'EdDSA')",[$id,$user,$device,bin2hex(hex2bin('302a300506032b6570032100').sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);}
    return $id;
}
foreach(['pin','biometric'] as $kind){
    test("$kind enrollment rejects dot segments invalid ASCII and 256 bytes; preserves case and 255 bytes",function()use($kind,$credentials,$db){
        $u=deviceUser();try{
        foreach(['.','..','',str_repeat('X',256),'a/b','a\\b','%2e','a?b','a#b',' a','a ',"a\n",'я'] as $device){
            errorCode('INVALID_DEVICE_ID',fn()=>$kind==='pin'?$credentials->setPin($u,['deviceId'=>$device,'deviceName'=>'Device','pin'=>'7395']):$credentials->enroll($u,bioInput($device)));
        }
        foreach(['A..'.Ids::uuid(),str_repeat('A',255)] as $device){
            if($kind==='pin'){$credentials->setPin($u,['deviceId'=>$device,'deviceName'=>'Device','pin'=>'7395']);$listed=$credentials->pinDevices($u);}
            else{$credentials->enroll($u,bioInput($device));$listed=$credentials->biometricDevices($u);}
            truth(in_array($device,array_column($listed,'deviceId'),true));
            if($kind==='pin'){$credentials->deletePin($u,$device);}else{$credentials->deleteBiometric($u,$device);}
        }
        }finally{$db->execute('DELETE FROM '.($kind==='pin'?'device_pins':'device_biometrics').' WHERE user_id=?',[$u]);}
    });
    foreach(['.','..'] as $device){test("$kind legacy $device query revoke is owner-only and revokes all credential session families",function()use($kind,$device,$db,$sessions,$credentials){
        $u=deviceUser();$other=deviceUser();$id=legacyDevice($u,$kind,$device);
        $one=$sessions->issue($u,$kind,$id);$two=$sessions->issue($u,$kind,$id);$unrelated=$sessions->issue($u);$foreign=$sessions->issue($other);
        $kernel=new ApiKernel($db);$path='/api/v1/auth/'.($kind==='pin'?'pin':'biometric').'?'.http_build_query(['deviceId'=>$device]);
        try{
            $r=$kernel->handle('DELETE',$path,['authorization'=>'Bearer '.$foreign['accessToken']],'','127.0.0.1');same(404,$r['status']);same($kind==='pin'?'PIN_NOT_SET':'BIOMETRIC_NOT_SET',$r['data']['error']['code']);
            same($u,$sessions->authenticate($one['accessToken'])['id']);
            foreach([$device,str_repeat('%2E',strlen($device)),str_repeat('%2e',strlen($device))] as $segment){
                $unsafe=$kernel->handle('DELETE','/api/v1/auth/'.($kind==='pin'?'pin':'biometric').'/devices/'.$segment,['authorization'=>'Bearer '.$unrelated['accessToken']],'','127.0.0.1');
                same(400,$unsafe['status']);same('INVALID_DEVICE_ID',$unsafe['data']['error']['code']);
            }
            same(204,$kernel->handle('DELETE',$path,['authorization'=>'Bearer '.$unrelated['accessToken']],'','127.0.0.1')['status']);
            foreach([$one,$two] as $tokens){errorCode('UNAUTHORIZED',fn()=>$sessions->authenticate($tokens['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$sessions->refresh($tokens['refreshToken']));}
            same($u,$sessions->authenticate($unrelated['accessToken'])['id']);same($other,$sessions->authenticate($foreign['accessToken'])['id']);
            same(2,$db->one('SELECT count(*) AS n FROM session_families WHERE credential_id=? AND revoked_at IS NOT NULL',[$id])['n']);
        }finally{$db->execute('DELETE FROM '.($kind==='pin'?'device_pins':'device_biometrics').' WHERE id=?',[$id]);}
    });}
}
test('controlled CLI dry-run counts and explicit owner kind device apply produce atomic audit',function()use($db,$sessions){
    $u=deviceUser();$other=deviceUser();$pin=legacyDevice($u,'pin','.');$bio=legacyDevice($u,'biometric','..');$old=$sessions->issue($u,'pin',$pin);
    $run=function(array $args): array {
        $p=proc_open([PHP_BINARY,dirname(__DIR__).'/yii',...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    try{
        [$exit,$out]=$run(['maintenance/legacy-devices']);same(0,$exit);same(['pin'=>1,'biometric'=>1],json_decode(trim($out),true,16,JSON_THROW_ON_ERROR));
        same(1,$db->one('SELECT count(*) AS n FROM device_pins WHERE id=?',[$pin])['n']);
        foreach([[$other,'pin','.','OPS-123'],[$u,'other','.','OPS-123'],[$u,'pin','normal','OPS-123'],[$u,'pin','.','']] as $args){[$exit]=$run(['maintenance/legacy-device-apply',...$args]);truth($exit!==0);}
        same(0,$db->one('SELECT count(*) AS n FROM credential_remediation_audit WHERE user_id=?',[$u])['n']);
        [$exit]=$run(['maintenance/legacy-device-apply',$u,'pin','.','OPS-123']);same(0,$exit);
        same(0,$db->one('SELECT count(*) AS n FROM device_pins WHERE id=?',[$pin])['n']);same(1,$db->one('SELECT count(*) AS n FROM device_biometrics WHERE id=?',[$bio])['n']);
        $audit=$db->one('SELECT user_id,credential_kind,credential_id,device_id,operator_evidence_ref FROM credential_remediation_audit WHERE user_id=?',[$u]);
        same(['user_id'=>$u,'credential_kind'=>'pin','credential_id'=>$pin,'device_id'=>'.','operator_evidence_ref'=>'OPS-123'],$audit);
        errorCode('UNAUTHORIZED',fn()=>$sessions->authenticate($old['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$sessions->refresh($old['refreshToken']));
        [$exit]=$run(['maintenance/legacy-device-apply',$u,'biometric','..','OPS-124']);same(0,$exit);same(2,$db->one('SELECT count(*) AS n FROM credential_remediation_audit WHERE user_id=?',[$u])['n']);
    }finally{$db->execute('DELETE FROM device_pins WHERE id=?',[$pin]);$db->execute('DELETE FROM device_biometrics WHERE id=?',[$bio]);}
});
test('audit failure rolls back credential deletion and every session revocation',function()use($db,$credentials,$sessions){
    $u=deviceUser();$id=legacyDevice($u,'pin','.');$tokens=$sessions->issue($u,'pin',$id);
    $suffix=str_replace('-','',Ids::uuid());$function='remediation_fail_'.$suffix;$trigger='remediation_fail_'.$suffix;
    $db->execute('CREATE FUNCTION '.$function.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'test-only audit failure\'; END $$');
    $db->execute('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON credential_remediation_audit FOR EACH ROW EXECUTE FUNCTION '.$function.'()');
    try{
        try{$credentials->remediateLegacyDevice($u,'pin','.','OPS-125');throw new RuntimeException('expected audit failure');}
        catch(PDOException $error){truth(str_contains($error->getMessage(),'test-only audit failure'));}
        same(1,$db->one('SELECT count(*) AS n FROM device_pins WHERE id=?',[$id])['n']);
        same($u,$sessions->authenticate($tokens['accessToken'])['id']);same($u,$sessions->refresh($tokens['refreshToken'])['userId']);
        same(0,$db->one('SELECT count(*) AS n FROM credential_remediation_audit WHERE user_id=?',[$u])['n']);
    }finally{$db->execute('DROP TRIGGER '.$trigger.' ON credential_remediation_audit');$db->execute('DROP FUNCTION '.$function.'()');$db->execute('DELETE FROM device_pins WHERE id=?',[$id]);}
});
runTests();
