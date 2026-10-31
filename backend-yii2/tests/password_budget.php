<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Shop\domain\PasswordHasher;

$h=new PasswordHasher();$samples=[];
for($i=0;$i<4;$i++){
    $start=hrtime(true);$hash=$h->hash('MeasuredPassword7395');$samples['hash'][]=(hrtime(true)-$start)/1e6;
    $options=password_get_info($hash)['options'];
    if($options!==['memory_cost'=>19456,'time_cost'=>2,'threads'=>1]){throw new RuntimeException('Unexpected native hash budget');}
    $start=hrtime(true);if(!$h->verify('MeasuredPassword7395',$hash)){throw new RuntimeException('Native verification failed');}$samples['verify'][]=(hrtime(true)-$start)/1e6;
}
foreach($samples as &$values){sort($values);}unset($values);
$report=['php'=>PHP_VERSION,'platform'=>php_uname('s').'/'.php_uname('m'),'options'=>$options,
    'sequential_ms'=>$samples,'peak_rss_kib'=>getrusage()['ru_maxrss']];
if(($argv[1]??'')!=='worker'){
    $children=[];$started=hrtime(true);
    for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$p,$pipes];}
    foreach($children as [$p,$pipes]){$body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0 || $error!==''){throw new RuntimeException('Parallel native hash probe failed');}$report['parallel_workers'][]=json_decode($body,true,32,JSON_THROW_ON_ERROR);}
    $report['parallel_elapsed_ms']=(hrtime(true)-$started)/1e6;
    $report['fpm_max_concurrent_hashes']=12;$report['argon_memory_ceiling_kib']=12*19456;
}
print json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
