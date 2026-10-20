<?php
declare(strict_types=1);
$root=dirname(__DIR__);$count=0;
$filter=new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),fn($f)=>!$f->isDir() || !in_array($f->getFilename(),['vendor','runtime'],true));
foreach(new RecursiveIteratorIterator($filter) as $file){
    if($file->getExtension()!=='php' && $file->getFilename()!=='yii'){continue;}
    $process=proc_open([PHP_BINARY,'-l',$file->getPathname()],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);$status=proc_close($process);
    if($status!==0){print $out.$err;exit(1);}$count++;
}
print "PHP syntax: $count files passed\n";
