# Independent review — Task 2 Go SDUI BFF

> **Historical review — before decommissioning.** Findings and line references
> describe the reviewed milestone, including the now-retired Go business source.
> Canonical contracts and ops tools have since moved; no new review pass is claimed.
> See [decommissioning](LEGACY_REMOVAL.md) and [deployment](DEPLOYMENT.md) for the
> current source locations and verification commands.

Date: 2026-10-18. Scope: `backend-sdui/**` and `docs/SDUI_CONTRACT.md`,
against `docs/MIGRATION_PLAN.md` and Task 2/global constraints in
`docs/IMPLEMENTATION_PLAN.md`.

## Verdict

**Specification: partially compliant; acceptance requires changes.** The
independent HTTP-only service, eight real builders, six finite commands,
schemas/fixtures and operational lifecycle are implemented. The authentication
failure boundary and composition of valid business data have the three Important
defects below. They conflict with the authorization/usable-screen requirements
in `MIGRATION_PLAN.md:89–99,108–112` and `IMPLEMENTATION_PLAN.md:28–34,55–65`.

**Quality: changes required.** The documented race/vet/build/live results are
substantial evidence, but miss these boundary cases. **0 Critical, 3 Important**
findings; four minor findings are separate. No direct bearer bypass, spoof-header
authority, cross-account cache, arbitrary upstream target or mutation retry was
identified in the BFF paths inspected.

## Method and evidence

Reviewed files directly because the repository is unborn. Read
`docs/SDUI_IMPLEMENTATION_REPORT.md`, production source, schemas, representative
fixtures/live captures, all Go test source, and relevant canonical Yii/CMP code.
The report records **38 passing top-level tests / 89 leaf cases**, integration-
enabled race tests, vet, Docker build, health and shutdown. These are the author's
recorded results, not independently rerun suites. The 52-step live manifest and
test source support the principal eight-screen/six-command journey.

Metadata inspection confirmed the reported Docker artifact:
`sha256:ffe4b0343ba7042e9f71b05fb9bd86b73cc6f058443d9ccea82d6816d19db066`,
user `nonroot:nonroot`. New probes used this image in temporary containers with
an in-memory HTTP upstream, synthetic identities/data and no business database.
Containers and socket servers were removed afterward. No application/test files
were edited; no commits or subagents were used. This document is the sole file
written.

## Important findings

### I1 — Malformed upstream 401/403 becomes a successful personalized partial screen

**Locations:** `backend-sdui/internal/yii/client.go:159–180`;
`backend-sdui/internal/bff/screens.go:20–23,55–58,77–100`.

The client retains HTTP status only in `UpstreamError`, created after validating
the JSON error envelope. A 401/403 with malformed/empty/oversized error content
becomes a status-less `TransportError`. Home recognizes only `UpstreamError`
401/403 as security failures, so it neither cancels siblings nor fails the screen.

**Observed:** with a successful `/users/me` authentication gate, changing only
`/auth/session`'s 403 body produced:

| Session response | BFF home result |
| --- | --- |
| Canonical JSON 403 error | HTTP 403, original error |
| HTTP 403, `<html>Forbidden</html>` | **HTTP 200**, `authenticated:true`, trusted account ID/profile, `partial:true`, `session_unavailable` |

**Impact:** a known authorization rejection is downgraded to ordinary section
unavailability; CMP receives a successful personalized document and misses its
auth-failure lifecycle. This is a fail-closed aggregation defect, not evidence
that an unauthenticated caller can bypass `/users/me` or read another account.
It contradicts `docs/SDUI_CONTRACT.md:349–354`.

**Required correction:** retain the HTTP security status independently of body
validation. Abort personalized composition and cancel siblings on that signal;
malformed error content must produce a sanitized whole-request failure. Add
malformed/oversized 401/403 aggregation regressions. Existing security tests use
well-formed envelopes (`internal/bff/regression_test.go:33–49`).

### I2 — A canonically valid description takes down product, catalog and home

**Locations:** `backend-sdui/internal/bff/screens.go:184–188`;
`backend-sdui/internal/bff/components.go:48–60`;
`backend-sdui/internal/sdui/model.go:22,364–366`;
`backend-sdui/internal/bff/http.go:121–124`.

Builders copy the complete business description into one Text/ProductCard
string. Canonical product input permits **10,000 characters**
(`backend/internal/transport/rest/openapi.yaml:747–766`;
`backend-yii2/application/CatalogService.php:44–46`), while SDUI strings are
limited to 8,192. There is no presentation mapping between those valid domains.

**Observed:** an otherwise valid product with 8,192 ASCII description characters
returns HTTP 200. At **8,193**, product detail, a one-product catalog and home
with that popular product all return **502 `BFF_INVALID_DOCUMENT`**. Their
upstream calls succeed; this is not malformed upstream data or a body-size limit.

**Impact:** legitimate catalog content prevents browsing/purchase. One popular
product can break the whole home screen rather than yield usable content.

**Required correction:** provide bounded card previews and bounded full-detail
composition, preserving the wire limits and meaningful product content. Exercise
8,192/8,193/10,000-character business DTOs through all affected builders. The
existing model long-text rejection test verifies the guard, not compatibility of
the business-to-screen mapping (`internal/sdui/model_test.go:62–64`).

### I3 — Unbudgeted action expansion makes valid carts and catalog pages unreadable

**Locations:** `backend-sdui/internal/bff/screens.go:146–164,213–233`;
`backend-sdui/internal/bff/components.go:37–60`;
`backend-sdui/internal/sdui/model.go:23,274–276`;
`backend-sdui/internal/bff/http.go:165–183`.

Builders expand every item/category before checking the complete document's
500-action ceiling. Cart creates three actions per row plus seven shared/form
actions. Canonical cart rules limit quantity, not distinct product count
(`backend-yii2/application/CommerceService.php:13–27`). The cart screen exposes
no pagination/continuation query to recover from overflow.

**Observed:**

| Valid upstream data / request | Result |
| --- | --- |
| 164 owned cart rows, quantity 1 each | HTTP 200, **499 actions**, 136,228 bytes |
| 165 owned cart rows, quantity 1 each | **502 `BFF_INVALID_DOCUMENT`**; builder requires 502 actions |
| 400 categories, catalog `size=20` | HTTP 200, 180,419 bytes |
| Same categories, catalog `size=100` | **502 `BFF_INVALID_DOCUMENT`**; builder requires 507 actions |

These inputs are well below upstream body limits; row/node counts alone do not
explain the failure. Both catalog sizes are explicitly supported by the contract.

**Impact:** adding a legitimate cart row can strand CMP on an error screen with
no rendered remove/quantity/checkout controls. Supported catalog pagination also
fails depending on unrelated category count. The 150-category regression does
not cover the combined budget (`internal/bff/regression_test.go:12–30`).

**Required correction:** budget complete documents and supply a finite bounded
continuation/paging representation for large collections, including cart
recovery controls. Update the shared contract/fixtures and cover the exact
overflow boundaries; do not merely omit items/actions silently.

## Minor findings

1. **Product image IDs are positional.**
   `backend-sdui/internal/bff/screens.go:179–182` emits `product.image.{index}`.
   Insertion/reordering reuses an existing node ID for another image, contrary to
   `docs/SDUI_CONTRACT.md:118` and the stable-ID requirement. CMP lazy/render state
   can attach to the wrong image. Derive image identity from stable media content.

2. **Profile phone validation diverges from the business contract.**
   `backend-sdui/internal/bff/actions.go:129–134` and
   `backend-sdui/contracts/action-request.schema.json:12` accept optional `+` and
   9–15 digits. Yii requires `+`, a nonzero first digit and **8–15** digits
   (`backend-yii2/domain/Rules.php:26–30`). An existing valid `+12345678` profile
   cannot submit even a name-only change through the generic form; other locally
   accepted phone values are rejected downstream. Align the schema, form and
   command validation with the canonical rule.

3. **Search length units disagree.**
   `backend-sdui/internal/yii/query.go:21–23`,
   `backend-sdui/contracts/screen.schema.json:54` and
   `docs/SDUI_CONTRACT.md:75` allow 200 Unicode characters. Both canonical server
   and Yii cap q at **200 bytes** (`backend/internal/transport/http/server.go:391`;
   `backend-yii2/application/CatalogService.php:20–21`). For example, 101 Cyrillic
   letters pass BFF/form validation but fail Yii. Publish/enforce the actual UTF-8
   byte bound at the CMP form boundary.

4. **Product detail loses the actual rating.**
   `backend-sdui/internal/bff/screens.go:178–198` displays review count but never
   emits `p.Rating`. Existing CMP detail displays both
   (`feature/product/product-ui/src/commonMain/kotlin/com/shopkmp/product/ui/ProductDetailsStateBuilder.kt:31–35`).
   Cards retain the rating, so this is a detail-screen information regression.

## Boundary and CMP handoff assessment

| Area | Assessment |
| --- | --- |
| Auth / account isolation | Fresh Yii `/users/me` gate for supplied bearers; no anonymous downgrade. Protected calls reauthenticate in Yii. Returned cart/address/session/order owners are checked. Request-local data and personalized `no-store` avoid cross-account server state/cache reuse. I1 affects aggregation failure handling. |
| Spoofing / upstream / injection | Requests construct an allowlisted bearer/request-ID context, not a forwarded header map. Operator-fixed origin, validated resource/device IDs, encoded queries, disabled proxy environment/redirects and finite methods prevent request-driven SSRF/header/path injection in the inspected paths (`internal/yii/client.go:55–150`). |
| Bounds / retry / cancellation | Upstream bodies/headers and command/document sizes are bounded. Contexts cover upstream calls and cancellable retry backoff. Explicit retries apply only to GET, once; disabling connection reuse/GetBody prevents implicit mutation replay. Canonical 401/403 cancels home siblings. I1–I3 expose missing failure/composition handling, not absent guards. |
| Commands / idempotency | Six strict, typed commands reject extra/case-aliased/duplicate members, invalid IDs/quantities and unconfirmed destructive inputs. Cancel checks the owned order before writing. AddToCart preserves the required key; successful writes return an acknowledgement without an extra failure-prone read. No BFF mutation retry or remote checkout/credential execution was found. |
| Schema / fixtures / revisions | Three local Draft 2020-12 schemas and nine deterministic fixtures cover the finite registry. Test source independently validates builder output and detects fixture drift. Action/command/form mappings agree, including absolute quantity and native checkout's five arguments. Revision hashes include actual content/state/account/actions/query; device/resource IDs are stable, except positional images above. Global reference/count/depth validation supplements schema as documented. |
| Real functionality | All eight routes use actual Yii DTOs; search/filter/pagination, purchase intent, cart update/removal, order detail/cancel, profile update and device revocation exist. There is no production sample-data fallback. I2/I3 leave valid-data screen completeness unresolved; M4 loses detail information. |

CMP still needs the planned Task 3 renderer, account-scoped host/state lifecycle
and native adapters. Two concrete handoff details deserve explicit resolution:

* **Back navigation:** no finite `Back` action exists in the published action
  registry, and detail/device documents lack back buttons. The native host must
  supply a local back affordance for those destinations, including iOS; current
  CMP product/device screens supply one explicitly. The wire alone does not.
* **Native payment retry:** the five-argument CheckoutUseCase signature matches,
  but its existing repository calls `retryOnceIfIndeterminate` for payment
  confirmation (`feature/home/home-data/src/commonMain/kotlin/com/shopkmp/home/data/BackendShopRepository.kt:198–200,358–365`).
  Task 3 must reconcile that inherited behavior with
  `IMPLEMENTATION_PLAN.md:34` and the no-blind-mutation-retry guidance in
  `SDUI_CONTRACT.md:323–331`. The BFF's single-attempt policy does not establish
  the same property for the native checkout adapter.

These handoff requirements are not additional newly introduced Task 2 Important
defects. The report correctly leaves platform rendering, production-provider
settlement and edge deployment to later stages. Resolve **I1–I3** and add their
focused regression evidence before accepting Task 2 and freezing its CMP input.

---

## Scoped re-review — I1/I2/I3 and four minor corrections (2026-10-18)

**Scoped specification verdict: all seven prior findings are addressed.** The
corrections meet the reviewed authentication, content, collection and compatibility
requirements. **Scoped quality verdict: acceptable; no new Critical or Important
breakage introduced by these corrections was identified.** This supersedes the
earlier blocking disposition for these findings.

### Evidence and scope

Read the appended `docs/SDUI_IMPLEMENTATION_REPORT.md:248–408`, relevant current
production code, all three schemas, all **13 deterministic fixtures**, the focused
regressions and updated fixture/live-test assertions. The appendix records
**56 passing top-level tests / 152 leaf cases, zero failures/skips**, an
integration-enabled race run, vet, Docker build and a 63-step live journey.
Those execution results are attributed to the implementation report; no suite,
previous probe or business flow was rerun during this re-review.

A metadata-only inspection of `shopkmp-sdui-task2` confirmed the reported rebuilt
image and nonroot user:
`sha256:d13e54fe1377372d922382e31f3cc9a9930c154f0b2cd370680da2cefb20ac0d`,
`nonroot:nonroot`. Review work only appends this document; no application/test
files were edited, and no commits or subagents were used.

### Important finding dispositions

#### I1 — Addressed

`backend-sdui/internal/yii/client.go:45–65,172–193` now retains HTTP status in
transport/protocol errors independently of body validity. Home classifies that
status and cancels siblings on 401/403
(`internal/bff/screens.go:20–23,57–84`). The response boundary preserves valid Yii
envelopes exactly and emits a sanitized, same-status `BFF_UPSTREAM_SECURITY`
envelope for malformed security responses (`internal/bff/http.go:228–245`).

`internal/yii/review_security_test.go:13–39` checks HTML, oversized and truncated
401/403 responses and one upstream attempt. The HTTP regressions in
`internal/bff/review_security_test.go:12–82` cover empty/HTML/oversized bodies,
actual sibling cancellation, absence of personalized/raw error content, no-store
and authentication-gate status preservation. These directly cover the prior
fail-open path rather than only well-formed errors.

#### I2 — Addressed

`backend-sdui/internal/bff/product_content.go:10–27` creates at-most-256-code-point
card previews with explicit truncation state and preserves full descriptions in
ordered, at-most-4096-code-point Text blocks. The actual card/detail builders use
that mapping (`internal/bff/components.go:52–59`;
`internal/bff/screens.go:210–220`). The 8192-code-point string limit remains.

`internal/bff/review_content_test.go:61–121` exercises 8192/8193/10000-code-point
descriptions containing Cyrillic, emoji and HTML-escaped characters through all
three affected screens, checks preview semantics and exact full-detail
reconstruction, and independently validates the documents. This addresses both
the valid-data outage and preservation of meaningful product content.

#### I3 — Addressed

`backend-sdui/internal/bff/collections.go:12–89` introduces explicit bounded
collection windows, visible ranges, effective counts and finite continuation.
Cart defaults to 50 rows, maximum 100; categories default to 50, maximum 50.
Cart sorts before windowing and retains full-cart quantity count, server total,
row recovery controls and native checkout
(`internal/bff/screens.go:234–281`). Home/catalog window categories independently
of the product query, and the selector pins a selected category outside the
window (`screens.go:46–135,144–187`; `collections.go:91–108`).

Complete-document action reservations and the existing global validation remain
in place. All collections stay reachable through explicit navigation; the fix
does not silently discard overflow rows. Out-of-range collection pages clamp,
including after deleting the final row of a final page. Typed destination and
collection validation constrain screen/size/page combinations
(`internal/sdui/model.go:212–233,315–335,546–560`).

`internal/bff/review_collections_test.go:33–287` covers the original 164/165-row
boundary, 166/301-row carts, traversal without loss/duplicates, last-page update
and removal, 400 categories plus 100 products, rich escaped content, preservation
of product paging and pinned selection. Lines 289–311 cover invalid collection
coordinates and a failed partial section's budget. The reported rich document is
840,052 bytes; global 1 MiB / 500-node / 500-action limits were not raised.

### Minor finding dispositions

| Prior minor | Disposition and supporting evidence |
| --- | --- |
| M1: positional image identity | **Addressed.** Safe-source SHA-256-derived IDs and source deduplication replace indices (`internal/bff/product_content.go:29–32`, `screens.go:200–208`). `review_content_test.go:123–163` checks insertion, reorder and duplicates. |
| M2: phone validation | **Addressed.** Command and request schema now use canonical `^\+[1-9][0-9]{7,14}$` (`internal/bff/actions.go:129–134`; `contracts/action-request.schema.json:12`). `review_phone_test.go:11–54` checks valid 8/15-digit values, invalid boundaries, no invalid write and schema agreement. |
| M3: search length units | **Addressed.** Query validation checks valid UTF-8 and 200 encoded bytes (`internal/yii/query.go:21–23`); fields publish `maxUtf8Bytes:200` (`internal/bff/components.go:28–39`). `internal/yii/review_query_test.go:8–20` covers ASCII/Cyrillic/emoji byte boundaries and invalid UTF-8; `internal/bff/review_content_test.go:196–214` checks the wire prop. |
| M4: missing detail rating | **Addressed.** Detail emits actual one-decimal rating/review count or the explicit unrated state (`internal/bff/screens.go:216–220`); both branches are covered by `review_content_test.go:165–194`. |

### Schema, fixtures and introduced-breakage assessment

The schema/model/contract changes agree on `descriptionTruncated`,
`maxUtf8Bytes`, collection state and screen-specific finite destination
coordinates. The contract explicitly documents UTF-8 byte validation beyond JSON
Schema, independent product/category paging, full-cart totals, normalized-page
adoption and source-page reload after mutation acknowledgements
(`docs/SDUI_CONTRACT.md:90–143,213–219,261–270,439–445`).

The eight base fixtures plus primitives and four added examples consistently
show the revised wire semantics. Golden/independent-schema checks cover all 13
through `internal/bff/contract_test.go:47–78`,
`primitives_contract_test.go:12–50` and
`continuation_contract_test.go:16–99`; the added fixtures demonstrate both cart
pagers, category continuation, pinned selection and full long-description detail.
Updated live assertions exercise category traversal and cart-page removal
normalization (`live_integration_test.go:210–243,274–307`).

**No newly introduced Critical/Important finding in this scope.** Fresh auth,
ownership gates, fixed upstream operations, mutation acknowledgements and the
single-attempt mutation policy are preserved in the inspected paths. Local Back
and inherited native payment retry are now explicit Task 3 acceptance items
(`docs/SDUI_CONTRACT.md:371–389`), not claims of completed CMP fixes. This verdict
closes the seven reviewed findings using source/test inspection and the appended
execution evidence; it is not a new full-system or platform test run.
