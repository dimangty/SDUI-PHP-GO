#!/usr/bin/env python3
"""Rapid cancellation regression for an explicitly owned Go/Nginx/FPM fault fixture.

The fixture forwards real PostgreSQL authentication, then stalls readiness SQL;
its fault service exposes /fixture/state.json and counts active native sockets.
Never starts/stops the parent database or changes its schema/data.
"""
import argparse
import concurrent.futures
import json
from pathlib import Path
import re
import socket
import subprocess
import time
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import urlopen


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run', action='store_true', required=True)
    parser.add_argument('--fixture-compose', type=Path, required=True)
    parser.add_argument('--fixture-project', required=True)
    parser.add_argument('--postgres-container', required=True)
    parser.add_argument('--postgres-project', required=True)
    parser.add_argument('--stats', type=Path, required=True)
    parser.add_argument('--evidence', type=Path, required=True)
    parser.add_argument('--url', default='http://127.0.0.1:58210')
    parser.add_argument('--php-url', default='http://127.0.0.1:58211')
    args = parser.parse_args()
    for project in (args.fixture_project, args.postgres_project):
        if not re.fullmatch(r'shopkmp-[a-z0-9-]+-e2e', project):
            parser.error('Explicit owned disposable projects are required')
    if args.fixture_project == args.postgres_project:
        parser.error('The fixture must be separate from the parent database project')
    config = json.loads(args.fixture_compose.read_text())
    services = {'fault', 'yii2', 'yii2-http', 'sdui', 'edge'}
    if set(config['services']) != services:
        parser.error('Expected only the five owned transport fixture services')
    for url in (args.url, args.php_url):
        if urlsplit(url).hostname != '127.0.0.1':
            parser.error('Only explicit loopback fixture URLs are accepted')
    compose = ['docker', 'compose', '-p', args.fixture_project, '-f', str(args.fixture_compose)]

    def output(command):
        return subprocess.check_output(command, text=True).strip()

    def owned(container, project):
        actual = output(['docker', 'inspect', '--format', '{{index .Config.Labels "com.docker.compose.project"}}', container])
        if actual != project:
            raise RuntimeError('Container does not belong to the explicit owned project')

    owned(args.postgres_container, args.postgres_project)
    for container in output(compose + ['ps', '-a', '-q']).split():
        owned(container, args.fixture_project)
    report = {'status':'RUNNING', 'cancelAfterMs':50, 'requests':80, 'drainGapsBetweenReplacements':0}
    args.evidence.parent.mkdir(parents=True, exist_ok=True)

    def run(command):
        subprocess.run(command, check=True, stdout=subprocess.DEVNULL)

    def state(tag, mode):
        run(compose + ['exec', '-T', 'fault', 'php', '-r',
            'file_put_contents("/fixture/state.json",$argv[1],LOCK_EX);',
            json.dumps({'tag':tag, 'pg':mode, 'optional':'blackhole'})])

    def sample(origin, path='/health/ready'):
        started = time.monotonic()
        try:
            response = urlopen(origin + path, timeout=3)
        except HTTPError as error:
            response = error
        with response:
            body = json.load(response)
        return {'status':response.status, 'ms':(time.monotonic()-started)*1000, 'body':body}

    def sessions():
        return int(output(['docker', 'exec', args.postgres_container, 'psql', '-U', 'shopkmp', '-d', 'shopkmp', '-Atc',
            "SELECT count(*) FROM pg_stat_activity WHERE datname='f1_chain_final' AND application_name='shopkmp-core-readiness'"]))

    def cancel_lane():
        endpoint = urlsplit(args.url)
        for _ in range(40):
            with socket.create_connection((endpoint.hostname, endpoint.port), timeout=1) as client:
                client.sendall(b'GET /health/ready HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n')
                time.sleep(.05)
            # Replace immediately. In particular there is no 750ms drain here.

    try:
        run(compose + ['up', '-d', '--no-deps', '--no-build', '--pull', 'never', 'fault'])
        state('rapid-startup', 'pass')
        run(compose + ['up', '-d', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '120', 'edge'])
        report['images'] = {service:output(['docker', 'inspect', '--format', '{{.Image}}',
            output(compose + ['ps', '-q', service])]) for service in ('yii2', 'sdui')}
        report['healthy'] = [sample(args.url), sample(args.php_url)]
        assert all(row['status'] == 200 for row in report['healthy']), report['healthy']
        state('rapid-stall', 'established')
        live = []; overloaded = []; counts = []
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
            lanes = [executor.submit(cancel_lane) for _ in range(2)]
            while not all(lane.done() for lane in lanes):
                counts.append(sessions())
                live.append({'go':sample(args.url, '/health/live'), 'php':sample(args.php_url, '/health/live')})
                overloaded.append(sample(args.php_url))
            for lane in lanes:
                lane.result()
        # Only after the entire storm ends may outstanding work drain.
        state('rapid-recovery', 'pass')
        time.sleep(.7)
        report.update(transport=json.loads(args.stats.read_text()).get('rapid-stall', {}),
                      peakReadinessSessions=max(counts), retainedReadinessSessions=sessions(),
                      workerAvailability=live, overload=overloaded,
                      recovery=[sample(args.url) for _ in range(20)])
        all_stats = json.loads(args.stats.read_text())
        report['optionalAccepts'] = sum(row.get('19200', {}).get('accepts', 0) for row in all_stats.values())
        report['optionalBytes'] = sum(row.get('19200', {}).get('bytes', 0) for row in all_stats.values())
        native = report['transport']['15432']
        assert 0 < native['maxActive'] <= 2, native
        assert native['queriesSuppressed'] > 0, native  # Real authenticated sessions, not only TCP accepts.
        assert 0 < report['peakReadinessSessions'] <= 2, report['peakReadinessSessions']
        assert native['active'] == report['retainedReadinessSessions'] == 0, report
        assert report['optionalAccepts'] == report['optionalBytes'] == 0, report
        assert all(row['go']['status'] == row['php']['status'] == 200 and
                   row['go']['ms'] < 2000 and row['php']['ms'] < 700 for row in live), live
        assert all(row['status'] == 503 and row['ms'] < 700 for row in overloaded), overloaded
        assert any(row['ms'] < 100 for row in overloaded), overloaded
        assert all(row['status'] == 200 and row['ms'] < 2000 for row in report['recovery']), report['recovery']
        report['status'] = 'PASSED'
    except BaseException as error:
        report['status'] = 'FAILED'; report['error'] = str(error)
        raise
    finally:
        args.evidence.write_text(json.dumps(report, indent=2) + '\n')
        run(compose + ['stop'])  # Only this fixture; retain every volume/network.
    print('PASS rapid 80-request cancel/replace: native/session cap 2, worker availability, cleanup and recovery')


if __name__ == '__main__':
    main()
