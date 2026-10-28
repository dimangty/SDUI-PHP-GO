#!/usr/bin/env python3
"""Probe new Java images only; never a deployed realm/database.

No published ports or network access. Exit 2 means insufficient Docker VM memory.
TLS material is generated for this test and removed with the temporary directory.
"""
import argparse
from pathlib import Path
import re
import signal
import subprocess
import tempfile
import time
import uuid


def docker(*args, **kwargs):
    kwargs.setdefault('timeout', 30)
    return subprocess.run(['docker', *args], check=True, text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.STDOUT, **kwargs).stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('keycloak-management', 'opensearch-inventory', 'opensearch-truststore'),
                        default='keycloak-management')
    parser.add_argument('--image')
    parser.add_argument('--parent', default=tempfile.gettempdir(), help='Parent for disposable TLS files')
    args = parser.parse_args()
    def interrupted(signum, _frame):
        raise SystemExit(128 + signum)
    signal.signal(signal.SIGTERM, interrupted)
    if args.mode == 'opensearch-truststore':
        # Unmodified OpenJDK checker: inspect the actual default JSSE trust
        # managers, not aliases or a substitute application/test keystore.
        checker = Path(__file__).resolve().parents[1] / 'deploy/tests/CheckCerts.java'
        result = subprocess.run(['docker', 'run', '--rm', '--network', 'none', '--memory', '256m',
                                 '-v', f'{checker}:/test/CheckCerts.java:ro',
                                 '--entrypoint', '/usr/share/opensearch/jdk/bin/java',
                                 args.image or 'shopkmp-a1-opensearch', '-Xms16m', '-Xmx128m',
                                 '-XX:ActiveProcessorCount=2', '/test/CheckCerts.java'],
                                text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=60)
        print(result.stdout, end='')
        return result.returncode
    if args.mode == 'opensearch-inventory':
        image = args.image or 'shopkmp-a1-opensearch'
        # Only a bounded CLI JVM; no OpenSearch server is started.
        output = docker('run', '--rm', '--network', 'none', '--memory', '256m',
                        '--hostname', 'java-probe', '--add-host', 'java-probe:127.0.0.1',
                        '-e', 'OPENSEARCH_JAVA_OPTS=-Xms32m -Xmx128m -XX:ActiveProcessorCount=2',
                        '--entrypoint', '/bin/bash', image, '-ec',
                        'test -d modules/transport-netty4; test -d modules/analysis-common; '
                        'bin/opensearch-plugin list')
        plugins = set(output.splitlines())
        assert 'opensearch-security' in plugins, 'Required security/TLS plugin missing'
        unused = {'opensearch-performance-analyzer', 'opensearch-skills', 'opensearch-ml',
                  'opensearch-flow-framework', 'opensearch-neural-search',
                  'opensearch-security-analytics', 'opensearch-anomaly-detection',
                  'opensearch-observability', 'opensearch-reports-scheduler',
                  'opensearch-notifications', 'opensearch-notifications-core', 'opensearch-sql'}
        assert not plugins & unused, f'Unused vulnerable plugins remain: {sorted(plugins & unused)}'
        print('PASS: official plugin CLI inventory; security/TLS, core transport and analysis retained; '
              'unused analytics/ML/SQL plugins absent')
        print(output, end='')
        return 0
    args.image = args.image or 'shopkmp-a1-keycloak'
    # Read VM memory rather than macOS host memory. No extra JVM for this check.
    memory = docker('run', '--rm', '--network', 'none', '--memory', '16m',
                    '--entrypoint', '/bin/bash', args.image, '-c',
                    'while IFS= read -r line; do case "$line" in MemAvailable:*) '
                    'printf "%s\\n" "$line";; esac; done < /proc/meminfo')
    available = int(re.search(r'MemAvailable:\s+(\d+)', memory)[1])
    if available < 1024 * 1024:
        print(f'BLOCKED: Docker VM has {available // 1024} MiB available; '
              'isolated 768 MiB JVM probe requires at least 1024 MiB available.')
        return 2

    with tempfile.TemporaryDirectory(prefix='shopkmp-a1-management-', dir=args.parent) as directory:
        path = Path(directory)
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                        '-days', '1', '-subj', '/CN=keycloak',
                        '-addext', 'subjectAltName=DNS:keycloak',
                        '-addext', 'basicConstraints=critical,CA:TRUE',
                        '-keyout', str(path / 'tls.key'), '-out', str(path / 'tls.crt')],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        (path / 'tls.key').chmod(0o644)  # Test key; parent is private, mounted read-only.
        # Main listener uses TLS in both cases, isolating the management option.
        for scheme, url, other_url in (
                ('http', 'http://127.0.0.1:9000/health/ready', 'https://keycloak:9000/health/ready'),
                ('inherited', 'https://keycloak:9000/health/ready', 'http://127.0.0.1:9000/health/ready')):
            name = 'shopkmp-a1-management-' + uuid.uuid4().hex[:12]
            try:
                docker('run', '-d', '--name', name, '--network', 'none', '--memory', '768m',
                       '--memory-swap', '768m', '--cpus', '2', '--add-host', 'keycloak:127.0.0.1',
                       '-v', f'{path / "tls.crt"}:/test-tls/tls.crt:ro',
                       '-v', f'{path / "tls.key"}:/test-tls/tls.key:ro',
                       '-e', 'JAVA_OPTS_KC_HEAP=-Xms64m -Xmx256m -XX:ActiveProcessorCount=2',
                       '-e', 'KC_HEALTH_ENABLED=true', '-e', 'KC_METRICS_ENABLED=true',
                       '-e', 'KC_DB=dev-mem', '-e', 'KC_HTTP_ENABLED=false',
                       '-e', 'KC_HOSTNAME=https://keycloak:8443',
                       '-e', 'KC_HTTPS_CERTIFICATE_FILE=/test-tls/tls.crt',
                       '-e', 'KC_HTTPS_CERTIFICATE_KEY_FILE=/test-tls/tls.key',
                       '-e', f'KC_HTTP_MANAGEMENT_SCHEME={scheme}',
                       '-e', f'HEALTHCHECK_URL={url}', '-e', 'SSL_CERT_FILE=/test-tls/tls.crt',
                       args.image, 'start')
                deadline = time.monotonic() + 180
                while True:
                    probe = subprocess.run(['docker', 'exec', name,
                                            '/opt/keycloak/bin/shopkmp-healthcheck'],
                                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                    if probe.returncode == 0:
                        break
                    if docker('inspect', '--format', '{{.State.Running}}', name).strip() != 'true':
                        raise AssertionError('Isolated Keycloak exited: ' + docker('logs', name))
                    if time.monotonic() >= deadline:
                        raise AssertionError('Readiness timed out: ' + docker('logs', name))
                    time.sleep(2)
                wrong_scheme = subprocess.run(['docker', 'exec', '-e', f'HEALTHCHECK_URL={other_url}',
                                               name, '/opt/keycloak/bin/shopkmp-healthcheck'],
                                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                assert wrong_scheme.returncode == 1, 'Opposite management scheme unexpectedly accepted'
                wrong_ca = subprocess.run(['docker', 'exec', '-e', 'SSL_CERT_FILE=/absent-test-ca',
                                          '-e', 'SSL_CERT_DIR=/absent-test-cas', name,
                                          '/opt/keycloak/bin/shopkmp-healthcheck'],
                                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                if scheme == 'inherited':
                    assert wrong_ca.returncode == 1, 'HTTPS probe accepted an untrusted certificate'
                print(f'PASS: management={scheme}, correct scheme ready, opposite scheme rejected'
                      + (', untrusted TLS rejected' if scheme == 'inherited' else ''))
            finally:
                subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, check=False)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
