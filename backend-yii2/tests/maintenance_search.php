<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/payment-fixture.php';
use Shop\domain\Ids;

function searchConsole(string $schema,string $index,array $args): array {
    $env=getenv();$env['PGOPTIONS']='-c search_path='.$schema;$env['OPENSEARCH_PRODUCTS_INDEX']=$index;
    $p=proc_open([PHP_BINARY,'/app/yii',...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
}
test('real console explicitly bootstraps empty SQL and exposes bounded stage inventory and reconciliation',function(){
    [$db,$schema]=paymentLegacySchema();$index='shopkmp-products-auth-cli-'.str_replace('-','',Ids::uuid());
    try{
        // Search needs only the original catalog schema; no identity schema writes.
        [$exit,$out,$err]=searchConsole($schema,$index,['maintenance/search-bootstrap','1','20']);same(0,$exit);same('',$err);$report=json_decode($out,true,32,JSON_THROW_ON_ERROR);same(true,$report['created']);same(0,$report['inventory']['documents']);
        [$exit,$out]=searchConsole($schema,$index,['maintenance/search-inventory','1','20']);same(0,$exit);same(0,json_decode($out,true,32,JSON_THROW_ON_ERROR)['documents']);
        [$exit,$out]=searchConsole($schema,$index,['maintenance/search-inventory','1','0.5']);same(0,$exit);same(0,json_decode($out,true,32,JSON_THROW_ON_ERROR)['documents']);
        [$exit,$out]=searchConsole($schema,$index,['maintenance/reindex','1','20']);same(0,$exit);truth(str_contains($out,'reconciled: 0'));
        [$exit,$out]=searchConsole($schema,$index,['maintenance/search-stage',$index.'-next','1','20']);same(0,$exit);same(false,json_decode($out,true,32,JSON_THROW_ON_ERROR)['switched']);
        foreach([['maintenance/search-bootstrap','0','20'],['maintenance/search-inventory','1','0'],['maintenance/reindex','0','20'],['maintenance/search-stage',$index.'-bad','1','1801']] as $args){[$exit]=searchConsole($schema,$index,$args);truth($exit!==0);}
    }finally{dropPaymentLegacySchema($db,$schema);}
});
runTests();
