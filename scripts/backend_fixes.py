#!/usr/bin/env python3
"""Focused SQL/payment/recovery suites on an already running disposable stack."""
import argparse
import os
import subprocess
from compose import ROOT, command


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env', default='.env.example')
    parser.add_argument('--project', required=True)
    parser.add_argument('--disposable', action='store_true', required=True)
    args = parser.parse_args()
    if not args.project.endswith('-e2e'):
        parser.error('The disposable project must end in -e2e')
    env = os.environ | {'COMPOSE_PROJECT_NAME': args.project}
    base = command(ROOT / args.env, args.project)
    compose = command(ROOT / args.env, args.project, verify=True)
    fixtures = ['payment-provider', 'payment-http']
    running = subprocess.check_output(base + ['ps', '--status', 'running', '--services'],
                                      cwd=ROOT, env=env, text=True).splitlines()
    workers = [name for name in ('maintenance', 'payment-recovery') if name in running]
    try:
        # Real schedulers must not consume deliberately unsent/faulted fixture
        # intents using the stack's normal (possibly demo) gateway environment.
        if workers:
            subprocess.run(base + ['stop', *workers], cwd=ROOT, env=env, check=True)
        subprocess.run(compose + ['--profile', 'verification', 'up', '-d', '--no-deps', '--wait', '--wait-timeout', '30', *fixtures],
                       cwd=ROOT, env=env, check=True)
        # These variables belong only to focused child commands. Normal demo,
        # API, Go and search suites retain their ordinary payment environment.
        payment_env = ['-e', 'PAYMENT_GATEWAY_URL=http://payment-fixture-gateway:8080',
                       '-e', 'PAYMENT_GATEWAY_API_KEY=payment-fixture-key',
                       '-e', 'PAYMENT_WEBHOOK_SECRET=payment-test-secret',
                       '-e', 'PAYMENT_TEST_HTTP_ORIGIN=http://payment-fixture-http:8080']
        for suite in ('local_auth_concurrency.php', 'local_recovery.php', 'payment-migration.php', 'payments.php', 'payment-http.php', 'uuid_identity.php', 'uuid_recovery.php', 'recovery.php', 'recovery-scheduling.php'):
            print('Focused backend suite: ' + suite, flush=True)
            subprocess.run(compose + ['run', '--rm', '--no-deps', *payment_env,
                                      'yii2', 'php', 'tests/' + suite], cwd=ROOT, env=env, check=True)
    finally:
        try:
            subprocess.run(compose + ['--profile', 'verification', 'stop', *fixtures],
                           cwd=ROOT, env=env, check=True)
        finally:
            if workers:
                subprocess.run(base + ['up', '-d', '--no-deps', *workers], cwd=ROOT, env=env, check=True)


if __name__ == '__main__':
    main()
