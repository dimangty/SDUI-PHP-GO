#!/usr/bin/env python3
"""Regression coverage for disposable TLS project's isolated trust topology."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

class DisposablePKITest(unittest.TestCase):
    def test_custom_project_uses_matching_ingress_and_fpm_trust(self):
        with tempfile.TemporaryDirectory() as parent:
            result = subprocess.run([
                sys.executable, str(Path(__file__).with_name('test_pki.py')),
                '--parent', parent, '--project', 'shopkmp-backend-fixes-tls-e2e',
                '--port', '58198', '--edge-subnet', '172.31.222.0/24',
                '--fpm-subnet', '172.31.223.0/24',
            ], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            env_file = Path(result.stdout.strip())
            # Do not merge the caller's environment into generated-file assertions.
            values = dict(line.split('=', 1) for line in env_file.read_text().splitlines())
            expected = {
                'COMPOSE_PROJECT_NAME': 'shopkmp-backend-fixes-tls-e2e',
                'EDGE_TLS_PORT': '58198', 'EDGE_SUBNET': '172.31.222.0/24',
                'EDGE_IP': '172.31.222.2', 'YII_HTTP_IP': '172.31.222.3',
                'SDUI_IP': '172.31.222.4', 'TRUSTED_BFF_IPS': '172.31.222.4',
                'YII_FPM_SUBNET': '172.31.223.0/24',
                'YII_FPM_HTTP_IP': '172.31.223.3',
                'YII_FPM_DYNAMIC_RANGE': '172.31.223.128/25',
            }
            for key, value in expected.items():
                self.assertEqual(values.get(key), value, key)
            self.assertEqual(os.stat(env_file).st_mode & 0o777, 0o600)
            self.assertEqual(os.stat(env_file.parent).st_mode & 0o777, 0o700)
            subprocess.run(['openssl', 'verify', '-CAfile', values['CA_BUNDLE_FILE'],
                            '-verify_hostname', values['PUBLIC_API_HOST'],
                            str(env_file.parent / 'api.crt')], check=True,
                           stdout=subprocess.DEVNULL)


if __name__ == '__main__':
    unittest.main()
