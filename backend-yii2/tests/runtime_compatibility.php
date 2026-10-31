<?php
declare(strict_types=1);
require __DIR__.'/harness.php';
require __DIR__.'/SocketUpstream.php';

use Shop\application\AvatarService;
use Shop\infrastructure\{Database,HttpClient};
use Shop\repositories\ShopRepository;

/** Match a framework boundary that promotes runtime diagnostics to exceptions. */
function strictRuntime(Closure $work): mixed {
    set_error_handler(static function (int $level,string $message,string $file,int $line): bool {
        if (!(error_reporting() & $level)) { return false; }
        throw new ErrorException($message,0,$level,$file,$line);
    });
    try { return $work(); } finally { restore_error_handler(); }
}

test('HTTP success remains a clean DTO when runtime diagnostics are exceptions', function () {
    $server=new SocketUpstream(static function ($peer) {
        fwrite($peer,"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    });
    try {
        $response=strictRuntime(fn()=>(new HttpClient())->request('GET',$server->origin));
        same(200,$response['status']); same('{}',$response['body']);
    } finally { same(1,$server->finish()); }
});

test('HTTP response limit preserves the primary failure across native handle cleanup', function () {
    $server=new SocketUpstream(static function ($peer) {
        fwrite($peer,"HTTP/1.1 200 OK\r\nContent-Length: 3\r\nConnection: close\r\n\r\nabc");
    });
    try {
        try { strictRuntime(fn()=>(new HttpClient(2,2))->request('GET',$server->origin)); throw new RuntimeException('Expected body limit'); }
        catch (Throwable $error) { same('Upstream response limit exceeded',$error->getMessage()); }
    } finally { same(1,$server->finish()); }
});

test('avatar normalization returns a valid bounded PNG without native cleanup diagnostics', function () {
    $image=imagecreatetruecolor(2,2); ob_start(); imagepng($image); $bytes=ob_get_clean(); unset($image);
    $db=new Database('postgres://unused/unused');
    $service=new AvatarService($db,new ShopRepository($db));
    $png=strictRuntime(fn()=>$service->normalize($bytes));
    same([2,2,IMAGETYPE_PNG],array_slice(getimagesizefromstring($png),0,3));
});

test('HTTP fixture survives a caller pause between independent requests until explicit shutdown', function () {
    $server=new SocketUpstream(static function ($peer) {
        fwrite($peer,"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    });
    try {
        same(200,(new HttpClient(2))->request('GET',$server->origin)['status']);
        // Deliberately exceed the old 400 ms fixture lifecycle cutoff. This is
        // not an application timeout or a relaxed HTTP deadline.
        usleep(600000);
        same(200,(new HttpClient(2))->request('GET',$server->origin)['status']);
    } finally { $requests=$server->finish(); }
    same(2,$requests);
});

test('explicit fixture shutdown wakes an idle listener instead of waiting for accept timeout', function () {
    $server=new SocketUpstream(static function () { throw new RuntimeException('No request expected'); });
    $started=hrtime(true);
    same(0,$server->finish());
    truth((hrtime(true)-$started)/1e9<2);
});

runTests();
