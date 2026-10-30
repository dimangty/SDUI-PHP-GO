<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use PDO;
use Shop\domain\ApiError;

final class Database
{
    private ?PDO $pdo=null;
    // Do not reconnect implicitly: session settings/locks and commit outcome may
    // be unknown. Callers must construct a fresh Database, never replay the work.
    private ?\Throwable $connectionFailure=null;
    public function __construct(private readonly string $url) {}
    public function pdo(): PDO
    {
        if($this->connectionFailure!==null){throw $this->connectionFailure;}
        if($this->pdo!==null){return $this->pdo;}
        $p=parse_url($this->url);
        if(!$p || !in_array($p['scheme']??'', ['postgres','postgresql'],true)){throw new \RuntimeException('DATABASE_URL must be a PostgreSQL URL');}
        parse_str($p['query']??'',$q);
        $dsn='pgsql:host='.($p['host']??'localhost').';port='.($p['port']??5432).';dbname='.rawurldecode(ltrim($p['path']??'','/')).';sslmode='.($q['sslmode']??'prefer').';connect_timeout=3';
        $pdo=new PDO($dsn,rawurldecode($p['user']??''),rawurldecode($p['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec("SET TIME ZONE 'UTC'; SET statement_timeout='10s'; SET lock_timeout='8s'");
        return $this->pdo=$pdo;
    }
    public function execute(string $sql,array $args=[]): int { $s=$this->pdo()->prepare($sql);$this->bind($s,$args);$s->execute();return $s->rowCount(); }
    public function all(string $sql,array $args=[]): array { $s=$this->pdo()->prepare($sql);$this->bind($s,$args);$s->execute();return $s->fetchAll(); }
    public function one(string $sql,array $args=[]): ?array { $s=$this->pdo()->prepare($sql);$this->bind($s,$args);$s->execute();return $s->fetch()?:null; }
    private function bind(\PDOStatement $s,array $args): void
    {foreach(array_values($args) as $i=>$v){$s->bindValue($i+1,$v,match(true){is_int($v)=>PDO::PARAM_INT,is_bool($v)=>PDO::PARAM_BOOL,$v===null=>PDO::PARAM_NULL,default=>PDO::PARAM_STR});}}
    public function transaction(\Closure $work): mixed
    {
        $pdo = $this->pdo();
        try {
            $active = $pdo->inTransaction();
        } catch (\Throwable $primary) {
            $this->quarantine($primary);
            throw $primary;
        }
        if ($active) {
            throw new \LogicException('Nested transaction is not supported');
        }
        $owned = false;
        $committing = false;
        try {
            if (!$pdo->beginTransaction()) {
                throw new \RuntimeException('Cannot begin transaction');
            }
            $owned = true;
            $result = $work();
            $committing = true;
            if (!$pdo->commit()) {
                throw new \RuntimeException('Transaction commit was not confirmed');
            }
            return $result;
        } catch (\Throwable $primary) {
            // PostgreSQL ErrorResponse for a constraint/transaction rollback or
            // deferred RAISE is a definite rejection. Transport errors, missing
            // SQLSTATE and 40003 (statement_completion_unknown) are NOT one.
            $state = $primary instanceof \PDOException ? ($primary->errorInfo[0] ?? null) : null;
            $rejected = $committing && is_string($state) && $state === $primary->getCode()
                && preg_match('/^(?:23[A-Z0-9]{3}|40[A-Z0-9]{3}|P0001)$/D', $state) === 1
                && $state !== '40003';
            $uncertain = !$owned || ($committing && !$rejected);
            if ($owned) {
                try {
                    if ($pdo->inTransaction()) {
                        if (!$pdo->rollBack()) {
                            throw new \RuntimeException('Transaction rollback was not confirmed');
                        }
                    } elseif (!$rejected) {
                        $uncertain = true;
                    }
                } catch (\Throwable) {
                    $uncertain = true;
                    // Fixed diagnostic only: never log driver messages, SQL or arguments.
                    error_log('Database transaction cleanup failed; connection quarantined');
                }
            }
            if ($uncertain) {
                $this->quarantine($primary);
            }
            // Preserve identity, errorInfo and SQLSTATE for existing mappers/recovery.
            throw $primary;
        }
    }
    private function quarantine(\Throwable $primary): void
    {
        $this->connectionFailure = $primary;
        $this->pdo = null;
    }
    public function advisory(string $scope): void { $this->one('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope]); }
    public function required(string $sql,array $args,string $code,string $message): array
    { return $this->one($sql,$args)??throw new ApiError($code,$message); }
}
