#!/usr/bin/env python3
"""Reproduce Task4 verification on a named disposable stack. Never production."""
import argparse
import os
from pathlib import Path
import subprocess
import sys
from compose import command, ROOT


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env', default='.env.example')
    parser.add_argument('--project', default='shopkmp-migrated-e2e')
    parser.add_argument('--url', default='http://127.0.0.1:58095')
    parser.add_argument('--disposable', action='store_true', required=True)
    args = parser.parse_args()
    if not args.project.endswith('-e2e'):
        parser.error('The disposable project must end in -e2e')
    env = os.environ | {'COMPOSE_PROJECT_NAME': args.project}
    compose = command((ROOT / args.env).resolve(), args.project)
    verify = command((ROOT / args.env).resolve(), args.project, verify=True)
    evidence = ROOT / 'deploy/evidence'
    evidence.mkdir(exist_ok=True)

    def run(label, cmd, extra=None, cwd=ROOT):
        print(label, flush=True)
        with (evidence / (label + '.log')).open('w') as log:
            result = subprocess.run(cmd, cwd=cwd, env=env | (extra or {}), stdout=log, stderr=subprocess.STDOUT)
        if result.returncode:
            print((evidence / (label + '.log')).read_text(), end='', flush=True)
            raise SystemExit(result.returncode)
        print(f'{label}: PASS (deploy/evidence/{label}.log)', flush=True)

    run('route-matrix', [sys.executable, 'scripts/route_matrix.py', '--check'])
    run('scheduler-regression', [sys.executable, 'scripts/test_scheduler.py', '--image', args.project + '-yii2'])
    run('seed-preservation', compose + ['exec', '-T', 'yii2', 'php', 'tests/development_seed.php'])
    # SQL regression suites intentionally insert fixtures without catalog APIs.
    # Reconcile explicitly before live search on a reused disposable project.
    run('initial-reindex', compose + ['exec', '-T', 'yii2', 'php', 'yii', 'maintenance/reindex'])
    run('edge-card', [sys.executable, 'scripts/integration.py', '--url', args.url, '--disposable'])
    run('bff-race', ['go', 'test', '-race', './...', '-count=1', '-v'],
        {'YII_INTEGRATION_ORIGIN': args.url, 'BFF_INTEGRATION_ORIGIN': args.url,
         'SDUI_EVIDENCE_DIR': str(evidence / 'bff')}, ROOT / 'backend-sdui')
    run('bff-vet', ['go', 'vet', './...'], cwd=ROOT / 'backend-sdui')
    run('ops-tests', ['go', 'test', './...', '-count=1'], cwd=ROOT / 'tools/ops')
    run('ops-vet', ['go', 'vet', './...'], cwd=ROOT / 'tools/ops')
    try:
        run('provider-fixture', verify + ['--profile', 'verification', 'up', '-d', '--no-deps', 'fixture'])
        run('php-all', verify + ['run', '--rm', '--no-deps',
            '-e', 'ADMIN_EMAILS=admin@test.example',
            '-e', 'API_ORIGIN=http://edge:8080',
            '-e', 'UPSTREAM_FIXTURE_ORIGIN=http://fixture:8080', 'yii2', 'composer', 'test:all'])
    finally:
        subprocess.run(verify + ['--profile', 'verification', 'stop', 'fixture'], cwd=ROOT, env=env, check=True)
    run('runtime', [sys.executable, 'scripts/runtime_checks.py', '--env', args.env, '--project', args.project,
                    '--url', args.url, '--shutdown'])
    print('PASS Task4 disposable verification. Evidence: deploy/evidence/. No mobile or production cutover claim.')


if __name__ == '__main__':
    main()
