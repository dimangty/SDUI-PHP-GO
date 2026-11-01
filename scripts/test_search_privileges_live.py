#!/usr/bin/env python3
"""Explicit disposable TLS/action test. Never operates on an existing project."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run', action='store_true', required=True)
    parser.add_argument('--parent', required=True)
    parser.add_argument('--subnet', default='10.252.212.0/24')
    parser.add_argument('--php-image', required=True)
    parser.add_argument('--postgres-image', default=os.getenv('POSTGRES_TEST_IMAGE'))
    parser.add_argument('--opensearch-image', default=os.getenv('OPENSEARCH_TEST_IMAGE'))
    args = parser.parse_args()
    if not args.postgres_image or not args.opensearch_image:
        parser.error('Build/provide --postgres-image and --opensearch-image (or the matching *_TEST_IMAGE variables)')
    # No implicit pulls, builds, or machine-local historical defaults.
    subprocess.run(['docker', 'image', 'inspect', args.php_image, args.postgres_image, args.opensearch_image],
                   stdout=subprocess.DEVNULL, check=True)
    subnet = ipaddress.ip_network(args.subnet)
    networks = subprocess.check_output(['docker', 'network', 'ls', '-q'], text=True).split()
    if networks:
        existing = json.loads(subprocess.check_output(['docker', 'network', 'inspect', *networks]))
        for net in existing:
            for config in net.get('IPAM', {}).get('Config', []) or []:
                if config.get('Subnet') and subnet.overlaps(ipaddress.ip_network(config['Subnet'])):
                    raise RuntimeError('Requested disposable subnet already in use')
    project = 'shopkmp-round7-search-' + secrets.token_hex(4) + '-e2e'
    if subprocess.check_output(['docker', 'ps', '-a', '-q', '--filter', 'label=com.docker.compose.project=' + project], text=True).strip():
        raise RuntimeError('Disposable project is already in use')
    env_path = Path(subprocess.check_output([sys.executable, str(ROOT / 'scripts/test_pki.py'),
        '--parent', args.parent, '--project', project, '--edge-subnet', '10.252.214.0/24',
        '--fpm-subnet', '10.252.215.0/24'], text=True).strip())
    values = dict(line.split('=', 1) for line in env_path.read_text().splitlines())
    environment = os.environ | values
    raw = subprocess.run(['docker', 'compose', '--env-file', str(env_path), '-f', str(ROOT / 'docker-compose.yml'),
        '-f', str(ROOT / 'docker-compose.prod.yml'), '--profile', '*', 'config', '--format', 'json'],
        env=environment, capture_output=True, text=True)
    if raw.returncode:
        raise RuntimeError('Production model render failed (details suppressed)')
    services = json.loads(raw.stdout)['services']
    selected = {}
    for name in ('opensearch', 'postgres', 'search-security-bootstrap', 'search-security-provision'):
        service = services[name]
        service.pop('build', None)
        service.pop('depends_on', None)
        service['networks'] = {'application': None}
        service['restart'] = 'no'
        service['image'] = (args.php_image if name == 'search-security-provision'
                            else args.postgres_image if name == 'postgres'
                            else args.opensearch_image)
        selected[name] = service
    selected['opensearch']['mem_limit'] = '768m'
    selected['opensearch']['environment']['OPENSEARCH_JAVA_OPTS'] = '-Xms256m -Xmx256m'
    selected['opensearch']['volumes'] = [v if v['type'] != 'volume' else
        dict(v, source='search_data') for v in selected['opensearch']['volumes']]
    selected['postgres']['mem_limit'] = '192m'
    selected['postgres']['volumes'] = [v if v['type'] != 'volume' else
        dict(v, source='postgres_data') for v in selected['postgres']['volumes']]
    provision = selected['search-security-provision']
    provision['environment'].update({
        'SEARCH_TEST_DATABASE_URL':'postgres://shopkmp:' + values['POSTGRES_PASSWORD'] + '@postgres:5432/shopkmp?sslmode=verify-full',
        'PGSSLROOTCERT':'/run/tls/ca.crt',
        'SEARCH_TEST_SQL_RUNTIME_PASSWORD':values['POSTGRES_RUNTIME_PASSWORD'],
        'SEARCH_TEST_SQL_RECONCILE_PASSWORD':values['POSTGRES_SEARCH_PASSWORD'],
        'SEARCH_TEST_SQL_INDEXER_PASSWORD':values['POSTGRES_INDEXER_PASSWORD'],
    })
    for directory in ('infrastructure','repositories','application','domain'):
        provision['volumes'].append({'type':'bind','source':str(ROOT / 'backend-yii2' / directory),
                                    'target':'/app/' + directory,'read_only':True})
    provision['volumes'].append({
        'type':'bind','source':str(ROOT / 'deploy/search-privileges-test.php'),
        'target':'/opt/search-privileges-test.php','read_only':True})
    provision['volumes'].append({
        'type':'bind','source':str(ROOT / 'deploy/search-catalog-test.php'),
        'target':'/opt/search-catalog-test.php','read_only':True})
    config = {'name':project, 'services':selected, 'volumes':{'search_data':{},'postgres_data':{}},
              'networks':{'application':{'ipam':{'config':[{'subnet':str(subnet)}]}}}}
    path = env_path.parent / 'search-test.compose.json'
    path.write_text(json.dumps(config))
    path.chmod(0o600)
    compose = ['docker', 'compose', '-p', project, '-f', str(path)]
    print('Disposable project=' + project + ' subnet=' + str(subnet) + ' directory=' + str(env_path.parent), flush=True)

    def run(label, command, timeout=120, output=True):
        start = time.monotonic()
        result = subprocess.run(command, capture_output=True, text=True, timeout=timeout)
        print(f'{label}: exit={result.returncode} elapsed={time.monotonic()-start:.3f}s', flush=True)
        # No Compose config/inspect env or raw internal-user response is emitted.
        if output:
            print(result.stdout, end='', flush=True)
            print(result.stderr, end='', flush=True)
        if result.returncode:
            raise RuntimeError(label + ' failed')
        return result

    try:
        run('start owned TLS nodes', compose + ['up', '-d', '--no-build', '--pull', 'never', '--no-deps', 'opensearch', 'postgres'])
        started = time.monotonic()
        while time.monotonic() - started < 180:
            response = subprocess.run(compose + ['exec', '-T', 'opensearch', 'curl', '-sS', '--max-time', '2',
                '--cacert', 'config/certs/ca.crt', '-o', '/dev/null', '-w', '%{http_code}',
                'https://opensearch:9200/'], capture_output=True, text=True)
            if response.returncode == 0 and response.stdout.strip() == '503':
                break
            time.sleep(2)
        else:
            run('owned node logs', compose + ['logs', '--no-color', 'opensearch'])
            raise RuntimeError('Fresh TLS node did not remain explicitly uninitialized')
        print(f'fresh TLS before provisioning: HTTP 503 after {time.monotonic()-started:.3f}s', flush=True)
        run('explicit fresh bootstrap', compose + ['run', '--rm', '--no-deps', 'search-security-bootstrap'])
        for phase in ('upgrade', 'check', 'upgrade', 'check'):
            run('targeted ' + phase, compose + ['run', '--rm', '--no-deps', 'search-security-provision', phase])
        run('explicit catalog writer schema', compose + ['run', '--rm', '--no-deps', 'search-security-provision', 'catalog-schema'])
        run('repeat fresh is non-writing', compose + ['run', '--rm', '--no-deps', 'search-security-bootstrap'])
        run('TLS allowed/denied action matrix', compose + ['run', '--rm', '--no-deps', '-e', 'SEARCH_PRIVILEGES_DISPOSABLE=1',
            '--entrypoint', 'php', 'search-security-provision', '/opt/search-privileges-test.php'])
        run('actual PHP catalog paths', compose + ['run', '--rm', '--no-deps', '-e', 'SEARCH_PRIVILEGES_DISPOSABLE=1',
            '--entrypoint', 'php', 'search-security-provision', '-d', 'curl.cainfo=/run/tls/ca.crt', '/opt/search-catalog-test.php'])
        run('owned node memory', ['docker', 'stats', '--no-stream', '--format', '{{.Name}} {{.MemUsage}}', project + '-opensearch-1'])
        run('restart owned node', compose + ['restart', 'opensearch'])
        # Restart must retain existing policy and users, with no provisioner run.
        for attempt in range(60):
            check = subprocess.run(compose + ['run', '--rm', '--no-deps', 'search-security-provision', 'check'], capture_output=True, text=True)
            if check.returncode == 0:
                print('ordinary restart retained policies/credentials: PASS', flush=True)
                break
            time.sleep(2)
        else:
            raise RuntimeError('Policy check after restart failed')
    finally:
        run('stop owned nodes', compose + ['stop', '-t', '45', 'opensearch', 'postgres'])
        run('remove owned containers only', compose + ['rm', '-f'])
        run('remove owned network only', ['docker', 'network', 'rm', project + '_application'])
        print('Retained disposable search_data volume and private test material; no index/volume deletion.', flush=True)


if __name__ == '__main__':
    main()
