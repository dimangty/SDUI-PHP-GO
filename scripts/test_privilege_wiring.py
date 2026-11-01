"""Execute the real Make recipes through a recording Python boundary; no Docker."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ('test_service_privileges.py', 'test_service_command_config.py', 'test_search_schema_protocol.py')


class MandatoryPrivilegeRecipes(unittest.TestCase):
    def invoke(self, failed=''):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            shim = directory / 'python3'
            shim.write_text('#!/usr/bin/env python3-real\n'
                            'import json, os, sys\n'
                            'with open(os.environ["CALLS"], "a") as f: f.write(json.dumps([sys.argv[1:], os.environ.get("PHP_TEST_IMAGE")])+"\\n")\n'
                            'sys.exit(9 if os.environ["FAILED"] in sys.argv[1:] else 0)\n')
            shim.chmod(0o755)
            (directory / 'python3-real').symlink_to(os.sys.executable)
            log = directory / 'calls'
            result = subprocess.run(['make', 'test-service-contracts', 'PHP_TEST_IMAGE=explicit-current-image'],
                                    cwd=ROOT, env=os.environ | {'PATH':str(directory) + ':' + os.environ['PATH'],
                                        'CALLS':str(log), 'FAILED':failed}, capture_output=True, text=True)
            return result, [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []

    def test_all_three_run_with_the_make_selected_image(self):
        result, calls = self.invoke()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([(['scripts/' + script], 'explicit-current-image') for script in SCRIPTS],
                         [(args, image) for args, image in calls])

    def test_each_runner_failure_fails_make_and_stops_following_gates(self):
        for index, script in enumerate(SCRIPTS):
            with self.subTest(script=script):
                result, calls = self.invoke('scripts/' + script)
                self.assertNotEqual(0, result.returncode)
                self.assertEqual(index + 1, len(calls))


if __name__ == '__main__':
    unittest.main(verbosity=2)
