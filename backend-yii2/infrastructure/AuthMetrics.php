<?php
declare(strict_types=1);
namespace Shop\infrastructure;

/** Log-based counters/histograms. Fixed labels only; no identifiers or exception text. */
final class AuthMetrics
{
    private const OPERATIONS=[
        'Auth.register'=>'register','Auth.login'=>'login','Auth.refresh'=>'refresh',
        'Auth.logout'=>'logout','Auth.changePassword'=>'change_password',
        'Credential.loginPin'=>'pin_login','Credential.loginBiometric'=>'biometric_login',
        'Credential.challenge'=>'biometric_challenge','Credential.setPin'=>'set_pin',
        'Credential.changePin'=>'change_pin','Credential.enroll'=>'enroll_biometric',
        'Credential.deletePin'=>'revoke_credential','Credential.deleteBiometric'=>'revoke_credential',
    ];
    public static function request(string $operation,string $code,float $seconds): void
    {
        if(!isset(self::OPERATIONS[$operation])){return;}
        $outcome=match($code){
            'ok'=>'success',
            'INVALID_CREDENTIALS','INVALID_PIN','INVALID_ASSERTION','INVALID_REFRESH_TOKEN','UNAUTHORIZED'=>'invalid_credentials',
            'ACCOUNT_LOCKED','PIN_LOCKED','BIOMETRIC_LOCKED'=>'locked',
            'RATE_LIMITED'=>'rate_limited','DATABASE_UNAVAILABLE'=>'storage_error',
            default=>'rejected',
        };
        self::emit(['metric'=>'auth_request','operation'=>self::OPERATIONS[$operation],'outcome'=>$outcome],$seconds);
    }
    public static function hash(string $stage,float $seconds): void
    {
        if(!in_array($stage,['hash','verify'],true)){return;}
        self::emit(['metric'=>'auth_hash_work','stage'=>$stage],$seconds);
    }
    private static function emit(array $labels,float $seconds): void
    {
        if(!is_finite($seconds) || $seconds<0){return;}
        error_log(json_encode($labels+['duration_ms'=>round(min($seconds,86400)*1000,3)],JSON_THROW_ON_ERROR));
    }
}
