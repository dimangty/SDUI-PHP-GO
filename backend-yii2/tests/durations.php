<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\infrastructure\Env;
test('milliseconds preserve their real fractional-second unit',function(){
    foreach(['500ms'=>0.5,'1ms'=>0.001,'1500ms'=>1.5,'1000ms'=>1,'2s'=>2,'5m'=>300,'2h'=>7200] as $raw=>$expected){putenv('TEST_DURATION='.$raw);same($expected,Env::duration('TEST_DURATION',2));}
    putenv('TEST_DURATION');same(2,Env::duration('TEST_DURATION',2));
});
runTests();
