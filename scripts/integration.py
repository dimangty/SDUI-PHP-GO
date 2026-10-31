#!/usr/bin/env python3
"""Real shared-edge CARD journey, route policies and concurrent checkout assertions.

Only run against a disposable development stack. No token/password/body is saved.
"""
from concurrent.futures import ThreadPoolExecutor
from decimal import Decimal
import argparse
import json
from pathlib import Path
import re
import socket
import threading
import time
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen
import uuid
from route_matrix import routes, ROOT


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--url', default='http://127.0.0.1:8080')
    parser.add_argument('--evidence', default='deploy/evidence/integration.json')
    parser.add_argument('--disposable', action='store_true', required=True)
    args = parser.parse_args()
    base = args.url.rstrip('/')
    assert urlsplit(base).scheme == 'http', 'This is a development/demo-payment test'
    steps = []

    def call(method, path, body=None, token='', headers=None, want=200, raw=None):
        h = {'Content-Type': 'application/json'} | (headers or {})
        if token:
            h['Authorization'] = 'Bearer ' + token
        data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
        req = Request(base + path, data=data, method=method, headers=h)
        try:
            response = urlopen(req, timeout=40)
        except HTTPError as error:
            response = error
        with response:
            content = response.read()
            assert response.status == want, f'{method} {path.split("?")[0]}: {response.status}, wanted {want}'
            if token and path.startswith('/api/v1/screens/'):
                assert response.headers['Cache-Control'] == 'no-store'
            steps.append({'method': method, 'path': path.split('?')[0], 'status': response.status,
                          'bytes': len(content)})
            return json.loads(content) if content and 'application/json' in response.headers.get('Content-Type', '') else content

    suffix = uuid.uuid4().hex
    email, password, device = 'task4-' + suffix + '@example.org', 'Task4!7395' + suffix, 'task4-' + suffix
    registered = call('POST', '/api/v1/auth/register', {'email': email, 'password': password, 'fullName': 'Task4 Buyer', 'phone': '+79991234567'}, want=201)
    logged = call('POST', '/api/v1/auth/login', {'email': email, 'password': password})
    assert registered['userId'] == logged['userId']
    password_token = logged['accessToken']
    call('POST', '/api/v1/auth/pin', {'deviceId': device, 'deviceName': 'Task4 integration', 'pin': '7395'}, password_token, want=201)
    session = call('POST', '/api/v1/auth/pin/login', {'deviceId': device, 'pin': '7395'})
    token = session['accessToken']
    call('GET', '/api/v1/screens/home?deviceId=' + device, token=token)
    call('GET', '/api/v1/screens/catalog', token=token)
    products = call('GET', '/api/v1/products?size=100&sort=price_asc')['items']
    product = next(p for p in products if p['stock'] >= 10 and p['isActive'])
    pid = product['id']
    call('GET', '/api/v1/screens/product/' + pid, token=token)
    for path in [product['imageUrls'][0] if product['imageUrls'] else '', '/icons/lamp.svg']:
        if path:
            call('GET', path)
    key = 'task4-cart-' + suffix
    add = {'command': 'AddToCart', 'input': {'productId': pid, 'quantity': 2}}
    call('POST', '/api/v1/sdui/actions', add, token, {'Idempotency-Key': key})
    call('POST', '/api/v1/sdui/actions', add, token, {'Idempotency-Key': key})
    assert call('GET', '/api/v1/cart', token=token)['items'][0]['quantity'] == 2
    call('GET', '/api/v1/screens/cart', token=token)
    address = call('POST', '/api/v1/addresses', {'city': 'Moscow', 'street': 'Task4', 'house': '4', 'apartment': '1'}, token, want=201)
    checkout = {'deliveryAddressId': address['id'], 'paymentMethod': 'CARD', 'comment': 'Task4 HTTP native boundary'}

    def simultaneous(keys):
        barrier = threading.Barrier(len(keys))
        def submit(k):
            barrier.wait()
            return call('POST', '/api/v1/orders', checkout, token, {'Idempotency-Key': k}, want=201)
        with ThreadPoolExecutor(max_workers=len(keys)) as pool:
            return list(pool.map(submit, keys))

    orders = simultaneous(['task4-order-' + suffix] * 2)
    order = orders[0]
    assert orders[0] == orders[1], 'Concurrent same-key checkout must replay identical snapshot'
    assert order['totalAmount'] == f"{Decimal(product['discountPrice'] or product['price']) * 2:.2f}"
    assert call('GET', '/api/v1/orders', token=token)['totalItems'] == 1
    assert call('GET', '/api/v1/products/' + pid)['stock'] == product['stock'] - 2
    assert call('GET', '/api/v1/cart', token=token)['items'] == []
    call('POST', '/api/v1/orders', checkout | {'comment': 'changed fingerprint'}, token,
         {'Idempotency-Key': 'task4-order-' + suffix}, want=409)
    confirmation = call('POST', '/api/v1/payments/' + order['paymentId'] + '/confirm', token=token)
    assert confirmation['status'] == 'AUTHORIZED' and confirmation['paymentUrl'].startswith('/demo/payments/')
    call('GET', confirmation['paymentUrl'])
    call('POST', confirmation['paymentUrl'])
    assert call('GET', '/api/v1/payments/' + order['paymentId'], token=token)['status'] == 'CAPTURED'
    assert call('GET', '/api/v1/orders/' + order['id'], token=token)['status'] == 'PAID'
    for path in ('orders', 'order/' + order['id'], 'profile', 'devices'):
        call('GET', '/api/v1/screens/' + path, token=token)

    # Different keys on one cart: one checkout, one CART_EMPTY, no partial reservation.
    call('POST', '/api/v1/sdui/actions', add, token, {'Idempotency-Key': 'second-' + suffix})
    barrier = threading.Barrier(2)
    def competing(index):
        barrier.wait()
        h = {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Idempotency-Key': f'compete-{index}-{suffix}'}
        req = Request(base + '/api/v1/orders', data=json.dumps(checkout).encode(), headers=h, method='POST')
        try:
            res = urlopen(req, timeout=30)
        except HTTPError as error:
            res = error
        with res:
            return res.status, json.load(res)
    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(competing, [1, 2]))
    assert sorted(s for s, _ in results) == [201, 400], results
    assert next(b for s, b in results if s == 400)['error']['code'] == 'CART_IS_EMPTY', results
    winner = next(b for s, b in results if s == 201)
    assert call('GET', '/api/v1/orders', token=token)['totalItems'] == 2
    assert call('GET', '/api/v1/products/' + pid)['stock'] == product['stock'] - 4
    call('POST', '/api/v1/orders/' + winner['id'] + '/cancel', token=token)
    assert call('GET', '/api/v1/products/' + pid)['stock'] == product['stock'] - 2

    spoof = {'X-User-Id': logged['userId'], 'X-Role': 'admin', 'X-Is-Admin': 'true',
             'X-Forwarded-For': '203.0.113.77', 'X-Real-IP': '203.0.113.77',
             'Forwarded': 'for=203.0.113.77;proto=https', 'X-Forwarded-Host': 'evil.invalid'}
    _, manifest = routes()
    checked = 0
    for method, path, _, _, policy in manifest:
        if policy == 'public':
            continue
        path = '/api/v1' + re.sub(r'\{[^}]+\}', str(uuid.uuid4()), path)
        call(method, path, {}, headers=spoof, want=401)
        checked += 1
        if policy == 'admin':
            call(method, path, {}, token, spoof, want=403)
    for path in ('cart', 'profile', 'devices', 'orders'):
        call('GET', '/api/v1/screens/' + path, headers=spoof, want=401)
    call('POST', '/api/v1/sdui/actions', add, headers=spoof, want=401)
    call('POST', '/api/v1/sdui/actions', {'command': 'Checkout', 'input': checkout}, token, want=400)
    call('POST', '/api/v1/sdui/actions', token=token, raw=b'x' * 32769, want=413)
    call('POST', '/api/v1/auth/login', raw=b'x' * 1048577, want=413)
    call('POST', '/api/v1/auth/login', raw=b'x' * (6 * 1048576 + 1), want=413)
    call('GET', '/swagger/openapi.yaml')
    contract = (ROOT / 'backend-yii2/contracts/openapi.yaml').read_bytes()
    assert call('GET', '/swagger/openapi.yaml') == contract

    # The edge waits only five seconds for incomplete headers / ten for a body.
    parsed = urlsplit(base)
    for wire, delay in [(b'GET /api/v1/categories HTTP/1.1\r\n', 6),
                        (b'POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\nContent-Length: 100\r\n\r\nx', 11)]:
        with socket.create_connection((parsed.hostname, parsed.port or 80), timeout=20) as sock:
            sock.sendall(wire)
            time.sleep(delay)
            response = sock.recv(4096)
            assert not response or b'408' in response, response[:80]
    call('POST', '/api/v1/auth/logout', {'refreshToken': session['refreshToken'], 'deviceId': device}, token, want=204)
    call('GET', '/api/v1/screens/profile', token=token, want=401)
    report = {'executedAt': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'origin': base,
              'userId': logged['userId'], 'productId': pid, 'paidOrderId': order['id'],
              'canonicalUnauthenticatedPolicies': checked, 'adminPolicies': 7,
              'concurrentSameKey': 'identical snapshot, one order',
              'concurrentDifferentKeys': '201 + 400 CART_IS_EMPTY, one reservation',
              'headerAndBodyTimeouts': 'passed', 'steps': steps}
    output = ROOT / args.evidence
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2) + '\n')
    print(f'PASS shared edge CARD journey; eight screens; replay + both checkout races; {checked}+7 policies; spoof/body/timeouts; {len(steps)} HTTP assertions')


if __name__ == '__main__':
    main()
