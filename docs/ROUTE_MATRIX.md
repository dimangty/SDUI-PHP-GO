# Route and contract matrix

Generated/checked by `python3 scripts/route_matrix.py --check`. Canonical `backend-yii2/contracts/routes.json`, OpenAPI and Yii routing agree on **54 operations = 15 public + 32 protected + 7 admin**. Public webhook additionally requires HMAC, timestamp, unique ID and configured source policy.

All rows: `backend-yii2/tests/api.php` asserts a successful HTTP response and the complete 54-operation set. Its policy loop rejects missing bearer on all 39 nonpublic operations and ordinary users on all 7 admin operations. `scripts/integration.py` repeats those access checks through the shared edge with spoof headers. This is route coverage, not exhaustive input/state parity.

Historical migration evidence: all54 operations had matching successful Go/Yii HTTP comparisons before the legacy backend was removed. The194 captured pairs and five disclosed corrections remain in `deploy/evidence/api-parity.json` and [API_PARITY_REPORT.md](API_PARITY_REPORT.md). The old comparison server/harness is retired; current API, security, concurrency and live BFF tests run against Yii2. See [LEGACY_REMOVAL.md](LEGACY_REMOVAL.md).

The final two columns name historical paired cases in `deploy/evidence/api-parity.json`; `--parity-evidence` validates the frozen evidence against the canonical contract without launching a legacy server. Negative examples are representative per group; every nonpublic row additionally names its exact access probe.

| # | Method | Exact path | Policy | PHP handler | Additional regression suites (`backend-yii2/tests/`) | Matching Go↔Yii2 success case | Negative/access parity evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | POST | `/api/v1/auth/register` | public | `Auth.register` | auth, signed_oidc, security, throttling | `auth.register` | `auth.wrong-password` (group) |
| 2 | POST | `/api/v1/auth/login` | public | `Auth.login` | auth, signed_oidc, security, throttling | `auth.login` | `auth.wrong-password` (group) |
| 3 | POST | `/api/v1/auth/refresh` | public | `Auth.refresh` | auth, signed_oidc, security, throttling | `auth.refresh` | `auth.wrong-password` (group) |
| 4 | POST | `/api/v1/auth/pin/login` | public | `Credential.loginPin` | integration, concurrency | `pin.login` | `pin.wrong` (group) |
| 5 | POST | `/api/v1/auth/biometric/challenge` | public | `Credential.challenge` | integration, concurrency | `bio.challenge` | `bio.replay` (group) |
| 6 | POST | `/api/v1/auth/biometric/login` | public | `Credential.loginBiometric` | integration, concurrency | `bio.login` | `bio.replay` (group) |
| 7 | GET | `/api/v1/categories` | public | `Catalog.categories` | search, integration | `catalog.categories` | `catalog.bad-sort` (group) |
| 8 | GET | `/api/v1/categories/{categoryID}` | public | `Catalog.category` | search, integration | `catalog.category` | `catalog.bad-sort` (group) |
| 9 | GET | `/api/v1/categories/{categoryID}/children` | public | `Catalog.children` | search, integration | `catalog.children` | `catalog.bad-sort` (group) |
| 10 | GET | `/api/v1/products` | public | `Catalog.products` | search, integration | `catalog.page` | `catalog.bad-sort` (group) |
| 11 | GET | `/api/v1/products/search` | public | `Catalog.products` | search, integration | `catalog.search` | `catalog.bad-sort` (group) |
| 12 | GET | `/api/v1/products/popular` | public | `Catalog.popular` | search, integration | `catalog.popular` | `catalog.bad-sort` (group) |
| 13 | GET | `/api/v1/products/{productID}` | public | `Catalog.product` | search, integration | `catalog.product` | `catalog.bad-sort` (group) |
| 14 | GET | `/api/v1/products/{productID}/reviews` | public | `Catalog.reviews` | search, integration | `reviews.page` | `reviews.other-owner` (group) |
| 15 | POST | `/api/v1/payments/webhook` | public | `Payment.webhook` | security, concurrency, adapters, deadlines | `payment.webhook` | `payment.webhook-replay` (group) |
| 16 | GET | `/api/v1/auth/session` | protected | `Credential.session` | integration, concurrency | `credentials.session` | `policy.missing.getSession`401; `pin.wrong` (group) |
| 17 | POST | `/api/v1/auth/logout` | protected | `Auth.logout` | auth, signed_oidc, security, throttling | `auth.logout-opaque` | `policy.missing.logout`401; `auth.wrong-password` (group) |
| 18 | POST | `/api/v1/auth/pin` | protected | `Credential.setPin` | integration, concurrency | `pin.set` | `policy.missing.createPin`401; `pin.wrong` (group) |
| 19 | PUT | `/api/v1/auth/pin` | protected | `Credential.changePin` | integration, concurrency | `pin.change` | `policy.missing.changePin`401; `pin.wrong` (group) |
| 20 | DELETE | `/api/v1/auth/pin` | protected | `Credential.deletePin` | integration, concurrency | `pin.delete-current` | `policy.missing.deleteCurrentPin`401; `pin.wrong` (group) |
| 21 | GET | `/api/v1/auth/pin/devices` | protected | `Credential.pinDevices` | integration, concurrency | `pin.devices` | `policy.missing.listPinDevices`401; `pin.wrong` (group) |
| 22 | DELETE | `/api/v1/auth/pin/devices/{deviceID}` | protected | `Credential.deletePin` | integration, concurrency | `pin.delete-device` | `policy.missing.deletePinDevice`401; `pin.wrong` (group) |
| 23 | POST | `/api/v1/auth/biometric` | protected | `Credential.enroll` | integration, concurrency | `bio.enroll` | `policy.missing.enrollBiometric`401; `bio.replay` (group) |
| 24 | DELETE | `/api/v1/auth/biometric` | protected | `Credential.deleteBiometric` | integration, concurrency | `bio.delete-current` | `policy.missing.deleteCurrentBiometric`401; `bio.replay` (group) |
| 25 | GET | `/api/v1/auth/biometric/devices` | protected | `Credential.biometricDevices` | integration, concurrency | `bio.devices` | `policy.missing.listBiometricDevices`401; `bio.replay` (group) |
| 26 | DELETE | `/api/v1/auth/biometric/devices/{deviceID}` | protected | `Credential.deleteBiometric` | integration, concurrency | `bio.delete-device` | `policy.missing.deleteBiometricDevice`401; `bio.replay` (group) |
| 27 | GET | `/api/v1/users/me` | protected | `Profile.user` | integration, address_race | `profile.initial` | `policy.missing.getCurrentUser`401; `profile.email-immutable` (group) |
| 28 | PUT | `/api/v1/users/me` | protected | `Profile.update` | integration, address_race | `profile.update-null` | `policy.missing.updateCurrentUser`401; `profile.email-immutable` (group) |
| 29 | PUT | `/api/v1/users/me/avatar` | protected | `Avatar.update` | security | `profile.avatar-null` | `policy.missing.updateCurrentUserAvatar`401; `profile.email-immutable` (group) |
| 30 | POST | `/api/v1/users/me/change-password` | protected | `Auth.changePassword` | auth, signed_oidc, security, throttling | `password.change` | `policy.missing.changePassword`401; `auth.wrong-password` (group) |
| 31 | GET | `/api/v1/cart` | protected | `Commerce.cart` | integration, concurrency, address_race | `cart.initial` | `policy.missing.getCart`401; `cart.key-conflict` (group) |
| 32 | POST | `/api/v1/cart/items` | protected | `Commerce.addCart` | integration, concurrency, address_race | `cart.add` | `policy.missing.addCartItem`401; `cart.key-conflict` (group) |
| 33 | PATCH | `/api/v1/cart/items/{productID}` | protected | `Commerce.updateCart` | integration, concurrency, address_race | `cart.patch` | `policy.missing.updateCartItem`401; `cart.key-conflict` (group) |
| 34 | DELETE | `/api/v1/cart/items/{productID}` | protected | `Commerce.removeCart` | integration, concurrency, address_race | `cart.remove` | `policy.missing.removeCartItem`401; `cart.key-conflict` (group) |
| 35 | DELETE | `/api/v1/cart` | protected | `Commerce.clearCart` | integration, concurrency, address_race | `cart.clear` | `policy.missing.clearCart`401; `cart.key-conflict` (group) |
| 36 | GET | `/api/v1/addresses` | protected | `Profile.addresses` | integration, address_race | `address.list` | `policy.missing.listAddresses`401; `address.other-owner` (group) |
| 37 | POST | `/api/v1/addresses` | protected | `Profile.createAddress` | integration, address_race | `address.create` | `policy.missing.createAddress`401; `address.other-owner` (group) |
| 38 | PUT | `/api/v1/addresses/{addressID}` | protected | `Profile.updateAddress` | integration, address_race | `address.update` | `policy.missing.updateAddress`401; `address.other-owner` (group) |
| 39 | DELETE | `/api/v1/addresses/{addressID}` | protected | `Profile.deleteAddress` | integration, address_race | `address.delete` | `policy.missing.deleteAddress`401; `address.other-owner` (group) |
| 40 | POST | `/api/v1/orders` | protected | `Commerce.createOrder` | integration, concurrency, address_race | `order.create` | `policy.missing.createOrder`401; `order.key-conflict` (group) |
| 41 | GET | `/api/v1/orders` | protected | `Commerce.orders` | integration, concurrency, address_race | `order.page` | `policy.missing.listOrders`401; `order.key-conflict` (group) |
| 42 | GET | `/api/v1/orders/{orderID}` | protected | `Commerce.order` | integration, concurrency, address_race | `order.paid` | `policy.missing.getOrder`401; `order.key-conflict` (group) |
| 43 | POST | `/api/v1/orders/{orderID}/cancel` | protected | `Payment.cancel` | security, concurrency, adapters, deadlines | `order.cancel` | `policy.missing.cancelOrder`401; `payment.webhook-replay` (group) |
| 44 | GET | `/api/v1/payments/{paymentID}` | protected | `Payment.payment` | security, concurrency, adapters, deadlines | `payment.pending` | `policy.missing.getPayment`401; `payment.webhook-replay` (group) |
| 45 | POST | `/api/v1/payments/{paymentID}/confirm` | protected | `Payment.confirm` | security, concurrency, adapters, deadlines | `payment.confirm` | `policy.missing.confirmPayment`401; `payment.webhook-replay` (group) |
| 46 | POST | `/api/v1/products/{productID}/reviews` | protected | `Catalog.createReview` | search, integration | `reviews.create` | `policy.missing.createReview`401; `reviews.other-owner` (group) |
| 47 | DELETE | `/api/v1/reviews/{reviewID}` | protected | `Catalog.deleteReview` | search, integration | `reviews.delete` | `policy.missing.deleteReview`401; `reviews.other-owner` (group) |
| 48 | POST | `/api/v1/orders/{orderID}/status` | admin | `Commerce.transition` | integration, concurrency, address_race | `order.transition-ASSEMBLING` | `policy.missing.transitionOrder`401; `policy.admin.transitionOrder`403; `order.key-conflict` (group) |
| 49 | POST | `/api/v1/payments/{paymentID}/refund` | admin | `Payment.refund` | security, concurrency, adapters, deadlines | `payment.refund` | `policy.missing.refundPayment`401; `policy.admin.refundPayment`403; `payment.webhook-replay` (group) |
| 50 | POST | `/api/v1/payments/{paymentID}/void` | admin | `Payment.void` | security, concurrency, adapters, deadlines | `payment.void` | `policy.missing.voidPayment`401; `policy.admin.voidPayment`403; `payment.webhook-replay` (group) |
| 51 | PATCH | `/api/v1/products/{productID}/stock` | admin | `Catalog.patchStock` | search, integration | `catalog.stock` | `policy.missing.patchProductStock`401; `policy.admin.patchProductStock`403; `catalog.bad-sort` (group) |
| 52 | POST | `/api/v1/admin/products` | admin | `Catalog.createProduct` | search, integration | `catalog.create` | `policy.missing.createProduct`401; `policy.admin.createProduct`403; `catalog.bad-sort` (group) |
| 53 | PUT | `/api/v1/admin/products/{productID}` | admin | `Catalog.updateProduct` | search, integration | `catalog.update` | `policy.missing.updateProduct`401; `policy.admin.updateProduct`403; `catalog.bad-sort` (group) |
| 54 | DELETE | `/api/v1/admin/products/{productID}` | admin | `Catalog.deleteProduct` | search, integration | `catalog.delete` | `policy.missing.deleteProduct`401; `policy.admin.deleteProduct`403; `catalog.bad-sort` (group) |

## Eight BFF screen routes

| GET route | Access | Coverage |
| --- | --- | --- |
| `/api/v1/screens/home` | public; supplied bearer verified | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |
| `/api/v1/screens/catalog` | public; supplied bearer verified | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |
| `/api/v1/screens/product/{id}` | public; supplied bearer verified | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |
| `/api/v1/screens/cart` | protected | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |
| `/api/v1/screens/orders` | protected | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |
| `/api/v1/screens/order/{id}` | protected | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |
| `/api/v1/screens/profile` | protected | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |
| `/api/v1/screens/devices` | protected | `TestLiveYiiEightScreensAndActions`: real HTTP + independent JSON Schema; builder/security/contract regressions |

## Six commands on POST /api/v1/sdui/actions

| Command | Access | Canonical mutation |
| --- | --- | --- |
| AddToCart | freshly authenticated; owner; confirmation for cancel/revoke | `POST /cart/items + Idempotency-Key` |
| RemoveFromCart | freshly authenticated; owner; confirmation for cancel/revoke | `DELETE /cart/items/{productID}` |
| UpdateQuantity | freshly authenticated; owner; confirmation for cancel/revoke | `PATCH /cart/items/{productID}` |
| CancelOrder | freshly authenticated; owner; confirmation for cancel/revoke | `POST /orders/{orderID}/cancel` |
| UpdateProfile | freshly authenticated; owner; confirmation for cancel/revoke | `PUT /users/me` |
| RevokeDevice | freshly authenticated; owner; confirmation for cancel/revoke | `DELETE /auth/{pin,biometric}/devices/{deviceID}` |

The same live test schema-validates command requests/acknowledgements and exercises all six. Checkout/payment/logout remain native canonical operations, exercised at HTTP boundaries here. Separate installed Android/iOS CARD journeys cover all eight screens: [mobile evidence](MOBILE_E2E_REPORT.md). See [SDUI contract](SDUI_CONTRACT.md) and [verification evidence](VERIFICATION_REPORT.md).
