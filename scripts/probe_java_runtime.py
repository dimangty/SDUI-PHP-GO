#!/usr/bin/env python3
"""Probe OpenSearch Java inventory/trust without published ports or network access."""
import argparse
from pathlib import Path
import subprocess


def docker(*args, **kwargs):
    kwargs.setdefault('timeout', 30)
    return subprocess.run(['docker', *args], check=True, text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.STDOUT, **kwargs).stdout


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--mode', choices=('opensearch-inventory', 'opensearch-truststore'),
                        default='opensearch-inventory')
    parser.add_argument('--image')
    args = parser.parse_args()
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
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
