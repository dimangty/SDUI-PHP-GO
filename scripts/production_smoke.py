#!/usr/bin/env python3
"""TLS-mode deployment smoke on the explicitly disposable test PKI project."""
import argparse
import http.client
import json
import socket
import ssl
import subprocess
import uuid
from compose import load_env, command, ROOT


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env', required=True)
    parser.add_argument('--disposable', required=True, action='store_true')
    args = parser.parse_args()
    env = load_env(args.env)
    assert env['COMPOSE_PROJECT_NAME'].endswith('-e2e')
    host, port = env['PUBLIC_API_HOST'], int(env['EDGE_TLS_PORT'])
    context = ssl.create_default_context(cafile=env['CA_BUNDLE_FILE'])
    steps = []

    def call(method, path, body=None, token='', want=200, extra=None):
        connection = http.client.HTTPSConnection(host, port, context=context, timeout=15)
        connection.sock = context.wrap_socket(socket.create_connection(('127.0.0.1', port), timeout=15), server_hostname=host)
        headers = {'Content-Type': 'application/json', 'Host': host} | (extra or {})
        if token:
            headers['Authorization'] = 'Bearer ' + token
        connection.request(method, path, body=json.dumps(body) if body is not None else None, headers=headers)
        response = connection.getresponse()
        data = response.read()
        assert response.status == want, (method, path, response.status)
        connection.close()
        steps.append({'method': method, 'path': path, 'status': response.status})
        return json.loads(data) if data and 'application/json' in response.getheader('Content-Type', '') else None

    for path in ('/health', '/health/live', '/health/ready', '/metrics', '/swagger/', '/swagger/openapi.yaml', '/fpm-ping'):
        call('GET', path, want=404)
    try:
        call('GET', '/', extra={'Host': 'untrusted.invalid'})
        raise AssertionError('Unknown HTTP host accepted')
    except http.client.RemoteDisconnected:
        pass
    try:
        with context.wrap_socket(socket.create_connection(('127.0.0.1', port), timeout=15), server_hostname='untrusted.invalid'):
            raise AssertionError('Unknown SNI accepted')
    except ssl.SSLError:
        pass
    call('GET', '/api/v1/categories')
    call('GET', '/api/v1/screens/home')
    denied = call('POST', '/api/v1/payments/webhook', {}, want=403,
                  extra={'X-Forwarded-For': '192.0.2.20', 'X-Real-IP': '192.0.2.20',
                         'Forwarded': 'for=192.0.2.20', 'X-User-Id': str(uuid.uuid4()), 'X-Role': 'admin'})
    assert denied['error']['code'] == 'WEBHOOK_SOURCE_DENIED'
    identity = uuid.uuid4().hex
    credentials = {'email': 'tls-' + identity + '@shopkmp.test', 'password': 'Tls!7395' + identity}
    call('POST', '/api/v1/auth/register', credentials | {'fullName': 'TLS Smoke', 'phone': '+79991234567'}, want=201)
    tokens = call('POST', '/api/v1/auth/login', credentials)
    call('GET', '/api/v1/screens/home?deviceId=task4-tls-smoke', token=tokens['accessToken'])
    call('GET', '/api/v1/screens/profile', token=tokens['accessToken'])
    call('POST', '/api/v1/auth/logout', {'refreshToken': tokens['refreshToken']}, token=tokens['accessToken'], want=204)
    compose = command(args.env, prod=True)
    sql_probe = 'require "/app/vendor/autoload.php"; $d=new \\Shop\\infrastructure\\Database(getenv("DATABASE_URL")); if(!$d->one("SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()")["ssl"]){exit(1);} print "PHP PDO verify-full connection uses TLS\\n";'
    subprocess.run(compose + ['exec', '-T', 'yii2', 'php', '-r', sql_probe], cwd=ROOT, check=True)
    for service in ('postgres',):
        subprocess.run(compose + ['exec', '-T', service, 'sh', '-ec',
            'export PGPASSWORD="$POSTGRES_PASSWORD"; if psql "host=' + service + ' user=$POSTGRES_USER dbname=$POSTGRES_DB sslmode=disable" -c "SELECT 1"; then exit 1; fi'], cwd=ROOT, check=True)
    output = ROOT / 'deploy/evidence/tls-smoke.json'
    output.parent.mkdir(exist_ok=True)
    output.write_text(json.dumps({'domain': host, 'steps': steps, 'dbTLS': 'verified; business DB rejects plaintext'}, indent=2) + '\n')
    print('PASS disposable TLS stack: trusted single-domain HTTPS, local password/session/profile, operational routes hidden, PostgreSQL plaintext connections rejected')


if __name__ == '__main__':
    main()
