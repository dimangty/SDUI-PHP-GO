<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Canonical middleware/rate_limit.go classifications, including method-independent auth policies. */
final class RatePolicy
{
    public static function limit(string $method,string $path): array
    {
        if($path==='/api/v1/payments/webhook' || $method==='OPTIONS'){return ['',0];}
        $sensitive=(int)Env::get('SENSITIVE_RATE_LIMIT','20');
        $auth=match($path){
            '/api/v1/auth/register'=>['register',10],
            '/api/v1/auth/login'=>['login',20],
            '/api/v1/auth/refresh'=>['refresh',60],
            '/api/v1/auth/pin/login'=>['pin-login',20],
            '/api/v1/auth/biometric/challenge'=>['biometric-challenge',20],
            '/api/v1/auth/biometric/login'=>['biometric-login',20],
            '/api/v1/users/me/change-password'=>['change-password',10],
            default=>null,
        };
        if($auth===null && ($path==='/api/v1/auth/pin' || str_starts_with($path,'/api/v1/auth/pin/devices/'))){$auth=['pin-management',20];}
        if($auth===null && ($path==='/api/v1/auth/biometric' || str_starts_with($path,'/api/v1/auth/biometric/devices'))){$auth=['biometric-management',20];}
        if($auth!==null){return [$auth[0],$auth[0]==='refresh'?$auth[1]:min($auth[1],$sensitive)];}
        $read=(int)Env::get('READ_RATE_LIMIT','300');
        if($method==='GET' || $method==='HEAD'){
            return $path==='/api/v1/products/search'?['search',min((int)Env::get('SEARCH_RATE_LIMIT','30'),$read)]:['read',$read];
        }
        return match(true){
            $path==='/api/v1/orders'=>['create-order',min(10,$sensitive)],
            str_ends_with($path,'/reviews')=>['create-review',min(10,$sensitive)],
            str_contains($path,'/payments/') || str_contains($path,'/change-password')=>['sensitive-mutation',$sensitive],
            default=>['mutation',(int)Env::get('MUTATION_RATE_LIMIT','120')],
        };
    }
}
