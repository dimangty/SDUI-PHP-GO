<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\domain\Rules;
test('enrollment rejects only dot segments in addition to existing ASCII and length policy',function(){
    foreach(['.','..','',str_repeat('x',256),'a/b','a\\b','%2e',' a','a ','a?b',"a\n",'я'] as $id){errorCode('INVALID_DEVICE_ID',fn()=>Rules::device($id));}
    foreach(['a.b','...','.A','A..b','a..B','-','_',str_repeat('A',255)] as $id){Rules::device($id);}
});
runTests();
