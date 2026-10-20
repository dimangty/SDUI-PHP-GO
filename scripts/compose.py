#!/usr/bin/env python3
"""One entry point for the root stack; production validation precedes Docker."""
import argparse
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def load_env(path):
    values = {}
    for line in Path(path).read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#'):
            key, value = line.split('=', 1)
            values[key.strip()] = value.strip().strip('"').strip("'")
    return values | dict(os.environ)


def command(env_file, project=None, prod=False, verify=False):
    values = load_env(env_file)
    production_file = Path(env_file).name.startswith('.env.prod')
    production_values = (values.get('APP_ENV', '').lower() == 'production'
                         or bool(values.get('TLS_CERT_DIR')) or bool(values.get('PUBLIC_API_HOST')))
    if not prod and (production_file or production_values):
        raise ValueError('Production environment requires --prod; generic lifecycle targets are development-only')
    if prod and verify:
        raise ValueError('Verification writers are development-only')
    result = ['docker', 'compose', '--env-file', str(env_file)]
    if project:
        result += ['-p', project]
    result += ['-f', str(ROOT / 'docker-compose.yml')]
    if prod:
        result += ['-f', str(ROOT / 'docker-compose.prod.yml')]
    if verify:
        result += ['-f', str(ROOT / 'deploy/compose.verify.yml')]
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env', default=os.getenv('ENV_FILE', '.env'))
    parser.add_argument('--project', default=os.getenv('COMPOSE_PROJECT_NAME'))
    parser.add_argument('--prod', action='store_true')
    parser.add_argument('--verify', action='store_true')
    parser.add_argument('args', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    path = (ROOT / args.env).resolve()
    compose = command(path, args.project, args.prod, args.verify)
    if args.prod and (not args.args or args.args[0] not in ('down', 'logs', 'ps', 'stop')):
        from validate_production import validate
        validate(load_env(path))
    return subprocess.call(compose + args.args, cwd=ROOT)


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
