#!/usr/bin/env python3
"""Exercise the dependency guard against real, isolated source trees."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

GUARD = Path(__file__).with_name('runtime_graph_guard.py')
PROVIDER = 'key' + 'cloak'


class RuntimeGraphGuardTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='local-auth-graph-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        subprocess.run(['git', 'init', '--quiet', str(self.root)], check=True)
        self.write('scripts/runtime_history_allowlist.json', json.dumps({'schemaVersion': 1, 'files': []}))
        self.write('docker-compose.yml', 'services: {postgres: {}, yii2: {}}\n')

    def write(self, path, text):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)

    def run_guard(self):
        return subprocess.run([sys.executable, str(GUARD), '--root', str(self.root)], capture_output=True, text=True)

    def allow(self, path):
        digest = hashlib.sha256((self.root / path).read_bytes()).hexdigest()
        self.write('scripts/runtime_history_allowlist.json', json.dumps({'schemaVersion': 1, 'files': [
            {'path': path, 'sha256': digest, 'reason': 'Frozen pre-local-auth evidence'}]}))

    def test_clean_tree_succeeds(self):
        self.write('build-logic/AndroidConvention.kt', 'class AndroidConvention\n')
        result = self.run_guard()
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)

    def test_historical_inventory_is_read_only_and_never_proposes_live_exceptions(self):
        self.write('docs/old-report.md', PROVIDER + '\n')
        self.write('scripts/live.py', PROVIDER + '\n')
        result = subprocess.run([sys.executable, str(GUARD), '--root', str(self.root),
                                 '--historical-inventory'], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        rows = json.loads(result.stdout)['files']
        self.assertEqual(['docs/old-report.md'], [row['path'] for row in rows])
        self.assertEqual([], json.loads((self.root / 'scripts/runtime_history_allowlist.json').read_text())['files'])

    def test_new_untracked_runtime_dependency_fails_with_file_and_line(self):
        self.write('scripts/launch.py', 'print("safe")\nurl="http://' + PROVIDER + ':8080"\n')
        result = self.run_guard()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn('scripts/launch.py:2', result.stdout)

    def test_current_cutover_and_audit_prose_can_describe_the_removed_provider(self):
        self.write('docs/backend-fixes/round-5-local-auth/ADR.md',
                   'Remove ' + PROVIDER + '; never accept its old tokens.\n')
        self.write('docs/backend-fixes/local-auth/API_COMPATIBILITY.md',
                   'Old ' + PROVIDER + ' tokens are rejected with HTTP 401.\n')
        self.write('docs/backend-fixes/round-5-local-auth/DEPENDENCY_EVIDENCE.json',
                   json.dumps({'removed': 'firebase/php-' + 'jwt'}))
        result = self.run_guard()
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)

    def test_provider_named_binary_cannot_hide_behind_text_scan_detection(self):
        path = 'deploy/' + PROVIDER + '.tar'
        self.write(path, '\0binary fixture')
        result = self.run_guard()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn(path + ':1', result.stdout)

    def test_dependency_manifest_and_obsolete_admin_registration_config_fail(self):
        for path, content in [('backend-yii2/composer.json', '{"require":{"firebase/php-' + 'jwt":"*"}}'),
                              ('.env.example', 'ADMIN_' + 'BOOTSTRAP_TOKEN=old\nADMIN_' + 'EMAILS=old@example.test\n')]:
            with self.subTest(path=path):
                self.write(path, content)
                result = self.run_guard()
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertIn(path + ':1', result.stdout)
                (self.root / path).unlink()

    def test_exact_historical_file_is_allowed_but_sibling_and_edits_are_not(self):
        path = 'docs/backend-fixes/local-auth/history/realm.json'
        self.write(path, '{"realm":"' + PROVIDER + '"}\n')
        self.allow(path)
        self.assertEqual(0, self.run_guard().returncode)
        self.write('docs/backend-fixes/local-auth/history/live.yml', PROVIDER + ': {}\n')
        self.assertEqual(1, self.run_guard().returncode)
        (self.root / 'docs/backend-fixes/local-auth/history/live.yml').unlink()
        self.write(path, 'changed\n')
        self.assertEqual(1, self.run_guard().returncode)

    def test_live_sources_and_current_runbooks_cannot_be_allowlisted(self):
        for path in ('scripts/live.py', 'backend-yii2/infrastructure/Provider.php',
                     'docker-compose.yml', 'README.md', 'docs/DEPLOYMENT.md',
                     'docs/backend-fixes/round-5-local-auth/PLAN.md'):
            with self.subTest(path=path):
                self.write(path, PROVIDER + '\n')
                self.allow(path)
                result = self.run_guard()
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertIn('not a historical evidence path', result.stdout)

    def test_archived_policy_cannot_become_a_live_mount_or_input(self):
        path = 'docs/backend-fixes/local-auth/history/realm.json'
        self.write(path, '{"realm":"' + PROVIDER + '"}\n')
        self.allow(path)
        self.write('docker-compose.yml', 'services: {auth: {volumes: ["./' + path + ':/import/policy:ro"]}}\n')
        result = self.run_guard()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn('evidence used by live source', result.stdout)

    def test_current_audit_report_cannot_be_repurposed_as_runtime_configuration(self):
        path = 'docs/backend-fixes/round-5-local-auth/runtime.json'
        self.write(path, json.dumps({'retiredProvider': PROVIDER}))
        self.write('docker-compose.yml', 'services: {auth: {volumes: ["./' + path + ':/runtime/config.json:ro"]}}\n')
        result = self.run_guard()
        self.assertEqual(1, result.returncode, result.stderr)
        self.assertIn('evidence used by live source', result.stdout)

    def test_missing_duplicate_and_traversal_history_entries_fail_closed(self):
        path = 'docs/old-report.md'
        self.write(path, PROVIDER + '\n')
        self.allow(path)
        manifest = json.loads((self.root / 'scripts/runtime_history_allowlist.json').read_text())
        manifest['files'] *= 2
        self.write('scripts/runtime_history_allowlist.json', json.dumps(manifest))
        self.assertEqual(1, self.run_guard().returncode)
        self.allow(path)
        (self.root / path).unlink()
        self.assertEqual(1, self.run_guard().returncode)
        manifest['files'] = [{'path': '../escape.md', 'sha256': '0' * 64, 'reason': 'invalid'}]
        self.write('scripts/runtime_history_allowlist.json', json.dumps(manifest))
        self.assertEqual(1, self.run_guard().returncode)


if __name__ == '__main__':
    unittest.main(verbosity=2)
