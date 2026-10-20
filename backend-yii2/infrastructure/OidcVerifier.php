<?php
declare(strict_types=1);
namespace Shop\infrastructure;

use Firebase\JWT\{JWT,JWK};
use Shop\domain\{ApiError,Ids};

final class OidcVerifier
{
    public function __construct(private readonly string $issuer,private readonly string $client) {}
    private function documents(bool $refresh=false,int|float|null $timeout=null): array
    {
        $path=sys_get_temp_dir().'/shopkmp-jwks-'.hash('sha256',$this->issuer).'.json';
        $cache=is_file($path)?json_decode((string)file_get_contents($path),true):null;
        if(is_array($cache) && ($cache['until']??0)>time() && (!$refresh || ($cache['fetched']??0)>time()-5)){return $cache;}
        $http=new HttpClient($timeout??5);$r=$http->request('GET',rtrim($this->issuer,'/').'/.well-known/openid-configuration');
        $discovery=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);
        if($r['status']!==200 || ($discovery['issuer']??null)!==$this->issuer || !is_string($discovery['jwks_uri']??null) || HttpClient::origin($discovery['jwks_uri'])!==HttpClient::origin($this->issuer)){throw new \RuntimeException('Invalid OIDC discovery');}
        $r=$http->request('GET',$discovery['jwks_uri']);$jwks=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);
        if($r['status']!==200 || !is_array($jwks['keys']??null) || count($jwks['keys'])>100){throw new \RuntimeException('Invalid OIDC JWKS');}
        $cache=['until'=>time()+300,'fetched'=>time(),'jwks'=>$jwks];
        file_put_contents($path,json_encode($cache,JSON_THROW_ON_ERROR),LOCK_EX);return $cache;
    }
    public function verify(string $token): array
    {
        try{
            if(strlen($token)>32768){throw new \RuntimeException('Oversized token');}
            $parts=explode('.',$token);if(count($parts)!==3){throw new \RuntimeException('Invalid JWT');}
            $head=json_decode(JWT::urlsafeB64Decode($parts[0]),true,16,JSON_THROW_ON_ERROR);
            if(($head['alg']??null)!=='RS256' || !is_string($head['kid']??null)){throw new \RuntimeException('Unsupported JWT algorithm');}
            $documents=$this->documents();$keys=$this->keys($documents['jwks']);
            if(!isset($keys[$head['kid']])){$keys=$this->keys($this->documents(true)['jwks']);}
            $claims=(array)JWT::decode($token,$keys);
            self::validateClaims($claims,$this->issuer,$this->client);return $claims;
        }catch(\Throwable){throw new ApiError('UNAUTHORIZED','invalid or expired access token');}
    }
    private function keys(array $jwks): array
    {
        $jwks['keys']=array_values(array_filter($jwks['keys'],fn($k)=>($k['kty']??'')==='RSA' && ($k['alg']??'RS256')==='RS256' && ($k['use']??'sig')==='sig'));
        return JWK::parseKeySet($jwks,'RS256');
    }
    public static function validateClaims(array $c,string $issuer,string $client): void
    {
        $aud=$c['aud']??[];$aud=is_string($aud)?[$aud]:$aud;
        if(($c['iss']??null)!==$issuer || !is_array($aud) || !in_array($client,$aud,true) || ($c['typ']??null)!=='Bearer' || ($c['azp']??null)!==$client || !is_string($c['sub']??null) || !Ids::valid($c['sub']) || !is_int($c['exp']??null) || $c['exp']<=time()){
            throw new ApiError('UNAUTHORIZED','access token is missing required claims');
        }
    }
    public function ready(): void {$this->documents(true,Env::duration('READINESS_TIMEOUT',2));}
}
