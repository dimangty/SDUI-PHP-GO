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
    def invoke(self, fail_php=False, fail_restore=False, fail_fixture=False):
        calls = []

        def command(args, **kwargs):
            calls.append(args)
            if fail_restore and 'up' in args and '--wait' in args:
                raise subprocess.CalledProcessError(1, args)
            if fail_fixture and args[-2:] == ['stop', 'fixture']:
                raise subprocess.CalledProcessError(2, args)
            return SimpleNamespace(returncode=7 if fail_php and args[-2:] == ['composer', 'test:all'] else 0)

        with tempfile.TemporaryDirectory(prefix='verify-lifecycle-') as temporary, \
             patch.object(sys, 'argv', ['verify.py', '--project', 'shopkmp-lifecycle-e2e',
                 '--disposable', '--evidence-dir', temporary]), \
             patch.object(verify.subprocess, 'run', side_effect=command), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            if fail_php:
                with self.assertRaises(SystemExit) as error:
                    verify.main()
                self.assertEqual(7, error.exception.code)
            elif fail_restore or fail_fixture:
                with self.assertRaises(subprocess.CalledProcessError):
                    verify.main()
            else:
                verify.main()
            self.last_output = output.getvalue()
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


if __name__ == '__main__':
    unittest.main(verbosity=2)
