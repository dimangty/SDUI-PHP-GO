#!/usr/bin/env python3
"""Create explicit short-lived TEST certificates/secrets; never production material."""
import argparse
import os
from pathlib import Path
import secrets
import subprocess
import tempfile


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--parent', default=tempfile.gettempdir())
    args = parser.parse_args()
    path = Path(tempfile.mkdtemp(prefix='shopkmp-task4-pki-', dir=args.parent))
    os.chmod(path, 0o700)

    def run(*args):
        subprocess.run(['openssl', *map(str, args)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    run('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '3', '-subj', '/CN=Task4 disposable test CA',
        '-keyout', path / 'ca.key', '-out', path / 'ca.crt', '-addext', 'basicConstraints=critical,CA:TRUE',
        '-addext', 'keyUsage=critical,keyCertSign,cRLSign')
    for name, host in [('api', 'api.shopkmp.test'), ('postgres', 'postgres'), ('keycloak-postgres', 'keycloak-postgres'),
                       ('keycloak', 'keycloak'), ('opensearch', 'opensearch'), ('provider', 'localhost')]:
        run('req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=' + host,
            '-keyout', path / (name + '.key'), '-out', path / (name + '.csr'))
        ext = path / (name + '.ext')
        ext.write_text(f'subjectAltName=DNS:{host}\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth,clientAuth\n')
        run('x509', '-req', '-in', path / (name + '.csr'), '-CA', path / 'ca.crt', '-CAkey', path / 'ca.key',
            '-CAcreateserial', '-days', '3', '-extfile', ext, '-out', path / (name + '.crt'))
        # Container service UIDs need read access to their explicitly mounted keys.
        # The parent directory is private; only test keys have this mode.
        os.chmod(path / (name + '.key'), 0o644)
    values = dict(COMPOSE_PROJECT_NAME='shopkmp-migrated-tls-e2e', EDGE_BIND='127.0.0.1', EDGE_TLS_PORT='58096',
                  EDGE_SUBNET='172.30.96.0/24', EDGE_IP='172.30.96.2', YII_HTTP_IP='172.30.96.3', SDUI_IP='172.30.96.4',
                  PUBLIC_API_HOST='api.shopkmp.test', TLS_CERT_DIR=str(path), CA_BUNDLE_FILE=str(path / 'ca.crt'),
                  PAYMENT_GATEWAY_URL='https://localhost:18443', PAYMENT_WEBHOOK_ALLOWED_CIDRS='192.0.2.16/28',
                  ADMIN_EMAILS='admin@shopkmp.test')
    for key in ('POSTGRES_PASSWORD', 'KEYCLOAK_DB_PASSWORD', 'KEYCLOAK_ADMIN_PASSWORD', 'KEYCLOAK_CLIENT_SECRET',
                'OPENSEARCH_ADMIN_PASSWORD', 'PAYMENT_WEBHOOK_SECRET', 'ADMIN_BOOTSTRAP_TOKEN', 'PAYMENT_GATEWAY_API_KEY'):
        values[key] = secrets.token_urlsafe(36)
    env = path / 'test.env'
    env.write_text('\n'.join(k + '=' + v for k, v in values.items()) + '\n')
    os.chmod(env, 0o600)
    print(env)


if __name__ == '__main__':
    main()
