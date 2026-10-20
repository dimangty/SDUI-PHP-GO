<?php
declare(strict_types=1);
require __DIR__.'/scheduler-state.php';
$mode=$argv[1]??'ready';
if(!in_array($mode,['ready','live','status'],true)){exit(2);}
try{
    $raw=@file_get_contents('/app/runtime/scheduler-state.json');
    if($raw===false){exit(1);}
    $health=schedulerHealth(json_decode($raw,true,32,JSON_THROW_ON_ERROR));
    if($mode==='status'){print json_encode($health,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";exit(0);}
    exit($health[$mode]?0:1);
}catch(Throwable $error){fwrite(STDERR,"Scheduler state unavailable\n");exit(1);}
