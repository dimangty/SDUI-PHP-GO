# Task2 execution ledger

Plan: `docs/IMPLEMENTATION_PLAN.md`, Task2; spec: `docs/MIGRATION_PLAN.md`.

Ruling: execute inline in the supplied unborn workspace; user forbids commits,
subagents and changes outside backend-sdui and the two named documents.
Use this ledger rather than root workflow artifacts.

Design: stdlib HTTP-only Yii client → typed commerce DTOs → finite SDUI v1
builders → bounded HTTP handlers. Independent internal health listener and
signal-driven shutdown. Schema and immutable common examples form the CMP input.

Work sequence:
- [x] Typed HTTP client and safety/retry/cancellation tests (RED → GREEN).
- [x] Wire model, limits, deterministic revisions and builders (RED → GREEN).
- [x] Reauthenticated finite commands, ownership and HTTP tests (RED → GREEN).
- [x] JSON Schema/common fixtures and independent validation.
- [x] Runtime configuration, Docker healthcheck/shutdown and README.
- [x] Real Yii HTTP integration across eight screens and commerce/actions.
- [x] Race/vet/Docker build, self-review and final evidence report.

Pre-flight: Task1→2 uses unchanged canonical HTTP DTOs; Task2→3 uses only
published schema/fixtures; Task2→4 uses independent Dockerfile and environment.
No shared implementation file ownership.

Client cycle: `go test ./internal/yii` first failed on missing Client/DTOs;
then exercised the implementation and found the test incorrectly excluded the
transport-generated `Connection: close`. Corrected the header expectation;
all client cases pass. No client-supplied header map exists in the API.
Ruling: disable upstream connection reuse to prevent net/http's implicit
stale-connection replay bypassing the explicit retry ceiling. Cost: extra
internal TCP/TLS connections; correctness of mutation delivery takes priority.

Builder/model cycle: missing models/handlers observed RED; all eight structured
screens, concurrent/partial home, ownership and deterministic revision checks GREEN.
Command cycle: real HTTP assertions observed 501 for every pending command;
implemented six finite commands and strict duplicate-key/input checks; suite GREEN.
Schema cycle: missing schema resources observed RED; implemented Draft 2020-12,
resolved canonical IDs offline in independent validator; eight builder fixtures
and schema rejection tests GREEN. jsonschema/v6 and x/text are test-only imports.

Runtime cycle: missing config/health/lifecycle functions observed RED; health
separation, real upstream readiness and graceful in-flight drain tests GREEN.
Docker Go1.26/distroless build and nonroot healthcheck verified.

Live integration discovered >100 real categories; 150-category regression RED
(502) → GREEN with category options bounded at 500 alongside global action/body
limits. No categories silently truncated. Existing reference owns port58089;
test BFF uses loopback58091 and operational58092. First full Docker-backed journey
passed all eight screens/six commands, native credentials, checkout/payment,
idempotency, stock restoration, cross-account denial and opaque-session logout.

UIKit/native review: regression tests caught missing order-row delivery/payment
props and checkout inputs unsupported by existing five-argument CheckoutUseCase.
Tests RED → GREEN; checkout now exactly four address strings plus paymentMethod.
Added controlled home search. Updated schema and deterministic fixtures together.

Security review: sibling aggregation cancellation test RED (2s delay) → GREEN
on 401/403; preserve exact Yii error while cancelling other home operations.
Model negative count/pagination/rating/missing-state cases RED → GREEN.

Final review: self-review (user forbids subagents). Exact-key command regression
caught encoding/json's case-insensitive aliases; dot-device-ID regression caught
unsafe path segments; explicit zero body-limit config regression caught fallback
to defaults. All RED → GREEN; exact tags, safe device segments and explicit
configuration limits enforced. Network EOF test verifies one GET retry and one
POST attempt even with Idempotency-Key.

Final evidence: Go1.26.0; integration-enabled `go test -race ./... -count=1`
38 top-level tests / 89 leaf cases passed, zero failed/skipped; vet passed;
independent Docker build passed; nonroot image healthy; internal readiness200,
public health404; signal shutdown logged completion and drain unit test passed.
Final Docker-backed live run recorded52 HTTP steps at 2026-10-18T05:43:58Z;
all eight screens and six command types schema-valid, real PHP login and SQL
commerce/ownership/logout readbacks passed. Full commands and resource IDs are
in `docs/SDUI_IMPLEMENTATION_REPORT.md`. No deferred review findings.

## Independent review follow-up

Verified `docs/SDUI_REVIEW.md` against source and canonical Yii rules. Leave the
independent review unchanged. I1 regressions reproduced personalized HTTP200 for
malformed/oversized 401/403 and HTTP502 at the auth gate. Retain raw response
status independently of body validation, return sanitized security failures and
cancel home siblings: focused tests and full offline suite GREEN.

Next cycles: bounded description previews/full chunks; stable media IDs, canonical
phone and byte-counted search/detail rating; bounded independent cart/category
collection pagination with explicit state/destinations, preserving full-cart
totals/checkout and the existing hard 500-node/action/1MiB/depth16 bounds.

I2/minors: 8192/8193/10000 multibyte business descriptions reproduced unbounded
previews/HTTP502; UTF-8-safe256-code-point previews and exact4096-code-point detail
blocks now GREEN. Image insertion/duplicate identity, canonical8-digit phone,
202-byte Cyrillic/204-byte emoji searches and missing detail rating reproduced
RED; stable hashed image sources, canonical phone regex, maxUtf8Bytes200 and actual
rating/unrated output now GREEN. Shared schema/default fixtures updated together.

I3: 165/166/301-row carts and400 categories/100 products reproduced HTTP502;
new page queries initially400. Independent category windows (max50), cart windows
(max100), explicit range/count/previous/next state and finite destinations now
expose all rows while preserving checkout/full totals and the fixed global budgets.
301-row traversal and last-page update/remove/clamp, 400-category traversal with
all100 products retained, and worst-case canonical escaped names/media pass.
Category viewport cap51 regression RED→GREEN.13 shared fixtures, including four
new continuation/full-description examples, independently validate and match
builders. Extended real-stack journey exercises category traversal and cart
last-page removal; final live/race/vet/image verification follows.

Review follow-up complete: full integration-enabled race run56 top-level/152 leaf
cases passed,0 failed/skipped; vet and formatting passed;13 fixture goldens and
independent schema validation passed. Rebuilt/recreated only the owned BFF at
58091/58092, new image d13e54fe1377372d922382e31f3cc9a9930c154f0b2cd370680da2cefb20ac0d,
nonroot healthy, readiness200. Live run2026-10-18T06:15:17Z records63 HTTP steps,
including all246 actual categories over5 windows and cart last-page removal/clamp.
Fixed budgets measured:301-row cart viewport84752 bytes/113 nodes/308 actions;
rich400-category/100-product case840052 bytes/115 nodes/160 actions. Report appendix
contains exact RED→GREEN observations, commands and updated live resource IDs.
Local Back and inherited native payment retry are explicit Task3 acceptance
handoff requirements. Independent review remains unchanged.
