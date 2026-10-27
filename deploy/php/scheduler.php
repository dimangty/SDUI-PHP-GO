<?php
declare(strict_types=1);
require __DIR__.'/scheduler-state.php';

$command=$argv[1]??'';$interval=(int)($argv[2]??0);
if(!in_array($command,['maintenance/run','maintenance/reindex','maintenance/recover'],true) || $interval<1){exit(2);}
$budget=schedulerPositiveEnv('SCHEDULER_JOB_TIMEOUT_SECONDS',$command==='maintenance/reindex'?1800:120);
$maxAge=schedulerPositiveEnv('SCHEDULER_SUCCESS_MAX_AGE_SECONDS',$interval+$budget+5);
set_time_limit(0);pcntl_async_signals(true);
$stop=false;$child=null;$terminateAt=null;$timedOut=false;$nextRun=0.0;
$state=['version'=>1,'pid'=>getmypid(),'command'=>$command,'phase'=>'starting',
    'intervalSeconds'=>$interval,'jobTimeoutSeconds'=>$budget,'successMaxAgeSeconds'=>$maxAge,
    'runningPid'=>null,'runStartedMonotonic'=>null,'lastStartedAt'=>null,
    'lastFinishedAt'=>null,'lastExitCode'=>null,'childExitCode'=>null,'lastResult'=>null,
    'lastSuccessAt'=>null,'lastSuccessMonotonic'=>null];
foreach([SIGTERM,SIGINT] as $signal){
    pcntl_signal($signal,function()use(&$stop,&$child){
        $stop=true;
        if(is_resource($child)){proc_terminate($child,SIGTERM);}
    });
}
schedulerWriteState($state);
while(true){
    $now=schedulerNow();
    if($stop){
        $state['phase']='stopping';
        if(is_resource($child) && $terminateAt===null){proc_terminate($child,SIGTERM);$terminateAt=$now;}
    }
    if(is_resource($child)){
        $process=proc_get_status($child);
        if($process['running']){
            if(!$stop && !$timedOut && $now-$state['runStartedMonotonic']>=$budget){
                $timedOut=true;$terminateAt=$now;$state['phase']='terminating';
                $state['lastResult']='timeout';$state['lastExitCode']=124;
                fwrite(STDERR,"Scheduled $command exceeded {$budget}s; terminating child\n");
                proc_terminate($child,SIGTERM);
            }
            // A child ignoring TERM cannot hold the scheduler forever, on timeout or shutdown.
            if($terminateAt!==null && $now-$terminateAt>=5){proc_terminate($child,SIGKILL);}
        }else{
            // A paused/starved parent may observe completion only after the deadline.
            // Never turn a late exit0 into a fresh success just because polling was delayed.
            $timedOut=$timedOut || (!$stop && $now-$state['runStartedMonotonic']>=$budget);
            // Capture the first wait result: proc_close() can return -1 after proc_get_status().
            $exit=$process['signaled']?128+$process['termsig']:$process['exitcode'];
            proc_close($child);$child=null;$state['runningPid']=null;
            $state['lastFinishedAt']=microtime(true);$state['childExitCode']=$exit;
            $state['lastExitCode']=$timedOut?124:$exit;
            $state['lastResult']=$stop?'stopped':($timedOut?'timeout':($exit===0?'success':'failed'));
            if(!$stop && !$timedOut && $exit===0){
                $state['lastSuccessAt']=microtime(true);$state['lastSuccessMonotonic']=$now;
            }elseif(!$stop){fwrite(STDERR,"Scheduled $command failed: {$state['lastExitCode']}\n");}
            $state['phase']=$stop?'stopped':'waiting';
        }
    }
    if($stop && !is_resource($child)){
        $state['phase']='stopped';schedulerWriteState($state);break;
    }
    if(!$stop && !is_resource($child) && $now>=$nextRun){
        $state['lastStartedAt']=microtime(true);$state['runStartedMonotonic']=$now;
        $nextRun=$now+$interval;$timedOut=false;$terminateAt=null;
        $child=proc_open([PHP_BINARY,'/app/yii',$command],
            [0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
        if(!is_resource($child)){throw new RuntimeException('Cannot start scheduled job');}
        $state['runningPid']=proc_get_status($child)['pid'];$state['phase']='running';
    }
    schedulerWriteState($state);usleep(200000);
}
