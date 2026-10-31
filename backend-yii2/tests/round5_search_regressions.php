<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/SearchNativeFixture.php';
SearchNativeFixture::configure();

// A selected, explicitly initialized private index; no old/default index is used.
$db=SearchNativeFixture::db(true);$name=SearchNativeFixture::name();SearchNativeFixture::search($db,$name)->bootstrap(100,20);
putenv('OPENSEARCH_PRODUCTS_INDEX='.$name);
foreach(['search.php','round3_reads.php','round4_catalog.php','round4_index.php'] as $file){
    print "SEARCH REGRESSION $file\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file),$status);
    if($status!==0){exit($status);}
}
