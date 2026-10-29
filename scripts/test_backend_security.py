#!/usr/bin/env python3
"""Behavioral policy fixtures; none are production exceptions."""
import copy
import datetime as dt
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

POLICY = Path(__file__).with_name('backend_security_policy.py')
from backend_security import go_policy
from backend_security_policy import fresh_database


class SecurityPolicyTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.report = {'SchemaVersion': 2, 'ArtifactName': 'fixture', 'ArtifactType': 'container_image',
                       'Metadata': {'ImageID': 'sha256:fixture', 'ImageConfig': {'os': 'linux', 'architecture': 'amd64'}}, 'Results': []}

    def run_policy(self, report, dispositions=None):
        if report is not None:
            (self.root / 'raw.json').write_text(json.dumps(report))
        (self.root / 'dispositions.json').write_text(json.dumps(dispositions or []))
        return subprocess.run([sys.executable, str(POLICY), '--report', str(self.root / 'raw.json'),
                               '--artifact-id', 'sha256:fixture', '--platform', 'linux/amd64',
                               '--dispositions', str(self.root / 'dispositions.json')], capture_output=True, text=True)

    def finding(self, severity='HIGH'):
        report = copy.deepcopy(self.report)
        report['Results'] = [{'Target': 'lib/vendor.jar', 'Vulnerabilities': [
            {'VulnerabilityID': 'CVE-FIXTURE-1', 'PkgName': 'example', 'InstalledVersion': '1', 'Severity': severity}]}]
        return report

    def test_clean_report_succeeds(self):
        result = self.run_policy(self.report)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('PASSED', json.loads(result.stdout)['status'])

    def test_blocking_and_unknown_findings_fail(self):
        for severity in ('HIGH', 'CRITICAL', 'UNKNOWN'):
            result = self.run_policy(self.finding(severity))
            self.assertEqual(1, result.returncode, result.stderr)
            self.assertEqual(1, len(json.loads(result.stdout)['blockers']))

    def test_missing_malformed_and_incomplete_reports_fail(self):
        for report in (None, {}, {'SchemaVersion': 2, 'Results': []}):
            result = self.run_policy(report)
            self.assertNotEqual(0, result.returncode)

    def test_documented_disposition_is_visible_and_raw_is_preserved(self):
        (self.root / 'evidence.txt').write_text('Fixture-only symbol/architecture evidence, not a real waiver.')
        import hashlib
        disposition = {'id': 'CVE-FIXTURE-1', 'package': 'example', 'version': '1', 'target': 'lib/vendor.jar',
                       'artifact': 'sha256:fixture', 'platform': 'linux/amd64', 'status': 'NotAffected',
                       'packagePath': '',
                       'owner': 'fixture-owner', 'approvedBy': 'fixture-reviewer', 'approval': 'TEST-1',
                       'expires': '2099-01-01T00:00:00+00:00', 'evidence': str(self.root / 'evidence.txt'),
                       'evidenceSha256': hashlib.sha256((self.root / 'evidence.txt').read_bytes()).hexdigest()}
        result = self.run_policy(self.finding(), [disposition])
        self.assertEqual(0, result.returncode, result.stderr)
        value = json.loads(result.stdout)
        self.assertEqual('PASSED_WITH_DISPOSITIONS', value['status'])
        self.assertEqual(1, value['rawCounts']['HIGH'])
        self.assertEqual(1, len(value['dispositions']))
        for patch in ({'platform': 'linux/arm64'}, {'expires': '2000-01-01T00:00:00+00:00'},
                      {'approvedBy': ''}, {'evidenceSha256': 'bad'}, {'artifact': 'sha256:other'}):
            result = self.run_policy(self.finding(), [disposition | patch])
            self.assertNotEqual(0, result.returncode)

    def test_scanner_failure_cannot_pass_even_with_clean_report(self):
        result = self.run_policy(self.report)
        self.assertEqual(0, result.returncode, result.stderr)
        # The same policy CLI accepts the recorded raw process exit explicitly.
        raw = self.root / 'raw.json'
        result = subprocess.run([sys.executable, str(POLICY), '--report', str(raw), '--artifact-id',
                                 'sha256:fixture', '--platform', 'linux/amd64', '--scanner-exit', '2'], capture_output=True)
        self.assertNotEqual(0, result.returncode)

    def test_govuln_binary_toolchain_is_checked_in_the_sbom(self):
        messages = [{'config': {'scanner_version': 'v1.9.0', 'scan_mode': 'binary',
                    'db_last_modified': dt.datetime.now(dt.timezone.utc).isoformat()}},
                    {'SBOM': {'go_version': 'go1.27.3'}}]
        self.assertEqual('PASSED', go_policy('\n'.join(map(json.dumps, messages)), 'go1.27.3')['status'])
        for version in ('go1.25.3', ''):
            messages[1]['SBOM']['go_version'] = version
            with self.assertRaises(ValueError):
                go_policy('\n'.join(map(json.dumps, messages)), 'go1.27.3')

    def test_missing_stale_and_future_databases_are_rejected(self):
        now = dt.datetime.now(dt.timezone.utc)
        for metadata in ({}, {'UpdatedAt': (now-dt.timedelta(days=3)).isoformat(), 'NextUpdate': (now-dt.timedelta(days=2)).isoformat()},
                         {'UpdatedAt': (now+dt.timedelta(hours=1)).isoformat(), 'NextUpdate': (now+dt.timedelta(days=1)).isoformat()}):
            with self.assertRaises((ValueError, KeyError)):
                fresh_database(metadata, now, dt.timedelta(hours=48))

    def test_artifact_receipt_rejects_missing_worker_wrong_image_and_source_drift(self):
        from backend_artifacts import REQUIRED_SERVICES, verify_receipt
        images = {name: {'imageId': 'sha256:fixture', 'platform': 'linux/amd64'} for name in REQUIRED_SERVICES}
        sources = {'backend-yii2/composer.lock': 'current-lock-hash'}
        receipt = {'sources': sources, 'images': copy.deepcopy(images), 'tested': True}
        verify_receipt(receipt, sources, images)
        missing = copy.deepcopy(images)
        del missing['payment-recovery']
        wrong = copy.deepcopy(images)
        wrong['payment-recovery']['imageId'] = 'sha256:old'
        for current in (missing, wrong):
            with self.assertRaises(ValueError):
                verify_receipt(receipt, sources, current)
        with self.assertRaises(ValueError):
            verify_receipt(receipt, sources | {'backend-yii2/composer.lock': 'new-lock-hash'}, images)
        with self.assertRaises(ValueError):
            verify_receipt(receipt | {'tested': False}, sources, images)

    def test_hung_scanner_retains_nonzero_exit_and_failed_gate(self):
        from backend_security import Scanner
        scan = Scanner(self.root, seconds=.2)
        scan.attempt('hung-scanner', lambda: scan.run('hung-scanner', [sys.executable, '-c', 'import time; time.sleep(60)']))
        self.assertEqual(1, scan.finish('linux/amd64'))
        self.assertEqual(124, json.loads((self.root / 'hung-scanner.command.json').read_text())['exitCode'])
        self.assertEqual('FAILED', json.loads((self.root / 'gate.json').read_text())['status'])

    def test_unhealthy_artifact_retains_verified_image_evidence_without_passing_gate(self):
        from backend_artifacts import inventory
        from backend_security import Scanner
        outputs = [json.dumps({'services': {'yii2': {'image': 'fixture'}}}),
                   json.dumps([{'Id': 'sha256:fixture', 'Os': 'linux', 'Architecture': 'arm64'}]),
                   'container', json.dumps({'image': 'sha256:fixture', 'state':
                       {'Running': True, 'Health': {'Status': 'unhealthy'}}})]
        observed = {}
        scan = Scanner(self.root)
        with patch('backend_artifacts.REQUIRED_SERVICES', ('yii2',)), patch(
                'backend_artifacts.subprocess.check_output', side_effect=outputs):
            scan.attempt('artifact-inventory', lambda: inventory(['compose'], observed=observed))
        self.assertEqual('sha256:fixture', observed['yii2']['imageId'])
        self.assertEqual(1, scan.finish('linux/arm64'))
        self.assertEqual('FAILED', json.loads((self.root / 'gate.json').read_text())['status'])

        outputs[-1] = json.dumps({'image': 'sha256:wrong', 'state': {'Running': True}})
        observed = {}
        with patch('backend_artifacts.REQUIRED_SERVICES', ('yii2',)), patch(
                'backend_artifacts.subprocess.check_output', side_effect=outputs):
            with self.assertRaises(ValueError):
                inventory(['compose'], observed=observed)
        self.assertEqual({}, observed)

    def test_report_wrong_architecture_is_rejected(self):
        report = copy.deepcopy(self.report)
        report['Metadata']['ImageConfig']['architecture'] = 'arm64'
        self.assertNotEqual(0, self.run_policy(report).returncode)


if __name__ == '__main__':
    unittest.main()
