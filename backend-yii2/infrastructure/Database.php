<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use PDO;
use Shop\domain\ApiError;

final class Database
{
    private ?PDO $pdo=null;
    public function __construct(private readonly string $url) {}
    public function pdo(): PDO
    {
        if($this->pdo!==null){return $this->pdo;}
        $p=parse_url($this->url);
        if(!$p || !in_array($p['scheme']??'', ['postgres','postgresql'],true)){throw new \RuntimeException('DATABASE_URL must be a PostgreSQL URL');}
        parse_str($p['query']??'',$q);
        $dsn='pgsql:host='.($p['host']??'localhost').';port='.($p['port']??5432).';dbname='.rawurldecode(ltrim($p['path']??'','/')).';sslmode='.($q['sslmode']??'prefer').';connect_timeout=3';
        $this->pdo=new PDO($dsn,rawurldecode($p['user']??''),rawurldecode($p['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->pdo->exec("SET TIME ZONE 'UTC'; SET statement_timeout='10s'; SET lock_timeout='8s'");
        return $this->pdo;
    }
    public function execute(string $sql,array $args=[]): int { $s=$this->pdo()->prepare($sql);$this->bind($s,$args);$s->execute();return $s->rowCount(); }
    public function all(string $sql,array $args=[]): array { $s=$this->pdo()->prepare($sql);$this->bind($s,$args);$s->execute();return $s->fetchAll(); }
    public function one(string $sql,array $args=[]): ?array { $s=$this->pdo()->prepare($sql);$this->bind($s,$args);$s->execute();return $s->fetch()?:null; }
    private function bind(\PDOStatement $s,array $args): void
    {foreach(array_values($args) as $i=>$v){$s->bindValue($i+1,$v,match(true){is_int($v)=>PDO::PARAM_INT,is_bool($v)=>PDO::PARAM_BOOL,$v===null=>PDO::PARAM_NULL,default=>PDO::PARAM_STR});}}
    public function transaction(\Closure $work): mixed
    {
        $pdo=$this->pdo();$pdo->beginTransaction();
        try{$result=$work();$pdo->commit();return $result;}
        catch(\Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }
    public function advisory(string $scope): void { $this->one('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope]); }
    public function required(string $sql,array $args,string $code,string $message): array
    { return $this->one($sql,$args)??throw new ApiError($code,$message); }
}
