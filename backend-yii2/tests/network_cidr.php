<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
use Shop\infrastructure\Network;
foreach([
    ['198.51.100.77','203.0.113.0/24',false],['203.0.113.255','203.0.113.0/24',true],
    ['198.51.100.77','203.0.113.0/0',true],['203.0.113.1','203.0.113.1/32',true],
    ['203.0.113.2','203.0.113.1',false],['203.0.113.1','203.0.113.1',true],
    ['2001:db8::1','2001:db8::/32',true],['2001:db9::1','2001:db8::/32',false],
    ['2001:db9::1','::/0',true],['2001:db8::1','2001:db8::1/128',true],
    ['2001:db8::2','2001:db8::1',false],['2001:db8::1','2001:db8::1',true],
    ['::ffff:203.0.113.1','203.0.113.0/24',false],
    ['203.0.113.1','::ffff:203.0.113.0/120',false],
    ['::ffff:203.0.113.1','::ffff:203.0.113.0/120',true],
] as [$ip,$cidr,$expected]){test("CIDR $ip in $cidr",fn()=>same($expected,Network::contains($ip,$cidr)));}
foreach(['203.0.113.0','2001:db8::'] as $network){
    foreach(['','garbage','0garbage','-1','+1','1/2',' 1','1 ','1.0','999999999999999999999','129','33x'] as $suffix){
        test("CIDR rejects entire prefix $network/$suffix",function()use($network,$suffix){
            try{Network::contains('198.51.100.77',$network.'/'.$suffix);}catch(InvalidArgumentException){return;}
            throw new RuntimeException('Malformed CIDR was accepted');
        });
    }
}
foreach(['203.0.113.0/33','2001:db8::/129','300.1.1.1/24','hostname/24','/0','203.0.113.0//0'] as $cidr){
    test("CIDR rejects $cidr",function()use($cidr){
        try{Network::allowed('203.0.113.1','203.0.113.0/24,'.$cidr);}catch(InvalidArgumentException){return;}
        throw new RuntimeException('Partial list acceptance');
    });
}
test('CIDR empty list denies but an empty member invalidates a nonempty list',function(){
    same(false,Network::allowed('203.0.113.1',''));
    foreach(['203.0.113.0/24,',',203.0.113.0/24'] as $list){
        try{Network::allowed('203.0.113.1',$list);throw new RuntimeException('Empty member accepted');}catch(InvalidArgumentException){}
    }
});
runTests();
