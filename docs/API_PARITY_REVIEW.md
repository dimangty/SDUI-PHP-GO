# Independent review — Go/Yii2 contract parity harness

> **Historical review — before decommissioning.** Findings, line references and
> captures below describe the reviewed migration milestone. The reference source,
> parity runner/profiles and comparison tests have since been retired; no new
> review or comparison pass is claimed. See [decommissioning](LEGACY_REMOVAL.md)
> and [deployment](DEPLOYMENT.md) for the current layout and checks.

Reviewed: **2026-10-20**. Scope: `docs/API_PARITY_REPORT.md`,
`backend-yii2/tests/parity.php`, `backend-yii2/tests/parity/**`,
`deploy/compose.parity.yml`, `deploy/parity-init.sql`,
`scripts/{parity,route_matrix,verify}.py`, generated route documentation and the
actual `deploy/evidence/api-parity*` artifacts. Canonical Go/PHP registries,
OpenAPI and relevant implementation paths were inspected to resolve the deltas.
Line references below describe the reviewed files.

## Verdict

**Specification: PASS for the requested mandatory-endpoint sampled parity
harness.** All 54 original operations have an actual matching successful
Go/Yii2 HTTP pair. Coverage is not inferred from the manifest. Representative
errors, ownership, permissions, money, state changes and replay are exercised.

**Quality: ACCEPT with two Minor follow-ups below. No Critical or Important
finding identified.** The comparison is behavioral, its normalization is narrow
for the exercised contracts, and the six exact differences are visible and
excluded from matching coverage. The disposable runner does not reuse the root
application stack or production database configuration.

This is **not a zero-difference legacy-wire compatibility verdict**: the six
cases genuinely differ. Three are canonical-schema corrections, two reflect
previously documented behavior corrections, and one is avoidable error-message
drift. Their disposition is explicit below; calling them all exact DTO matches
would be incorrect. Exhaustive input/state/interleaving coverage is not required
for this verdict and is not claimed.

## Findings, by priority

### M1 — Minor: final report does not identify the current evidence run

**Locations:** `docs/API_PARITY_REPORT.md:95–96,112–115,217–219`;
`deploy/evidence/api-parity.json:2`;
`deploy/evidence/api-parity-contracts.log:1–2,391–406`.

The final evidence section still identifies a **2026-10-19 14:16:23Z** capture.
The actual supplied JSON is **2026-10-20T11:25:33+00:00**, and both contract and
PHP-regression logs identify project **`shopkmp-parity-951355944f-e2e`**.
The report also points to a separate final parity-only milestone "below", but
the file ends without that milestone. This leaves the final normalizer's run
provenance unnecessarily ambiguous despite the current successful artifacts.

The statement "No introduced migration regression ... was proven" also needs a
clear task-local meaning: the harness exposed existing Go/Yii wire differences;
the absence of a runtime edit in this task does not make those differences exact
legacy matches, especially the message drift in M2.

**Action:** apply the precise report-only corrections in the final section of
this review. Counts do not need changing. This is an evidence/documentation
follow-up, not a reason to rerun the recorded suites.

### M2 — Minor: consumed-refresh error wording is an unnecessary legacy delta

**Locations:** `backend-yii2/tests/parity/compare.php:44–45`;
`backend-yii2/application/SessionService.php:30–36`;
`backend/internal/shop/postgres/store.go:113–119`;
`docs/API_PARITY_REPORT.md:172`.

Go returns `invalid or expired refresh token` when the opaque refresh row is
absent/consumed/expired; Yii returns `invalid refresh token`. Both reject replay
with HTTP401 and `INVALID_REFRESH_TOKEN`, and the harness correctly reports this
as `SCOPED_DIFFERENCE`. No permission or replay failure is hidden.

Unlike the image-array and historical-address/search cases, no correctness or
canonical-schema reason for changing this particular message is established.
OpenAPI permits a string message and tells clients to branch on the stable code
(`backend/internal/transport/rest/openapi.yaml:326–340`), making the impact low,
but schema validity does not establish exact legacy-message compatibility.

**Action:** classify this as avoidable compatibility drift, not an intentional
correctness fix. In a separately authorized compatibility follow-up, align the
no-row branch at `SessionService.php:35` with Go and remove this one pinned delta
so the existing replay case must become `MATCH`. The wrong-token-kind handling
does not need to change. No runtime edit is requested as part of this review.

## Why this is a real behavioral comparison

* **Actual HTTP on both implementations.** `parity.php:10–18` runs the same
  workflow first against the reference origin/database and then against Yii's.
  `Harness.php:32–48` sends a bounded HTTP request to `/api/v1`, requires the
  expected status, decodes the returned body, records its JSON shape and resolves
  the canonical method/path. The Go image builds `./cmd/server`
  (`backend/Dockerfile:2–11`); Yii starts its actual application front controller
  (`deploy/compose.parity.yml:90–96`; `backend-yii2/web/index.php:3–7`). Neither
  comparison side is a canned response generator or a proxy to the other side.
* **Coverage cannot be satisfied by registry declarations.** The registry is the
  expected set and policy input only (`Harness.php:15–20`;
  `scenarios.php:17–24`). Coverage is recorded after completed successful HTTP
  calls. Both sides must cover the exact expected set, then
  `compare.php:53–64` independently requires 54 operations with a `MATCH` 2xx
  pair. An allowed delta cannot supply a missing matching success. Event
  operations and both statuses participate in equality. The route checker adds
  54 independently named success-case checks against the actual evidence and
  both registries (`scripts/route_matrix.py:12–81,121–136`).
* **Independent business expectations supplement cross-runtime equality.**
  Literal expectations include `20.02`/`30.03` totals, stock22→19, ownership
  denials, payment/order transitions, cart clearing, replay of the original
  snapshot after mutation, one persisted order/reservation and cancellation
  restoration (`scenarios.php:97–150`). Shared fixture and scenario code does not
  manufacture either implementation's response. Direct SQL assertions check
  persistence; application services are used only to provision auxiliary
  admin/other-user session tokens (`Harness.php:28–30`), not to generate compared
  responses.
* **Real authentication and credentials.** Registration/login/refresh/password
  change/logout use the original realm on a real private Keycloak instance.
  Returned JWT claims and TTL are checked, and every issued access token must
  authenticate a real `/users/me` request. PIN/biometric sessions are verified
  through their hashed SQL records and HTTP use; real generated Ed25519
  signatures, invalid assertions and consumed challenges execute
  (`Harness.php:71–85`; `scenarios.php:9–15,39–73,167–176`).
* **Access probes are executed, not just counted.** The 39 nonpublic routes
  receive missing-bearer requests with forged identity/proxy headers; the seven
  admin routes also receive a real ordinary-user bearer. Expected401/403 and
  error codes are asserted before full pair comparison
  (`scenarios.php:19–24`). The actual evidence records matching results for each.

## Normalization and comparator assessment

`Harness.php:93–122` is a small, inspectable normalization surface:

* Generated UUIDs are bound to unique semantic resource labels from real HTTP
  responses, and only explicit ID/reference fields are mapped. Fixture IDs stay
  exact. This preserves cross-resource reference identity; it is not global UUID
  replacement (`Harness.php:22–26,106–122`).
* Runtime timestamps retain null, must be UTC RFC3339 strings and lie within the
  run window; explicit epoch/2020 fixtures remain exact. Birthday is not masked.
  This does not prove relative timing or lockout duration; sampled locks are
  null, as the report already acknowledges (`Harness.php:100–105`;
  `docs/API_PARITY_REPORT.md:138–142`).
* Tokens must be registered as issued and validated before masking. Challenge
  values are exercised through the real signature/replay workflow. Trace IDs are
  the only normalized error field. Demo URLs retain their route and bound
  payment ID, and their capability must verify for that exact payment
  (`Harness.php:71–85,97–99,110–114`).
* Money, quantities, stock, availability, permissions, statuses, business text,
  error code/message/details, pagination and TTL are not masked. Strict leaf
  comparison preserves scalar types and keysets. Separately captured JSON shape
  distinguishes objects/lists, including empty containers, and `bodyKind`
  distinguishes an empty body from JSON `null`
  (`Harness.php:37–42,60–64`; `compare.php:5–11`). Array indexes/order remain
  significant; object key ordering does not.
* Thirteen mutations of captured HTTP events exercise the same normalizer and
  comparator, including changed money, rights, references, null/missing fields
  and empty-body/container shapes (`compare.php:14–36`). The contract log records
  every rejection. These probes complement the live requests; they are not
  passed off as live calls themselves.

No material false-match path was identified for the exercised contracts.

## Six differences: exact DTO requirement versus documented corrections

`compare.php:43–61` pins the complete difference map for each exact case: fields,
statuses and old/new values. Extra differences, disappeared expected differences
or any changed pinned value fail. None of these six enters the matching-success
operation map (`compare.php:62–63`; evidence `api-parity.json:1444–1722`).

| Exact case(s) | Review disposition |
| --- | --- |
| `delta.empty-images-update`, `delta.empty-images-stock`, `delta.empty-images-search` | Genuine `imageUrls:null`→`[]` legacy-wire differences, **not** matches. The unchanged canonical Product schema requires `imageUrls` and specifies a nonnullable array (`openapi.yaml:289–301`). Go's nil-slice cloning explains the observed null responses (`backend/internal/catalog/rules.go:36–37`; `backend/internal/search/opensearch_searcher.go:322–325`). Keeping Yii's array is justified by the canonical DTO; document the source/schema discrepancy rather than demand recreation of the Go defect. Nonempty-image success cases genuinely match; the additional empty-image probes remain visible. |
| `delta.search-stale-stock` | Both stock values are schema-valid, so OpenAPI alone does not select a winner. Yii's SQL rehydration is the previously documented behavior correction (`YII2_IMPLEMENTATION_REPORT.md:261–263`; `backend-yii2/infrastructure/OpenSearch.php:74–76`). The workflow actually reserves stock, then asserts indexed22 versus current19 (`scenarios.php:110–112,126–130`). Retain this exact observable exception; it is not stock normalization or a reason to restore stale responses. |
| `delta.historical-address-delete` | Genuine HTTP503/database error versus HTTP409/`ADDRESS_IN_USE`. Go checks only active orders before reaching the historical FK failure (`backend/internal/shop/postgres/profile_commands.go:195–205`); Yii explicitly rejects any referenced address (`backend-yii2/application/ProfileService.php:56–63`). This correction was already documented (`YII2_IMPLEMENTATION_REPORT.md:255–256`), and address preservation is read back. OpenAPI includes `ADDRESS_IN_USE` but does not explicitly prescribe this historical-delete409 (`openapi.yaml:123,338–343`); the justification is the documented behavior correction, not an invented schema mandate. |
| `pin.refresh-replay` | Genuine message-only difference. Same status, code, envelope and rejection behavior; schema-conformant on both sides. No correctness benefit demonstrated. Minor follow-up M2. |

Thus the harness meets the stated sampled-endpoint requirement. A literal
requirement for identical Go output in every sampled case would still have six
exceptions, and documentation cannot turn them into matches. That qualification
does not require changing legacy Go or reversing the intentional corrections.

## Disposal, integration and evidence

* `scripts/parity.py:14–19,31–55` requires `--disposable`, creates a unique
  `shopkmp-parity-<random>-e2e` Compose project and tears it down in `finally` with
  `down --volumes --remove-orphans`, including on a failed contract/check phase.
  The standalone Compose file has no host-published ports, external networks,
  production env-file reference or production data mount. Its PostgreSQL storage
  is tmpfs; separate `reference` and `migrated` databases receive original
  migrations before equivalent fixtures (`compose.parity.yml:14–21,58–70,90–116`;
  `parity-init.sql:1`; `fixtures.php:6–12,22–33`).
* The buyer identity is removed by exact ID/email on the test issuer; the shared
  test index is cleared between the two sequential runtimes
  (`scenarios.php:179–186`). Neither process queries the other side's business
  database during the differential. `--regressions` deliberately adds a third Go
  process against the migrated **disposable** DB only after comparison, for the
  existing shared-persistence suites (`scripts/parity.py:45–52`).
* `scripts/verify.py:35–36` makes parity unconditional and failure-propagating in
  `make integration-check` (`Makefile:50–51`). No environment skip was found.
  The acknowledged absence of another complete root integration/mobile/build
  run after adding this phase is acceptable for this harness review; the report
  explicitly distinguishes direct phase/PHP evidence from prior broader runs.
* The inspected JSON contains case/operation/status/result metadata and the six
  nonsecret difference maps, not response bodies, tokens, token hashes, private
  keys or capability query values (`compare.php:57,65–67`). All supplied parity
  setup/build/contract/PHP/cleanup logs were inspected; no credential-bearing
  response or production secret was found. Compose's explicit disposable fixture
  credentials are test configuration, not captured production secrets.

### Recorded results inspected, not rerun

The parent executed `python3 scripts/parity.py --disposable --regressions`.
This review inspected its current artifacts and source rather than rerunning
documented suites. No concrete runtime diagnostic requiring another run emerged.

| Evidence | Recorded result |
| --- | --- |
| `deploy/evidence/api-parity.json:2–27` | Capture **2026-10-20 11:25:33Z**;54 matching successful operations;194 pairs,188 matches,108 successful pairs,86 negative pairs;39 missing-bearer and7 admin probes;13 mutation checks. |
| `deploy/evidence/api-parity-contracts.log:3–406` |194 completed cases per runtime; all13 mutations rejected;54/54 matching successful operations;6 scoped differences;0 unexpected mismatches;1 test,0 failures. Successful pairs split into104 matches +4 deltas; negative pairs into84 matches +2 deltas. |
| `deploy/evidence/api-parity-php-all.log:4–142` |77 tests across the recorded suites,0 failures;66 PHP syntax checks. Signed OIDC, concurrency/address races, uploads/CORS/demo payment, real search, deadlines, legacy differential and bidirectional cross-runtime cases are present; cross-runtime is not skipped. Provider rejection/timeout diagnostics accompany passing negative tests. |
| `deploy/evidence/api-parity-cleanup.log:1–30` |All seven long-running containers, including the compatibility reference, and the unique project network are recorded removed. Setup and cleanup logs use the same `951355944f` project as both test logs. |

Only this review document was added. No code, tests or other documentation were
edited, no suites were rerun, and no commit or subagent was created. The workspace
has no Git commits, so revision-based production/native change attribution is
unavailable; the task's stated no-production/native-edit boundary is taken from
the handoff, not represented as independently proven by an empty Git diff.

## Exact suggested corrections to `API_PARITY_REPORT.md`

These are proposed text corrections only; the reviewed report was not edited.

1. **Lines95–96:** replace the final run-identification paragraph with:

   > The latest full parity/regression command passed on **2026-10-20**, with
   > contract capture **2026-10-20T11:25:33Z**, in disposable project
   > **`shopkmp-parity-951355944f-e2e`**. The current JSON, contracts, PHP-suite and
   > cleanup artifacts identify this run. Result:

2. **Lines112–115:** replace the dangling milestone reference with:

   > The latest full parity/regression run exercises the exact normalization
   > boundary below. All rows in `docs/ROUTE_MATRIX.md` name a matching successful
   > paired case and exact access probes where applicable. The matrix checker
   > reads the actual case results and refuses missing/extra operations.

3. **Lines173–175:** add this explanation to the empty-image dispositions:

   > The unchanged canonical Product schema requires a nonnullable `imageUrls`
   > array (`backend/internal/transport/rest/openapi.yaml:289–301`). Yii's `[]`
   > follows that schema; these three cases expose a legacy Go wire/schema
   > discrepancy and remain nonmatching compatibility exceptions.

4. **Line172:** replace "Newly pinned wording difference" with:

   > Avoidable legacy-message drift, retained as an exact visible exception in
   > this test-only task. Replay rejection and error shape agree; aligning the
   > consumed/expired-token wording is a minor compatibility follow-up.

5. **Lines217–219:** replace the potentially overbroad regression statement with:

   > This parity task made no business-code changes. It characterized six
   > existing Go/Yii wire differences: three canonical image-array corrections,
   > two previously documented behavior corrections, and one message-only
   > compatibility follow-up. They are not counted as matches. No
   > production/runtime/native-client source was edited and no commit/subagent
   > was created.

The54/194/188/6/0 counts, normalization limits, root-integration rerun caveat and
non-exhaustive coverage qualification otherwise agree with the reviewed source
and supplied evidence.

---

## Scoped closure — refresh wording and final provenance (2026-10-20)

**M1 and M2 are addressed. Specification: PASS for the sampled mandatory-endpoint
parity requirement. Quality: ACCEPT; no new Critical or Important defect found
in this follow-up.** The preceding six-difference/188-match verdict and artifact
references remain the historical assessment of the earlier capture.

* **M2:** `backend-yii2/application/SessionService.php:35` now uses Go's exact
  `invalid or expired refresh token` for an absent/consumed/expired row. Prefix
  rejection, expiry/hash predicates, atomic DELETE/RETURNING and token rotation
  remain intact. `tests/parity/compare.php:43–50` removes the replay exception,
  rather than masking the message; `scenarios.php:45–46` still executes actual
  refresh/replay. The final JSON's `pin.refresh-replay` is **MATCH**,401 on both
  runtimes, no differences. The documented focused RED→GREEN sequence is in
  `API_PARITY_REPORT.md:241–257`; no reproduction was run by this reviewer.
* **M1:** `API_PARITY_REPORT.md:3–18,112–132,233–261` identifies the current capture,
  separates initial test-only work from the later runtime edit and replaces the
  dangling milestone reference with the actual final verification section. The
  current artifact is **2026-10-20T11:48:38Z**; contracts/PHP/cleanup logs all name
  **`shopkmp-parity-2c8ced471f-e2e`**, replacing the earlier11:25:33Z project/run.

Final inspected results: **54/54 matching successful operations;194 pairs;
189 matches;5 scoped differences;0 unexpected;13 rejected comparator mutations;
77 PHP regressions,0 failures;66 syntax checks**, with bidirectional compatibility
executed and cleanup recorded. The five retained exceptions remain exact,
visible and excluded from matching-success operation coverage. They are three
canonical image-array corrections and the previously reviewed search-stock and
historical-address corrections, not exact Go-wire matches.

Read-only inspection also found the recreated root Yii FPM container healthy;
the deployed SessionService SHA256 matches the workspace correction. Full details,
Android remediation and the remaining documentation-only mobile-summary note are
in the October20 appendix of `FINAL_REVIEW.md`.

Only these two review appendices were written. No tests/builds were rerun, no
app/test/config source was edited, and no commit or subagent was created. This
closes the two scoped parity minors without converting sampled contracts into
exhaustive compatibility or production-release approval.
