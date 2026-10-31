"""Fail closed on missing secrets, bad TLS identities and unsafe provider config."""
import ipaddress
from pathlib import Path
import re
import subprocess
from urllib.parse import urlsplit


def validate_model(model):
    """Inspect Docker's fully merged model; never log its credential values."""
    services = model['services']
    expected = {name: ('shopkmp_runtime', 'runtime') for name in ('yii2', 'seed')}
    expected.update({'maintenance':('shopkmp_maintenance','maintenance'),
                     'reconcile':('shopkmp_search','search'),
                     'payment-recovery':('shopkmp_recovery','recovery'),
                     'reindex':('shopkmp_indexer','indexer')})
    expected.update({'yii-migrate': ('shopkmp_owner', 'owner'),
                     'identity-operator': ('shopkmp_identity_operator', 'operator')})
    for name, (user, capability) in expected.items():
        if name not in services:
            if name in ('seed', 'identity-operator'):
                continue  # disabled CLI profiles are not runtime services
            raise ValueError('Production database service missing: '+name)
        env = services[name].get('environment', {})
        url = urlsplit(env.get('DATABASE_URL', ''))
        if (url.scheme not in ('postgres', 'postgresql') or url.username != user
                or not url.password or url.hostname != 'postgres' or url.path != '/shopkmp'
                or url.query != 'sslmode=verify-full'
                or env.get('APP_ENV') != 'production' or env.get('DATABASE_ROLE') != capability):
            raise ValueError(name+': unsafe production database configuration')
        if any(key in env for key in ('PGPASSWORD', 'PGUSER', 'PGSERVICE', 'PGSERVICEFILE',
               'POSTGRES_PASSWORD', 'POSTGRES_OWNER_PASSWORD', 'POSTGRES_OPERATOR_PASSWORD')):
            raise ValueError(name+': unexpected privileged database secret/configuration')
    migrate = services['migrate']['environment']
    url = urlsplit(migrate.get('DATABASE_URL', ''))
    if url.username != 'shopkmp_owner' or not migrate.get('PGPASSWORD') or 'sslmode=verify-full' not in url.query:
        raise ValueError('migrate: explicit owner TLS credentials required')


def openssl(*args):
    return subprocess.check_output(['openssl', *map(str, args)], stderr=subprocess.STDOUT)


def validate(env):
    keys = ('POSTGRES_PASSWORD', 'POSTGRES_RUNTIME_PASSWORD', 'POSTGRES_OWNER_PASSWORD',
            'POSTGRES_SEARCH_PASSWORD','POSTGRES_INDEXER_PASSWORD','POSTGRES_MAINTENANCE_PASSWORD','POSTGRES_RECOVERY_PASSWORD',
            'POSTGRES_OPERATOR_PASSWORD', 'OPENSEARCH_ADMIN_PASSWORD', 'PAYMENT_WEBHOOK_SECRET',
            'PAYMENT_GATEWAY_API_KEY')
    secrets = []
    for key in keys:
        value = env.get(key, '')
        if not re.fullmatch(r'[A-Za-z0-9_-]{32,}', value) or any(
                word in value.lower() for word in ('replace', 'example', 'changeme', 'devdatabase', 'placeholder')):
            raise ValueError(f'{key}: provide a unique random URL-safe secret (32+ characters)')
        secrets.append(value)
    if len(set(secrets)) != len(secrets):
        raise ValueError('Production secrets must be distinct')
    host = env.get('PUBLIC_API_HOST', '')
    if not re.fullmatch(r'[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?', host) or host.endswith('.example.com') or host in ('localhost', 'example.com'):
        raise ValueError('PUBLIC_API_HOST must be the real single ingress DNS name')
    provider = urlsplit(env.get('PAYMENT_GATEWAY_URL', ''))
    if provider.scheme != 'https' or not provider.hostname or provider.username or provider.password or provider.query or provider.fragment or provider.hostname.endswith('example.com'):
        raise ValueError('PAYMENT_GATEWAY_URL must be a configured HTTPS provider URL')
    cidrs = env.get('PAYMENT_WEBHOOK_ALLOWED_CIDRS', '').split(',')
    for cidr in cidrs:
        if not cidr or ipaddress.ip_network(cidr.strip(), strict=True).prefixlen == 0:
            raise ValueError('Specify bounded provider source CIDRs')
    subnet = ipaddress.ip_network(env.get('EDGE_SUBNET', ''))
    addresses = [ipaddress.ip_address(env.get(key, '')) for key in ('EDGE_IP', 'YII_HTTP_IP', 'SDUI_IP')]
    if len(set(addresses)) != 3 or any(address not in subnet or address in (subnet.network_address, subnet.broadcast_address) for address in addresses):
        raise ValueError('EDGE_IP, YII_HTTP_IP, SDUI_IP must be distinct usable addresses in EDGE_SUBNET')
    for origin in filter(None, env.get('CORS_ALLOWED_ORIGINS', '').split(',')):
        if urlsplit(origin.strip()).scheme != 'https' or '*' in origin:
            raise ValueError('Production CORS origins must be explicit HTTPS origins')
    directory = Path(env.get('TLS_CERT_DIR', ''))
    bundle = Path(env.get('CA_BUNDLE_FILE', ''))
    if not directory.is_absolute() or not bundle.is_absolute() or not bundle.is_file():
        raise ValueError('TLS_CERT_DIR and CA_BUNDLE_FILE must be existing absolute paths')
    ca = directory / 'ca.crt'
    openssl('x509', '-in', ca, '-noout', '-checkend', '86400')
    openssl('crl2pkcs7', '-nocrl', '-certfile', bundle)
    for name, dns in [('api', host), ('postgres', 'postgres'), ('opensearch', 'opensearch')]:
        cert, key = directory / (name + '.crt'), directory / (name + '.key')
        openssl('verify', '-CAfile', ca, '-purpose', 'sslserver', '-verify_hostname', dns, cert)
        openssl('verify', '-CAfile', bundle, '-purpose', 'sslserver', '-verify_hostname', dns, cert)
        openssl('x509', '-in', cert, '-noout', '-checkend', '86400')
        if openssl('x509', '-in', cert, '-pubkey', '-noout') != openssl('pkey', '-in', key, '-passin', 'pass:', '-pubout'):
            raise ValueError(f'{name}: private key does not match certificate')
        if name == 'opensearch':
            if openssl('x509', '-in', cert, '-subject', '-noout', '-nameopt', 'RFC2253').strip() != b'subject=CN=opensearch':
                raise ValueError('OpenSearch node certificate subject must match CN=opensearch')
            if not key.read_bytes().startswith(b'-----BEGIN PRIVATE KEY-----'):
                raise ValueError('OpenSearch requires an unencrypted PKCS#8 private key')
            openssl('verify', '-CAfile', ca, '-purpose', 'sslclient', cert)
    print('Production secrets, TLS chains/hostnames/keys, CA bundle and source CIDRs validated')
