#!/usr/bin/env python3
"""Capability checks against Docker's actual base + overlay merge; no secret output."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import uuid
from test_service_support import php_test_image

ROOT = Path(__file__).resolve().parents[1]
EXTERNAL = {'OPENSEARCH_URL', 'PAYMENT_GATEWAY_API_KEY', 'PAYMENT_WEBHOOK_SECRET'}
CAPABILITIES = {
    'yii2': EXTERNAL,
    'reconcile': {'OPENSEARCH_URL'}, 'reindex': {'OPENSEARCH_URL'},
    'payment-recovery': {'PAYMENT_GATEWAY_API_KEY'},
    'yii-migrate': set(), 'migrate': set(), 'maintenance': set(),
    'identity-operator': set(), 'database-provision': set(), 'seed': set(),
}


def model(production=True, all_profiles=True):
    # Synthetic renderer inputs only. Do not load a real deployment env file.
    values = dict(COMPOSE_PROJECT_NAME='shopkmp-round7-model-e2e',
                  TRUSTED_BFF_IPS='10.252.214.4', PUBLIC_API_HOST='api.shopkmp.test',
                  TLS_CERT_DIR='/tmp/shopkmp-test-pki', CA_BUNDLE_FILE='/tmp/shopkmp-test-pki/ca.crt',
                  PAYMENT_GATEWAY_URL='https://provider.shopkmp.test',
                  PAYMENT_WEBHOOK_ALLOWED_CIDRS='192.0.2.16/28')
    for i, key in enumerate(('POSTGRES_PASSWORD', 'POSTGRES_RUNTIME_PASSWORD', 'POSTGRES_OWNER_PASSWORD',
                            'POSTGRES_OPERATOR_PASSWORD', 'POSTGRES_SEARCH_PASSWORD', 'POSTGRES_INDEXER_PASSWORD',
                            'POSTGRES_MAINTENANCE_PASSWORD', 'POSTGRES_RECOVERY_PASSWORD',
                            'OPENSEARCH_ADMIN_PASSWORD', 'OPENSEARCH_RUNTIME_PASSWORD',
                            'OPENSEARCH_RECONCILE_PASSWORD', 'OPENSEARCH_INDEXER_PASSWORD',
                            'PAYMENT_WEBHOOK_SECRET', 'PAYMENT_GATEWAY_API_KEY')):
        values[key] = f'modelOnly{i:02d}' + 'x' * 40
    with tempfile.NamedTemporaryFile(mode='w', suffix='.env') as env:
        env.write('\n'.join(k + '=' + v for k, v in values.items()))
        env.flush()
        command = ['docker', 'compose', '--env-file', env.name, '-f', str(ROOT / 'docker-compose.yml')]
        if production:
            command += ['-f', str(ROOT / 'docker-compose.prod.yml')]
        result = subprocess.run(command + (['--profile', '*'] if all_profiles else []) + ['config', '--format', 'json'],
                                env=os.environ | values, capture_output=True, text=True)
        if result.returncode:
            raise AssertionError('Compose renderer failed (output suppressed to protect credentials)')
        return json.loads(result.stdout)


class ServicePrivileges(unittest.TestCase):
    def test_actual_merged_wrapper_requires_operator_key_only_for_search_admin(self):
        with tempfile.TemporaryDirectory(prefix='shopkmp-f2-admin-boundary-') as parent:
            env_path = Path(subprocess.check_output([
                sys.executable, str(ROOT / 'scripts/test_pki.py'), '--parent', parent,
                '--project', 'shopkmp-round7-admin-boundary-e2e'], text=True).strip())
            key = env_path.parent / 'search-admin.key'
            offline = key.with_suffix('.offline')
            key.rename(offline)
            command = [sys.executable, str(ROOT / 'scripts/compose.py'), '--env', str(env_path), '--prod']
            ordinary = subprocess.run(command + ['config', '--quiet'], env=os.environ | {'COMPOSE_PROFILES': ''}, capture_output=True)
            self.assertEqual(ordinary.returncode, 0, 'inactive operator key blocked ordinary production config')
            admin_env = os.environ | {'COMPOSE_PROFILES': 'search-admin'}
            explicit = subprocess.run(command + ['config', '--quiet'], env=admin_env, capture_output=True)
            self.assertNotEqual(explicit.returncode, 0, 'explicit administration accepted missing operator key')
            self.assertIn(b'search-admin.key', explicit.stderr)
            key.write_text('invalid test key\n')
            invalid = subprocess.run(command + ['config', '--quiet'], env=admin_env, capture_output=True)
            self.assertNotEqual(invalid.returncode, 0, 'explicit administration accepted invalid operator key')
            self.assertIn(b'PKCS#8', invalid.stderr)
            key.write_bytes(offline.read_bytes())
            valid = subprocess.run(command + ['config', '--quiet'], env=admin_env, capture_output=True)
            self.assertEqual(valid.returncode, 0, 'explicit administration rejected valid operator TLS')

    def test_actual_merged_secret_distribution(self):
        for prod in (False, True):
            services = model(prod)['services']
            for name, expected in CAPABILITIES.items():
                if name not in services:
                    continue
                actual = set(services[name].get('environment', {})) & EXTERNAL
                with self.subTest(production=prod, service=name):
                    self.assertEqual(sorted(actual), sorted(expected), 'external credential key set')

    def test_production_scoped_search_and_explicit_administration(self):
        from urllib.parse import urlsplit
        services = model()['services']
        for service, user in (('yii2', 'shopkmp_runtime'), ('reconcile', 'shopkmp_reconcile'),
                              ('reindex', 'shopkmp_indexer')):
            url = urlsplit(services[service]['environment']['OPENSEARCH_URL'])
            self.assertEqual(url.username, user, service + ': search principal')
            self.assertEqual(url.scheme, 'https')
        for name in ('search-security-bootstrap', 'search-security-provision'):
            self.assertIn(name, set(services), 'explicit security administration service')
            service = services[name]
            self.assertEqual(service['profiles'], ['search-admin'])
            self.assertEqual(set(service['networks']), {'application'})
            self.assertFalse(set(service.get('environment', {})) &
                             (EXTERNAL | {'DATABASE_URL', 'PGPASSWORD'}))
        self.assertNotIn('OPENSEARCH_ADMIN_PASSWORD', services['opensearch']['environment'])
        self.assertEqual(services['seed']['profiles'], ['development-only'])
        self.assertEqual(set(services['seed']['environment']), {'APP_ENV'})
        self.assertEqual(services['seed']['environment']['APP_ENV'], 'production')
        for name in ('yii2', 'reconcile', 'reindex', 'maintenance', 'payment-recovery'):
            targets = {v['target'] for v in services[name].get('volumes', [])}
            self.assertNotIn('/run/tls/search-admin.key', targets, name)
            if name in ('reconcile', 'maintenance', 'payment-recovery'):
                self.assertIn('/opt/scheduler', targets, name + ': inherited scheduler mount')
        self.assertIn('/usr/local/etc/php-fpm.d/zz-integration.conf',
                      {v['target'] for v in services['yii2']['volumes']})

    def test_default_model_keeps_explicit_indexer_visible_to_required_sql_gate(self):
        from validate_production import validate_model
        cfg = model(all_profiles=False)
        services = cfg['services']
        self.assertIn('reindex', set(services), 'unchanged database_roles_native.py consumes default-profile indexer DSN')
        self.assertEqual(services['reindex'].get('scale'), 0, 'ordinary up must not start privileged index bootstrap')
        self.assertNotIn('reindex', services['reconcile']['depends_on'])
        self.assertNotIn('search-security-bootstrap', services)
        self.assertNotIn('search-security-provision', services)
        validate_model(cfg)

    def test_actual_zero_scale_skips_up_but_explicit_run_works(self):
        service = model(all_profiles=False)['services']['reindex']
        for key in ('build', 'depends_on', 'networks', 'volumes'):
            service.pop(key, None)
        service.update(image=php_test_image(),
                       network_mode='none', mem_limit='128m', entrypoint=['php'],
                       command=['-r', 'print "explicit indexer invocation checked\\n";'])
        project = 'shopkmp-round7-scale-' + uuid.uuid4().hex[:8] + '-e2e'
        with tempfile.NamedTemporaryFile(mode='w', suffix='.json') as fixture:
            json.dump({'name': project, 'services': {'reindex': service}}, fixture)
            fixture.flush()
            compose = ['docker', 'compose', '-p', project, '-f', fixture.name]
            try:
                up = subprocess.run(compose + ['up', '-d', '--no-deps'], capture_output=True, text=True)
                self.assertEqual(up.returncode, 0, 'owned zero-scale up failed')
                containers = subprocess.check_output(compose + ['ps', '-a', '--quiet'], text=True)
                self.assertEqual(containers.strip(), '', 'ordinary up started privileged one-shot')
                run = subprocess.run(compose + ['run', '--rm', '--no-deps', 'reindex'], capture_output=True, text=True)
                self.assertEqual(run.returncode, 0, 'explicit owned indexer run failed')
                self.assertEqual(run.stdout.strip(), 'explicit indexer invocation checked')
            finally:
                subprocess.run(compose + ['stop', '-t', '5'], check=True, capture_output=True)
                subprocess.run(compose + ['rm', '-f'], check=True, capture_output=True)

    def test_validator_rejects_capability_leaks(self):
        from validate_production import validate_model
        cfg = model(all_profiles=False)
        validate_model(cfg)
        for name, key in (('reconcile', 'PAYMENT_WEBHOOK_SECRET'), ('reindex', 'PAYMENT_GATEWAY_API_KEY'),
                          ('yii-migrate', 'OPENSEARCH_URL'), ('maintenance', 'OPENSEARCH_URL'),
                          ('payment-recovery', 'PAYMENT_WEBHOOK_SECRET')):
            bad = copy.deepcopy(cfg)
            bad['services'][name]['environment'][key] = 'synthetic-only'
            with self.subTest(service=name, key=key), self.assertRaises(ValueError):
                validate_model(bad)
        bad = copy.deepcopy(cfg)
        bad['services']['yii2']['environment']['OPENSEARCH_URL'] = 'https://admin:synthetic@opensearch:9200'
        with self.assertRaises(ValueError):
            validate_model(bad)

    def test_operator_key_detection_does_not_reject_a_parent_directory_name(self):
        from validate_production import validate_model
        cfg = model(all_profiles=False)
        for service in cfg['services'].values():
            for volume in service.get('volumes', []):
                if volume['type'] == 'bind':
                    volume['source'] = '/tmp/search-admin-pki/' + Path(volume['source']).name
        validate_model(cfg)
        cfg['services']['yii2']['volumes'].append({
            'type': 'bind', 'source': '/tmp/search-admin-pki/search-admin.key',
            'target': '/run/tls/unexpected-name.key', 'read_only': True})
        with self.assertRaises(ValueError):
            validate_model(cfg)


if __name__ == '__main__':
    unittest.main()
