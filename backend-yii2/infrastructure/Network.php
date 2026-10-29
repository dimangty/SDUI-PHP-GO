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
        return self::matches($ip,self::parseCidr($cidr));
    }
    private static function parseCidr(string $cidr): array
    {
        $parts=explode('/',$cidr);$network=@inet_pton($parts[0]);
        // Validate syntax before conversion: a typo must never become /0.
        if($network===false || count($parts)>2 || (isset($parts[1]) && !preg_match('/^(0|[1-9][0-9]{0,2})$/D',$parts[1]))){
            throw new \InvalidArgumentException('PAYMENT_WEBHOOK_ALLOWED_CIDRS contains an invalid IP/CIDR entry');
        }
        $bits=isset($parts[1])?(int)$parts[1]:strlen($network)*8;
        if($bits>strlen($network)*8){throw new \InvalidArgumentException('PAYMENT_WEBHOOK_ALLOWED_CIDRS contains an invalid prefix');}
        return [$network,$bits];
    }
    /** Bare IPs match exactly. Mapped IPv6 stays IPv6 here (unlike rate aggregation). */
    private static function matches(string $ip,array $cidr): bool
    {
        [$network,$bits]=$cidr;$address=@inet_pton($ip);
        if($address===false || strlen($address)!==strlen($network)){return false;}
        $bytes=intdiv($bits,8);$remaining=$bits%8;
        return substr($address,0,$bytes)===substr($network,0,$bytes) && ($remaining===0 || ((ord($address[$bytes])^ord($network[$bytes])) & (255 << (8-$remaining)))===0);
    }
    public static function cidrs(string $list): array
    {
        if($list===''){return [];}
        // Materialize/validate ALL entries before evaluating a single match.
        return array_map(fn($entry)=>self::parseCidr(trim($entry)),explode(',',$list));
    }
    public static function allowed(string $ip,string $list): bool
    {foreach(self::cidrs($list) as $cidr){if(self::matches($ip,$cidr)){return true;}}return false;}
    public static function client(string $remote,array $headers): string
    {
        // Nginx already resolves direct edge clients into REMOTE_ADDR. Only
        // exact BFF transport peers may provide the Nginx-owned rate context.
        // Raw X-Real-IP/X-Forwarded-For are deliberately never consumed here.
        $raw=Env::get('TRUSTED_BFF_IPS');if($raw===''){return $remote;}
        $trusted=[];
        foreach(explode(',',$raw) as $entry){
            $peer=self::canonical(trim($entry));
            if($peer===null || in_array($peer,['0.0.0.0','::'],true)){
                throw new \RuntimeException('TRUSTED_BFF_IPS requires exact IP addresses, never CIDRs or hostnames');
            }
            $trusted[]=$peer;
        }
        if(!in_array(self::canonical($remote),$trusted,true)){return $remote;}
        return self::canonical($headers['x-shop-rate-client-ip']??null)??$remote;
    }
    private static function canonical(mixed $ip): ?string
    {
        if(!is_string($ip) || filter_var($ip,FILTER_VALIDATE_IP)===false){return null;}
        $packed=inet_pton($ip);
        if(strlen($packed)===16 && substr($packed,0,12)===hex2bin('00000000000000000000ffff')){$packed=substr($packed,12);}
        return inet_ntop($packed);
    }
}
