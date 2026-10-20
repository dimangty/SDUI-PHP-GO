# ShopKMP SDUI v1 — shared BFF/CMP contract

Status: implemented by Task2 in `backend-sdui/`. The schemas and fixtures below
are the input to Task3; consume their wire names unchanged. Runtime code uses
Go 1.26 stdlib only. Yii owns business rules, identity and persistence.

## Published artifacts

* [`screen.schema.json`](../backend-sdui/contracts/screen.schema.json): JSON
  Schema Draft 2020-12, including every node, prop, destination and action.
* [`action-request.schema.json`](../backend-sdui/contracts/action-request.schema.json)
  and [`action-response.schema.json`](../backend-sdui/contracts/action-response.schema.json).
* [`contracts/fixtures/`](../backend-sdui/contracts/fixtures/): `home.json`,
  `catalog.json`, `product.json`, `cart.json`, `orders.json`, `order.json`,
  `profile.json`, `devices.json`, and `primitives.json`. Four additional handoff
  examples are `cart-continuation.json`, `home-continuation.json`,
  `catalog-continuation.json`, and `product-long-description.json`. These **13**
  fixtures cover all 26 types, independent collection paging, a pinned selected
  category, and an exact 10,000-code-point description split into full-detail blocks.
* [`tests/evidence/`](../backend-sdui/tests/evidence/): separately captured live
  HTTP documents and SQL-backed business DTOs; these random-account captures
  are evidence, not the deterministic common fixtures.

Schema `$id` values identify local resources; they are not fetch instructions.
Load the three schema files locally when validating. Independent validation
uses test-only `github.com/santhosh-tekuri/jsonschema/v6`.

## HTTP interface and authentication

All successful screen responses are HTTP 200 JSON. Routes are exact:

| GET route | Accepted query | Yii data used after the authentication gate |
| --- | --- | --- |
| `/api/v1/screens/home` | `deviceId`, `categoryPage`, `categorySize` | categories, popular products, and authenticated session concurrently |
| `/api/v1/screens/catalog` | `q`, `categoryId`, `sort`, `priceMin`, `priceMax`, `page`, `size`, `categoryPage`, `categorySize` | products or product search, categories |
| `/api/v1/screens/product/{id}` | none | product |
| `/api/v1/screens/cart` | `page`, `size` | owned full cart, bounded visible row window; addresses when nonempty |
| `/api/v1/screens/orders` | `status`, `page`, `size` | owned order page |
| `/api/v1/screens/order/{id}` | none | owned order, immutable item/address/price snapshots |
| `/api/v1/screens/profile` | none | authenticated profile and owned addresses |
| `/api/v1/screens/devices` | none | current user's PIN and biometric device metadata |

Product/order/category IDs are canonical UUID strings. Unknown or duplicate
query parameters, malformed IDs, invalid filters and unsupported methods fail.
There is no arbitrary path, method, URL, user or role parameter.

No bearer: home/catalog/product are public, with `authenticated:false`, no
account ID and no protected Yii calls. Product purchase offers `Login`.
Other screens and all commands require a bearer. A supplied bearer, even on
public screens, is authenticated first through **`GET /api/v1/users/me`**;
invalid credentials are never silently treated as anonymous. Yii validates JWT
or opaque application access tokens. The BFF does not parse token claims as
identity, issue tokens, refresh tokens or trust identity/role/cookie headers.

Personalized home requires the installation's existing stable `deviceId`
(`[A-Za-z0-9._-]{1,255}`, excluding `.` and `..`), used only for Yii's `/auth/session` query. The native
adapter supplies it when resolving a home destination/refresh; it is not a user
ID. Do not invent a shared installation ID.

Only request-scoped `Authorization: Bearer …` and sanitized `X-Request-Id`
are propagated, plus BFF-generated JSON headers and the supported idempotency
header. Invalid request IDs are replaced with random hex, not copied partially.
Client `X-User-Id`, `X-Role`, forwarding, admin-bootstrap, cookie and arbitrary
headers are never used as authority or forwarded. Unknown identity/role fields
in command JSON are rejected. Yii reauthenticates each protected API operation.
The BFF also checks owner IDs in returned carts, addresses, sessions and orders
against the trusted `/users/me` result before publishing data.

Every personalized response, command response and error has
`Cache-Control: no-store`. Anonymous documents use
`public, max-age=0, must-revalidate`; the BFF maintains no cross-request cache.
Operational routes exist only on the separate internal listener.

### Catalog and order queries

* Product/order pagination is zero-based: default `page=0`, `size=20`, size 1–100. BFF page
  ceiling is 1,000,000. Out-of-range business pages may return an empty list.
* `q`: valid UTF-8, at most **200 UTF-8 encoded bytes**, matching Yii. Nonempty uses `/products/search` and
  OpenSearch relevance. `sort` must then be absent/empty. This is also enforced
  by the local form adapter: clear sort when submitting a nonempty search.
* Catalog sorts: `price_asc`, `price_desc`, `rating`, `new`; absent means Yii
  default. Filters: category UUID, canonical decimal-string `priceMin` and
  `priceMax`, with min ≤ max. Search window `(page+1)*size` ≤ 10,000.
* Order statuses: `CREATED`, `PAID`, `ASSEMBLING`, `DELIVERING`, `COMPLETED`,
  `CANCELLED`; absent/empty means all.
* Category navigation resets page to 0 and retains the other current filters.
  Pagination actions preserve the complete current query. Form submission resets
  page to 0. Refresh/retry preserves the fetched query.

### Independent bounded collection paging

Cart rows and category options are presentation collections, separate from
product/order business pagination. Their source APIs return full collections;
the BFF reads a bounded complete response before selecting a visible window.
It never silently truncates an upstream response or omits overflow actions.

| Collection | HTTP query | Default | Allowed size | State metadata | Destination parameter |
| --- | --- | --- | --- | --- | --- |
| Cart rows | `/cart?page=N&size=S` | page0, size50 | 1–100 | `state.collections.cart` | `cart:{page,size}` |
| Categories on home/catalog | `categoryPage=N&categorySize=S` | page0, size50 | 1–50 | `state.collections.categories` | `categories:{page,size}` |

Each metadata value contains `page`, `size`, `totalPages`, `totalItems`. Counts
describe **all** rows/options returned by Yii; the visible component window has
at most its size. Cart `totalItems` counts distinct rows, while `cartItemsCount`
sums quantities across the **entire** cart. `cart.total` and native checkout also
refer to the entire cart, never just the visible page. Every nonempty cart page
retains unit quantity/removal actions, the full total, and native checkout form.

The BFF emits a visible range/count label and finite previous/next Navigate
actions named `collection.cart.previous/next` or
`collection.categories.previous/next`. Fetching consecutive pages exposes every
row/option. Cart rows sort by product UUID; categories retain canonical Yii order.
Collection pages beyond the last page normalize to the last valid page (or page0
for an empty collection), so removing the last row on a last page does not strand
the host. Adopt the effective page in returned metadata for subsequent loads.
These are fresh snapshots, not snapshot-isolated cursors across concurrent writes.

On catalog, category paging preserves the complete product query/page; product
paging preserves the category window. Category selection resets only product page
to0 and retains the category window. The category form selector contains the
visible category window, “all categories”, and the current selected category
pinned if outside that window; its filter value never silently disappears.
The CategoryList and category pager provide explicit access to all remaining
options. Home category navigation carries the category window into catalog.

Examples of finite destinations (not URL instructions):

```json
{"screen":"catalog","catalog":{"page":2,"size":100},"categories":{"page":3,"size":50}}
{"screen":"home","categories":{"page":3,"size":50}}
{"screen":"cart","cart":{"page":1,"size":100}}
```

Only home/catalog allow `categories`; only cart allows `cart`. Omission means the
default collection page. The native home adapter still injects its stable deviceId.
The client builds query parameters from these finite fields using its existing
GET facade. These parameters are never forwarded to Yii's cart/categories APIs.

Fixed disjoint caps reserve complete screen controls: up to100 cart rows ×3
actions +16 reserved =316, or100 products +50 categories +16 reserved =166,
below500. Bounded description previews and category windows also control combined
JSON size. The complete encoded document is still checked against **all** hard
limits; the BFF does not raise them or return an unannounced shortened list.

## Envelope, bounds and revision

```json
{
  "schemaVersion": 1,
  "screenId": "catalog",
  "revision": "<64 lowercase hex characters>",
  "components": [{"id":"screen.content","type":"LazyColumn","props":{"spacing":"m","padding":"m","align":"Start"},"children":[]}],
  "actions": [],
  "state": {"authenticated":false,"fields":{},"cartItemsCount":0},
  "metadata": {"title":"Каталог","locale":"ru-RU","partial":false}
}
```

`screenId` is exactly one of the eight route names (product/order IDs are in
destinations, not appended to screenId). `components` and `actions` are arrays,
including when empty. Every component has `id`, `type`, `props`; only container
types can have optional `children`. Nullable media, discount and rating fields
are explicit `null`; `children` and other optional fields may be absent.

* Maximum complete document: **1,048,576 UTF-8 bytes**.
* Maximum total component nodes: **500**; maximum nesting depth **16**, root at 1.
* Maximum actions: **500**; CategoryList visible options ≤**50**, field options ≤500.
* Each string ≤**8192 Unicode code points**; tighter field/query/ID limits apply.
* Node and action IDs: `[A-Za-z0-9][A-Za-z0-9._:-]{0,127}`, unique within each
  namespace. Every action reference must resolve. Every controlled field must
  have an entry in `state.fields`; respect each field's `maxLength`.
* Server validation and CMP must enforce global counts/depth/bytes, uniqueness,
  action references and field invariants in addition to JSON Schema. Recursive
  JSON Schema alone cannot express every global tree constraint.

IDs derive from screen roles and resource UUIDs, not array positions. Device
view IDs use the first 24 hex characters of SHA-256(`device.{kind}.{deviceId}`)
after `device.` so a 255-character installation ID remains a bounded stable ID.
Product image IDs are `product.image.` plus the first32 hex characters of
SHA-256(the safe relative source string). Duplicate sources render once;
inserting or reordering media does not transfer image identity to another source.

`revision` is SHA-256 of the compact serialized complete envelope with revision
set to `""`, before inserting the resulting hex string. Go uses deterministic
struct field order and sorted map keys; no time, token or correlation ID is
included. It changes with renderable content, state, account, actions or query.
Treat it as an opaque content revision, not an authorization token or a generic
JSON canonicalization algorithm. Reuse same-revision rendering only within the
same authenticated account and destination.

Money is always a **string** matching
`^(0|[1-9][0-9]{0,9})\.[0-9]{2}$`, e.g. `"50.01"`, `"100.02"`.
Currency is `RUB`. Never decode money via Float/Double. Use existing money
formatting or integer kopecks. Rating is a nullable number 1–5 and is not money.

### Controlled state

`state.fields` is a string→string map with only these keys:

* `catalog.q`, `catalog.categoryId`, `catalog.sort`, `catalog.priceMin`,
  `catalog.priceMax`, `catalog.page`, `catalog.size`;
* `orders.status`, `orders.page`, `orders.size`;
* `product.quantity`;
* `checkout.city`, `checkout.street`, `checkout.house`, `checkout.apartment`,
  `checkout.paymentMethod`;
* `profile.fullName`, `profile.phone`.

Input state is local after decoding. `UpdateField` changes exactly its field;
it never sends a request. Field props reference the state key instead of
duplicating its value. `QuantityStepper.value` is the initial numeric snapshot;
its controlled value subsequently comes from `state.fields`. Clear form state
on account changes/logout. Do not carry product quantity to another product.
`catalog.q` fields also publish `maxUtf8Bytes:200`. Enforce both code-point
`maxLength` and UTF-8 byte length before SubmitForm; in Kotlin use
`value.encodeToByteArray().size <= 200`, not String.length as a byte count.
Reject overlong input without cutting through a UTF-8 sequence. JSON Schema's
`maxLength` counts code points; its `x-maxUtf8Bytes` query annotation requires this
additional runtime rule. Input `Phone` uses the fixed canonical
`^\+[1-9][0-9]{7,14}$` rule (mandatory +, 8–15 digits, nonzero first digit).

`state.pagination`, when present, has integer `page`, `size`, `totalPages`,
`totalItems`. `cartItemsCount` sums quantities, not distinct product rows.
`metadata.accountId` is present only after authentication; it is a client state
partition marker, never an identity assertion accepted by the server.
`state.collections` is optional and has only `categories` and/or `cart` as
described above. It is independent of `state.pagination` for product/order data.
When a home categories request fails, no guessed collection totals are emitted;
the Error/Retry section replaces that collection.

## Finite component registry and UIKit mapping

Names are case-sensitive and fixed. Props are fully specified in JSON Schema;
this table supplies rendering semantics and the existing UIKit binding.

| Wire type | Props / local rendering |
| --- | --- |
| `Text` | `text`, finite `style`; `EpsText(EpsTextState)` |
| `Image` | `source`, `description`, `aspect`; `EpsImage`/`EpsImagePlaceholder` |
| `Icon` | finite `name`, accessibility `description`; local icon resource registry |
| `Column`, `Row`, `Box` | finite `spacing`, `padding`, `align`; Compose layout wrappers |
| `LazyColumn`, `LazyRow` | same layout props, stable keyed children; Compose lazy wrappers |
| `Button` | `label`, `actionId`, `style`, `enabled`; `EpsButton`, callbacks constructed locally |
| `TextField` | controlled field props; `EpsTextField` |
| `SearchField` | same field props; `EpsSearchInput` or `EpsTextField` with local search submit |
| `ProductCard` | `productId`, `name`, bounded `description`, `descriptionTruncated`, `price`, `discountPrice`, `image`, `stock`, `rating`, `reviewsCount`, `openAction`, optional `addAction`; `EpsProductCard`/`EpsProductListItem` |
| `ProductGrid` | `columns:2`, ProductCard children; two-card rows/locally bounded grid |
| `CategoryList` | `items:[{id,label,image,actionId,selected}]`; `EpsChip` row; optional icon may fall back to label |
| `Price` | `amount`, `currency`; `EpsText` with `EpsTextStyle.Price` |
| `QuantityStepper` | `fieldId`, initial `value`, `min`, `max`, `actionId`, `enabled`; `EpsQuantityStepper` |
| `CartItem` | `productId`, `name`, `quantity`, unit `price`, `image`, `available`, `stock`, `openAction`, `quantityAction`, `removeAction`; `EpsCartItem` plus local remove control |
| `OrderCard` | `orderId`, `number`, `status`, `total`, `deliveryAddress`, `paymentMethod`, `createdAt`, `openAction`; `EpsOrderRow` |
| `ProfileHeader` | `fullName`, `email`, `phone`, nullable `avatar`; `EpsProfileHeader` with locally derived initials; phone in `EpsProfileField` |
| `Empty` | `title`, `message`, optional `retryAction`; `EpsEmptyState`/`EpsNoData` |
| `Loading` | same props; `EpsLoader` and accessible label |
| `Error` | same props; `EpsErrorMessage`/`EpsFullscreenError` plus finite retry button |
| `Spacer` | finite `size`; `EpsSpacer` |
| `Divider` | empty props; `EpsDivider` |
| `Form` | `formId`, `title`, `submitAction`, field/button children; local form container |
| `FormField` | controlled field props, `Text`/`Number`/`Phone`/`Select`; `EpsTextField` or finite `EpsButton`/`EpsChip` option selector |

ProductCard description is a **preview of at most256 Unicode code points**.
If the source exceeds256, it contains the first255 code points plus `…`, with
`descriptionTruncated:true`; otherwise it is exact with `false`. OpenProduct
retrieves the full detail document. Full descriptions (canonical maximum10,000
code points) are preserved exactly in traversal-ordered Text nodes named
`product.description.{codePointOffset}`, chunks of at most4096 code points.
Render those blocks in order; do not append a second ellipsis or use a card
preview as the full description. The detail `product.rating` Text includes the
actual one-decimal rating and review count; null rating displays “Нет оценок”,
not a fabricated zero. Prices remain decimal strings throughout.

Text styles are actual `EpsTextStyle` names: `Body`, `Secondary`, `Title`,
`SectionTitle`, `ScreenTitle`, `HeroTitle`, `Price`, `Error`, `Avatar`.
Security-specific `PinDot` is not remotely selected. Button styles are actual
`EpsButtonType`: `Primary`, `Secondary`, `Text`.

Spacing/padding tokens: `none=0`, `xs=4.dp`, `s=8.dp`, `m=16.dp`, `l=24.dp`,
`xl=32.dp`; Spacer excludes `none`. Alignment: `Start`, `Center`, `End`.
Image aspects: `Product` (full-width 220.dp product image), `Thumbnail`
(local 64–96.dp product/card slot), `Avatar` (72.dp). Icon names: `Search`,
`Cart`, `Profile`, `Back`, `Check`, `Device`, `Warning`. Bind these to local
resources; never resolve a symbol/class name or code from JSON.

Styles do not accept raw colors, fonts, dimensions, modifiers or CSS. UIKit
state/callback/Compose types are constructed locally. Avoid unbounded nested
vertical scrolling: ProductGrid inside a screen LazyColumn is non-scrolling
two-card rows; only the outer vertical list owns vertical scrolling. LazyRow
may own horizontal scrolling. Box overlays/bounds its children locally.

Only same-origin relative media paths under `/images/products/`, `/icons/`,
`/uploads/users/` are emitted. Allowed characters are ASCII letters/digits,
slash, underscore, dot and hyphen; no `..`, `//`, query or fragment. External
upstream media values become a placeholder/null. Media uses the client's
configured business origin. Public product/category images require no bearer;
uploaded avatars require the existing authenticated media adapter, and never
send a bearer to an external origin. Existing EpsProfileHeader can use initials
when avatar loading is unavailable. Media paths are not action URLs.

For CartItem, use `quantityAction` (`UpdateQuantity`) with the newly selected
absolute quantity 0–1,000,000, not an increment delta. Zero removes the row.
Use `min(stock,1_000_000)` as the increase ceiling; when current quantity is
above stock allow reduction/removal and show availability. Yii revalidates stock.
Order item/detail Price values are unit-price snapshots; the final total comes
from Yii, never a floating-point client recomputation.

Unknown optional future node types: skip their subtree with a safe local
fallback. Unknown actions: disable the associated control; do not guess.
Unsupported `schemaVersion`, invalid bounds/references or a malformed envelope:
show a local screen-level error/retry. Do not partially execute invalid actions.

## Finite action semantics

Actions are a list keyed by unique `id`; components refer to IDs. There is no
inline executable object, request method/path, script, URL navigation or remote
reflection. Only these actions exist in v1:

| Action type | Parameters | CMP behavior |
| --- | --- | --- |
| `Navigate` | `destination` | Resolve finite screen enum to native SDUI destination |
| `Refresh`, `Retry` | `destination` | Cancel superseded load, fetch same finite destination/query |
| `OpenProduct` | `productId` | Open `product/{uuid}` via local route builder |
| `OpenOrder` | `orderId` | Open `order/{uuid}` via local route builder |
| `UpdateField` | `fieldId` | Local controlled state only |
| `SubmitForm` | `formId:catalog\|orders\|profile` | Local form adapter described below |
| `AddToCart` | `productId`, optional `quantityField:product.quantity` | POST finite AddToCart command; default quantity 1 |
| `RemoveFromCart` | `productId` | POST finite RemoveFromCart command |
| `UpdateQuantity` | `productId` | Read newly selected absolute quantity from local event, POST command |
| `CancelOrder` | `orderId`, required `confirmation` | Local confirmation, then command with `confirmed:true` |
| `RevokeDevice` | `deviceId`, `deviceKind:pin\|biometric`, required `confirmation` | Local confirmation then finite command |
| `Checkout` | `formId:checkout` | Existing native CheckoutUseCase, never `/sdui/actions` |
| `EditProfile` | none | Existing native edit-profile destination/adapter |
| `Logout` | none | Existing AuthService logout/token clearing/account-state clearing |
| `ChangePin` | none | Existing native PIN flow |
| `ManageBiometric` | none | Existing native hardware/key/credential flow |
| `Login` | none | Existing native sign-in destination |

`destination` has `screen`, plus `id` only for product/order; `catalog` query
only for catalog; `orders` query only for orders; `categories` only for home/catalog,
and `cart` only for cart. The latter two are bounded collection queries. It never contains an HTTP
path. Confirmation has `title`, `message`, `confirmLabel`. The user must confirm
locally before setting `confirmed:true`; that boolean is not proof of identity
and never replaces Yii ownership checks.

### Local form adapters

* `catalog`: collect `catalog.*`, defaults page=0/size=20, normalize optional
  empty filters away, clear sort for nonempty q, reset page=0, validate locally,
  navigate/refresh the finite catalog destination. Preserve the current category
  window from `state.collections.categories`, defaulting to0/50 if absent.
  No mutation POST.
* `orders`: collect status/size, reset page=0, validate, refresh finite orders.
* `profile`: collect fullName/phone and send `UpdateProfile`. Native edit-profile
  remains available for other capabilities; the generic form never edits email,
  password, avatar uploads or credentials.
* `checkout`: prefilled trusted default/first address, four editable address
  strings and payment selection. Map `CARD → ShopPaymentMethod.Card`,
  `SBP → ShopPaymentMethod.Sbp`, `ON_DELIVERY → ShopPaymentMethod.OnDelivery`
  with a local exhaustive mapping, then call the existing
  `com.shopkmp.home.domain.CheckoutUseCase.execute(city, street, house, apartment,
  paymentMethod)`. Its native repository owns address creation, order creation,
  idempotency and payment handling. Generic SDUI does not add unsupported address
  IDs/comments to that signature or choose a payment URL. After success refresh
  cart/orders or open the returned native result. Native code owns critical
  confirmation, gateway handling and any credential entry.

The host allows one mutation at a time, disables submitting controls, retains
local input on validation/network errors, handles cancellation, and clears
account-scoped state on logout/401/account change. Reuse the existing
RequestExecutor refresh mutex; do not add another token refresh loop.

### Required native host handoff

* **Local Back is mandatory**, including an explicit iOS affordance on product,
  order and device/detail destinations, plus platform/system back handling. Bind
  it to the native navigation stack/scaffold. The server action registry has no
  Back action, and a collection's previous-page control is not navigation Back.
* The inherited `BackendShopRepository.checkout` currently calls
  `retryOnceIfIndeterminate { shopApi.confirmPayment(paymentId) }` (lines198–200,
  helper358–365 in `feature/home/home-data`). Reusing the five-argument
  CheckoutUseCase does **not** prove native payment confirmation is single-attempt.
  Task3 must reconcile/remove that automatic ambiguous-result retry and cover the
  native adapter with tests: read/reconcile trusted payment/order state, surface
  an unknown outcome, and require explicit recovery rather than blindly repeating
  confirmation or the entire checkout. Preserve the existing order idempotency key
  for the same intent. The BFF never performs that critical payment command.

These are required CMP integration acceptance items. Task2's live test drives
the native canonical HTTP boundaries directly; it does not run or change this
inherited Kotlin retry implementation.

## Finite command HTTP protocol

`POST /api/v1/sdui/actions`, bearer required, `Content-Type: application/json`,
maximum **32768 bytes**, one strict JSON object. Unknown members, duplicate keys,
null objects, depth >16, wrong scalar types and remote/native critical commands
are rejected before mutation. Each request performs fresh `/users/me` auth and
the canonical protected Yii call; cancellation also reads the owned order first.

| `command` | Exact `input` | Fixed canonical Yii operation |
| --- | --- | --- |
| `AddToCart` | `{productId,quantity}` quantity integer 1–1,000,000 | `POST /api/v1/cart/items` |
| `UpdateQuantity` | `{productId,quantity}` quantity integer 0–1,000,000, required | `PATCH /api/v1/cart/items/{productId}` |
| `RemoveFromCart` | `{productId}` | `DELETE /api/v1/cart/items/{productId}` |
| `CancelOrder` | `{orderId,confirmed:true}` | `POST /api/v1/orders/{orderId}/cancel` |
| `UpdateProfile` | `{fullName,phone}` name 1–255 trimmed chars, no controls; phone `^\+[1-9][0-9]{7,14}$` | `PUT /api/v1/users/me` |
| `RevokeDevice` | `{deviceId,deviceKind,confirmed:true}` kind pin/biometric | `DELETE /api/v1/auth/{kind}/devices/{deviceId}` |

`AddToCart` requires `Idempotency-Key` (1–255 visible ASCII bytes), preserved
exactly through to Yii. Generate once per user intent; retain it for explicit
same-intent recovery. Yii scopes replay to user/endpoint and returns 409 for a
changed fingerprint. Other command operations do not offer canonical replay
keys; the BFF does not simulate idempotency or automatically retry them.

Example (use a real product ID and bearer):

```http
POST /api/v1/sdui/actions
Authorization: Bearer <existing access token>
Content-Type: application/json
Idempotency-Key: cart-intent-9c0fb2

{"command":"AddToCart","input":{"productId":"22222222-2222-4222-8222-222222222222","quantity":2}}
```

```json
{"schemaVersion":1,"command":"AddToCart","outcome":"applied","refresh":{"screen":"cart"}}
```

Acknowledgements are HTTP 200. They deliberately do not add a second business
read that could hide a committed write behind a refresh failure. The client
refreshes separately. Mutation success is never inferred from an optimistic
screen action; the command must succeed. After a network-unknown result do not
blindly retry: refresh state; for AddToCart explicit recovery uses the original
key/payload. No mutation is automatically retried, including gateway-sensitive
cancellation. GETs may retry once for transient network/502/503/504 failures
within the same total deadline and with cancellable backoff. Auth/business
validation/conflict/rate-limit errors are not retried.

An acknowledgement's `refresh:{screen:"cart"}` names the canonical target, not
a request to discard local view coordinates. For a mutation initiated on a cart
page, reload that source cart destination/page/size and adopt any normalized page
returned in `state.collections.cart`; this preserves late-page recovery controls.
From another screen, open the default cart page0/size50. No client view coordinates
are accepted as user identity, authorization, a business command or an arbitrary
request target.

## Error and partial-content behavior

Yii errors preserve their **exact HTTP status and bounded JSON envelope/body**:

```json
{"error":{"code":"OUT_OF_STOCK","message":"requested quantity exceeds stock","traceId":"...","details":{"items":"..."}}}
```

Transport/protocol errors are distinct from valid Yii errors: invalid/oversized
JSON, malformed upstream error, redirect, network failure or account mismatch
become BFF errors, without forwarding internal URLs, tokens or raw exception
messages. BFF codes start `BFF_`, with `code`, `message`, `traceId` in the same
error-envelope shape. Typical statuses: 400 request/query validation, 401 no
bearer, 404 route, 405 method, 413 body limit, 415 content type, 502 transport/
invalid document/account mismatch, 504 upstream deadline, 408 cancellation.
**Known upstream HTTP401/403 is retained independently of body validity.** Empty,
HTML, oversized or truncated security bodies yield the same401/403 status with a
sanitized `BFF_UPSTREAM_SECURITY` envelope, never raw body content, a502/504
transport downgrade, or a successful personalized partial document. Valid Yii
security envelopes remain byte-for-byte preserved. This rule also applies at
the initial authentication gate and on commands.

Home authenticates before personalized composition, then runs categories,
popular and (when authenticated) session concurrently. A nonsecurity section
failure yields HTTP 200 with `metadata.partial:true`, finite warning strings
and an Error node with Retry; available sections remain usable. A 401/403 from
any section cancels siblings and returns the exact Yii failure, never a partial
personalized screen, including when that status has a malformed/oversized body.
Authentication-gate failures always fail the whole request.
Other screens fail atomically on required upstream failures. No sample products,
synthetic balances, invented identities or mock business fallbacks are served.
