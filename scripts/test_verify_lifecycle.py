"""A restarted worker is not ready until its real first job has succeeded."""
import contextlib
import io
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import verify


class VerificationWorkerLifecycle(unittest.TestCase):
    def invoke(self, fail_php=False, fail_restore=False, fail_fixture=False, fail_gate=None):
        calls = []
        gate_images = []

        def command(args, **kwargs):
            calls.append(args)
            if any('scripts/' + script in args for script in
                   ('test_service_privileges.py', 'test_service_command_config.py', 'test_search_schema_protocol.py')):
                gate_images.append(kwargs['env']['PHP_TEST_IMAGE'])
                self.assertEqual(kwargs['env']['PHP_TEST_IMAGE'], kwargs['env']['SERVICE_PRIVILEGES_PHP_IMAGE'])
            if fail_restore and 'up' in args and '--wait' in args:
                raise subprocess.CalledProcessError(1, args)
            if fail_fixture and args[-2:] == ['stop', 'fixture']:
                raise subprocess.CalledProcessError(2, args)
            return SimpleNamespace(returncode=7 if (fail_php and args[-2:] == ['composer', 'test:all']) or fail_gate in args else 0)

        def output(args, **kwargs):
            if 'ps' in args:
                return 'container-' + args[-1] + '\n'
            return 'sha256:current-' + args[-1].removeprefix('container-') + '\n'

        with tempfile.TemporaryDirectory(prefix='verify-lifecycle-') as temporary, \
             patch.object(sys, 'argv', ['verify.py', '--project', 'shopkmp-lifecycle-e2e',
                 '--disposable', '--evidence-dir', temporary]), \
             patch.object(verify.subprocess, 'run', side_effect=command), \
             patch.object(verify.subprocess, 'check_output', side_effect=output), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            if fail_php or fail_gate:
                with self.assertRaises(SystemExit) as error:
                    verify.main()
                self.assertEqual(7, error.exception.code)
            elif fail_restore or fail_fixture:
                with self.assertRaises(subprocess.CalledProcessError):
                    verify.main()
            else:
                verify.main()
            self.last_output = output.getvalue()
            self.gate_images = gate_images
        return calls

    def assert_restore_waits(self, calls):
        restores = [args for args in calls if args[-3:] == ['maintenance', 'payment-recovery', 'reconcile'] and 'up' in args]
        self.assertEqual(1, len(restores))
        restore = restores[0]
        self.assertIn('--no-deps', restore)
        self.assertIn('--wait', restore)
        self.assertEqual('2100', restore[restore.index('--wait-timeout') + 1])
        return restores[0]

    def test_runtime_checks_follow_actual_worker_readiness(self):
        calls = self.invoke()
        restore = self.assert_restore_waits(calls)
        runtime = next(args for args in calls if 'scripts/runtime_checks.py' in args)
        self.assertLess(calls.index(restore), calls.index(runtime))

    def test_failed_php_still_restores_ready_workers_and_preserves_primary_exit(self):
        calls = self.invoke(fail_php=True)
        self.assert_restore_waits(calls)
        self.assertFalse(any('scripts/runtime_checks.py' in args for args in calls))

    def test_secondary_readiness_failure_does_not_replace_primary_php_exit(self):
        calls = self.invoke(fail_php=True, fail_restore=True)
        self.assert_restore_waits(calls)
        self.assertIn('worker-readiness-restore: FAILED', self.last_output)

    def test_cleanup_failure_is_primary_when_verification_had_succeeded(self):
        calls = self.invoke(fail_restore=True)
        self.assert_restore_waits(calls)
        self.assertFalse(any('scripts/runtime_checks.py' in args for args in calls))

    def test_fixture_stop_failure_still_attempts_ready_worker_restoration(self):
        calls = self.invoke(fail_fixture=True)
        self.assert_restore_waits(calls)
        self.assertIn('fixture-stop: FAILED', self.last_output)

    def test_canonical_invokes_every_privilege_gate_with_current_immutable_images(self):
        calls = self.invoke()
        for script in ('test_service_privileges.py', 'test_service_command_config.py', 'test_search_schema_protocol.py'):
            self.assertTrue(any('scripts/' + script in args for args in calls), script)
        live = next(args for args in calls if 'scripts/test_search_privileges_live.py' in args)
        self.assertIn('--run', live)
        for flag, service in (('--php-image', 'yii2'), ('--postgres-image', 'postgres'), ('--opensearch-image', 'opensearch')):
            self.assertEqual('sha256:current-' + service, live[live.index(flag) + 1])
        self.assertFalse(any('build' in args for args in calls))
        self.assertEqual(['sha256:current-yii2'] * 3, self.gate_images)
        runtime = next(args for args in calls if 'scripts/runtime_checks.py' in args)
        self.assertLess(calls.index(runtime), calls.index(live))

    def test_each_privilege_gate_failure_is_a_canonical_failure(self):
        for script in ('test_service_privileges.py', 'test_service_command_config.py',
                       'test_search_schema_protocol.py', 'test_search_privileges_live.py'):
            with self.subTest(script=script):
                calls = self.invoke(fail_gate='scripts/' + script)
                self.assertEqual('scripts/' + script, next(arg for arg in calls[-1] if arg.startswith('scripts/')))

    def test_absent_current_image_binding_fails_before_any_runner(self):
        with patch.object(sys, 'argv', ['verify.py', '--project', 'shopkmp-lifecycle-e2e', '--disposable']), \
             patch.object(verify.subprocess, 'check_output', return_value=''), \
             patch.object(verify.subprocess, 'run') as run:
            with self.assertRaisesRegex(RuntimeError, 'requires exactly one current project container'):
                verify.main()
            run.assert_not_called()


if __name__ == '__main__':
    unittest.main(verbosity=2)
