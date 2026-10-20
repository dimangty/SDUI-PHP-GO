<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

$tests = [];
function test(string $name, Closure $test): void { global $tests; $tests[$name] = $test; }
function same(mixed $expected, mixed $actual): void {
    $normalize = function (mixed $v) use (&$normalize): mixed {
        if (!is_array($v)) { return $v; }
        if (!array_is_list($v)) { ksort($v); }
        return array_map($normalize, $v);
    };
    $expected=$normalize($expected); $actual=$normalize($actual);
    if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function truth(bool $value): void { same(true, $value); }
function errorCode(string $code, Closure $operation): void {
    try { $operation(); } catch (\Shop\domain\ApiError $e) { same($code, $e->errorCode); return; }
    throw new RuntimeException("Expected $code");
}
function runTests(): void {
    global $tests; $failures = 0;
    foreach ($tests as $name => $test) {
        try { $test(); print "PASS $name\n"; }
        catch (Throwable $e) { $failures++; print "FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n"; }
    }
    print count($tests) . " tests, $failures failures\n";
    exit($failures === 0 ? 0 : 1);
}
