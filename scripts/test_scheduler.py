#!/usr/bin/env python3
"""Exercise the deployed scheduler and exact Compose healthcheck in isolated Docker.

The regression is a healthy live parent while every real/fixture child fails.
Only the business CLI process is replaced for deterministic outcome/duration tests.
No business network, credentials or database writes are used by these fixtures.
"""
import argparse
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import time
import unittest
import uuid
from compose import ROOT, command

# Preserve all deadline/health assertions while allowing real Docker round trips
# on a loaded shared VM. Production scheduler budgets/heartbeat are unchanged.
TIME_SCALE = int(os.getenv('SCHEDULER_TEST_TIME_SCALE', '1'))
if not 1 <= TIME_SCALE <= 5:
    raise ValueError('SCHEDULER_TEST_TIME_SCALE must be between 1 and 5')


def docker(*args, check=True):
    return subprocess.run(['docker', *map(str, args)], check=check, text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.STDOUT)


def wait_for(predicate, message, timeout=15):
    deadline = time.monotonic() + timeout * TIME_SCALE
    while time.monotonic() < deadline:
        value = predicate()
        if value:
            return value
        time.sleep(0.2)
    raise AssertionError(message)


class Worker:
    def __init__(self, service, image, fixture=True, interval=1, budget=3, max_age=30, mode='success'):
        interval *= TIME_SCALE
        budget *= TIME_SCALE
        max_age *= TIME_SCALE
        self.temp = tempfile.TemporaryDirectory(prefix='shopkmp-scheduler-')
        self.path = Path(self.temp.name)
        (self.path / 'runtime').mkdir()
        self.set_mode(mode)
        self.name = 'shopkmp-task4-scheduler-test-' + uuid.uuid4().hex[:12]
        self.command = service['command'][2]
        probe = service['healthcheck']['test']
        health = shlex.join(probe[1:]) if probe[0] == 'CMD' else probe[1]
        args = ['run', '-d', '--name', self.name, '--network', 'none', '--stop-signal', service['stop_signal'],
                '--health-cmd', health, '--health-interval', '1s', '--health-timeout', f'{2 * TIME_SCALE}s',
                '--health-retries', '1', '--health-start-period', '0s',
                '-e', 'DATABASE_URL=postgres://regression:unused@127.0.0.1:1/regression?sslmode=disable',
                '-e', 'OPENSEARCH_URL=http://127.0.0.1:1',
                '-e', f'SCHEDULER_JOB_TIMEOUT_SECONDS={budget}',
                '-e', f'SCHEDULER_SUCCESS_MAX_AGE_SECONDS={max_age}',
                '-v', f'{self.path}:/scheduler-fixture', '-v', f'{self.path}/runtime:/app/runtime']
        for volume in service['volumes']:
            if volume['type'] == 'bind':
                args += ['-v', f'{volume["source"]}:{volume["target"]}:ro']
        if fixture:
            args += ['-v', f'{ROOT}/deploy/tests/scheduler-job.php:/app/yii:ro']
        args += [image, *service['command'][:-1], str(interval)]
        try:
            docker(*args)
        except BaseException:
            self.close()
            raise

    def set_mode(self, mode):
        if hasattr(self, 'name'):
            # Update from the container side: a host-side atomic rename can be
            # observed as ENOENT through Docker Desktop's shared filesystem.
            docker('exec', self.name, 'php', '-r',
                   'file_put_contents("/scheduler-fixture/mode",$argv[1],LOCK_EX);', mode)
            return
        path = self.path / 'mode.next'
        path.write_text(mode)
        path.replace(self.path / 'mode')

    def events(self, kind):
        path = self.path / 'events.jsonl'
        return [json.loads(line) for line in path.read_text().splitlines()
                if json.loads(line)['event'] == kind] if path.exists() else []

    def state(self):
        return json.loads(docker('inspect', self.name).stdout)[0]['State']

    def status(self):
        return self.state()['Health']['Status']

    def probe(self, mode='ready'):
        return docker('exec', self.name, 'php', '/opt/scheduler/scheduler-health.php', mode, check=False)

    def snapshot(self):
        return json.loads((self.path / 'runtime/scheduler-state.json').read_text())

    def close(self):
        docker('kill', '--signal', 'CONT', self.name, check=False)
        docker('rm', '--force', self.name, check=False)
        self.temp.cleanup()


def worker_tests(service, image):
    class SchedulerRegression(unittest.TestCase):
        def worker(self, **kwargs):
            w = Worker(service, image, **kwargs)
            self.addCleanup(w.close)
            return w

        def test_unreachable_business_dependencies_never_become_healthy(self):
            w = self.worker(fixture=False)
            wait_for(lambda: docker('logs', w.name).stdout.count(f'Scheduled {w.command} failed:') >= 2,
                     'The real business CLI did not retry its dependency failures')
            time.sleep(1.2)
            self.assertEqual('unhealthy', w.status(), 'Failed real jobs with a live parent were reported healthy')
            self.assertTrue(w.state()['Running'])
            self.assertEqual(0, w.probe('live').returncode)
            state = w.snapshot()
            self.assertIsNone(state['lastSuccessAt'])
            self.assertNotEqual(0, state['lastExitCode'])

        def test_first_success_failure_retries_and_recovery_drive_health(self):
            w = self.worker(mode='hold', budget=6)
            wait_for(lambda: w.events('started'), 'No first child')
            time.sleep(1.2)
            self.assertNotEqual('healthy', w.status(), 'A first running job has not successfully completed')
            self.assertEqual(0, w.probe('live').returncode)
            w.set_mode('success')
            wait_for(lambda: w.status() == 'healthy', 'First success did not make worker healthy')
            w.set_mode('fail')
            wait_for(lambda: len(w.events('failed')) >= 2, 'Failed jobs were not retried')
            wait_for(lambda: w.status() == 'unhealthy', 'Failed child did not make worker unhealthy')
            state = w.snapshot()
            self.assertIsNotNone(state['lastSuccessAt'])
            self.assertEqual(7, state['lastExitCode'])
            self.assertEqual(0, w.probe('live').returncode)
            failed_success = state['lastSuccessAt']
            w.set_mode('hold')
            count = len(w.events('started'))
            wait_for(lambda: len(w.events('started')) > count, 'Recovery child did not start')
            self.assertEqual('unhealthy', w.status(), 'Starting recovery cannot erase a failed result')
            w.set_mode('success')
            wait_for(lambda: w.status() == 'healthy', 'A successful retry did not restore health')
            self.assertGreater(w.snapshot()['lastSuccessAt'], failed_success)

        def test_legitimate_running_job_is_bounded_and_shutdown_signals_child(self):
            w = self.worker()
            wait_for(lambda: w.status() == 'healthy', 'No initial success')
            count = len(w.events('started'))
            w.set_mode('hold-ignore')
            wait_for(lambda: len(w.events('started')) > count, 'No long-running child')
            time.sleep(1.2)
            self.assertEqual('healthy', w.status(), 'Legitimate in-budget job lost health after a prior success')
            wait_for(lambda: w.status() == 'unhealthy', 'Overdue/stuck child stayed healthy', timeout=8)
            self.assertEqual(0, w.probe('live').returncode)
            count = len(w.events('started'))
            wait_for(lambda: len(w.events('started')) > count, 'TERM-ignoring timed-out child was not killed/retried', timeout=10)
            self.assertEqual('unhealthy', w.status())
            w.set_mode('success')
            wait_for(lambda: w.status() == 'healthy', 'Worker did not recover after timeout')
            count = len(w.events('started'))
            w.set_mode('hold')
            wait_for(lambda: len(w.events('started')) > count, 'No active child for graceful stop')
            docker('stop', '--time', str(8 * TIME_SCALE), w.name)
            self.assertTrue(w.events('term'), 'Parent did not forward SIGTERM to its child')
            self.assertEqual(0, w.state()['ExitCode'])
            self.assertEqual('stopped', w.snapshot()['phase'])

        def test_stale_success_fails_with_live_parent_then_recovers(self):
            w = self.worker(interval=8, budget=2, max_age=3)
            wait_for(lambda: w.status() == 'healthy', 'No initial success')
            self.assertTrue(w.events('succeeded'))
            wait_for(lambda: w.status() == 'unhealthy', 'Stale success remained healthy despite the live scheduler', timeout=6)
            self.assertEqual(0, w.probe('live').returncode)
            wait_for(lambda: w.status() == 'healthy', 'Next successful scheduled run did not refresh success', timeout=10)
            self.assertGreaterEqual(len(w.events('succeeded')), 2)

        def test_frozen_parent_cannot_reuse_recent_success(self):
            w = self.worker()
            wait_for(lambda: w.status() == 'healthy', 'No initial success')
            docker('kill', '--signal', 'STOP', w.name)
            wait_for(lambda: w.status() == 'unhealthy', 'Frozen scheduler remained healthy on old success', timeout=9)
            wait_for(lambda: w.probe('live').returncode != 0,
                     'Frozen parent heartbeat never became stale', timeout=6)
            docker('kill', '--signal', 'CONT', w.name)
            wait_for(lambda: w.status() == 'healthy', 'Resumed scheduler did not recover')

        def test_overdue_completion_cannot_be_accepted_after_parent_pause(self):
            w = self.worker(mode='hold', budget=2)
            wait_for(lambda: w.events('started'), 'No child before parent pause')
            docker('kill', '--signal', 'STOP', w.name)
            time.sleep(2.3 * TIME_SCALE)  # Really exceeds the scaled budget while the parent cannot poll.
            w.set_mode('success')
            wait_for(lambda: w.events('succeeded'), 'Child did not finish while parent was paused')
            w.set_mode('hold')
            docker('kill', '--signal', 'CONT', w.name)
            wait_for(lambda: len(w.events('started')) >= 2, 'Scheduler did not resume retries')
            state = w.snapshot()
            self.assertEqual('timeout', state['lastResult'], 'Late exit0 was accepted as an in-budget success')
            self.assertEqual(124, state['lastExitCode'])
            self.assertIsNone(state['lastSuccessAt'])
            self.assertNotEqual(0, w.probe().returncode)

    SchedulerRegression.__name__ = service['command'][2].replace('/', '_')
    SchedulerRegression.__qualname__ = SchedulerRegression.__name__
    return unittest.defaultTestLoader.loadTestsFromTestCase(SchedulerRegression)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', default=os.getenv('SCHEDULER_TEST_IMAGE', 'shopkmp-migrated-e2e-yii2'))
    parser.add_argument('--service', choices=['maintenance', 'reconcile', 'payment-recovery'])
    parser.add_argument('--test', help='Run only test names containing this substring')
    args = parser.parse_args()
    print(f'Scheduler fixture timing scale: {TIME_SCALE}; all production-script/probe assertions enabled', flush=True)
    config = json.loads(subprocess.check_output(command(ROOT / '.env.example') + ['config', '--format', 'json']))
    suite = unittest.TestSuite(worker_tests(config['services'][name], args.image)
                               for name in ([args.service] if args.service else ['maintenance', 'reconcile', 'payment-recovery']))
    if args.test:
        suite = unittest.TestSuite(test for group in suite for test in group if args.test in test.id())
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    raise SystemExit(not result.wasSuccessful())


if __name__ == '__main__':
    main()
