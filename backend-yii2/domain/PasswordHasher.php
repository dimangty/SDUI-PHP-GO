<?php
declare(strict_types=1);
namespace Shop\domain;

/** Password PHC strings are independent of the preserved PIN encoding. */
class PasswordHasher
{
    private const DUMMY='$argon2id$v=19$m=19456,t=2,p=1$STVUNWs0TFVyUTJxTlFUQQ$SWxUmQMtrzuI6rRdKSQo9NK/u5fwLpHBVnbs5Lp9kmY';
    private array $options;
    public function __construct(?array $options=null,private ?\Closure $observer=null)
    {
        if(!defined('PASSWORD_ARGON2ID') || !in_array('argon2id',password_algos(),true)){throw new \RuntimeException('Argon2id is required');}
        $this->options=$options??['memory_cost'=>19456,'time_cost'=>2,'threads'=>1];
        if(array_keys($this->options)!==['memory_cost','time_cost','threads']
            || !is_int($this->options['memory_cost']) || $this->options['memory_cost']<19456 || $this->options['memory_cost']>65536
            || !is_int($this->options['time_cost']) || $this->options['time_cost']<2 || $this->options['time_cost']>4
            || $this->options['threads']!==1){throw new \InvalidArgumentException('Invalid password hash budget');}
    }
    public function hash(#[\SensitiveParameter] string $password): string {return $this->work('hash',fn()=>password_hash($password,PASSWORD_ARGON2ID,$this->options));}
    public function kind(string $hash): string
    {
        if(preg_match('/^\$argon2id\$v=19\$m=(\d{4,5}),t=([1-4]),p=([1-4])\$[A-Za-z0-9+\/]{22}\$[A-Za-z0-9+\/]{43}$/D',$hash,$m)
            && (int)$m[1]>=8192 && (int)$m[1]<=65536){return 'native';}
        if(preg_match('/^argon2id\$v=19\$m=(\d{4,5}),t=([1-4]),p=([1-4])\$[a-fA-F0-9]{32}\$[a-fA-F0-9]{64}$/D',$hash,$m)
            && (int)$m[1]>=8192 && (int)$m[1]<=65536){return 'legacy';}
        return $hash===''?'empty':'unknown';
    }
    public function verify(#[\SensitiveParameter] string $password,#[\SensitiveParameter] string $hash): bool
    {
        if(strlen($password)>128){return false;}
        return $this->work('verify',fn()=>match($this->kind($hash)){
            'native'=>password_verify($password,$hash),
            'legacy'=>Secret::verify($password,$hash),
            default=>$this->dummyRaw($password),
        });
    }
    private function dummyRaw(#[\SensitiveParameter] string $password): bool {password_verify($password,self::DUMMY);return false;}
    public function dummy(#[\SensitiveParameter] string $password): bool {return $this->work('verify',fn()=>$this->dummyRaw($password));}
    public function needsRehash(string $hash): bool {return $this->kind($hash)!=='native' || password_needs_rehash($hash,PASSWORD_ARGON2ID,$this->options);}
    private function work(string $stage,\Closure $work): mixed
    {
        $started=hrtime(true);
        try{return $work();}finally{if($this->observer!==null){($this->observer)($stage,(hrtime(true)-$started)/1e9);}}
    }
}
