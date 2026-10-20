<?php
declare(strict_types=1);
namespace Shop\domain;

final class Ids
{
    public static function uuid(): string
    {
        $b = random_bytes(16); $b[6]=chr((ord($b[6]) & 15)|64); $b[8]=chr((ord($b[8]) & 63)|128);
        $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    public static function valid(string $id): bool { return (bool)preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $id); }
    public static function opaque(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    public static function timestamp(?string $value): ?string
    {
        if($value===null){return null;}
        $date=(new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));$fraction=rtrim($date->format('u'),'0');
        return $date->format('Y-m-d\TH:i:s').($fraction===''?'':'.'.$fraction).'Z';
    }
    public static function now(): string { return self::timestamp('now'); }
    public static function epoch(string $value): float {return (float)(new \DateTimeImmutable($value))->format('U.u');}
}
