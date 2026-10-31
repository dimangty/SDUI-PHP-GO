#!/usr/bin/env python3
"""Create explicit short-lived TEST certificates/secrets; never production material."""
import argparse
import ipaddress
import os
from pathlib import Path
import secrets
import subprocess
import tempfile


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--parent', default=tempfile.gettempdir())
    parser.add_argument('--project', default='shopkmp-migrated-tls-e2e')
    parser.add_argument('--port', type=int, default=58096)
    parser.add_argument('--edge-subnet', default='172.30.96.0/24')
    parser.add_argument('--fpm-subnet', default='172.30.97.0/24')
    args = parser.parse_args()
    edge_subnet = ipaddress.ip_network(args.edge_subnet)
    fpm_subnet = ipaddress.ip_network(args.fpm_subnet)
    if not args.project.endswith('-e2e') or not 1 <= args.port <= 65535:
        parser.error('Use a disposable -e2e project and a valid port')
    if edge_subnet.num_addresses < 8 or fpm_subnet.num_addresses < 8 or edge_subnet.overlaps(fpm_subnet):
        parser.error('Use distinct non-overlapping subnets with space for service addresses')
    path = Path(tempfile.mkdtemp(prefix='shopkmp-task4-pki-', dir=args.parent))
    os.chmod(path, 0o700)

    def run(*args):
        subprocess.run(['openssl', *map(str, args)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    run('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '3', '-subj', '/CN=Task4 disposable test CA',
        '-keyout', path / 'ca.key', '-out', path / 'ca.crt', '-addext', 'basicConstraints=critical,CA:TRUE',
        '-addext', 'keyUsage=critical,keyCertSign,cRLSign')
    for name, host in [('api', 'api.shopkmp.test'), ('postgres', 'postgres'),
                       ('opensearch', 'opensearch'), ('provider', 'localhost')]:
        run('req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=' + host,
            '-keyout', path / (name + '.key'), '-out', path / (name + '.csr'))
        ext = path / (name + '.ext')
        ext.write_text(f'subjectAltName=DNS:{host}\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth,clientAuth\n')
        run('x509', '-req', '-in', path / (name + '.csr'), '-CA', path / 'ca.crt', '-CAkey', path / 'ca.key',
            '-CAcreateserial', '-days', '3', '-extfile', ext, '-out', path / (name + '.crt'))
        # Container service UIDs need read access to their explicitly mounted keys.
        # The parent directory is private; only test keys have this mode.
        os.chmod(path / (name + '.key'), 0o644)
    values = dict(COMPOSE_PROJECT_NAME=args.project, EDGE_BIND='127.0.0.1', EDGE_TLS_PORT=str(args.port),
                  EDGE_SUBNET=str(edge_subnet), EDGE_IP=str(edge_subnet[2]), YII_HTTP_IP=str(edge_subnet[3]),
                  SDUI_IP=str(edge_subnet[4]), TRUSTED_BFF_IPS=str(edge_subnet[4]),
                  YII_FPM_SUBNET=str(fpm_subnet), YII_FPM_HTTP_IP=str(fpm_subnet[3]),
                  YII_FPM_DYNAMIC_RANGE=str(list(fpm_subnet.subnets(prefixlen_diff=1))[1]),
                  PUBLIC_API_HOST='api.shopkmp.test', TLS_CERT_DIR=str(path), CA_BUNDLE_FILE=str(path / 'ca.crt'),
                  PAYMENT_GATEWAY_URL='https://localhost:18443', PAYMENT_WEBHOOK_ALLOWED_CIDRS='192.0.2.16/28')
    for key in ('POSTGRES_PASSWORD', 'POSTGRES_RUNTIME_PASSWORD', 'POSTGRES_OWNER_PASSWORD',
                'POSTGRES_SEARCH_PASSWORD','POSTGRES_INDEXER_PASSWORD','POSTGRES_MAINTENANCE_PASSWORD','POSTGRES_RECOVERY_PASSWORD',
                'POSTGRES_OPERATOR_PASSWORD', 'OPENSEARCH_ADMIN_PASSWORD', 'PAYMENT_WEBHOOK_SECRET', 'PAYMENT_GATEWAY_API_KEY'):
        values[key] = secrets.token_urlsafe(36)
    env = path / 'test.env'
    env.write_text('\n'.join(k + '=' + v for k, v in values.items()) + '\n')
    os.chmod(env, 0o600)
    print(env)


if __name__ == '__main__':
    main()
