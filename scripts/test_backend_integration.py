#!/usr/bin/env python3
"""Resolved shared development/production/verification trust regression checks."""
import json
import os
import subprocess
import sys
import unittest
from unittest.mock import patch
import backend_fixes
from compose import ROOT, command

RETIRED_PROVIDER = 'key' + 'cloak'


class IntegrationModel(unittest.TestCase):
    def test_owned_disposable_network_overlay_is_opt_in_and_never_changes_production(self):
        overlay = str(ROOT/'deploy/compose.disposable-networks.yml')
        with patch.dict(os.environ, {'BACKEND_DISPOSABLE_NETWORKS':'1'}):
            self.assertIn(overlay, command(ROOT/'.env.example','shopkmp-round6-acceptance-e2e'))
            self.assertNotIn(overlay, command(ROOT/'.env.example','shopkmp-round6-roles-e2e',prod=True))
            with self.assertRaises(ValueError):
                command(ROOT/'.env.example','shopkmp-production')
            with patch.dict(os.environ, {'COMPOSE_PROJECT_NAME':'shopkmp-round6-acceptance-e2e'}):
                self.assertIn(overlay, command(ROOT/'.env.example'))

    def model(self, prod=False, verify=False):
        # Config rendering only; no deployment and no production credentials.
        env = os.environ | {'TLS_CERT_DIR': '/tmp/config-only-tls', 'CA_BUNDLE_FILE': '/tmp/config-only-ca',
                            'PUBLIC_API_HOST': 'config-only.example.test', 'OPENSEARCH_ADMIN_PASSWORD': 'config-only',
                            'PAYMENT_GATEWAY_URL': 'https://provider.example.test',
                            'PAYMENT_GATEWAY_API_KEY': 'config-only', 'PAYMENT_WEBHOOK_ALLOWED_CIDRS': '192.0.2.9/32',
                            'POSTGRES_RUNTIME_PASSWORD': 'runtime-config-only',
                            'POSTGRES_OWNER_PASSWORD': 'owner-config-only',
                            'POSTGRES_OPERATOR_PASSWORD': 'operator-config-only',
                            'POSTGRES_SEARCH_PASSWORD': 'search-config-only',
                            'POSTGRES_INDEXER_PASSWORD': 'indexer-config-only',
                            'POSTGRES_MAINTENANCE_PASSWORD': 'maintenance-config-only',
                            'POSTGRES_RECOVERY_PASSWORD': 'recovery-config-only'}
        return json.loads(subprocess.check_output(command(ROOT / '.env.example', prod=prod, verify=verify) +
                          ['--profile', 'verification', '--profile', 'tools', '--profile', 'database-admin', 'config', '--format', 'json'], env=env))

    def test_exact_transport_boundaries_survive_all_overlays(self):
        for prod, verify in ((False, False), (True, False), (False, True)):
            with self.subTest(production=prod, verification=verify):
                cfg=self.model(prod, verify);s=cfg['services']
                self.assertEqual({'ingress'}, set(s['sdui']['networks']))
                self.assertEqual(s['edge']['networks']['ingress']['ipv4_address'], s['sdui']['environment']['TRUSTED_EDGE_IPS'])
                self.assertEqual(s['sdui']['networks']['ingress']['ipv4_address'], s['yii2']['environment']['TRUSTED_BFF_IPS'])
                self.assertEqual({'ingress','fpm'}, set(s['yii2-http']['networks']))
                self.assertEqual(s['yii2-http']['networks']['fpm']['ipv4_address']+',127.0.0.1', s['yii2']['environment']['FPM_TRUSTED_NGINX_IPS'])
                self.assertTrue(cfg['networks']['fpm']['internal'])
                self.assertTrue(any(v['target']=='/usr/local/etc/php-fpm.d/zz-integration.conf' for v in s['yii2']['volumes']))
                for name in ('sdui','yii2-http','yii2','edge'):
                    self.assertIn('NET_RAW',s[name]['cap_drop'])
                self.assertEqual('EDGE_IP',s['yii2-http']['environment']['NGINX_ENVSUBST_FILTER'])
                for name, service in s.items():
                    if name!='edge': self.assertFalse(service.get('ports'))

    def test_production_database_credentials_are_separated_in_the_merged_model(self):
        from urllib.parse import urlsplit
        s = self.model(prod=True)['services']
        for name, role in {'yii2':'runtime','maintenance':'maintenance','reconcile':'search','payment-recovery':'recovery','reindex':'indexer'}.items():
            env = s[name]['environment']
            self.assertEqual('shopkmp_'+role, urlsplit(env['DATABASE_URL']).username, name)
            self.assertNotIn('PGPASSWORD', env, name)
            self.assertNotIn('POSTGRES_OWNER_PASSWORD', env, name)
            self.assertNotIn('POSTGRES_OPERATOR_PASSWORD', env, name)
            self.assertEqual(role, env['DATABASE_ROLE'])
        for name in ('migrate', 'yii-migrate'):
            self.assertEqual('shopkmp_owner', urlsplit(s[name]['environment']['DATABASE_URL']).username)
        self.assertEqual('shopkmp_identity_operator', urlsplit(s['identity-operator']['environment']['DATABASE_URL']).username)

    def test_production_model_gate_rejects_unsafe_overrides_and_missing_runtime_secret(self):
        import copy
        from validate_production import validate_model
        cfg = self.model(prod=True)
        validate_model(cfg)
        for change in ({'DATABASE_URL':'postgres://shopkmp@postgres/shopkmp?sslmode=verify-full'},
                       {'DATABASE_URL':'postgres://shopkmp_runtime@postgres/shopkmp?sslmode=disable'},
                       {'APP_ENV':'development'}, {'PGPASSWORD':'owner-secret'},
                       {'DATABASE_ROLE':'owner'}):
            altered = copy.deepcopy(cfg)
            altered['services']['yii2']['environment'].update(change)
            with self.assertRaises(ValueError):
                validate_model(altered)

    def test_local_identity_graph_has_no_provider_or_registration_admin_secrets(self):
        for prod, verify in ((False, False), (True, False), (False, True)):
            with self.subTest(production=prod, verification=verify):
                cfg = self.model(prod, verify)
                self.assertNotIn(RETIRED_PROVIDER, cfg['services'])
                self.assertNotIn(RETIRED_PROVIDER + '-postgres', cfg['services'])
                self.assertNotIn(RETIRED_PROVIDER + '_data', cfg.get('volumes', {}))
                for name, service in cfg['services'].items():
                    for key in service.get('environment', {}):
                        self.assertFalse(key.startswith(RETIRED_PROVIDER.upper() + '_'), (name, key))
                        self.assertNotIn(key, ('ADMIN_EMAILS', 'ADMIN_BOOTSTRAP_TOKEN'), (name, key))

    def test_search_bootstrap_is_explicit_and_cannot_block_application_startup(self):
        for prod in (False, True):
            services = self.model(prod)['services']
            self.assertEqual(['php', 'yii', 'maintenance/search-bootstrap'], services['reindex']['command'])
            self.assertEqual(['php', '/opt/scheduler/scheduler.php', 'maintenance/reindex'], services['reconcile']['command'][0:3])
            self.assertNotIn('reindex', services['yii2']['depends_on'])
            self.assertNotIn('opensearch', services['yii2']['depends_on'])
            self.assertEqual('service_completed_successfully', services['yii2']['depends_on']['yii-migrate']['condition'])
            self.assertEqual('service_healthy', services['reindex']['depends_on']['opensearch']['condition'])
            self.assertEqual('service_completed_successfully', services['reconcile']['depends_on']['reindex']['condition'])

    def test_post_seed_reindex_runs_reconciliation_not_bootstrap_inventory(self):
        for target in ('reindex', 'integration-up'):
            rendered = subprocess.check_output(['make', '--dry-run', target,
                'ENV_FILE=.env.example'], cwd=ROOT, text=True)
            self.assertIn('exec -T yii2 php yii maintenance/reindex', rendered)

    def test_recovery_is_separate_from_search_and_keeps_production_tls(self):
        for prod in (False,True):
            s=self.model(prod)['services'];worker=s['payment-recovery']
            self.assertEqual('maintenance/recover',worker['command'][2])
            self.assertEqual('maintenance/reindex',s['reconcile']['command'][2])
            self.assertEqual('ready',worker['healthcheck']['test'][-1])
            self.assertEqual('unless-stopped',worker['restart'])
            if prod:
                self.assertIn('sslmode=verify-full',worker['environment']['DATABASE_URL'])
                self.assertTrue(any(v['target']=='/run/tls/ca.crt' for v in worker['volumes']))
                self.assertTrue(any(v['target']=='/opt/scheduler' for v in worker['volumes']))

    def test_fixture_allowlist_is_exact_and_migrations_are_read_only(self):
        s=self.model(verify=True)['services']
        allow=s['payment-http']['environment']['PAYMENT_WEBHOOK_ALLOWED_CIDRS']
        self.assertEqual({s['payment-provider']['networks']['payment-test']['ipv4_address']+'/32',
                          s['yii2']['networks']['payment-test']['ipv4_address']+'/32'},set(allow.split(',')))
        self.assertTrue(any(v['target']=='/migrations' and v['read_only'] for v in s['yii2']['volumes']))
        self.assertEqual(str(ROOT/'tools/ops/migrations'),next(v['source'] for v in s['yii2']['volumes'] if v['target']=='/migrations'))
        self.assertTrue(any(v['source']==str(ROOT/'deploy/php') and v['target']=='/opt/scheduler' and v['read_only'] for v in s['yii2']['volumes']))
        # Each fixture has two interfaces. Unique aliases force webhook traffic
        # over the exact allowlisted test interface, never the data network.
        self.assertIn('payment-fixture-gateway',s['payment-provider']['networks']['payment-test']['aliases'])
        self.assertIn('payment-fixture-http',s['payment-http']['networks']['payment-test']['aliases'])
        self.assertEqual('http://payment-fixture-gateway:8080',s['payment-http']['environment']['PAYMENT_GATEWAY_URL'])
        self.assertEqual('http://payment-fixture-http:8080',s['payment-provider']['environment']['PAYMENT_TEST_HTTP_ORIGIN'])


class FocusedRunner(unittest.TestCase):
    def invoke(self, fail=False, running='maintenance\npayment-recovery\n'):
        calls=[]
        def docker(argv, **kwargs):
            calls.append((argv,kwargs))
            if fail and argv[-1]=='tests/payments.php':
                raise subprocess.CalledProcessError(1,argv)
        with patch.object(sys,'argv',['backend_fixes.py','--project','shared-integration-e2e','--disposable']), patch.object(backend_fixes.subprocess,'run',side_effect=docker), patch.object(backend_fixes.subprocess,'check_output',return_value=running):
            if fail:
                with self.assertRaises(subprocess.CalledProcessError): backend_fixes.main()
            else: backend_fixes.main()
        return calls

    def test_focused_suites_get_child_only_gateway_configuration(self):
        calls=self.invoke()
        self.assertTrue(any(str(ROOT/'scripts/database_roles_native.py') in a for a,k in calls),
                        'Focused acceptance must execute the real production role matrix')
        suites=[(a,k) for a,k in calls if 'run' in a]
        self.assertEqual(['tests/local_auth_relock_native.php','tests/local_auth_concurrency.php','tests/local_recovery.php','tests/payment-migration.php','tests/payments.php','tests/payment-http.php','tests/uuid_identity.php','tests/uuid_recovery.php','tests/recovery.php','tests/recovery-scheduling.php'],[a[-1] for a,k in suites])
        for argv,kwargs in suites:
            self.assertIn('PAYMENT_GATEWAY_URL=http://payment-fixture-gateway:8080',argv)
            self.assertEqual(os.environ.get('PAYMENT_GATEWAY_URL'),kwargs['env'].get('PAYMENT_GATEWAY_URL'))
        self.assertEqual(['stop','maintenance','payment-recovery'],calls[0][0][-3:])
        self.assertIn('--wait',calls[1][0])
        self.assertEqual(['stop','payment-provider','payment-http'],calls[-2][0][-3:])
        self.assertEqual(['up','-d','--no-deps','maintenance','payment-recovery'],calls[-1][0][-5:])

    def test_failure_is_propagated_and_fixtures_are_stopped(self):
        calls=self.invoke(fail=True)
        self.assertFalse(any(a[-1]=='tests/recovery.php' for a,k in calls))
        self.assertEqual(['stop','payment-provider','payment-http'],calls[-2][0][-3:])
        self.assertEqual(['up','-d','--no-deps','maintenance','payment-recovery'],calls[-1][0][-5:])

    def test_already_paused_workers_stay_paused(self):
        calls=self.invoke(running='yii2\n')
        self.assertFalse(any('maintenance' in a or 'payment-recovery' in a for a,k in calls))
        self.assertEqual(['stop','payment-provider','payment-http'],calls[-1][0][-3:])


if __name__=='__main__': unittest.main(verbosity=2)
