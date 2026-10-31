<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/LocalAuthFixture.php';
use Shop\application\AuthService;
use Shop\domain\Ids;

[$db,$sessions,$credentials,$auth]=localServices();
test('local registration login single-use refresh and logout preserve token contract',function()use($auth){
    [$pair,$email]=localRegister($auth);truth(str_starts_with($pair['accessToken'],'app_access_'));same($pair['userId'],$auth->authenticate($pair['accessToken'])['id']);
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>$email,'password'=>'wrong']));
    $login=$auth->login(['email'=>' '.strtoupper($email).' ','password'=>'StrongPass7395']);same($pair['userId'],$login['userId']);
    $next=$auth->refresh(['refreshToken'=>$login['refreshToken']]);same($pair['userId'],$next['userId']);
    errorCode('INVALID_REFRESH_TOKEN',fn()=>$auth->refresh(['refreshToken'=>$login['refreshToken']]));
    $bad=$next['accessToken'];$bad[strlen($bad)-1]=$bad[strlen($bad)-1]==='a'?'b':'a';errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($bad));
    $auth->logout($pair['userId'],['refreshToken'=>$next['refreshToken']]);
    foreach([$pair,$login,$next] as $old){errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($old['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$auth->refresh(['refreshToken'=>$old['refreshToken']]));}
});
test('public registration never bootstraps admin even with legacy bootstrap argument',function()use($auth,$db){
    $email=Ids::uuid().'@test.example';$pair=$auth->register(['email'=>$email,'password'=>'StrongPass7395','fullName'=>'Admin','phone'=>'+79991234567'],'untrusted-bootstrap');
    same(false,$db->one('SELECT is_admin FROM users WHERE id=?',[$pair['userId']])['is_admin']);
    same(false,$auth->authenticate($pair['accessToken'])['is_admin']);
});
test('local password change revokes every previous family and leaves reviewed PIN available',function()use($auth,$credentials,$db,$sessions){
    [$pair,$email]=localRegister($auth);$old=[$pair];
    foreach([Ids::uuid(),Ids::uuid()] as $device){$credentials->setPin($pair['userId'],['deviceId'=>$device,'deviceName'=>'Phone','pin'=>'7395']);$old[]=$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395']);}
    $old[]=$sessions->refresh($old[1]['refreshToken']);
    $auth->changePassword($pair['userId'],['oldPassword'=>'StrongPass7395','newPassword'=>'ChangedPass8395']);
    foreach($old as $tokens){errorCode('UNAUTHORIZED',fn()=>$auth->authenticate($tokens['accessToken']));errorCode('INVALID_REFRESH_TOKEN',fn()=>$sessions->refresh($tokens['refreshToken']));}
    same(0,$db->one('SELECT count(*) AS n FROM password_session_operations WHERE user_id=?',[$pair['userId']])['n']);
    same(false,$db->one('SELECT sessions_blocked FROM users WHERE id=?',[$pair['userId']])['sessions_blocked']);
    same($pair['userId'],$credentials->loginPin(['deviceId'=>$device,'pin'=>'7395'])['userId']);
    errorCode('INVALID_CREDENTIALS',fn()=>$auth->login(['email'=>$email,'password'=>'StrongPass7395']));same($pair['userId'],$auth->login(['email'=>$email,'password'=>'ChangedPass8395'])['userId']);
});
runTests();
