#!/usr/bin/env python3
"""Bind a real build and successful functional run to sources and all backend containers."""
import argparse
import datetime as dt
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import time
from compose import command, ROOT

REQUIRED_SERVICES = ('yii2', 'sdui', 'migrate', 'postgres', 'opensearch', 'edge',
                     'yii2-http', 'maintenance', 'reconcile', 'payment-recovery', 'yii-migrate', 'reindex')
INIT_SERVICES = {'migrate', 'yii-migrate', 'reindex'}
SOURCE_PATHS = ('backend-yii2', 'backend-sdui', 'tools/ops', 'deploy', 'scripts',
                'Makefile', 'docker-compose.yml', 'docker-compose.prod.yml', '.github/workflows/ci.yml', '.env.example')


def source_snapshot():
    paths = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--', *SOURCE_PATHS], cwd=ROOT, text=True)
    return {p: hashlib.sha256((ROOT / p).read_bytes()).hexdigest() for p in sorted(set(paths.splitlines()))
            if not p.startswith('deploy/evidence/') and (ROOT / p).is_file()}


def inventory(compose, platform=None, deadline=None, observed=None):
    def output(argv):
        remaining = 30 if deadline is None else min(30, deadline-time.monotonic())
        if remaining <= 0:
            raise TimeoutError('artifact inventory deadline exceeded')
        return subprocess.check_output(argv, cwd=ROOT, text=True, timeout=remaining)
    # Keep resolved environment/credentials exclusively in memory.
    config = json.loads(output(compose + ['config', '--format', 'json']))
    artifacts = {}
    for service in REQUIRED_SERVICES:
        entry = config['services'][service]
        tag = entry.get('image') or config['name'] + '-' + service
        image = json.loads(output(['docker', 'image', 'inspect', tag]))[0]
        actual = image['Os'] + '/' + image['Architecture']
        if platform is not None and actual != platform:
            raise ValueError(service + ' image platform differs from required platform')
        containers = output(compose + ['ps', '-a', '-q', service]).split()
        if not containers:
            raise ValueError(service + ' required container is absent')
        states = []
        for container in containers:
            # Never inspect/persist a container's environment.
            used = json.loads(output(['docker', 'inspect', '--format', '{"image":{{json .Image}},"state":{{json .State}}}', container]))
            if used['image'] != image['Id']:
                raise ValueError(service + ' container differs from the current immutable image')
            states.append(used['state'])
        row = {'imageId': image['Id'], 'repoDigests': image.get('RepoDigests', []),
            'descriptor': image.get('Descriptor'), 'platform': actual, 'tagAtInspection': tag}
        # Preserve scan evidence for verified image/container bindings even when
        # health fails. Observations never constitute a build/test acceptance.
        if observed is not None:
            observed[service] = row
        for state in states:
            if service in INIT_SERVICES:
                if state['Status'] != 'exited' or state['ExitCode'] != 0:
                    raise ValueError(service + ' initialization did not complete successfully')
            elif not state['Running'] or state.get('Health', {}).get('Status', 'healthy') != 'healthy':
                raise ValueError(service + ' runtime is not running and healthy')
        artifacts[service] = row
    return artifacts


def verify_receipt(receipt, sources, images, require_tested=True):
    if require_tested and receipt.get('tested') is not True:
        raise ValueError('successful functional artifact receipt is required')
    if receipt.get('sources') != sources:
        raise ValueError('source/lockfile drift since the artifact build; rebuild and retest')
    for service in REQUIRED_SERVICES:
        before, current = receipt.get('images', {}).get(service), images.get(service)
        if not before or not current or any(before.get(k) != current.get(k) for k in ('imageId', 'platform')):
            raise ValueError(service + ' missing or differs from the build/test receipt')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env', default='.env.example')
    parser.add_argument('--project', required=True)
    parser.add_argument('--receipt', type=Path, required=True)
    parser.add_argument('--phase', choices=['build', 'test'], required=True)
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    cmd = args.command[1:] if args.command[:1] == ['--'] else args.command
    if not args.project.endswith('-e2e') or not cmd:
        parser.error('explicit disposable project and real build/test command required')
    base = command(ROOT / args.env, args.project)
    sources = source_snapshot()
    if args.phase == 'test':
        receipt = json.loads(args.receipt.read_text())
        verify_receipt(receipt, sources, inventory(base), require_tested=False)
    else:
        receipt = {'sources': sources, 'buildCommand': cmd, 'buildStarted': dt.datetime.now(dt.timezone.utc).isoformat()}
    receipt['tested'] = False
    args.receipt.parent.mkdir(parents=True, exist_ok=True)
    args.receipt.write_text(json.dumps(receipt, indent=2) + '\n')
    code = subprocess.call(cmd, cwd=ROOT)
    receipt[args.phase + 'ExitCode'] = code
    receipt[args.phase + 'Finished'] = dt.datetime.now(dt.timezone.utc).isoformat()
    if code == 0:
        images = inventory(base)
        if source_snapshot() != sources:
            raise ValueError('sources changed during build/test')
        if args.phase == 'build':
            receipt['images'] = images
        else:
            verify_receipt(receipt, sources, images, require_tested=False)
            receipt['tested'] = True
    args.receipt.write_text(json.dumps(receipt, indent=2) + '\n')
    return code


if __name__ == '__main__':
    sys.exit(main())
