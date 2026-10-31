"""The FastCGI adversary must hit the selected fixture, never a shared DNS alias."""
import importlib.util
import json
import os
from pathlib import Path
import unittest
from unittest.mock import patch


PATH = Path(__file__).resolve().parents[1] / 'backend-yii2/tests/trust-live/run.py'
SPEC = importlib.util.spec_from_file_location('trust_live_target_fixture', PATH)
trust = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(trust)


class FastCGITarget(unittest.TestCase):
    def invoke(self, networks):
        attempts = []

        def compose(*args):
            if args == ('ps', '-q', 'yii2'):
                return 'exact-fixture-container\n'
            if args == ('logs', 'yii2'):
                return 'ERROR: Connection disallowed: IP address has been dropped.\n'
            if args[:3] == ('exec', '-T', 'untrusted'):
                attempts.append(args[-1])
                self.assertIn("tcp://192.0.2.17:9000", args[-1])
                self.assertNotIn("tcp://yii2:9000", args[-1])
                return json.dumps({'bytes': 0, 'timeout': False})
            self.fail('Unexpected fixture operation')

        def inspect(args, **kwargs):
            self.assertEqual(['docker', 'inspect', '--format', '{{json .NetworkSettings.Networks}}',
                              'exact-fixture-container'], args)
            return json.dumps(networks)

        with patch.dict(os.environ, {'TRUST_DB_NETWORK': 'selected-disposable-data'}), \
             patch.object(trust, 'reset'), patch.object(trust, 'buckets', return_value=[]), \
             patch.object(trust, 'compose', side_effect=compose), \
             patch.object(trust, 'run', side_effect=inspect):
            trust.check_fpm()
        return attempts

    def test_shared_alias_cannot_select_the_main_php_stack(self):
        attempts = self.invoke({'selected-disposable-data': {'IPAddress': '192.0.2.17'},
                                'private-fpm': {'IPAddress': '10.246.249.4'}})
        self.assertEqual(1, len(attempts))

    def test_missing_or_invalid_fixture_address_never_falls_back_to_dns(self):
        for networks in ({}, {'selected-disposable-data': {'IPAddress': ''}},
                         {'selected-disposable-data': {'IPAddress': '192.0.2.17/24'}},
                         {'selected-disposable-data': {'IPAddress': '2001:db8::17'}}):
            with self.subTest(networks=networks), self.assertRaises((ValueError, RuntimeError)):
                self.invoke(networks)


if __name__ == '__main__':
    unittest.main(verbosity=2)
