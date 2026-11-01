<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\infrastructure\{ReadinessBudget,ReadinessPolicy};

test('policy preserves capability negotiation and marks configured search unmeasured',function(){
    $policy=new ReadinessPolicy(new ReadinessBudget());$now=10.0;
    $result=$policy->evaluate(static function(float $deadline,callable $clock,callable $cancel)use(&$now){
        truth($deadline>10 && $deadline<10.7);truth(!$cancel());$now+=.02;
    },static function()use(&$now){return $now;},static fn()=>false,true);
    same(['status'=>200,'data'=>['status'=>'ok','components'=>['postgres'=>'up','local-auth'=>'up','opensearch'=>'unknown'],'capabilities'=>['catalog-cards-v1']]],$result);
});
test('policy omits disabled search and fails closed on thrown, overdue or cancelled required work',function(){
    $policy=new ReadinessPolicy(new ReadinessBudget());
    $ok=$policy->evaluate(static function(){},static fn()=>1.0,static fn()=>false,false);
    same(false,isset($ok['data']['components']['opensearch']));
    foreach(['throw','overdue','cancel'] as $mode){
        $now=1.0;$cancelled=false;
        $result=$policy->evaluate(static function()use($mode,&$now,&$cancelled){
            if($mode==='throw'){throw new RuntimeException('postgres://secret:password@private/db');}
            if($mode==='overdue'){$now=2.0;}else{$cancelled=true;}
        },static function()use(&$now){return $now;},static function()use(&$cancelled){return $cancelled;},true);
        same(503,$result['status']);same('down',$result['data']['components']['dependency']);
        same('unknown',$result['data']['components']['opensearch']);
        truth(!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'password'));
        same(false,isset($result['data']['capabilities']));
    }
});
test('already cancelled or expired policy does not start required I/O',function(){
    $policy=new ReadinessPolicy(new ReadinessBudget());$calls=0;
    $result=$policy->evaluate(static function()use(&$calls){$calls++;},static fn()=>1.0,static fn()=>true,false);
    same(503,$result['status']);same(0,$calls);
});
test('budgets reject invalid or overcommitted deadline allocations',function(){
    foreach([
        ['coreMilliseconds'=>0],['totalMilliseconds'=>-1],['coreMilliseconds'=>701],
        ['queueMilliseconds'=>-1],['httpMilliseconds'=>0],['serializationMilliseconds'=>0],
        ['cleanupMilliseconds'=>700],['statementMilliseconds'=>0],['lockMilliseconds'=>300],
    ] as $arguments){
        try{new ReadinessBudget(...$arguments);throw new RuntimeException('Expected invalid budget');}
        catch(InvalidArgumentException $e){same('Invalid readiness budget',$e->getMessage());}
    }
});
test('shared admission rejects a third actual probe before I/O and releases on errors and cancellation',function(){
    $policy=new ReadinessPolicy();$clock=static fn()=>1.0;$cancel=static fn()=>false;$calls=0;$rejected=null;$inner=null;
    $outer=$policy->evaluate(function()use($policy,$clock,$cancel,&$calls,&$rejected,&$inner){
        $inner=$policy->evaluate(function()use($policy,$clock,$cancel,&$calls,&$rejected){
            $rejected=$policy->evaluate(function()use(&$calls){$calls++;},$clock,$cancel,false);
        },$clock,$cancel,false);
    },$clock,$cancel,false);
    same(200,$outer['status']);same(200,$inner['status']);same(503,$rejected['status']);same(0,$calls);
    foreach(['throw','cancel'] as $mode){
        $cancelled=false;
        $result=$policy->evaluate(static function()use($mode,&$cancelled){
            if($mode==='throw'){throw new RuntimeException('required failure');}$cancelled=true;
        },$clock,static function()use(&$cancelled){return $cancelled;},false);
        same(503,$result['status']);
        // Both slots must be available again, including after the failed probe.
        $result=$policy->evaluate(function()use($policy,$clock,$cancel){
            same(200,$policy->evaluate(static function(){},$clock,$cancel,false)['status']);
        },$clock,$cancel,false);
        same(200,$result['status']);
    }
});
test('admission is shared across independent PHP processes',function(){
    $code='require "vendor/autoload.php"; $r=(new Shop\\infrastructure\\ReadinessPolicy())->evaluate(static function(){fwrite(STDOUT,"held\\n");fgetc(STDIN);},static fn()=>1.0,static fn()=>false,false); exit($r["status"]===200?0:1);';
    $child=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>STDERR],$pipes,dirname(__DIR__));
    truth(is_resource($child));stream_set_timeout($pipes[1],2);
    try{
        same("held\n",fgets($pipes[1]));$rejected=null;$calls=0;$policy=new ReadinessPolicy();
        $result=$policy->evaluate(function()use($policy,&$rejected,&$calls){
            $rejected=$policy->evaluate(function()use(&$calls){$calls++;},static fn()=>1.0,static fn()=>false,false);
        },static fn()=>1.0,static fn()=>false,false);
        same(200,$result['status']);same(503,$rejected['status']);same(0,$calls);
    }finally{fclose($pipes[0]);fclose($pipes[1]);same(0,proc_close($child));}
});
runTests();
