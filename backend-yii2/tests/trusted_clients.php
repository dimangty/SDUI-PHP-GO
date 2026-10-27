<?php
declare(strict_types=1);
require __DIR__.'/harness.php';

use Shop\infrastructure\Network;

function trustEnv(array $values,Closure $test): void {
    $old=[];foreach($values as $key=>$value){$old[$key]=getenv($key);putenv($key.'='.$value);}
    try{$test();}finally{foreach($old as $key=>$value){putenv($value===false?$key:$key.'='.$value);}}
}

test('exact BFF replicas preserve independently bucketed IPv4 and IPv6 clients',function(){
    trustEnv(['TRUSTED_BFF_IPS'=>'172.30.95.4,172.30.95.5,fd00:95::4'],function(){
        foreach(['172.30.95.4','172.30.95.5','fd00:95::4'] as $peer){
            same('198.51.100.1',Network::client($peer,['x-shop-rate-client-ip'=>'198.51.100.1']));
            same('2001:db8:1::1',Network::client($peer,['x-shop-rate-client-ip'=>'2001:db8:1::1']));
            same('198.51.100.1',Network::client($peer,['x-shop-rate-client-ip'=>'::ffff:198.51.100.1']));
        }
    });
});
test('untrusted container and raw forwarded headers cannot assert rate context',function(){
    trustEnv(['TRUSTED_BFF_IPS'=>'172.30.95.4','TRUSTED_PROXY_CIDRS'=>'172.30.95.0/24'],function(){
        $headers=['x-forwarded-for'=>'203.0.113.1','x-real-ip'=>'203.0.113.2','forwarded'=>'for=203.0.113.3','x-shop-rate-client-ip'=>'203.0.113.4'];
        same('172.30.95.99',Network::client('172.30.95.99',$headers));
        same('172.30.95.4',Network::client('172.30.95.4',array_diff_key($headers,['x-shop-rate-client-ip'=>true])));
    });
});
test('BFF trust is disabled by default and malformed original client context fails closed',function(){
    trustEnv(['TRUSTED_BFF_IPS'=>''],fn()=>same('172.30.95.4',Network::client('172.30.95.4',['x-shop-rate-client-ip'=>'203.0.113.1'])));
    trustEnv(['TRUSTED_BFF_IPS'=>'172.30.95.4'],function(){
        foreach(['','203.0.113.1, 198.51.100.1','203.0.113.1:80','[2001:db8::1]','fe80::1%eth0',"203.0.113.1\r\nX-Admin: true",['203.0.113.1']] as $value){
            same('172.30.95.4',Network::client('172.30.95.4',['x-shop-rate-client-ip'=>$value]));
        }
    });
});
test('CIDRs unspecified addresses hostnames and partially invalid lists cannot enable BFF trust',function(){
    foreach(['0.0.0.0/0','::/0','172.30.95.0/24','172.30.95.4/32','0.0.0.0','::','sdui','172.30.95.4,garbage'] as $list){
        trustEnv(['TRUSTED_BFF_IPS'=>$list],function(){
            try{Network::client('172.30.95.4',['x-shop-rate-client-ip'=>'203.0.113.1']);}
            catch(RuntimeException $e){truth(str_contains($e->getMessage(),'TRUSTED_BFF_IPS'));return;}
            throw new RuntimeException('Unsafe BFF peer configuration accepted');
        });
    }
});
runTests();
