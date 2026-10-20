<?php
// Process-boundary fixture only. Mounted as /app/yii in isolated scheduler tests.
declare(strict_types=1);
pcntl_async_signals(true);
$command=$argv[1]??'';
if(!in_array($command,['maintenance/run','maintenance/reindex'],true)){exit(64);}
function event(string $kind): void {
    global $command;
    file_put_contents('/scheduler-fixture/events.jsonl',json_encode(['event'=>$kind,'command'=>$command,'pid'=>getmypid(),'at'=>microtime(true)])."\n",FILE_APPEND|LOCK_EX);
}
$mode=trim(file_get_contents('/scheduler-fixture/mode'));
event('started');
pcntl_signal(SIGTERM,function(){event('term');exit(0);});
if($mode==='hold-ignore'){pcntl_signal(SIGTERM,SIG_IGN);}
while(str_starts_with($mode,'hold')){usleep(50000);$mode=trim(file_get_contents('/scheduler-fixture/mode'));}
if($mode==='fail'){event('failed');exit(7);}
event('succeeded');exit(0);
