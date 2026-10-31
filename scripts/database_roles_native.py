#!/usr/bin/env python3
"""Own disposable TLS PostgreSQL using the actual merged production Compose.

No production env input, no foreign lifecycle calls, no volume deletion. Test
secrets stay in the private temporary PKI directory and subprocess environment.
"""
import argparse
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import tempfile

from compose import ROOT, command


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--project', default='shopkmp-round6-roles-'+secrets.token_hex(4)+'-e2e')
    parser.add_argument('--parent', default=tempfile.gettempdir())
    parser.add_argument('--resume-test-env', type=Path)
    args = parser.parse_args()
    if not re.fullmatch(r'shopkmp-round6-[a-z0-9-]+-e2e', args.project):
        parser.error('Use a task-owned shopkmp-round6-...-e2e project')
    path = args.resume_test_env or Path(subprocess.check_output([sys.executable, str(ROOT/'scripts/test_pki.py'),
        '--parent', args.parent, '--project', args.project, '--edge-subnet', '10.251.108.0/24',
        '--fpm-subnet', '10.251.109.0/24'], text=True).strip())
    values = dict(line.split('=', 1) for line in path.read_text().splitlines() if line and not line.startswith('#'))
    if values.get('COMPOSE_PROJECT_NAME') != args.project or 'shopkmp-task4-pki-' not in str(path.parent):
        parser.error('Only this disposable project test-PKI environment may be resumed')
    # Explicit subnet avoids consuming the shared host's exhausted default pools.
    overlay = path.parent/'role-test.json'
    overlay.write_text(json.dumps({'services': {'postgres': {'mem_limit': '256m'}},
        'networks': {'data': {'ipam': {'config': [{'subnet': '10.251.107.0/24'}]}},
                     'application': {'ipam': {'config': [{'subnet': '10.251.110.0/24'}]}}}}))
    compose = command(path, args.project, prod=True) + ['-f', str(overlay)]
    env = dict(os.environ) | values | {'COMPOSE_PROJECT_NAME': args.project}

    def run(*argv, extra=None):
        subprocess.run(compose + list(argv), cwd=ROOT, env=env | (extra or {}), check=True)

    primary_failure = None
    try:
        # Tests inspect the real rendered graph, without serializing its secrets.
        cfg = json.loads(subprocess.check_output(compose+['--profile','database-admin','config','--format','json'],env=env))
        from validate_production import validate_model
        validate_model(cfg)
        run('up','-d','--build','--wait','--wait-timeout','120','postgres')
        run('build','yii2','migrate')
        admin_env = {'DATABASE_URL': cfg['services']['database-provision']['environment']['DATABASE_URL']+'&sslrootcert=/run/tls/ca.crt',
                     'PGPASSWORD': values['POSTGRES_PASSWORD']}
        # Model the existing shared-owner installation BEFORE switching credentials.
        run('run','--rm','--no-deps','-e','DATABASE_URL','-e','PGPASSWORD','migrate',extra=admin_env)
        run('run','--rm','--no-deps','-e','DATABASE_URL','-e','APP_ENV=development','yii-migrate',extra=admin_env)
        run('run','--rm','--no-deps','-e','DATABASE_URL','-e','APP_ENV=development',
            'yii-migrate','php','tests/role_upgrade_seed.php',extra=admin_env)
        run('run','--rm','--no-deps','database-provision','php','/opt/database-provision.php','roles')
        run('run','--rm','--no-deps','database-provision','php','/opt/database-provision.php','grants')
        # Reapply on existing data/roles. No password rotation in the grants phase.
        run('run','--rm','--no-deps','migrate')
        run('run','--rm','--no-deps','yii-migrate')
        run('run','--rm','--no-deps','database-provision','php','/opt/database-provision.php','grants')
        extra = {'ROLE_TEST_ADMIN_URL': cfg['services']['database-provision']['environment']['DATABASE_URL'],
                 'ROLE_TEST_OPERATOR_URL': cfg['services']['identity-operator']['environment']['DATABASE_URL'],
                 'ROLE_TEST_DISPOSABLE': args.project,
                 'ROLE_TEST_WORKER_URLS': json.dumps({cfg['services'][name]['environment']['DATABASE_ROLE']:
                    cfg['services'][name]['environment']['DATABASE_URL'] for name in ('reindex','reconcile','maintenance','payment-recovery')})}
        run('run','--rm','--no-deps','-e','ROLE_TEST_ADMIN_URL','-e','ROLE_TEST_OPERATOR_URL','-e','ROLE_TEST_WORKER_URLS','-e','ROLE_TEST_DISPOSABLE',
            '-v',str(path.parent/'provider.crt')+':/run/test-provider.crt:ro',
            '-v',str(path.parent/'provider.key')+':/run/test-provider.key:ro',
            'yii2','php','tests/database_roles_native.php', extra=extra)
        run('run','--rm','--no-deps','yii2','php','tests/local_auth_relock_native.php')
        run('run','--rm','--no-deps','identity-operator')
        print('PASSED actual production Compose SQL role matrix; no full-stack acceptance claimed')
    except BaseException as error:
        primary_failure = error
        raise
    finally:
        try:
            run('down')  # only this task-owned project; volumes are retained
        except BaseException:
            if primary_failure is None:
                raise
            print('Owned role fixture cleanup failed; primary failure preserved', file=sys.stderr)
        print('Owned test containers removed; volumes retained; private test configuration: '+str(path))


if __name__ == '__main__':
    try:
        main()
    except subprocess.CalledProcessError as error:
        sys.exit('Native role check failed (exit '+str(error.returncode)+'); no acceptance receipt issued')
