<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** One monotonic work deadline; cleanup has separate <=1s statements/requests. */
final class SearchBudget
{
    private float $deadline;
    public function __construct(float $seconds)
    {
        if(!is_finite($seconds) || $seconds<=0 || $seconds>1800){throw new \InvalidArgumentException('Search budget must be greater than zero and at most 1800 seconds');}
        $this->deadline=hrtime(true)/1e9+$seconds;
    }
    public static function limit(int $limit): void
    {
        if($limit<1 || $limit>1000000){throw new \InvalidArgumentException('Search document limit must be 1..1000000');}
    }
    public function timeout(float $cap=5): float
    {
        $remaining=$this->deadline-hrtime(true)/1e9;
        if($remaining<=0){throw new \RuntimeException('Search operation deadline exceeded');}
        return min($cap,$remaining);
    }
    /** Call before each potentially blocking repository/SQL operation. */
    public function sql(Database $db): void
    {
        if(!$db->pdo()->inTransaction()){throw new \LogicException('Search SQL limits require an owned transaction');}
        $milliseconds=max(1,(int)floor($this->timeout(10)*1000));
        $db->one("SELECT set_config('statement_timeout',?,true),set_config('lock_timeout','1ms',true)",[$milliseconds.'ms']);
    }
    public function read(Database $db,\Closure $work): mixed
    {
        $this->timeout();return $db->transaction(function()use($db,$work){$this->sql($db);return $work();});
    }
    public function locked(Database $db,string $scope,\Closure $work,bool $wait=false): mixed
    {
        $this->timeout();
        return $db->transaction(function()use($db,$scope,$work,$wait){
            do{
                $this->sql($db);
                $locked=$db->one('SELECT pg_try_advisory_xact_lock(hashtextextended(?,0)) AS acquired',[$scope])['acquired'];
                if($locked){break;}
                if(!$wait){throw new \RuntimeException('Search maintenance is already running');}
                usleep((int)(min(.02,$this->timeout())*1000000));
            }while(true);
            return $work();
        });
    }
    /** One maintenance process, but short separate transactions for each live row. */
    public function maintenance(Database $db,\Closure $work): mixed
    {
        $this->timeout();
        if($db->pdo()->inTransaction()){throw new \LogicException('Search maintenance requires an independent SQL session');}
        $scope='shopkmp-products:maintenance';$locked=false;$primary=null;
        try{
            $this->read($db,function()use($db,$scope,&$locked){$locked=$db->one('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired',[$scope])['acquired'];});
            if(!$locked){throw new \RuntimeException('Search maintenance is already running');}
            return $work();
        }catch(\Throwable $error){$primary=$error;throw $error;}
        finally{
            if($locked){
                try{
                    $released=(new self(1))->read($db,fn()=>$db->one('SELECT pg_advisory_unlock(hashtextextended(?,0)) AS released',[$scope])['released']);
                    if($released!==true){throw new \RuntimeException('Search maintenance lock release failed');}
                }catch(\Throwable $cleanup){
                    // Never reconnect/replay. A failed CLI operation must exit and
                    // discard this session; normal business writers use xact locks.
                    error_log('Search maintenance cleanup failed; discard database session');if($primary===null){throw $cleanup;}
                }
            }
        }
    }
}
