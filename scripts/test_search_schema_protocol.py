#!/usr/bin/env python3
"""Deterministic real mTLS protocol checks; prerequisite: make test-php / PHP_TEST_IMAGE."""
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import uuid
from test_service_support import php_test_image

ROOT = Path(__file__).resolve().parents[1]


class SchemaProtocol(unittest.TestCase):
    def test_acknowledgements_readback_and_no_write_retry(self):
        with tempfile.TemporaryDirectory(prefix='shopkmp-f2-schema-protocol-') as parent:
            env_path = Path(subprocess.check_output([
                sys.executable, str(ROOT / 'scripts/test_pki.py'), '--parent', parent,
                '--project', 'shopkmp-round7-schema-protocol-e2e'], text=True).strip())
            command = ['docker', 'run', '--rm', '--network', 'none', '--memory', '128m',
                       '--add-host', 'opensearch:127.0.0.1', '--entrypoint', 'php',
                       '--name', 'shopkmp-round7-schema-' + uuid.uuid4().hex[:8] + '-e2e',
                       '-v', str(ROOT / 'deploy') + ':/opt/f2:ro']
            for name in ('ca.crt', 'search-admin.crt', 'search-admin.key', 'opensearch.crt', 'opensearch.key'):
                command += ['-v', str(env_path.parent / name) + ':/run/tls/' + name + ':ro']
            result = subprocess.run(command + [php_test_image(), '/opt/f2/search-schema-protocol-test.php'],
                                    capture_output=True, text=True, timeout=120)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            print(result.stdout, end='')


if __name__ == '__main__':
    unittest.main()
