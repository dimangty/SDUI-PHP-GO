<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\infrastructure\{Database,Env};
test('development seed is registered and cannot write in production',function(){
    $config=require dirname(__DIR__).'/config/console.php';
    truth(isset($config['controllerMap']['development-seed']));
    exec('APP_ENV=production '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/yii').' development-seed/run 2>&1',$out,$status);
    same(1,$status);truth(str_contains(implode("\n",$out),'forbidden in production'));
});
test('explicit seed leaves any populated catalog byte-for-byte unchanged',function(){
    $db=new Database(Env::get('DATABASE_URL'));
    truth($db->one('SELECT id FROM products LIMIT 1')!==null);
    $before=$db->all('SELECT row_to_json(p)::text AS data FROM products p ORDER BY id');
    $categories=$db->all('SELECT row_to_json(c)::text AS data FROM categories c ORDER BY id');
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/yii').' development-seed/run 2>&1',$out,$status);
    same(0,$status);truth(str_contains(implode("\n",$out),'no changes'));
    same($before,$db->all('SELECT row_to_json(p)::text AS data FROM products p ORDER BY id'));
    same($categories,$db->all('SELECT row_to_json(c)::text AS data FROM categories c ORDER BY id'));
});
runTests();
