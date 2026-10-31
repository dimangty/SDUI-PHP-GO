#!/usr/bin/env python3
"""Verify the exact canonical registry and publish its auditable coverage map."""
from pathlib import Path
import json
import re
import sys

ROOT = Path(__file__).resolve().parents[1]

# Named historical migration cases. Their captured evidence remains auditable
# without keeping or launching the retired business implementation.
PARITY = dict(line.split() for line in '''
registerUser auth.register
login auth.login
refreshSession auth.refresh
loginWithPin pin.login
createBiometricChallenge bio.challenge
loginWithBiometric bio.login
listCategories catalog.categories
getCategory catalog.category
listCategoryChildren catalog.children
listProducts catalog.page
searchProducts catalog.search
listPopularProducts catalog.popular
getProduct catalog.product
listProductReviews reviews.page
webhook payment.webhook
getSession credentials.session
logout auth.logout-opaque
createPin pin.set
changePin pin.change
deleteCurrentPin pin.delete-current
listPinDevices pin.devices
deletePinDevice pin.delete-device
enrollBiometric bio.enroll
deleteCurrentBiometric bio.delete-current
listBiometricDevices bio.devices
deleteBiometricDevice bio.delete-device
getCurrentUser profile.initial
updateCurrentUser profile.update-null
updateCurrentUserAvatar profile.avatar-null
changePassword password.change
getCart cart.initial
addCartItem cart.add
updateCartItem cart.patch
removeCartItem cart.remove
clearCart cart.clear
listAddresses address.list
createAddress address.create
updateAddress address.update
deleteAddress address.delete
createOrder order.create
listOrders order.page
getOrder order.paid
cancelOrder order.cancel
getPayment payment.pending
confirmPayment payment.confirm
createReview reviews.create
deleteReview reviews.delete
transitionOrder order.transition-ASSEMBLING
refundPayment payment.refund
voidPayment payment.void
patchProductStock catalog.stock
createProduct catalog.create
updateProduct catalog.update
deleteProduct catalog.delete
'''.strip().splitlines())


def routes():
    contract = json.loads((ROOT / 'backend-yii2/contracts/routes.json').read_text())
    assert contract['schemaVersion'] == 1 and contract['prefix'] == '/api/v1'
    canonical = [(r['name'], r['method'], r['path'], r['access']) for r in contract['routes']]
    php = re.findall(r"\['(GET|POST|PUT|PATCH|DELETE) ([^']+)','([^']+)','([^']+)','([^']+)'",
                     (ROOT / 'backend-yii2/config/routes.php').read_text())
    normalize = lambda path: re.sub(r'\{[^}]+\}', '{}', path)
    expected = {(method, normalize(path)): access for _, method, path, access in canonical}
    actual = {(method, normalize(path)): policy for method, path, _, _, policy in php}
    assert len(canonical) == len(php) == len(expected) == len(actual) == 54 and expected == actual
    assert [sum(p == kind for p in actual.values()) for kind in ('public', 'protected', 'admin')] == [15, 32, 7]
    assert set(PARITY) == {name for name, *_ in canonical}
    spec_routes = set()
    path = None
    for line in (ROOT / 'backend-yii2/contracts/openapi.yaml').read_text().splitlines():
        item = re.match(r'^  (/[^:]+):\s*$', line)
        method = re.match(r'^    (get|post|put|patch|delete):', line)
        if line and not line[0].isspace():
            path = None
        if item:
            path = item[1]
        elif method and path:
            spec_routes.add((method[1].upper(), normalize(path)))
    assert spec_routes == set(expected), 'OpenAPI/route contract drift'
    return canonical, php


def document():
    go, php = routes()
    out = ['# Route and contract matrix', '',
           'Generated/checked by `python3 scripts/route_matrix.py --check`. Canonical `backend-yii2/contracts/routes.json`, OpenAPI and Yii routing agree on **54 operations = 15 public + 32 protected + 7 admin**. Public webhook additionally requires HMAC, timestamp, unique ID and configured source policy.', '',
           'All rows: `backend-yii2/tests/api.php` asserts a successful HTTP response and the complete 54-operation set. Its policy loop rejects missing bearer on all 39 nonpublic operations and ordinary users on all 7 admin operations. `scripts/integration.py` repeats those access checks through the shared edge with spoof headers. This is route coverage, not exhaustive input/state parity.', '',
           'Historical migration evidence: all54 operations had matching successful Go/Yii HTTP comparisons before the legacy backend was removed. The194 captured pairs and five disclosed corrections remain in `deploy/evidence/api-parity.json` and [API_PARITY_REPORT.md](API_PARITY_REPORT.md). The old comparison server/harness is retired; current API, security, concurrency and live BFF tests run against Yii2. See [LEGACY_REMOVAL.md](LEGACY_REMOVAL.md).', '',
           'The final two columns name historical paired cases in `deploy/evidence/api-parity.json`; `--parity-evidence` validates the frozen evidence against the canonical contract without launching a legacy server. Negative examples are representative per group; every nonpublic row additionally names its exact access probe.', '',
           '| # | Method | Exact path | Policy | PHP handler | Additional regression suites (`backend-yii2/tests/`) | Matching Go↔Yii2 success case | Negative/access parity evidence |',
           '| --- | --- | --- | --- | --- | --- | --- | --- |']
    for i, ((name, method, path, access), row) in enumerate(zip(go, php), 1):
        verb, ppath, service, operation, policy = row
        extra = {'Auth': 'auth, local_auth, security, throttling', 'Credential': 'integration, concurrency',
                 'Catalog': 'search, integration', 'Profile': 'integration, address_race', 'Avatar': 'security',
                 'Commerce': 'integration, concurrency, address_race', 'Payment': 'security, concurrency, adapters, deadlines'}[service]
        negative = {'Auth': 'auth.wrong-password', 'Credential': 'bio.replay' if 'Biometric' in name or 'biometric' in name else 'pin.wrong',
                    'Catalog': 'reviews.other-owner' if 'Review' in name else 'catalog.bad-sort',
                    'Profile': 'address.other-owner' if 'Address' in name else 'profile.email-immutable',
                    'Avatar': 'profile.email-immutable', 'Commerce': 'order.key-conflict' if 'Order' in name else 'cart.key-conflict',
                    'Payment': 'payment.webhook-replay'}[service]
        probes = [f'`{negative}` (group)']
        if policy != 'public':
            probes.insert(0, f'`policy.missing.{name}`401')
        if policy == 'admin':
            probes.insert(1, f'`policy.admin.{name}`403')
        out.append(f'| {i} | {verb} | `/api/v1{path}` | {policy} | `{service}.{operation}` | {extra} | `{PARITY[name]}` | {"; ".join(probes)} |')
    out += ['', '## Eight BFF screen routes', '', '| GET route | Access | Coverage |', '| --- | --- | --- |']
    for path in ('home', 'catalog', 'product/{id}', 'cart', 'orders', 'order/{id}', 'profile', 'devices'):
        policy = 'public; supplied bearer verified' if path.split('/')[0] in ('home', 'catalog', 'product') else 'protected'
        out.append(f'| `/api/v1/screens/{path}` | {policy} | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |')
    out += ['', '## Six commands on POST /api/v1/sdui/actions', '', '| Command | Access | Canonical mutation |', '| --- | --- | --- |']
    for command, operation in [('AddToCart', 'POST /cart/items + Idempotency-Key'), ('RemoveFromCart', 'DELETE /cart/items/{productID}'), ('UpdateQuantity', 'PATCH /cart/items/{productID}'), ('CancelOrder', 'POST /orders/{orderID}/cancel'), ('UpdateProfile', 'PUT /users/me'), ('RevokeDevice', 'DELETE /auth/{pin,biometric}/devices/{deviceID}')]:
        out.append(f'| {command} | freshly authenticated; owner; confirmation for cancel/revoke | `{operation}` |')
    out += ['', 'The same live test schema-validates command requests/acknowledgements and exercises all six. Checkout/payment/logout remain native canonical operations, exercised at HTTP boundaries here. Separate installed Android/iOS CARD journeys cover all eight screens: [mobile evidence](MOBILE_E2E_REPORT.md). See [SDUI contract](SDUI_CONTRACT.md) and [verification evidence](VERIFICATION_REPORT.md).', '']
    return '\n'.join(out)


def check_parity_evidence(path):
    evidence = json.loads(Path(path).read_text())
    go, _ = routes()
    expected = {method.upper() + ' ' + route for _, method, route, _ in go}
    assert evidence['successfulOperations'] == evidence['matchingSuccessfulOperations'] == 54
    assert set(evidence['operations']) == expected and evidence['unexpected'] == []
    for name, method, route, access in go:
        case = evidence['cases'][PARITY[name]]
        assert case['operation'] == method.upper() + ' ' + route
        assert case['result'] == 'MATCH' and 200 <= case['goStatus'] < 300 and case['goStatus'] == case['yiiStatus']
        if access != 'public':
            assert evidence['cases']['policy.missing.' + name]['result'] == 'MATCH'
            assert evidence['cases']['policy.missing.' + name]['goStatus'] == 401
        if access == 'admin':
            assert evidence['cases']['policy.admin.' + name]['result'] == 'MATCH'
            assert evidence['cases']['policy.admin.' + name]['goStatus'] == 403
    print('54 named matching live contracts, 39 missing-bearer and 7 admin denials: evidence matches matrix')


if __name__ == '__main__':
    if '--parity-evidence' in sys.argv:
        check_parity_evidence(sys.argv[sys.argv.index('--parity-evidence') + 1])
    text = document()
    if '--check' in sys.argv:
        assert (ROOT / 'docs/ROUTE_MATRIX.md').read_text() == text, 'Route matrix drift'
        print('54 exact operations/policies; eight screens and six commands: matrix matches source')
    else:
        print(text, end='')
