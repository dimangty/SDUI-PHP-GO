# Task2 — independent Go SDUI BFF implementation report

Executed **2026-10-18** in `/Users/dmitrijbykov/Documents/GoLang/SDUI`.
Plan/spec: `docs/IMPLEMENTATION_PLAN.md` Task2 and `docs/MIGRATION_PLAN.md`.

## Result

Task2 is implemented and verified against the running Task1 PHP service. This
is a real HTTP-only BFF: all business data comes from Yii, including persisted
cart/order/profile/device state. Production handlers have no sample-business
fallback, database driver, SQL, database configuration or identity-provider
credentials. Runtime nonstandard dependency inspection lists only this module's
five packages; all external dependencies are test-only schema validation.

Final integration-enabled race run: **38 top-level tests passed, 89 leaf cases
passed, zero failed, zero skipped**. Four tested packages passed; `cmd/server`
has no separate test functions. `go vet ./...` and the independent Docker build
passed. Actual Docker healthcheck/readiness and graceful shutdown passed.

All authored files are within `backend-sdui/**`, `docs/SDUI_CONTRACT.md`, and this
report. PHP/legacy/CMP/root implementation files were not edited. No commits,
staging, subagents or production cutover were performed. The pre-existing PHP
69-test review result is supplied context, not counted in the Go results here.

## Deliverables

| Files | Responsibility |
| --- | --- |
| `backend-sdui/go.mod`, `go.sum` | Go 1.26 module; locked test-only validator dependencies |
| `cmd/server/main.go` | signal lifecycle and built-in Docker healthcheck command |
| `internal/yii/{client,dto,query}.go` | bounded typed canonical Yii HTTP boundary and decimal-string money |
| `internal/sdui/model.go` | finite sealed props/actions, limits, IDs, references, controlled fields and revisions |
| `internal/bff/{http,screens,components,actions}.go` | eight screen builders, auth/ownership gates and six command handlers |
| `internal/runtime/{config,server}.go` | validated environment, HTTP timeouts, internal health listener, graceful drain |
| `internal/{yii,sdui,bff,runtime}/*_test.go` | client, model, builder, command, contract, runtime and real-stack verification |
| `contracts/{screen,action-request,action-response}.schema.json` | fixed Draft 2020-12 shared wire contract |
| `contracts/fixtures/*.json` | eight deterministic screens plus primitive/loading/error/empty examples |
| `tests/evidence/*.json` | final real-stack manifest, screens, business DTOs and action acknowledgements |
| `Dockerfile`, `.dockerignore`, `README.md`, `PROGRESS.md` | independent minimal runtime, operator/test instructions and execution ledger |
| `docs/SDUI_CONTRACT.md` | exact Kotlin-consumable wire semantics, UIKit registry and native adapter mapping |

The nine deterministic fixtures collectively exercise all **26** published
component types. Examples and schema use the requested exact names: Text, Image,
Icon, Column, Row, Box, LazyColumn, LazyRow, Button, TextField, SearchField,
ProductCard, ProductGrid, CategoryList, Price, QuantityStepper, CartItem,
OrderCard, ProfileHeader, Empty, Loading, Error, Spacer, Divider, Form, FormField.

## Boundary/security verification

* Yii origin is operator-fixed and validated as HTTP(S) origin only. Request JSON
  cannot choose paths/methods/URLs/headers/code. Client methods fix canonical
  operations; resource/device identifiers are validated before interpolation.
* Only bearer and sanitized correlation context propagate, with generated JSON
  headers and supported AddToCart idempotency key. No client role/user/cookie/
  bootstrap/forwarding headers are trusted or forwarded.
* Every bearer screen first authenticates through Yii `/users/me`; protected
  business calls authenticate independently in Yii. Anonymous home/catalog/
  product perform only public calls. Invalid supplied bearer never becomes
  anonymous. Returned cart/session/address/order owners must match trusted Yii
  identity. No response or session cache can cross requests/accounts.
* Authenticated home authenticates first, then aggregates categories/popular/
  session concurrently. Nonsecurity failures produce typed partial Error/Retry
  sections. 401/403 cancels siblings and preserves the exact upstream error.
* Redirects and proxy environment are disabled. Responses and response headers
  are bounded. Context deadlines cover the entire operation, including one
  cancellable-backoff GET retry. GET HTTP 502/503/504 or transient network errors
  can retry once; authorization/validation/conflict/429 do not. No mutation retry.
* Transport/protocol failures are distinct from valid Yii error envelopes, whose
  bounded body and status are preserved. Internal error messages/URLs/tokens
  are not emitted. Personalized documents/actions/errors use `no-store`.
* Six commands: AddToCart, RemoveFromCart, UpdateQuantity, CancelOrder,
  UpdateProfile, RevokeDevice. Strict JSON rejects unknown fields, case aliases,
  duplicate keys, null inputs, invalid quantities/IDs and unconfirmed destructive
  intent. Each command freshly reauthenticates. Cancellation checks a trusted
  owned order before the canonical write. Idempotency keys pass unchanged.
* Wire limits: 1 MiB documents, 500 total nodes, depth16, 500 actions, 8192 Unicode
  characters/string, 32 KiB commands, finite options/styles/media paths. Controlled
  fields and action references are validated; revisions hash actual document
  content deterministically. Money remains a decimal string, never float.
* Checkout, logout, login, PIN and biometric entry remain finite **native**
  semantics. Checkout form exactly matches the existing five-argument
  CheckoutUseCase: city/street/house/apartment/paymentMethod. Generic SDUI cannot
  post orders, passwords, PINs, key material or arbitrary payment instructions.

## Observed TDD / regression evidence

The initial module/client, model/builders and runtime tests failed because their
interfaces were missing; implementations then passed their HTTP/behavior tests.
The command suite specifically observed **501** for the pending adapter before
the six canonical forwarding implementations made it GREEN. Schema tests first
failed on missing resources; local schemas/fixtures then passed independent
Draft 2020-12 validation and deliberate malformed/executable-wire rejection.

Additional observed behavioral RED→GREEN cycles:

| Test / finding | Observed RED | GREEN change |
| --- | --- | --- |
| `TestRealCatalogWithMoreThan100CategoriesRemainsUsable` | HTTP502 for 150 real-shaped categories; also found by the first live run | 500-option cap with unchanged global action/body bounds; no silent truncation |
| `TestOrdersContainDeliveryAndPaymentForExistingUIKitOrderRow` | order row lacked delivery/payment data | typed props/schema/fixtures include actual UIKit row fields |
| `TestCheckoutFormExactlyMatchesExistingNativeUseCaseInputs` | seven fields, including two unsupported native inputs | exactly five existing CheckoutUseCase inputs |
| `TestHomeOffersControlledCatalogSearch` | no controlled search form | SearchField + local catalog SubmitForm state/action |
| `TestHomeSecurityFailureCancelsSiblingRequestsAndPreserves403` | 403 delayed ~2s by blocked siblings | immediate sibling context cancellation, preserved Yii403 |
| model unsafe-state cases | negative count, invalid pagination/rating and missing controlled state accepted | explicit state/prop validation |
| model option-bound case | 501 options accepted | reject >500 options |
| command exact-key cases | `COMMAND`, `ProductId`, quantity/Quantity aliases reached mutation | exact JSON-tag member allowlist before typed decode |
| device path cases | `.`/`..` reached a device mutation path | reject dot path segments in shared validator/schema |
| explicit zero upstream-body configuration | zero silently selected defaults | reject explicit values outside 1..8388608 |

Client tests also demonstrate bounded/malformed bodies, numeric-money rejection,
safe headers, no redirect credential forwarding, cancellation/deadline,
error/status preservation, one GET retry ceiling and a single POST attempt even
for a transient network EOF with Idempotency-Key. The initial header expectation
was corrected to allow Go's generated `Connection: close`; it was not a forwarded
client header. Full suites were rerun after implementation/fix cycles.

## Exact final verification

Host module toolchain: **`go version go1.26.0 darwin/arm64`**. Docker builder:
`golang:1.26-alpine`. Commands run from `backend-sdui/`:

```sh
gofmt -w internal
go test ./...
go vet ./...
docker build -t shopkmp-sdui-task2 .

YII_INTEGRATION_ORIGIN=http://127.0.0.1:58088 \
BFF_INTEGRATION_ORIGIN=http://127.0.0.1:58091 \
SDUI_EVIDENCE_DIR=../../tests/evidence \
go test -race ./... -count=1 -json
```

The final `-json` stream was summarized by a Python stdin-only event counter:
38 passing top-level tests; 89 passing leaf cases (parents not double-counted);
0 test skips/failures. This includes `TestLiveYiiEightScreensAndActions`, which
otherwise explicitly skips when its upstream environment is absent. The normal
offline suite therefore does not misrepresent the live check as executed.

`go list -deps -f '{{if not .Standard}}{{.ImportPath}}{{end}}' ./cmd/server`
reported only `internal/yii`, `internal/sdui`, `internal/bff`, `internal/runtime`
and `cmd/server` from this module. `jsonschema/v6` and its x/text dependency are
not runtime-linked.

Docker command:

```sh
docker run --detach --name shopkmp-sdui-task2 \
  --network shopkmp-migration-test \
  --publish 127.0.0.1:58091:8080 \
  --publish 127.0.0.1:58092:9090 \
  --env YII_ORIGIN=http://shopkmp-yii2-task1-http:8080 \
  shopkmp-sdui-task2
docker exec shopkmp-sdui-task2 /server healthcheck
curl http://127.0.0.1:58092/health/ready
curl http://127.0.0.1:58091/health/live
```

Observed: image `sha256:ffe4b0343ba7042e9f71b05fb9bd86b73cc6f058443d9ccea82d6816d19db066`,
image size **3,503,806 bytes** from Docker inspection, user `nonroot:nonroot`,
health **healthy**; internal readiness HTTP200 `{"status":"ok"}`; application
port `/health/live` HTTP404, proving operational routes are not publicly routed
by the application handler. Final-image `docker stop --timeout 10` logged
`2026/10/18 05:47:38 SDUI shutdown complete` and inspection reported **exitCode=0**.
The runtime test separately proves an in-flight request is drained before exit.
The container was restarted; built-in healthcheck and readiness200 passed again.
The BFF container remains available on the loopback ports above.

## Real Task1 integration / SQL-backed E2E

Final captured run: **2026-10-18T05:43:58Z**.

* PHP HTTP: `http://127.0.0.1:58088`.
* Docker BFF HTTP: `http://127.0.0.1:58091`.
* Internal BFF→Yii: `http://shopkmp-yii2-task1-http:8080` on
  `shopkmp-migration-test`.
* Random account: `281382c6-cc65-4b22-9378-36589f519453`, registered/logged in
  through PHP using a generated valid `sdui-…@example.org` email. Keycloak's
  internal issuer is handled by PHP, not rewritten or bypassed in Go.
* Existing SQL-backed product selected through canonical catalog API:
  `0001db9b-0ab1-470f-a555-a23ffe9e5c0a` (`Exact product`), unit price **"0.10"**,
  initial stock **10**. No catalog seeding or hardcoded business-response mock.
* Created order: `b7c308ac-5ec1-41c4-8632-5ed68daa96f6`, number118, quantity2,
  exact total **"0.20"**, payment method ON_DELIVERY. SQL stock readback **8**
  after reservation, restored to **10** after cancellation.
* **52 actual HTTP steps** recorded in
  `backend-sdui/tests/evidence/manifest.json`. Noncredential response hashes,
  body sizes, paths and statuses are recorded. Auth steps retain only route/
  status/size metadata; token/credential bodies are neither saved nor hashed.
  A scan of evidence JSON found no access/refresh tokens, passwords or key fields.

| Journey stage | Actual evidence / result |
| --- | --- |
| Anonymous home/catalog/product | HTTP200, independent screen-schema validation, no invented session |
| PHP register/login | HTTP201/200, same trusted subject |
| Native PIN setup/login and biometric enrollment | HTTP201/200/201; real opaque access session and Ed25519 public-key enrollment |
| Authenticated **home** | HTTP200, real concurrent category/popular/session data |
| **catalog** | HTTP200; real OpenSearch q/category/exact price filters and page1 query |
| **product** | HTTP200, actual product/price/stock, local controlled quantity and finite add |
| **cart** | HTTP200, persisted quantity/total, trusted address-prefilled native checkout form |
| AddToCart + same-key replay | two HTTP200 responses, SQL/API readback quantity2, not4 |
| Same key, different quantity | preserved Yii HTTP409 conflict |
| UpdateQuantity | HTTP200; replacement quantity3 verified through canonical readback |
| RemoveFromCart | HTTP200; empty cart verified through canonical readback |
| UpdateProfile / **profile** | HTTP200; changed name/phone visible in actual profile document |
| **devices** / RevokeDevice | HTTP200; real PIN and biometric rows, both kind revocations and empty readback |
| Native address/checkout/replay/payment | HTTP201/201/201/200, one order ID on replay; canonical native operations, no critical BFF command |
| **orders** / **order** | HTTP200, real item/address/payment/total snapshots and confirmation-bound cancel action |
| CancelOrder | HTTP200; persisted CANCELLED status and stock restoration verified |
| Cancelled order/status filter/post-checkout empty cart | HTTP200, actual updated documents |
| Second account attempts order read/cancel | preserved Yii HTTP404 for both |
| Remote Checkout command | BFF HTTP400, cannot execute critical order submission |
| Native logout / revoked opaque session | HTTP204, then protected BFF profile HTTP401 |

All eight main screen captures, plus anonymous/empty/search/pagination/cancelled
variants, are saved under `tests/evidence/`. Business product/cart/address/order/
payment DTOs and all six command acknowledgements are also saved. The test creates
random users, addresses, idempotency records and cancelled orders in the disposable
Task1 stack; no direct SQL access is used even by the Go integration test.

## Review decisions and remaining integration work

Self-review was performed against the user requirements, actual UIKit/checkout
sources, DTO routes, schema and tests; no reviewer subagent was used as requested.
Important findings were fixed with the regression cycles listed above. There
are no known outstanding Task2 implementation blockers or deferred minor defects.

Decisions:

1. Execute in the supplied unborn repository and keep the ledger under
   `backend-sdui/`, honoring the explicit file boundary/no-commit/no-subagent
   instructions. No isolation branch/commit was created.
2. Disable upstream connection reuse to prevent net/http's implicit replay from
   escaping the explicit retry budget. Cost: additional internal TCP/TLS setup.
3. Native checkout uses exactly the existing five inputs; no remote order,
   payment URL or unsupported form argument was introduced. Native repository
   continues to own checkout idempotency and address/payment behavior.
4. Use loopback58091/58092 for the BFF because the existing reference service
   already owns58089. Task1 services and reference listener were preserved.

Task3 still needs to consume these unchanged schemas/fixtures and implement/
verify the CMP renderer/native adapters on Android/iOS. Task4 owns public-edge
TLS/routing and production orchestration. This run verifies the native HTTP
boundaries, not a launched CMP application, hardware biometric prompt, external
card/SBP provider or production cutover. ON_DELIVERY confirmation is the actual
payment path covered by this Task2 live journey. Those integration boundaries
are not represented as passing platform/provider checks here.

## Independent review corrections — 2026-10-18

This appendix supersedes the earlier final verification/handoff snapshot above.
`docs/SDUI_REVIEW.md` was read and verified against production source, canonical
Yii rules and focused failing tests; it was **not edited**. All three Important
findings and all four minor findings were addressed before the revised v1 handoff.
All edits remain in the original owned paths; no commits or subagents were used.

### Verified RED → GREEN evidence

| Review item | Observed RED | Implemented correction and GREEN evidence |
| --- | --- | --- |
| **I1: security status lost on invalid body** | Client HTML/oversized/truncated401/403 errors lacked status; personalized home returned200 for empty/HTML/oversized security bodies after ~2s; auth gate returned502 | Transport errors retain raw status independently of body decoding. Valid Yii envelopes remain exact; malformed security errors return sanitized `BFF_UPSTREAM_SECURITY` with original401/403. Home cancels siblings and never produces a personalized partial response. Client and HTTP gate/aggregation regressions pass, with no body leakage/no retries. |
| **I2: valid descriptions break browsing** | At8192 characters card previews were unbounded; at8193/10000 product/catalog/home returned502 | Card preview ≤256 code points, explicit `descriptionTruncated`; detail retains every code point in ≤4096-code-point Text blocks.8192/8193/10000 multibyte descriptions with emoji/escaped HTML characters pass all three builders and independent schema validation. Full-detail concatenation equals the original string exactly. |
| **I3: combined collection expansion exceeds actions** |165/166/301-row carts returned502;400 categories plus100 products returned502; valid new page coordinates initially400 | Explicit independent collection windows, metadata, visible range and finite previous/next destinations. Cart default50/max100; categories default50/max50. Every cart page retains row update/removal, global totals/count and native checkout. Category windows do not reduce the100-product page. All301 cart rows and all400 categories are reachable without duplicate/lost IDs. Last-page update/removal works and clamps the removed final page. |
| **Minor: positional images** | Reordering/insertion reused another source's ID; duplicate source rendered twice | Stable `product.image.` + first32 SHA-256 hex characters of the safe source; source deduplication. Existing image identities survive reorder/insertion. |
| **Minor: phone rule** | Canonically valid `+12345678` returned400; missing plus/leading-zero phones reached mutation | Exact canonical `^\+[1-9][0-9]{7,14}$` rule in command/schema and Phone-form semantics. Valid8–15-digit cases pass; invalid cases fail before mutation. |
| **Minor: search length units** |101 Cyrillic characters (202 bytes) and51 emoji (204 bytes) passed BFF validation | Query validator uses valid UTF-8 and ≤200 bytes. Search fields publish `maxUtf8Bytes:200`; schema annotates the byte rule and contract gives the Kotlin `encodeToByteArray().size` check.200-byte ASCII/Cyrillic/emoji cases pass; excess/invalid UTF-8 fail. |
| **Minor: lost detail rating** | Detail emitted neither4.5 nor the unrated state | `product.rating` Text contains actual one-decimal rating and review count, or “Нет оценок” for null. Both branches verified. |

Additional review-fix regressions verify the published50-category viewport cap
(51 initially accepted → rejected) and prevent a partially decoded, failed
popular response from consuming the successful home section's action budget
(initial502 → typed200 partial with Error/Retry). The original150-category test
now traverses all three explicit pages on both home and catalog instead of
requiring an unbounded first document.

### Fixed budgets and exact collection semantics

Global limits remain **500 total nodes, 500 actions, 1,048,576 document bytes,
depth16, 8192 code points/string**. No limit was raised.

* Cart accepts `page`/`size`, defaults0/50, max100. Full bounded Yii cart responses
  are sorted by product UUID before windowing. Full quantity count/total and
  native checkout remain visible; metadata counts distinct rows separately.
* Home/catalog accept `categoryPage`/`categorySize`, defaults0/50, max50. Visible
  category options and the selector are bounded, with any current selected
  category pinned outside the window. All remaining options have explicit
  continuation. Product and category pagers preserve each other's coordinates.
* `state.collections.cart/categories` publishes effective page/size/totalPages/
  totalItems. `destination.cart/categories` carries only finite typed coordinates.
  Cart coordinates are valid only for cart; category coordinates only for
  home/catalog. Schema and Go validation reject cross-screen/oversized queries.
* Collection pages past the end clamp to the last existing page (empty →0).
  After a cart mutation the adapter reloads the source view and adopts the
  effective metadata, rather than losing recovery controls on a removed page.
* The fixed partition reserves complete shared controls: at most100 cart rows
  ×3 actions +16 reserved =316, or100 products +50 categories +16 reserved =166.
  The complete result is still globally validated; no overflow rows/actions are
  silently discarded. Full upstream response/body bounds also remain enforced.

Measured passing regression documents:

| Valid source collection / rendered window | Bytes | Total nodes | Actions |
| --- | ---: | ---: | ---: |
|301-row cart,100 visible rows, first page |84,752 |113 |308 |
|400 categories +100 products,50 visible categories |78,532 |115 |160 |
|Same counts,255-character HTML-escaped names,256-character escaped previews and near2048-byte media paths |840,052 |115 |160 |

The rich case is explicitly below1MiB and keeps all100 product cards on every
category page. Subsequent pages are traversed and independently schema-validated;
the full set of400 category IDs is checked for loss/duplicates. Cart traversal
likewise checks all301 unique rows before performing the last-row mutations.

### Published v1 Kotlin input

`docs/SDUI_CONTRACT.md`, all three JSON Schemas and **13 deterministic fixtures**
are updated together. The26 component names and finite command registry remain
the same. New/clarified wire data: bounded/truncated description previews,
`maxUtf8Bytes`, collection state and collection destination coordinates.

The four additional exact-builder fixtures are:

* `cart-continuation.json`: visible middle page, both pagers, full total/checkout;
* `home-continuation.json`: category continuation with personalized home;
* `catalog-continuation.json`: independent product/category paging and a selected
  category pinned outside the visible window;
* `product-long-description.json`: a complete10k-code-point product description,
  stable image identity and actual rating.

All13 files pass golden drift checks and independent Draft2020-12 validation.
The UTF-8 byte bound, total node/depth/reference constraints and collection
normalization remain explicitly documented extra runtime rules where JSON Schema
alone is insufficient. These artifacts are the reviewed-fix v1 handoff snapshot;
future CMP consumes their exact field/type/query semantics.

The handoff now explicitly requires **local Back on native detail/device screens,
including iOS**, and platform/system back handling. It also identifies the inherited
`BackendShopRepository.checkout` call to `retryOnceIfIndeterminate` around payment
confirmation. Task3 must reconcile/remove that blind ambiguous-outcome retry,
test native recovery/state reconciliation, and preserve same-intent order keys.
Task2 does not claim the native retry behavior is fixed by the BFF's single-attempt
policy. No CMP files were changed.

### Final commands and live evidence after corrections

From `backend-sdui/`:

```sh
UPDATE_FIXTURES=1 go test ./internal/bff -run TestPublished
gofmt -w internal
go test ./...
go vet ./...
docker build -t shopkmp-sdui-task2 .
docker stop --timeout 10 shopkmp-sdui-task2
docker rm shopkmp-sdui-task2
docker run --detach --name shopkmp-sdui-task2 \
  --network shopkmp-migration-test \
  --publish 127.0.0.1:58091:8080 \
  --publish 127.0.0.1:58092:9090 \
  --env YII_ORIGIN=http://shopkmp-yii2-task1-http:8080 \
  shopkmp-sdui-task2

YII_INTEGRATION_ORIGIN=http://127.0.0.1:58088 \
BFF_INTEGRATION_ORIGIN=http://127.0.0.1:58091 \
SDUI_EVIDENCE_DIR=../../tests/evidence \
go test -race ./... -count=1 -json

docker exec shopkmp-sdui-task2 /server healthcheck
curl --fail http://127.0.0.1:58092/health/ready
```

Results: **56 passing top-level tests /152 passing leaf cases, zero failures,
zero skipped tests** in the full integration-enabled race run. Four test packages
passed; `cmd/server` has no separate test functions. JSON output was counted with
the same stdin-only summary method as the original run. Vet passed with no
diagnostics, formatting check was empty, and Docker build succeeded.

The original owned container was stopped (exit0), removed and recreated on the
same loopback58091/58092 ports. New image:
**`sha256:d13e54fe1377372d922382e31f3cc9a9930c154f0b2cd370680da2cefb20ac0d`**,
Docker inspected size **3,514,057 bytes**, user `nonroot:nonroot`, health
**healthy**. Built-in healthcheck exited0; readiness returned HTTP200
`{"status":"ok"}`. Only the owned BFF container was recreated.

The final live manifest is now **2026-10-18T06:15:17Z**, with **63 actual HTTP
steps**, and supersedes the earlier52-step capture at the same evidence path.
Random PHP register/login account:
`00e831b3-5c58-4471-93ef-e03b9bf4cb07`; real created/cancelled order:
`6cd31259-fcba-4278-a17f-cf8f5175ab7a`. The same existing SQL product
`0001db9b-0ab1-470f-a555-a23ffe9e5c0a` supplied exact unit price"0.10" and
order total"0.20"; checkout/cancellation reserve/restore assertions still passed.

The enhanced real-stack journey retains all eight screens/six commands and the
native credential/checkout/payment/logout boundaries, and additionally:

* reads all **246** actual Yii category DTOs, traverses all **five** category
  windows alongside `catalog?size=100`, and verifies the complete ID set;
* loads authenticated home category continuation;
* adds another existing SQL-backed product, verifies a one-row cart viewport
  still reports two distinct rows/three total units, removes that row, and
  verifies the former last page normalizes to0 without stranding the cart;
* saves the new category/continuation captures and canonical category payload in
  `tests/evidence/`, with no auth token/password/key material in evidence JSON.

Large301-row/400-category boundary data is deterministic HTTP regression data,
not falsely labelled as live SQL fixture counts. The live run uses only existing
canonical business HTTP APIs and random real accounts, without direct SQL,
credentials for persistence or hardcoded business responses. Payment coverage
remains the actual ON_DELIVERY path; platform/native retry settlement and external
card/SBP handling remain the explicit Task3/4 handoff items above.
