<?php
declare(strict_types=1);
namespace Shop\infrastructure;

final class Network
{
    public static function aggregate(string $ip): string
    {
        $packed=@inet_pton($ip);if($packed===false){return trim($ip);}
        if(strlen($packed)===16 && substr($packed,0,12)===hex2bin('00000000000000000000ffff')){$packed=substr($packed,12);}
        return strlen($packed)===4
            ?inet_ntop(substr($packed,0,3)."\0").'/24'
            :inet_ntop(substr($packed,0,8).str_repeat("\0",8)).'/64';
    }
    public static function contains(string $ip,string $cidr): bool
    {
        $parts=explode('/',$cidr,2);$address=@inet_pton($ip);$network=@inet_pton($parts[0]);
        if($address===false || $network===false || strlen($address)!==strlen($network)){return false;}
        $bits=isset($parts[1])?(int)$parts[1]:strlen($address)*8;
        if($bits<0 || $bits>strlen($address)*8){return false;}
        $bytes=intdiv($bits,8);$remaining=$bits%8;
        return substr($address,0,$bytes)===substr($network,0,$bytes) && ($remaining===0 || ((ord($address[$bytes])^ord($network[$bytes])) & (255 << (8-$remaining)))===0);
    }
    public static function allowed(string $ip,string $list): bool
    {foreach(explode(',',$list) as $cidr){if(self::contains($ip,trim($cidr))){return true;}}return false;}
    public static function client(string $remote,array $headers): string
    {
        if(self::allowed($remote,Env::get('TRUSTED_PROXY_CIDRS','127.0.0.1/32,::1/128'))){
            $chain=array_map('trim',explode(',',$headers['x-forwarded-for']??''));
            for($i=count($chain)-1;$i>=0;$i--){if(filter_var($chain[$i],FILTER_VALIDATE_IP) && !self::allowed($chain[$i],Env::get('TRUSTED_PROXY_CIDRS','127.0.0.1/32,::1/128'))){return $chain[$i];}}
        }return $remote;
    }
}
