#!/usr/bin/env python3
"""Exercise Make -> wrapper -> Docker command boundary without deployment writes."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from compose import ROOT, command


def tests(production_env):
    class DeploymentCommands(unittest.TestCase):
        def setUp(self):
            self.temp = tempfile.TemporaryDirectory(prefix='shopkmp-command-boundary-')
            self.addCleanup(self.temp.cleanup)
            self.path = Path(self.temp.name)
            self.trace = self.path / 'calls.jsonl'
            # Only Docker's side-effect boundary is replaced; actual Make/wrapper/
            # production validation run. Every would-be Docker argv is recorded.
            executable = self.path / 'docker'
            executable.write_text('#!/usr/bin/env python3\nimport json,os,sys\nwith open(os.environ["DOCKER_COMMAND_TRACE"],"a") as f: f.write(json.dumps(sys.argv[1:])+"\\n")\n')
            executable.chmod(0o755)
            self.env = os.environ | {'PATH': str(self.path) + os.pathsep + os.environ['PATH'],
                                     'DOCKER_COMMAND_TRACE': str(self.trace)}

        def run_make(self, target, **values):
            self.trace.unlink(missing_ok=True)
            result = subprocess.run(['make', '--no-print-directory', target,
                                     *[k + '=' + str(v) for k, v in values.items()]], cwd=ROOT,
                                    env=self.env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
            calls = [json.loads(line) for line in self.trace.read_text().splitlines()] if self.trace.exists() else []
            return result, calls

        def test_generic_lifecycle_rejects_production_environment_before_docker(self):
            for target in ('run', 'up', 'down', 'logs', 'seed', 'migrate', 'reindex', 'build'):
                with self.subTest(target=target):
                    result, calls = self.run_make(target, ENV_FILE=production_env)
                    self.assertNotEqual(0, result.returncode, result.stdout)
                    self.assertIn('requires --prod', result.stdout)
                    self.assertEqual([], calls, 'Wrong-overlay operation reached Docker')

        def test_standalone_php_checks_cannot_use_production_configuration_or_network(self):
            image = 'standalone-php-command-test'
            result, calls = self.run_make('test-php', ENV_FILE=production_env, PHP_TEST_IMAGE=image)
            self.assertEqual(0, result.returncode, result.stdout)
            self.assertEqual(3, len(calls))
            self.assertEqual(['build', '-t', image, 'backend-yii2'], calls[0])
            self.assertEqual({('composer', 'test'), ('composer', 'lint')},
                             {tuple(argv[-2:]) for argv in calls[1:]})
            for argv in calls[1:]:
                self.assertEqual('run', argv[0])
                self.assertEqual('none', argv[argv.index('--network') + 1])
                self.assertIn('--rm', argv)
                self.assertIn(image, argv)
                self.assertFalse(any(arg.startswith(('--env', '--mount', '--volume')) for arg in argv))
                self.assertNotIn('-e', argv)
                self.assertNotIn('-v', argv)
            self.assertNotIn('compose', [arg for argv in calls for arg in argv])
            self.assertNotIn(str(production_env), [arg for argv in calls for arg in argv])

        def test_production_stages_select_verified_tls_model_and_ordered_services(self):
            stages = {
                'prod-migrate': [('run', ['migrate']), ('run', ['yii-migrate'])],
                'prod-reindex': [('run', ['reindex'])],
                'prod-drain': [('stop', ['edge']), ('stop', ['sdui', 'yii2-http', 'maintenance', 'reconcile', 'payment-recovery', 'yii2'])],
                'prod-rollback-stop': [('stop', ['edge']), ('stop', ['sdui', 'yii2-http', 'maintenance', 'reconcile', 'payment-recovery', 'yii2'])],
                'prod-app': [('up', ['yii2', 'yii2-http', 'sdui'])],
                'prod-workers': [('up', ['maintenance', 'reconcile', 'payment-recovery'])],
                'prod-edge': [('up', ['edge'])],
                'prod-infra': [('up', ['postgres', 'keycloak-postgres', 'keycloak', 'opensearch'])],
            }
            model = json.loads(subprocess.check_output(command(production_env, prod=True) + ['config', '--format', 'json']))
            for target, expected in stages.items():
                with self.subTest(target=target):
                    result, calls = self.run_make(target, PROD_ENV_FILE=production_env)
                    self.assertEqual(0, result.returncode, result.stdout)
                    self.assertEqual(len(expected), len(calls))
                    for argv, (action, names) in zip(calls, expected):
                        self.assertEqual('compose', argv[0])
                        self.assertEqual(str(production_env), argv[argv.index('--env-file') + 1])
                        self.assertIn(str(ROOT / 'docker-compose.prod.yml'), argv)
                        self.assertNotIn(str(ROOT / 'deploy/compose.verify.yml'), argv)
                        self.assertEqual(names, argv[-len(names):])
                        self.assertIn(action, argv)
                        if action == 'run' or target in ('prod-app', 'prod-workers', 'prod-edge'):
                            self.assertIn('--no-deps', argv)
                        for name in names:
                            if name == 'migrate':
                                self.assertIn('sslmode=verify-full', model['services'][name]['environment']['DATABASE_URL'])
                            elif name in ('yii-migrate', 'reindex', 'yii2', 'maintenance', 'reconcile'):
                                env = model['services'][name]['environment']
                                self.assertEqual('production', env['APP_ENV'])
                                self.assertIn('sslmode=verify-full', env['DATABASE_URL'])
                                self.assertEqual('/run/tls/ca.crt', env['PGSSLROOTCERT'])

        def test_production_filename_and_marker_cannot_silently_select_development(self):
            for name, content in [('.env.prod', 'APP_ENV=development\n'),
                                  ('custom.env', 'APP_ENV=production\n')]:
                with self.subTest(name=name):
                    path = self.path / name
                    path.write_text(content)
                    result, calls = self.run_make('migrate', ENV_FILE=path)
                    self.assertNotEqual(0, result.returncode)
                    self.assertIn('requires --prod', result.stdout)
                    self.assertEqual([], calls)

        def test_generic_development_config_still_selects_development(self):
            result, calls = self.run_make('compose-config')
            self.assertEqual(0, result.returncode, result.stdout)
            self.assertEqual(1, len(calls))
            self.assertNotIn(str(ROOT / 'docker-compose.prod.yml'), calls[0])

    return unittest.defaultTestLoader.loadTestsFromTestCase(DeploymentCommands)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--production-env', type=Path, required=True)
    args = parser.parse_args()
    result = unittest.TextTestRunner(verbosity=2).run(tests(args.production_env.resolve()))
    raise SystemExit(not result.wasSuccessful())
