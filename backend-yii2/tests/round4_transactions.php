<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\infrastructure\Database;

// Only the transport faults that a live PDO cannot deterministically schedule
// are doubled. Native termination/SQLSTATE coverage is in round4_catalog.php.
final class CleanupFaultPdo extends PDO
{
    public bool $active=false;
    public int $begins=0;
    public int $commits=0;
    public int $rollbacks=0;
    public int $checks=0;
    public ?Throwable $rollbackFailure=null;
    public ?Throwable $stateFailure=null;
    public ?Throwable $commitFailure=null;
    public function __construct() {}
    public function beginTransaction(): bool {$this->begins++;$this->active=true;return true;}
    public function inTransaction(): bool {
        $this->checks++;
        if($this->stateFailure!==null && $this->begins>0){throw $this->stateFailure;}
        return $this->active;
    }
    public function commit(): bool {
        $this->commits++;
        if($this->commitFailure!==null){throw $this->commitFailure;}
        $this->active=false;return true;
    }
    public function rollBack(): bool {
        $this->rollbacks++;
        if($this->rollbackFailure!==null){throw $this->rollbackFailure;}
        $this->active=false;return true;
    }
}
function faultDatabase(CleanupFaultPdo $pdo): Database {
    $db=new Database('postgres://unused');
    (new ReflectionProperty(Database::class,'pdo'))->setValue($db,$pdo);
    return $db;
}
function primaryFailure(string $state='57014'): PDOException {
    $error=new PDOException('fixture primary; never log SQL arguments or credentials');
    $error->errorInfo=[$state,7,'fixture'];
    (new ReflectionProperty(Exception::class,'code'))->setValue($error,$state);
    return $error;
}
foreach(['rollback-success','rollback-failure','state-failure','commit-failure'] as $case){
    test("transaction preserves primary throwable and SQLSTATE on $case without replay",function()use($case){
        $pdo=new CleanupFaultPdo();$db=faultDatabase($pdo);$state=$case==='commit-failure'?'08006':'57014';$primary=primaryFailure($state);$calls=0;
        if($case==='rollback-failure'){$pdo->rollbackFailure=new RuntimeException('cleanup secret');}
        if($case==='state-failure'){$pdo->stateFailure=new RuntimeException('state secret');}
        if($case==='commit-failure'){$pdo->commitFailure=$primary;}
        try{$db->transaction(function()use(&$calls,$primary,$case){$calls++;if($case!=='commit-failure'){throw $primary;}return 42;});throw new RuntimeException('expected failure');}
        catch(PDOException $caught){truth($caught===$primary);same($state,$caught->getCode());same($state,$caught->errorInfo[0]);}
        same(1,$calls);same(1,$pdo->begins);same($case==='commit-failure'?1:0,$pdo->commits);
        if($case==='rollback-success'){truth($db->pdo()===$pdo);same(false,$pdo->active);}
        else{
            // An uncertain connection cannot be handed out as a healthy session.
            try{$db->pdo();throw new RuntimeException('uncertain connection reused');}
            catch(PDOException $caught){truth($caught===$primary);}
        }
    });
}
test('successful transaction returns closure value and commits once',function(){
    $pdo=new CleanupFaultPdo();$db=faultDatabase($pdo);$calls=0;
    same(42,$db->transaction(function()use(&$calls){$calls++;return 42;}));
    same(1,$calls);same(1,$pdo->commits);same(0,$pdo->rollbacks);truth($db->pdo()===$pdo);
});
test('nested transaction rejects work without rolling back its caller',function(){
    $pdo=new CleanupFaultPdo();$pdo->active=true;$db=faultDatabase($pdo);$calls=0;
    try{$db->transaction(function()use(&$calls){$calls++;});throw new RuntimeException('nested transaction accepted');}
    catch(LogicException){}
    same(0,$calls);same(0,$pdo->begins);same(0,$pdo->rollbacks);truth($pdo->active);
});
test('HTTP mapper keeps the original PDO classification without logging driver details or caller identifiers',function(){
    $pdo=new class extends PDO {
        public function __construct() {}
        public function inTransaction(): bool {return false;}
        public function beginTransaction(): bool {return true;}
        public function prepare(string $query,array $options=[]): PDOStatement|false {
            $error=new PDOException('PRIVATE driver SQL arguments DSN password token');
            $error->errorInfo=['08006',7,'PRIVATE'];
            (new ReflectionProperty(Exception::class,'code'))->setValue($error,'08006');throw $error;
        }
    };
    $db=new Database('postgres://unused');(new ReflectionProperty(Database::class,'pdo'))->setValue($db,$pdo);
    $log=tempnam(sys_get_temp_dir(),'round4-log-');$old=ini_get('error_log');ini_set('error_log',$log);
    try{
        $response=(new \Shop\infrastructure\ApiKernel($db))->handle('GET','/api/v1/products',['x-request-id'=>'PRIVATE-caller-id'],'','127.0.0.1');
        same('DATABASE_UNAVAILABLE',$response['data']['error']['code']);same('PRIVATE-caller-id',$response['data']['error']['traceId']);
        $text=file_get_contents($log);truth($text!=='');truth(!str_contains($text,'PRIVATE'));truth(str_contains($text,'08006'));
    }finally{ini_set('error_log',$old);unlink($log);}
});
runTests();
