<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

use Shop\domain\Money;
use Shop\domain\Secret;
use Shop\domain\Rules;
use Shop\infrastructure\JsonInput;

test('money retains cents at NUMERIC boundary without float rounding', function () {
    same(999999999999, Money::parse('9999999999.99'));
    same('9999999999.99', Money::format(999999999999));
    same('0.30', Money::format(Money::parse('0.10') + Money::parse('0.20')));
    same('-0.01', Money::format(Money::parse('-0.01')));
    same(120, Money::parse('1.2'));
});
test('money rejects fractional excess and checked total overflow', function () {
    errorCode('BAD_REQUEST', fn() => Money::parse('1.001'));
    errorCode('BAD_REQUEST', fn() => Money::parse('999999999999999999999999'));
    errorCode('AMOUNT_TOO_LARGE', fn() => Money::line(999999999999, 2));
    errorCode('AMOUNT_TOO_LARGE', fn() => Money::add(999999999999, 1));
});
test('legacy Argon2id hex encoding interoperates with PHP native verifier', function () {
    truth(Secret::verify('7395','argon2id$v=19$m=19456,t=2,p=1$000102030405060708090a0b0c0d0e0f$d6e888e081e7252f4945dfb1a356bf954208fdb9347ef8f44b1d1a9ec67494cf'));
    $hash = Secret::hash('7395');
    truth((bool)preg_match('/^argon2id\$v=19\$m=19456,t=2,p=1\$[a-f0-9]{32}\$[a-f0-9]{64}$/D', $hash));
    truth(Secret::verify('7395', $hash));
    same(false, Secret::verify('7396', $hash));
    $p = explode('$', $hash);
    $native = '$argon2id$v=19$'.$p[2].'$'.rtrim(base64_encode(hex2bin($p[3])), '=').'$'.rtrim(base64_encode(hex2bin($p[4])), '=');
    truth(password_verify('7395', $native));
    same(false, Secret::verify('7395', str_replace('m=19456', 'm=9999999', $hash)));
});
test('PIN rejects patterns and profile birthday but accepts strong PIN', function () {
    foreach (['1234','9876','1111','1212','1122','2580','2902','2004'] as $pin) {
        errorCode('WEAK_PIN', fn() => Rules::pin($pin, '2004-02-29'));
    }
    Rules::pin('7395', null);
    errorCode('INVALID_PIN_FORMAT', fn() => Rules::pin('7a95', null));
});
test('JSON boundary rejects unknown keys, coercions, arrays and trailing objects', function () {
    same(['quantity'=>2], JsonInput::decode('{"quantity":2}', 'application/json', ['quantity'=>'int']));
    foreach (['{"quantity":"2"}','{"quantity":2.0}','{"quantity":true}','{"quantity":2,"admin":true}','[]','{} {}','null'] as $body) {
        errorCode('BAD_REQUEST', fn() => JsonInput::decode($body, 'application/json', ['quantity'=>'int']));
    }
    errorCode('UNSUPPORTED_MEDIA_TYPE', fn() => JsonInput::decode('{}', 'text/plain', []));
    errorCode('REQUEST_TOO_LARGE', fn() => JsonInput::decode('{}', 'application/json', [], 1));
});
test('route manifest has all canonical operations and strict access policy', function () {
    $routes = require dirname(__DIR__) . '/config/routes.php';
    $contract = json_decode(file_get_contents(dirname(__DIR__).'/contracts/routes.json'),true,512,JSON_THROW_ON_ERROR);
    same(1,$contract['schemaVersion']);same('/api/v1',$contract['prefix']);
    $expected=[];foreach($contract['routes'] as $route){$expected[$route['method'].' '.$route['path']]=$route['access'];}
    $actual=[];foreach($routes as $route){$actual[$route[0]]=$route[3];}
    ksort($expected);ksort($actual);same($expected,$actual);
    same(54, count($routes));same(54,count($actual));
    same(7, count(array_filter($routes, fn($r) => $r[3] === 'admin')));
    foreach ($routes as $r) { truth(method_exists('Shop\\application\\' . $r[1] . 'Service', $r[2])); }
});
test('timestamps use Go RFC3339Nano representation without redundant fractional zeros',function(){
    same('2026-10-18T00:00:00Z',\Shop\domain\Ids::timestamp('2026-10-18 00:00:00.000000+00'));
    same('2026-10-18T00:00:00.1234Z',\Shop\domain\Ids::timestamp('2026-10-18 00:00:00.123400+00'));
});
runTests();
