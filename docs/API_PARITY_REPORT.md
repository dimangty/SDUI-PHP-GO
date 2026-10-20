# Focused Go → Yii2 HTTP contract parity

> **Historical evidence — before decommissioning.** The 194-pair captures and
> results below are preserved, not rerun after removal. The Go reference source,
> parity runner/profiles and differential/cross-runtime tests have been retired;
> their commands are no longer current instructions. Use
> [decommissioning](LEGACY_REMOVAL.md) and [deployment](DEPLOYMENT.md) for current checks.

## Current result — 2026-10-20

Final capture: **2026-10-20T11:48:38Z**, from
`python3 scripts/parity.py --disposable --regressions` (exit0).
**54/54 operations** each have a matching successful Go/Yii HTTP contract;
**194 pairs:189 matches,5 explicitly scoped differences,0 unexpected mismatches**.
All39 missing-bearer and7 ordinary-user/admin denials match. The complete PHP
regression suite also passed: **77 tests,66 syntax checks**.

The earlier six-difference captures below are historical. The avoidable
consumed/expired refresh-message difference was removed after a focused
RED→GREEN cycle; it is now an exact match. The five retained differences are
the three canonical nonnullable image-array corrections, current SQL search stock,
and explicit rejection of deleting an address referenced by a historical order.
They remain visible, are never normalized away, and do not count as matching
successful operation coverage.

## Milestone 1 — scope and baseline (2026-10-19)

Original `backend/internal/transport/http/router.go` registers **54
operations (15 public / 32 protected / 7 admin)**. Existing `tests/api.php`
successfully exercises all 54 against Yii; `tests/differential.php` compares only
14 GET DTOs and 3 errors. Its registry assertion is structural evidence, not
successful differential operation coverage. `tests/cross_runtime.php` retains
bidirectional persistence/hash/session evidence.

Implementation/check sequence:

- [x] Observe the new live-operation coverage gate fail on a read-only baseline.
- [x] Run equivalent HTTP workflows on fresh, separate Go/Yii databases, with
  canonical Keycloak password auth, live PIN/biometric keys, and independent
  literal money/stock/state/replay/ownership assertions.
- [x] Compare successful contracts for exactly the original 54-operation set;
  compare representative errors/access denials and document intentional deltas.
- [x] Verify the comparator rejects changed money/stock/status/resource references
  and missing/null fields; persist secret-free operation/case evidence.
- [x] Integrate a mandatory disposable parity phase into `integration-check`,
  run relevant existing suites, update generated route evidence, self-review.

Owned scope: `backend-yii2/tests/`, test-only `deploy/` and `scripts/` support,
this report and generated `docs/ROUTE_MATRIX.md`. No production/runtime or native
client changes, commits or subagents. Production changes would require a new
failing contract proving an introduced migration regression.

## Planned comparison boundary

Full JSON keysets, scalar types, nullability and array order are compared. Only
explicitly bound generated resource IDs (at ID/reference fields), validated
runtime timestamps, verified token/challenge secrets and derived capability URLs
may differ. Business strings, amounts, quantities, statuses, error details and
pagination are preserved. Both databases start empty with equivalent fixtures.
No live application data is reset. This is **54-operation sampled successful
contract coverage**, not exhaustive parity over all inputs/states/interleavings.

Final commands, counts, intentional differences and review evidence will be
appended at subsequent milestones.

## Milestone 2 — observed coverage RED

`python3 scripts/parity.py --disposable --baseline` executed on the new standalone
stack. Both real servers returned HTTP200 for `/categories`; their parsed body
and JSON shape matched. The live coverage gate then **failed as intended**:
`Successful live differential coverage 1/54; missing: ...` (53 named operations).
This proves a matching registry/one successful comparison cannot satisfy the gate.
The unique project's containers/network/disposable databases were removed in
`finally`. The existing 58095 application stack was not reset or reconfigured.

## Milestone 3 — first complete live comparison GREEN

At **2026-10-19T14:10:43Z**, `python3 scripts/parity.py --disposable` passed:
**194 paired HTTP cases, 54/54 operations with at least one matching successful
full-body contract, 6 explicitly pinned differences, 0 unexpected mismatches**.
Each pair is two real HTTP handlers, on separate fresh PostgreSQL databases.
Original 001–011 migrations, original realm, real Keycloak password flows and real
OpenSearch execute; the legacy automatic seed is disabled only by the existing
test bootstrap marker. Test servers have no host ports/external networks.

Before GREEN, full comparison exposed four additional legacy wire differences:
one refresh-error wording and three empty `imageUrls` null/array responses.
No business code was changed. The main successful catalog samples use one actual
image URL; additional empty-image samples retain and explicitly report that
nullability difference. The comparator requires **54 matching successes** even
when the extra differing samples exist.

The other two pinned probes demonstrate previously documented behavior:
historical-order address deletion (Go FK/database503 versus Yii409
`ADDRESS_IN_USE`) and stale OpenSearch stock (Go indexed22 versus Yii SQL19).
These differences are not removed from the compared body or counted as matches.
Exact case/field/old/new values are required; all other changes fail.

## Final evidence and reproducible commands

From the repository root, with Docker available:

```sh
# Required live parity only; fresh unique project, no existing stack required.
python3 scripts/parity.py --disposable

# Same parity plus the complete existing PHP suite on disposable fixtures.
python3 scripts/parity.py --disposable --regressions

# Verify every named evidence row against both canonical registries and the docs.
python3 scripts/route_matrix.py --check \
  --parity-evidence deploy/evidence/api-parity.json

# Reproduce the missing-coverage RED (intentionally exits nonzero).
python3 scripts/parity.py --disposable --baseline
```

The final full parity/regression command passed on **2026-10-20**, contract capture
**11:48:38Z**. Result:

| Measure | Observed |
| --- | --- |
| Original successful operation set | **54/54**, exact method/path set, 15 public / 32 protected / 7 admin |
| Paired HTTP cases | **194** (388 canonical/preflight HTTP requests) |
| Successful paired samples |108:104 matching,4 explicitly differing supplemental samples |
| Negative/access pairs |86:85 matching,1 explicitly differing supplemental error |
| Matching full status/body/JSON-shape pairs |**189** |
| Scoped differences |**5**, exact values listed below; never counted as matches |
| Unexpected differences |**0** |
| Missing bearer / forged identity |**39/39** nonpublic operations:401 on both runtimes |
| Ordinary user → admin operations |**7/7**:403 `ADMIN_REQUIRED` on both runtimes |
| Live-response comparator mutation probes |**13 rejected**: money, stock, status, rights, replay quantity, cross-resource reference, nullability, missing key, business string, pagination, TTL, empty body versus JSON null, empty list versus object |
| Existing `composer test:all` |**77 tests,0 failures;66 PHP syntax checks** |

The final verification and error-wording regression are recorded below. All rows
in `docs/ROUTE_MATRIX.md` name a
matching successful paired case and exact access probes where applicable. The
matrix checker reads the actual case results and refuses missing/extra operations.

### Successful operation coverage by group

| Group | Operations | Representative contracts/state checked |
| --- | ---: | --- |
| Password auth |5 | Keycloak register/login/refresh/change-password/logout; token keysets/types/300s TTL; changed password and old refresh rejection |
| Session/PIN/biometric |13 | Real Argon2 PIN lifecycle; generated Ed25519 key/SPKI/signature;120s challenge; invalid assertion and one-use replay; opaque rotation/revocation; enabled flags/device lists |
| Catalog/reviews/admin stock |14 | Category descendants, exact prices, nullable discount/rating, image arrays, two-page pagination/search/popular, purchased review creation/deletion, stock patch and inactive-product readback |
| Profile/address/avatar |7 | User/null fields, immutable email, omitted versus explicit-null default address, address owner isolation/update/delete, canonical avatar-clear success |
| Cart |5 | Increment/replacement/removal/clear, exact`20.02`/`30.03`, availability/stock, persisted replay snapshot and conflicting key rejection |
| Orders |5 | Atomic checkout/read/list/transition/cancel, stock22→19, cart cleared, snapshot replay after PAID with one order/reservation, cancellation restoration |
| Payments/webhook |5 | Pending/authorized/captured/refunded/cancelled readbacks; exact amount, ownership, raw-body HMAC and webhook dedup; invalid transition/reversal rejection |
| **Total** |**54** | Every row exercised through real HTTP on both runtimes |

### Exact normalization boundary

`tests/parity/Harness.php::normalized()` is the entire normalization surface:

* Resource IDs must first be explicitly bound from an HTTP creation response to
  a unique semantic label (buyer/product/address/order/payment/review/credential).
  Only ID/reference field names are mapped; fixture UUIDs remain exact. Derived
  `cart-<buyer>` IDs use that same binding. Swapped payment/order references fail.
* `createdAt`, `updatedAt`, `lastUsedAt`, `lockedUntil`: preserve null; validate
  UTC RFC3339 fractional precision and the current run window before substituting
  the timestamp field marker. Epoch/explicit2020 fixture times remain exact.
  Birthday is **not normalized**. The sampled device locks are null; this is not
  a differential lockout-duration matrix.
* Only issued `accessToken`/`refreshToken` values are masked. Exact response keys,
  string types, distinctness/freshness and integer300s TTL are asserted first.
  JWT subject/issuer/exp–iat are checked; each returned access token subsequently
  authenticates `/users/me`. Opaque sessions are looked up by binary SHA256 with
  approximately300s access /2592000s refresh SQL expiry, and old refresh/revoked
  access fail through HTTP. Password hashes remain empty in application SQL.
* Challenge bytes are masked only after the real enrollment/signature/login flow;
  keyset and integer120s TTL are exact, valid and invalid signatures execute, and
  challenge replay returns410. Trace IDs alone differ in error envelopes.
* Demo payment URLs retain the exact route and bound payment ID; their only query
  value must equal the HMAC capability for that exact payment before it is masked.
* **No money, stock, quantity, availability, status, role/access result, order
  number, transaction ID, birthday, email, name, comment, address, image URL,
  rating, pagination value, error code/message/details or JSON key is removed or
  normalized.** Object key order is insignificant; array order, scalar types,
  absent keys, null and empty object/list shapes remain significant. An empty
  response body is distinguished from a JSON literal null by `bodyKind`.

Secrets/private keys/raw tokens/capability queries are held in process memory;
the evidence file stores operation/case/status/result metadata and the exact
nonsecret differing leaves only. It saves no token/key bodies or token hashes.

### Visible compatibility differences

These are explicitly asserted samples, not generic exceptions or claims that
clients cannot observe a difference:

| Case | Original Go | Current Yii2 | Disposition |
| --- | --- | --- | --- |
| `delta.empty-images-update` |`imageUrls:null` |`imageUrls:[]` | Newly characterized legacy wire/nullability difference on PUT product. Both keys/shapes remain compared. |
| `delta.empty-images-stock` |`imageUrls:null` |`imageUrls:[]` | Same on stock PATCH. Main nonempty-image successful contract matches. |
| `delta.empty-images-search` |`imageUrls:null` |`imageUrls:[]` | Same on indexed search. No blanket null→[] conversion. |
| `delta.search-stale-stock` |Indexed stock22 |Current SQL stock19 | Documented rehydration correction; actual checkout changes SQL while index remains stale. See `YII2_IMPLEMENTATION_REPORT.md:261–263`. |
| `delta.historical-address-delete` |503 `DATABASE_UNAVAILABLE`, `database operation failed` |409 `ADDRESS_IN_USE`, `address is used by an order` | Documented historical-FK correction; address preserved on both sides. See `YII2_IMPLEMENTATION_REPORT.md:255–256`. |

CORS is **matching**, not an outstanding exception: actual emulator Origin
GET200 and preflight403 compare exact `Vary: Origin`/absent permission headers;
the existing seven-case CORS suite and existing actual demo-form Origin test both
passed. Existing Android/iOS CARD evidence is preserved, not rerun here. Published
query byte limits and negative pagination return matching400 contracts. No
intentional legacy correction was silently changed to obtain these results.

### Existing-suite preservation and integration

`--regressions` starts a third private Go process against the **disposable migrated
test DB only**, after the separate-DB differential completes. This permits the
unchanged `differential.php` and bidirectional `cross_runtime.php` suites to use
their required shared SQL fixture/session/replay format. The complete existing
suite passes, including signed OIDC, throttling, concurrency/address races,
strict HTTP JSON, real uploads, CORS/demo payment, real search and deadlines.
Expected provider-rejection/timeout diagnostic lines belong to passing negative
tests; none is omitted as an unexplained failure.

`scripts/verify.py` now invokes `scripts/parity.py --disposable` as an unconditional
failing phase of `make integration-check`, immediately after route-matrix checking.
There is no environment-based parity skip. This session ran the new phase and
the complete existing PHP suite directly; it **did not rerun the entire root
integration/mobile/build suite**, whose prior evidence is retained unchanged.

Artifacts: `deploy/evidence/api-parity.json` plus `api-parity-contracts.log`,
`api-parity-php-all.log`, setup/build/cleanup logs. The wrapper always tears down
its unique `shopkmp-parity-<random>-e2e` project in `finally`, including anonymous
volumes; DB data uses tmpfs. No parity container remains after the recorded run.

### Review handoff and scope limits

Author self-review checked original/PHP registry equality, real HTTP coverage
accounting, reference-bound ID consistency, comparison exceptions, persistence
readbacks, secret-free evidence, default mandatory integration invocation and
standalone cleanup/isolation. This is **not an independent parent review**; the
parent/reviewer can run the exact commands above and inspect the five pinned deltas
in `tests/parity/compare.php`. No independent approval is claimed.

The initial harness task changed no runtime source; that does not make its six
characterized differences exact matches. The later message-only compatibility
correction below edits one SessionService branch. Remaining scope is every
untested input/state/interleaving (including
full upload-format, credential-lockout and provider/JWKS outage matrices), actual
production-provider behavior, production data/cutover/restore/load. All54 sampled
successful contracts do **not** imply exhaustive all-input parity.

## Final parent verification and refresh wording RED→GREEN

The independent [parity review](API_PARITY_REVIEW.md) accepted the behavioral
comparison and identified avoidable refresh-message drift. Removed only the
`pin.refresh-replay` comparator exception, then ran
`python3 scripts/parity.py --disposable`: **FAIL**,194 comparisons, one unexpected
mismatch at `/data/error/message` (`invalid or expired refresh token` versus
`invalid refresh token`). The disposable project was cleaned up on failure.

Changed only the absent/consumed/expired opaque-refresh row branch in
`application/SessionService.php` to the original message. Wrong-token-kind handling
and all validation/transaction/token rules are unchanged. Re-ran
`python3 scripts/parity.py --disposable --regressions`: **PASS**,54/54 matching
successful operations,194 pairs,189 matches,5 scoped differences,0 unexpected;
all13 comparator mutation probes rejected;77 PHP regressions passed with no skip
of bidirectional persistence compatibility. Final capture11:48:38Z supersedes
the review's inspected11:25:33Z capture and the earlier October19 results.

The root Yii image was rebuilt and FPM recreated on the existing disposable
58095 stack; its actual healthcheck passed. No existing DB/identity/catalog was
reset. The original backend and migration sources remain unchanged.
