#!/usr/bin/env python3
"""Create a test-owned trust_ database, run live Nginx/FPM trust tests, remove it."""
import argparse
import os
import subprocess
import sys
import uuid
from urllib.parse import quote
from compose import command, load_env, ROOT


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env', default='.env.example')
    parser.add_argument('--project', required=True)
    args = parser.parse_args()
    if not args.project.endswith('-e2e'):
        parser.error('requires an explicitly disposable *-e2e project')
    values = load_env(ROOT / args.env)
    base = command(ROOT / args.env, args.project)
    database = 'trust_' + uuid.uuid4().hex
    # No environment/configuration dump; the password travels only in child env.
    subprocess.run(base + ['exec', '-T', 'postgres', 'createdb', '-U', 'shopkmp', database], check=True, cwd=ROOT)
    try:
        subprocess.run(base + ['exec', '-T', '-e', 'TRUST_DATABASE=' + database, 'postgres', 'sh', '-ec',
            'set -o pipefail; pg_dump -U shopkmp --schema-only shopkmp | psql -X -v ON_ERROR_STOP=1 -U shopkmp -d "$TRUST_DATABASE"'], check=True, cwd=ROOT)
        env = os.environ | {'TRUST_PHP_IMAGE': args.project + '-yii2',
            'TRUST_DB_NETWORK': args.project + '_data',
            'TRUST_DATABASE_URL': 'postgres://shopkmp:' + quote(values['POSTGRES_PASSWORD'], safe='') + '@postgres:5432/' + database + '?sslmode=disable'}
        subprocess.run([sys.executable, 'backend-yii2/tests/trust-live/run.py'], env=env, check=True, cwd=ROOT)
    finally:
        subprocess.run(base + ['exec', '-T', 'postgres', 'dropdb', '-U', 'shopkmp', database], check=True, cwd=ROOT)


if __name__ == '__main__':
    main()
