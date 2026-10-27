#!/usr/bin/env python3
"""Inspect Docker's resolved model and exercise fail-closed production validation."""
import argparse
import copy
import json
import subprocess
from compose import command, load_env, ROOT
from validate_production import validate


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--production-env', required=True)
    args = parser.parse_args()
    for prod, path in [(False, ROOT / '.env.example'), (True, args.production_env)]:
        if prod:
            validate(load_env(path))
        cfg = json.loads(subprocess.check_output(command(path, prod=prod) + ['config', '--format', 'json']))
        services = cfg['services']
        assert set(services) == {'postgres', 'migrate', 'yii-migrate', 'keycloak-postgres', 'keycloak', 'opensearch',
                                 'reindex', 'yii2', 'yii2-http', 'sdui', 'maintenance', 'reconcile', 'payment-recovery'} | {'edge'}
        assert all(not service.get('ports') for name, service in services.items() if name != 'edge')
        assert len(services['edge']['ports']) == 1
        assert not any(key in services['sdui']['environment'] for key in ('DATABASE_URL', 'PGPASSWORD', 'KEYCLOAK_CLIENT_SECRET'))
        assert services['sdui']['environment']['YII_ORIGIN'] == 'http://yii2-http:8080'
        assert services['yii2']['environment']['TRUSTED_BFF_IPS'] == services['sdui']['networks']['ingress']['ipv4_address']
        assert set(services['sdui']['networks']) == {'ingress'}
        assert services['sdui']['environment']['TRUSTED_EDGE_IPS'] == services['edge']['networks']['ingress']['ipv4_address']
        assert services['yii2']['environment']['FPM_TRUSTED_NGINX_IPS'] == services['yii2-http']['networks']['fpm']['ipv4_address'] + ',127.0.0.1'
        assert services['migrate']['build']['dockerfile'].endswith('deploy/migrate.Dockerfile')
        assert services['yii2']['healthcheck']['test'][-1] == '/opt/probes/fpm-health.php'
        assert services['yii2']['depends_on']['reindex']['condition'] == 'service_completed_successfully'
        if prod:
            for name in ('yii2', 'yii-migrate', 'reindex', 'maintenance', 'reconcile', 'payment-recovery'):
                e = services[name]['environment']
                assert e['APP_ENV'] == 'production' and 'sslmode=verify-full' in e['DATABASE_URL']
                assert e['PGSSLROOTCERT'] == '/run/tls/ca.crt'
                assert e['KEYCLOAK_ISSUER'] == 'https://keycloak:8443/realms/shopkmp'
            assert services['keycloak']['command'][0] == 'start'
            assert not services['opensearch']['environment'].get('DISABLE_SECURITY_PLUGIN')
            assert not any('swagger' in v['target'] for v in services['edge']['volumes'])
        print(('production' if prod else 'development') + ' resolved Compose boundaries: PASS')
    env = load_env(args.production_env)
    for change in ({'POSTGRES_PASSWORD': 'replace-me'}, {'PAYMENT_GATEWAY_URL': 'http://insecure.invalid'},
                   {'PAYMENT_WEBHOOK_ALLOWED_CIDRS': '0.0.0.0/0'}, {'CA_BUNDLE_FILE': '/absent'},
                   {'EDGE_IP': '203.0.113.1'}, {'KEYCLOAK_CLIENT_SECRET': env['POSTGRES_PASSWORD']},
                   {'PUBLIC_API_HOST': 'wrong.shopkmp.test'}):
        invalid = copy.copy(env) | change
        try:
            validate(invalid)
        except (ValueError, subprocess.CalledProcessError, FileNotFoundError):
            continue
        raise AssertionError('Unsafe production configuration accepted: ' + ','.join(change))
    print('Seven unsafe production configurations rejected: PASS')


if __name__ == '__main__':
    main()
