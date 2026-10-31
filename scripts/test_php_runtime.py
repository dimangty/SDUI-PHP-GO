#!/usr/bin/env python3
"""Check the built default PHP image, offline and without application services.

Usage: python3 scripts/test_php_runtime.py --image shopkmp-a1-php
Does not build/tag images or touch a running stack. All containers are disposable.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import time
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[1]


def docker(*args):
    return subprocess.run(['docker', *map(str, args)], text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)


def runtime_tests(image):
    class PhpRuntime(unittest.TestCase):
        def run_image(self, *command):
            result = docker('run', '--rm', '--network', 'none', image, *command)
            self.assertEqual(0, result.returncode, result.stdout)
            return result.stdout

        def test_no_build_packages_compilers_or_headers_in_runtime(self):
            # Retaining PHPIZE_DEPS or any extension -dev dependency must fail.
            packages = self.run_image('sh', '-ec', r'''
                if test -f /lib/apk/db/installed; then
                    apk --no-network --repositories-file /dev/null info -q
                elif test -f /var/lib/dpkg/status; then
                    dpkg-query -W -f='${Package} ${db:Status-Status}\n' \
                        | while read -r package status; do
                            if test "$status" = installed; then printf '%s\n' "$package"; fi
                        done
                else
                    printf 'Unsupported package inventory\n' >&2; exit 1
                fi
            ''')
            installed = packages.splitlines()
            self.assertGreater(len(installed), 10, 'Package inventory must not silently be empty')
            self.assertTrue(all(re.fullmatch(r'[a-z0-9][a-z0-9+_.-]*', name) for name in installed),
                            f'Unexpected package inventory: {packages}')
            forbidden = [name for name in installed if name.endswith('-dev') or name in {
                'build-essential', 'build-base', 'autoconf', 'automake', 'dpkg-dev', 'make',
                'gcc', 'g++', 'cpp', 're2c', 'pkg-config', 'pkgconf', 'binutils',
                'linux-headers', 'fortify-headers',
            } or re.fullmatch(r'(?:clang|llvm)\d*(?:-.*)?', name)
              or (name.startswith(('gcc-', 'g++-', 'cpp-', 'binutils-'))
                  and not name.endswith('-base'))]
            self.assertEqual([], forbidden, f'Build-only packages remain: {forbidden}')
            self.run_image('php', '-r', r'''
                foreach (['/usr/include', '/usr/local/include', '/usr/src', '/usr/lib', '/usr/local/lib'] as $root) {
                    if (!is_dir($root)) { continue; }
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                    foreach ($files as $file) {
                        if (in_array($file->getExtension(), ['h', 'hpp', 'a'], true) || str_contains($file->getFilename(), 'php.tar')) {
                            fwrite(STDERR, "Build artifact remains: $file\n"); exit(1);
                        }
                    }
                }
                foreach (['gcc', 'g++', 'cc', 'make', 'phpize', 'php-config'] as $tool) {
                    exec('command -v '.escapeshellarg($tool), $output, $status);
                    if ($status === 0) { fwrite(STDERR, "Build tool remains: $tool\n"); exit(1); }
                }
                echo "No development packages, headers or compilers\n";
            ''')

        def test_shared_libraries_resolve_after_package_pruning(self):
            # Check both executables. musl ldd also relocates shared objects outside
            # their host, so validate every extension in the real PHP host below.
            output = self.run_image('php', '-r', r'''
                $files = ['/usr/local/bin/php', '/usr/local/sbin/php-fpm'];
                foreach ($files as $file) {
                    $lines = []; exec('ldd '.escapeshellarg($file).' 2>&1', $lines, $status);
                    if ($status !== 0 || str_contains(implode("\n", $lines), 'not found')) {
                        fwrite(STDERR, "$file: ".implode("\n", $lines)); exit(1);
                    }
                }
                echo "Executables resolve\n";
            ''')
            self.assertEqual('Executables resolve\n', output, 'PHP startup/loader diagnostics')
            extensions = json.loads(self.run_image('php', '-r',
                "echo json_encode(glob(ini_get('extension_dir').'/*.so'), JSON_THROW_ON_ERROR);"))
            self.assertGreaterEqual(len(extensions), 6)
            options = []
            names = []
            for extension in extensions:
                name = Path(extension).stem
                options.extend(['-d', ('zend_extension=' if name == 'opcache' else 'extension=') + extension])
                names.append('Zend OPcache' if name == 'opcache' else name)
            # -n prevents the regular ini from masking an omitted extension; eager
            # binding plus exact clean output catches missing libraries AND symbols.
            probe = '''foreach (json_decode($argv[1]) as $name) {
                if (!extension_loaded($name)) { fwrite(STDERR, "Not loaded: $name"); exit(1); }
            } echo "All shared extensions loaded cleanly\\n";'''
            output = self.run_image('env', 'LD_BIND_NOW=1', 'php', '-n', *options,
                                    '-r', probe, json.dumps(names))
            self.assertEqual('All shared extensions loaded cleanly\n', output)

        def test_php83_extensions_tls_image_codecs_and_job_processes_work(self):
            self.run_image('php', '-r', r'''
                function need($ok, $why) { if (!$ok) { fwrite(STDERR, $why."\n"); exit(1); } }
                need(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3, 'PHP 8.3 contract');
                need(PHP_INT_SIZE === 8 && PHP_INT_MAX === 9223372036854775807, '64-bit integer money contract');
                foreach (['pdo_pgsql', 'intl', 'mbstring', 'sodium', 'openssl', 'gd', 'curl', 'pcntl'] as $ext) {
                    need(extension_loaded($ext), "Missing extension: $ext");
                }
                need(in_array('pgsql', PDO::getAvailableDrivers(), true), 'PostgreSQL PDO driver');
                need(class_exists('Normalizer') && Normalizer::normalize("e\u{301}") === "é", 'ICU normalization');
                need(mb_strlen('é') === 1, 'Multibyte strings');
                $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES); $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                need(sodium_crypto_secretbox_open(sodium_crypto_secretbox('runtime', $nonce, $key), $nonce, $key) === 'runtime', 'Sodium');
                need(openssl_digest('runtime', 'sha256') === hash('sha256', 'runtime'), 'OpenSSL');
                need(in_array('https', curl_version()['protocols'], true), 'cURL HTTPS');
                $ca = openssl_get_cert_locations();
                need(is_readable($ca['default_cert_file']) && filesize($ca['default_cert_file']) > 1000, 'System CA bundle');
                need(is_readable('/etc/ssl/certs/ca-certificates.crt'), 'PostgreSQL/cURL CA bundle');
                need(gd_info()['FreeType Support'], 'GD FreeType support');
                $im = imagecreatetruecolor(2, 2);
                foreach (['png', 'jpeg', 'webp'] as $codec) {
                    ob_start(); ('image'.$codec)($im); $bytes = ob_get_clean();
                    need(imagecreatefromstring($bytes) !== false, "GD $codec round trip");
                }
                $pid = pcntl_fork(); need($pid >= 0, 'Job fork');
                if ($pid === 0) { exit(23); }
                need(pcntl_waitpid($pid, $status) === $pid && pcntl_wexitstatus($status) === 23, 'Job wait/reap');
                $child = proc_open([PHP_BINARY, '-r', 'exit(17);'], [], $pipes);
                need(is_resource($child) && proc_close($child) === 17, 'Scheduler proc_open');
                echo PHP_VERSION." extensions, codecs, CA and child processes OK\n";
            ''')
            # Inspect the linked libpq itself; SSL support is a runtime dependency.
            output = self.run_image('php', '-r', r'''
                $pq = array_merge(glob('/usr/lib/libpq.so.5'), glob('/usr/lib/*/libpq.so.5'));
                if (count($pq) !== 1) { fwrite(STDERR, 'Expected exactly one libpq'); exit(1); }
                passthru('ldd '.escapeshellarg($pq[0]), $status); exit($status);
            ''')
            self.assertIn('libssl.so', output)
            self.assertIn('libcrypto.so', output)
            # Exercise the application's actual PDO/libpq client on loopback, with
            # --network none. The peer speaks only PostgreSQL SSL negotiation,
            # then returns a deliberate authentication error over TLS; no SQL,
            # database, application credentials or external services are used.
            self.run_image('php', '-r', r'''
                function need($ok, $why) { if (!$ok) { fwrite(STDERR, $why."\n"); exit(1); } }
                require '/app/vendor/autoload.php';
                $dir = sys_get_temp_dir().'/a1-pg-tls-'.bin2hex(random_bytes(6));
                need(mkdir($dir, 0700), 'TLS fixture directory');
                $owner = getmypid();
                register_shutdown_function(function () use ($dir, $owner) {
                    if (getmypid() !== $owner) { return; }
                    foreach (glob($dir.'/*') as $file) { unlink($file); } rmdir($dir);
                });
                file_put_contents($dir.'/openssl.cnf', "[req]\ndistinguished_name=dn\n[dn]\n[cert]\nsubjectAltName=DNS:localhost\nbasicConstraints=critical,CA:TRUE\n");
                $options = ['config'=>$dir.'/openssl.cnf', 'digest_alg'=>'sha256'];
                $key = openssl_pkey_new(['private_key_bits'=>2048]);
                $csr = openssl_csr_new(['commonName'=>'localhost'], $key, $options);
                $cert = openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions'=>'cert']);
                need($cert !== false && openssl_x509_export_to_file($cert, $dir.'/cert.pem')
                    && openssl_pkey_export_to_file($key, $dir.'/key.pem'), 'TLS fixture certificate');
                foreach ([['localhost', $dir.'/cert.pem', 'A1 verified TLS peer'],
                          ['127.0.0.1', $dir.'/cert.pem', 'does not match host name'],
                          ['localhost', '/etc/ssl/certs/ca-certificates.crt', 'certificate verify failed']] as [$host, $ca, $expected]) {
                    $context = stream_context_create(['ssl'=>['local_cert'=>$dir.'/cert.pem', 'local_pk'=>$dir.'/key.pem']]);
                    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
                    need($server !== false, 'TLS peer listen');
                    $port = substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
                    $pid = pcntl_fork(); need($pid >= 0, 'TLS peer fork');
                    if ($pid === 0) {
                        // Only the parent owns fixture cleanup.
                        $peer = @stream_socket_accept($server, 5);
                        if (!$peer) { exit(2); }
                        stream_set_timeout($peer, 5);
                        $request = stream_get_contents($peer, 8);
                        if ($request === pack('NN', 8, 80877103)) {
                            fwrite($peer, 'S');
                            if (@stream_socket_enable_crypto($peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                                $header = @stream_get_contents($peer, 4);
                                if (strlen($header) === 4) {
                                    $length = unpack('N', $header)[1];
                                    if ($length < 8 || $length > 4096) { exit(3); }
                                    stream_get_contents($peer, $length-4);
                                    $body = "SFATAL\0C28000\0MA1 verified TLS peer\0\0";
                                    fwrite($peer, 'E'.pack('N', strlen($body)+4).$body);
                                }
                            }
                        }
                        fclose($peer); fclose($server);
                        exit(0);
                    }
                    fclose($server);
                    // Same CA environment and sslmode URL as production wiring.
                    putenv('PGSSLROOTCERT='.$ca);
                    try {
                        (new \Shop\infrastructure\Database("postgres://runtime@$host:$port/runtime?sslmode=verify-full"))->pdo();
                        $message = 'Unexpected database connection';
                    } catch (PDOException $e) { $message = $e->getMessage(); }
                    pcntl_waitpid($pid, $status);
                    need(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'TLS peer completed');
                    need(str_contains($message, $expected), "libpq verify-full ($host): $message");
                }
                echo "PDO/libpq verify-full: trusted hostname accepted, wrong hostname and unknown CA rejected\n";
            ''')

        def test_composer_locked_dependencies_and_fixture_cli_remain_available(self):
            expected = hashlib.sha256((ROOT / 'backend-yii2/composer.lock').read_bytes()).hexdigest()
            actual = self.run_image('php', '-r', 'echo hash_file("sha256", "/app/composer.lock");')
            self.assertEqual(expected, actual, 'Image changed the application lockfile')
            self.run_image('composer', '--version')
            self.run_image('composer', 'validate', '--no-check-publish')
            self.run_image('composer', 'check-platform-reqs', '--no-dev')
            self.run_image('php', '-r', r'''
                require '/app/vendor/autoload.php';
                foreach (['/app/yii', '/app/tests/run.php', '/app/tests/integration.php', '/app/tests/lint.php'] as $file) {
                    if (!is_readable($file)) { fwrite(STDERR, "Missing CLI/fixture: $file\n"); exit(1); }
                }
                foreach (['yii\\base\\Application', 'Shop\\application\\AuthService', 'Shop\\application\\SessionService'] as $class) {
                    if (!class_exists($class)) { exit(1); }
                }
            ''')
            self.run_image('php', '-r', r'''
                foreach (['/var/lib/apt/lists', '/var/cache/apk', '/etc/apk/cache'] as $path) {
                    if (is_dir($path) && count(array_diff(scandir($path), ['.', '..'])) !== 0) {
                        fwrite(STDERR, "Package download cache remains: $path\n"); exit(1);
                    }
                }
            ''')
            self.run_image('php', '-r', 'if (ini_get("upload_max_filesize") !== "5M" || ini_get("post_max_size") !== "6M") { exit(1); }')

        def test_deployed_fpm_config_starts_and_serves_fastcgi_ping(self):
            name = 'shopkmp-a1-php-fpm-' + uuid.uuid4().hex[:12]
            result = docker('run', '-d', '--name', name, '--network', 'none',
                            '-e', 'FPM_TRUSTED_NGINX_IPS=127.0.0.1',
                            '-v', f'{ROOT}/deploy/php/fpm.conf:/usr/local/etc/php-fpm.d/zzz-shopkmp.conf:ro', image)
            self.addCleanup(docker, 'rm', '-f', name)
            self.assertEqual(0, result.returncode, result.stdout)
            config = docker('exec', name, 'php-fpm', '-tt')
            self.assertEqual(0, config.returncode, config.stdout)
            # Real FastCGI request: no nginx, database, host port or application writes.
            probe = r'''
                function record($type, $body) { return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0).$body; }
                $socket = @stream_socket_client('tcp://127.0.0.1:9000', $errno, $error, 2);
                if (!$socket) { fwrite(STDERR, $error); exit(1); }
                stream_set_timeout($socket, 3);
                $params = '';
                foreach (['REQUEST_METHOD'=>'GET', 'SCRIPT_NAME'=>'/fpm-ping', 'SCRIPT_FILENAME'=>'/fpm-ping', 'REQUEST_URI'=>'/fpm-ping', 'SERVER_PROTOCOL'=>'HTTP/1.1'] as $key=>$value) {
                    $params .= chr(strlen($key)).chr(strlen($value)).$key.$value;
                }
                fwrite($socket, record(1, pack('nCxxxxx', 1, 0)).record(4, $params).record(4, '').record(5, ''));
                $out = '';
                while (!feof($socket)) {
                    $header = '';
                    while (strlen($header) < 8 && !feof($socket)) { $part = fread($socket, 8 - strlen($header)); if ($part === false || $part === '') { break; } $header .= $part; }
                    if (strlen($header) !== 8) { break; }
                    $h = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $header); $body = '';
                    while (strlen($body) < $h['length'] + $h['padding']) {
                        $part = fread($socket, $h['length'] + $h['padding'] - strlen($body));
                        if ($part === false || $part === '') { break 2; } $body .= $part;
                    }
                    if ($h['type'] === 6) { $out .= substr($body, 0, $h['length']); }
                    if ($h['type'] === 3) { break; }
                }
                fclose($socket);
                if (!str_contains($out, "\r\n\r\npong")) { fwrite(STDERR, $out); exit(1); }
                echo $out;
            '''
            deadline = time.monotonic() + 30
            while True:
                response = docker('exec', name, 'php', '-r', probe)
                if response.returncode == 0 or time.monotonic() >= deadline:
                    break
                time.sleep(0.2)
            logs = docker('logs', name).stdout
            self.assertEqual(0, response.returncode, response.stdout + logs)

    return unittest.defaultTestLoader.loadTestsFromTestCase(PhpRuntime)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', default='shopkmp-a1-php')
    args = parser.parse_args()
    inspect = docker('image', 'inspect', args.image)
    if inspect.returncode:
        parser.error(inspect.stdout)
    metadata = json.loads(inspect.stdout)[0]
    print(f'Checking {args.image}: {metadata["Id"]}', flush=True)
    result = unittest.TextTestRunner(verbosity=2).run(runtime_tests(args.image))
    raise SystemExit(not result.wasSuccessful())


if __name__ == '__main__':
    main()
