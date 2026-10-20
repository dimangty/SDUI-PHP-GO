<?php
declare(strict_types=1);

function schedulerNow(): float {return hrtime(true)/1000000000;}
function schedulerPositiveEnv(string $name,int $default): int {
    $value=getenv($name);
    if($value===false || $value===''){return $default;}
    if(!ctype_digit($value) || (int)$value<1 || (int)$value>86400){throw new InvalidArgumentException("$name must be 1..86400 seconds");}
    return (int)$value;
}
function schedulerWriteState(array &$state): void {
    $state['heartbeatAt']=microtime(true);$state['heartbeatMonotonic']=schedulerNow();
    $file='/app/runtime/scheduler-state.json';$temp=$file.'.'.getmypid().'.tmp';
    if(file_put_contents($temp,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($temp,$file)){
        throw new RuntimeException('Cannot publish scheduler state');
    }
}
function schedulerHealth(array $state): array {
    $now=schedulerNow();$heartbeat=$state['heartbeatMonotonic']??null;
    $live=is_numeric($heartbeat) && $now>=$heartbeat && $now-$heartbeat<5
        && is_int($state['pid']??null) && $state['pid']>0 && @posix_kill($state['pid'],0)
        && !in_array($state['phase']??'', ['stopping','stopped'],true);
    $success=$state['lastSuccessMonotonic']??null;
    $ready=$live && ($state['lastResult']??null)==='success'
        && is_numeric($success) && $now>=$success && $now-$success<($state['successMaxAgeSeconds']??0)
        && in_array($state['phase']??'', ['running','waiting'],true);
    if(($state['phase']??'')==='running'){
        $start=$state['runStartedMonotonic']??null;
        $ready=$ready && is_numeric($start) && $now>=$start && $now-$start<($state['jobTimeoutSeconds']??0);
    }
    return ['live'=>$live,'ready'=>$ready,'state'=>$state];
}
