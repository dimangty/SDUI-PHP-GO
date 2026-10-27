<?php
// Process-boundary fixture only. Mounted as /app/yii in isolated scheduler tests.
declare(strict_types=1);
pcntl_async_signals(true);
$command=$argv[1]??'';
if(!in_array($command,['maintenance/run','maintenance/reindex','maintenance/recover'],true)){exit(64);}
function event(string $kind): void {
    global $command;
    file_put_contents('/scheduler-fixture/events.jsonl',json_encode(['event'=>$kind,'command'=>$command,'pid'=>getmypid(),'at'=>microtime(true)])."\n",FILE_APPEND|LOCK_EX);
}
function mode(): string {
    $file=fopen('/scheduler-fixture/mode','rb');
    if($file===false){throw new RuntimeException('Scheduler fixture mode unavailable');}
    try{flock($file,LOCK_SH);$value=trim(stream_get_contents($file));}
    finally{flock($file,LOCK_UN);fclose($file);}
    if(!in_array($value,['hold','hold-ignore','success','fail'],true)){throw new RuntimeException('Invalid scheduler fixture mode');}
    return $value;
}
$mode=mode();
event('started');
pcntl_signal(SIGTERM,function(){event('term');exit(0);});
if($mode==='hold-ignore'){pcntl_signal(SIGTERM,SIG_IGN);}
while(str_starts_with($mode,'hold')){usleep(50000);$mode=mode();}
if($mode==='fail'){event('failed');exit(7);}
event('succeeded');exit(0);
