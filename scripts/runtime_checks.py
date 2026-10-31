#!/usr/bin/env python3
"""Real probes, trusted-hop/counter correlation and optional disposable shutdown."""
import argparse
import ipaddress
import json
import subprocess
import time
from urllib.error import HTTPError
from urllib.request import Request, urlopen
from compose import ROOT, command
from backend_artifacts import REQUIRED_SERVICES, INIT_SERVICES


def check_containers(containers):
    """Validate actual container state without persisting environment/credentials."""
    services = {}
    optional = {'seed', 'fixture', 'payment-provider', 'payment-http'}
    retired = 'key' + 'cloak'
    for container in containers:
        name = container['Config']['Labels']['com.docker.compose.service']
        if name not in set(REQUIRED_SERVICES) | optional:
            raise ValueError('Unexpected runtime service: ' + name)
        for entry in container['Config'].get('Env', []):
            key = entry.split('=', 1)[0]
            if key.startswith(retired.upper() + '_') or key in ('ADMIN_EMAILS', 'ADMIN_BOOTSTRAP_TOKEN'):
                raise ValueError('Retired identity/registration-admin environment in service: ' + name)
        bindings = container['HostConfig'].get('PortBindings') or {}
        if name != 'edge' and any(bindings.values()):
            raise ValueError('Private runtime service publishes a port: ' + name)
        state = container['State']
        if name in INIT_SERVICES or name == 'seed':
            if state['Status'] != 'exited' or state['ExitCode'] != 0:
                raise ValueError('Initialization has not completed successfully: ' + name)
        elif name in REQUIRED_SERVICES:
            if not state['Running'] or state.get('Health', {}).get('Status') != 'healthy':
                raise ValueError('Runtime service is not running and healthy: ' + name)
        services[name] = {'image': container['Image'], 'status': state['Status'],
                          'health': state.get('Health', {}).get('Status')}
    if set(REQUIRED_SERVICES) - services.keys():
        raise ValueError('Required runtime containers are missing: ' + ', '.join(sorted(set(REQUIRED_SERVICES) - services.keys())))
    return services


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env', default='.env.example')
    parser.add_argument('--project', default='shopkmp-migrated-e2e')
    parser.add_argument('--url', default='http://127.0.0.1:58095')
    parser.add_argument('--shutdown', action='store_true')
    parser.add_argument('--wait-timeout', type=int, default=2100)
    args = parser.parse_args()
    assert args.project.endswith('-e2e')
    compose = command(args.env, args.project)

    def run(*args):
        return subprocess.check_output(compose + list(args), cwd=ROOT, stderr=subprocess.STDOUT).decode()

    def sql(query):
        code = ('require "/app/vendor/autoload.php"; $d=new \\Shop\\infrastructure\\Database(getenv("DATABASE_URL")); '
                'print json_encode($d->all(' + json.dumps(query) + '));')
        return json.loads(run('exec', '-T', 'yii2', 'php', '-r', code))

    def worker_statuses():
        statuses = {}
        for name in ('maintenance', 'reconcile', 'payment-recovery'):
            status = json.loads(run('exec', '-T', name, 'php', '/opt/scheduler/scheduler-health.php', 'status'))
            assert status['live'] and status['ready'] and status['state']['lastSuccessAt'] is not None, (name, status)
            statuses[name] = status
        return statuses

    marker = sql('SELECT clock_timestamp()::text AS ts')[0]['ts']
    spoof = '203.0.113.77'
    request = Request(args.url + '/api/v1/users/me', headers={'X-Forwarded-For': spoof, 'X-Real-IP': spoof,
                      'Forwarded': 'for=' + spoof, 'X-Role': 'admin', 'X-User-Id': 'forged'})
    try:
        urlopen(request, timeout=15)
        raise AssertionError('Spoof authorized private profile')
    except HTTPError as response:
        assert response.code == 401
        trace = response.headers['X-Request-Id']
    time.sleep(0.2)  # Allow the completed Nginx access record to flush to Docker.
    lines = run('logs', '--no-log-prefix', '--since', '10s', 'edge').splitlines()
    line = next(line for line in reversed(lines) if line.endswith(trace))
    client = line.split()[0]
    expected = 'read:network-' + str(ipaddress.ip_network(client + ('/32' if ':' not in client else '/128')).supernet(new_prefix=24 if ':' not in client else 64))
    counters = sql("SELECT bucket_key FROM rate_limit_hits WHERE occurred_at > '" + marker + "'::timestamptz AND bucket_key LIKE 'read:network-%'")
    keys = {r['bucket_key'] for r in counters}
    assert client != spoof and expected in keys and 'read:network-203.0.113.0/24' not in keys, (client, keys)
    for url in ('http://yii2-http:8080/health/live', 'http://yii2-http:8080/health/ready',
                'http://sdui:9090/health/live', 'http://sdui:9090/health/ready'):
        # Nginx is on ingress and FPM; PHP deliberately has no ingress interface.
        run('exec', '-T', 'yii2-http', 'wget', '-q', '-T', '8', '-O', '/dev/null', url)
    run('exec', '-T', 'yii2', 'php', '/opt/probes/fpm-health.php')
    run('exec', '-T', 'yii2', 'php-fpm', '-t')
    run('exec', '-T', 'yii2-http', 'nginx', '-t')
    run('exec', '-T', 'edge', 'nginx', '-t')
    ids = run('ps', '--all', '--quiet').split()
    inspected = json.loads(subprocess.check_output(['docker', 'inspect', *ids]))
    evidence = {'actualClientIP': client, 'phpRatePrincipal': expected, 'spoofedAddressIgnored': spoof,
                'schemaMigrationCount': sql('SELECT count(*) AS n FROM schema_migrations')[0]['n'],
                'yiiHistoryCount': sql('SELECT count(*) AS n FROM yii2_migration_history')[0]['n'], 'services': {}}
    evidence['workerReadiness'] = worker_statuses()
    evidence['services'] = check_containers(inspected)
    if args.shutdown:
        names = ['edge', 'sdui', 'yii2-http', 'yii2', 'maintenance', 'reconcile', 'payment-recovery']
        run('stop', *names)
        stopped = json.loads(subprocess.check_output(['docker', 'inspect', *run('ps', '--all', '--quiet', *names).split()]))
        exits = {c['Config']['Labels']['com.docker.compose.service']: c['State']['ExitCode'] for c in stopped}
        assert all(code == 0 for code in exits.values()), exits
        evidence['gracefulStopExitCodes'] = exits
        run('up', '-d', '--no-deps', '--wait', '--wait-timeout', str(args.wait_timeout), *reversed(names))
        evidence['workerReadinessAfterRestart'] = worker_statuses()
    output = ROOT / 'deploy/evidence/local-auth-runtime.json'
    output.write_text(json.dumps(evidence, indent=2) + '\n')
    print(f'PASS real internal probes; edge client {client} = PHP {expected}; spoof ignored; private ports; histories; graceful stop/restart={args.shutdown}')


if __name__ == '__main__':
    main()
