<?php
declare(strict_types=1);
namespace Shop\infrastructure;

final class Env
{
    public static function get(string $key,string $default=''): string { $v=getenv($key);return $v===false?$default:$v; }
    public static function duration(string $key,int|float $default): int|float
    {
        $raw=self::get($key); if($raw===''){return $default;}
        if(!preg_match('/^(\d+)(ms|s|m|h)$/D',$raw,$m)){throw new \RuntimeException("Invalid $key duration");}
        $seconds=(float)$m[1]*match($m[2]){'h'=>3600,'m'=>60,'ms'=>0.001,default=>1};
        if(!is_finite($seconds) || $seconds<=0 || $seconds>PHP_INT_MAX){throw new \RuntimeException("Invalid $key duration");}
        return $seconds===floor($seconds)?(int)$seconds:$seconds;
    }
    public static function production(): bool { return self::get('APP_ENV','development')==='production'; }
}
