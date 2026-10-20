# Final independent whole-system review

> **Historical review — before decommissioning.** The results and source/archive
> comparisons below remain unchanged records of that milestone. The original
> business source/archive and Go comparison harnesses are no longer bundled;
> no new review pass is claimed. See [decommissioning](LEGACY_REMOVAL.md) and
> [deployment](DEPLOYMENT.md) for current checks and actual rollback requirements.

Date: **2026-10-19**. Scope: the Yii2 business API → Go SDUI BFF → shared CMP
application and deployment, especially the installed mobile harnesses, native iOS
security repair, startup correction and CORS changes after the subsystem reviews.

## Verdict

**Specification: ACCEPT for the implemented migration/integration deliverable and
the requested installed development-stack journeys.** PHP business ownership,
HTTP-only Go composition, commonMain rendering/native actions, the 54-operation
route set, eight screens and both installed CARD journeys have supporting source
and recorded evidence. The previously reviewed acceptance blockers are closed.

**Quality: ACCEPT with minor follow-ups — 0 Critical, 0 Important, 2 Minor
findings.** No new blocking regression was identified in the inspected changes.
Exhaustive compatibility verification and production-release/cutover readiness
remain incomplete; this verdict does not turn those separate gates into passes.

## Basis and independence

Read `MIGRATION_PLAN.md`, `IMPLEMENTATION_PLAN.md`, `ROUTE_MATRIX.md`, all three
new reports (`MOBILE_E2E_REPORT.md`, `IOS_SECURITY_BRIDGE_REPORT.md`,
`CORS_REGRESSION_REPORT.md`), their relevant source/tests/build configuration,
saved mobile output and all eight published mobile PNGs. Also inspected the
deployment integration/TLS/worker evidence, PHP full-suite log, BFF live/race
evidence and affected JVM XML. Results below are **previously recorded executions
reviewed here**, not tests independently rerun by this reviewer.

The repository is unborn/untracked; there is no usable SHA range. Compared the
relevant native/startup/UIKit changes directly with `Shop-Go-master.zip`.
An independent byte comparison found **all 171 archived `backend/` files unchanged**,
including the original CORS policy, OpenAPI, migrations and migrator. The invalid
native map casts and original startup ordering are also present in the archive;
the bridge repair addresses an inherited executable defect.

The latest scoped dispositions, rather than the historical opening verdicts, are
the prior-review baseline:

| Review | Latest disposition used |
| --- | --- |
| `YII2_REVIEW.md:381–495` | I1–I3 and M1–M3 addressed; no new Critical/Important finding |
| `SDUI_REVIEW.md:199–313` | All seven findings addressed; bounded content/collections and fail-closed security errors accepted |
| `CMP_REVIEW.md:220–356` | All seven findings addressed; logout, drafts, intent recovery, renderer and refresh fixes accepted |
| `DEPLOYMENT_REVIEW.md:141–228` | Worker-success readiness and production staging findings addressed |

Unchanged subsystem audits were not repeated. No documented suite, build,
deployment or live journey was rerun, and no business mutation/probe was executed.
Only this report was written; no app/test/config edits, commits or subagents.

## Post-review boundary assessment

### 1. Native CoreFoundation / Keychain / biometric bridge — acceptable

* `core/common/src/iosMain/kotlin/com/shopkmp/common/security/CoreFoundationBridge.kt:27–73`
  constructs actual native dictionaries with CFType retain/release callbacks.
  Keys and existing native pointer values are borrowed at insertion; the
  dictionary's retain is balanced by its destruction. They are **not** passed to
  the consuming `useCF` helper as owned values. Objective-C/Kotlin bridge values
  receive a temporary `CFBridgingRetain`, released after insertion. Booleans use
  `kCFBooleanTrue/False`, not NSNumber; the key-size integer remains CFNumber.
  Nested dictionaries remain native. `useCF` releases owned Create/Copy/Retain
  references in `finally`; empty CFData does not dereference element zero.
* `core/settings/src/iosMain/kotlin/com/shopkmp/settings/SecurityKeychainSettings.kt:114–155`
  supplies real dictionaries to add/read/delete. The initialized `CFTypeRefVar`
  out-pointer is correct for `SecItemCopyMatching`; the data-only query permits
  interpreting its successful result as CFData, copying bytes before releasing
  the **owned copied result**. Static `kSec*` constants are not directly released.
  Generic-password/key/private-key classes, key type and match limits remain
  native `kSec*` CFString pointers; return/permanence flags use CFBoolean.
  Service `shopkmp`, account names, UTF-8 scalar values/newline registry and
  `AfterFirstUnlockThisDeviceOnly` match the archive; no storage migration was
  introduced. Independently seeded native values and accessibility are covered by
  `core/settings/src/iosTest/kotlin/com/shopkmp/settings/SecurityKeychainSettingsIosTest.kt:112–165`.
* `core/common/src/iosMain/kotlin/com/shopkmp/common/biometric/BiometricKeyService.ios.kt:79–118,140–212`
  balances access control, private/public key copies, external representation,
  signatures, challenge/tag data and initialized typed `CFErrorRefVar` results.
  Tag `com.shopkmp.biometric.login.es256`, permanent P-256 private key,
  Secure Enclave token, `WhenUnlockedThisDeviceOnly`,
  `BiometryCurrentSet | PrivateKeyUsage`, real LAContext authentication and
  enrollment signing are retained. Unavailable hardware/key/signature paths
  throw; there is no software-key or unauthenticated fallback.
* Native tests inspect actual CF types, nested attributes/context identity and
  retain lifetimes, exercise real isolated Keychain operations and reject
  unsupported/unenrolled Secure Enclave behavior. Services/tags use generated
  namespaces. The recorded **settings 10 + common 12 native cases** are meaningful
  bridge evidence, not physical biometric-success coverage. The native runner
  requires process success, completed test output, positive passes and no failures
  (`core/settings/src/iosTest/run_security_tests.py:15–25`).

The app entitlement section is restricted to **unsigned simulator** builds;
Debug's dylib adjustment is likewise conditional
(`iosApp/iosApp.xcodeproj/project.pbxproj:330–351,377–385`). Signed builds and device
builds do not receive those fallback flags. The entitlement names only this
simulator app's access group. Module test entitlements attach to simulator Debug
**test executables**, not production frameworks. No production security weakening
was identified. Signed physical-device behavior remains a separate hardware gate.

### 2. Startup / logout / fast login — acceptable intentional correction

`feature/main/src/commonMain/kotlin/com/shopkmp/main/InitialSessionRouter.kt:10–31`
now returns Login when both saved tokens are absent, before invoking automatic
biometrics or choosing PIN. `AuthServiceImpl.kt:40–41` defines saved session as
nonblank access **or** refresh token. Persisted enrollment alone no longer
automatically authenticates an explicitly logged-out application.

For saved sessions, biometric → PIN → restore/password ordering is preserved.
The change neither opens Main without authentication nor skips an existing
fast-login check. Logout clears credentials in the already reviewed `finally`
(`feature/auth/auth-data/src/commonMain/kotlin/com/shopkmp/auth/data/AuthServiceImpl.kt:317–327`).
Explicit PIN and available configured biometric login remain wired from Login
(`feature/auth/auth-ui/src/commonMain/kotlin/com/shopkmp/auth/ui/login/LoginViewModel.kt:74–85,134–149`).
This is not removal of fast login.

Two no-session regressions and the four existing startup cases cover the routing
decision; the biometric case asserts zero automatic biometric calls. Saved JVM
XML supports all **31 feature/main cases** after the correction; real native
31-case execution and APK build are recorded in `evidence/ios/regression-summary.txt`.
The final iOS installed test additionally terminates/relaunches and requires Login
with no authenticated navigation. Android's earlier successful launcher re-entry
is not cold-process restoration evidence.

### 3. CORS / authorization / payment capability — original policy restored

`backend-yii2/infrastructure/ApiKernel.php:36–50` matches the original
`backend/internal/transport/http/server.go:209–233` and
`server_cache.go:160–169`: trim origins, ignore blank/wildcard configuration,
exact allowlisting, `Vary: Origin` for nonempty origins, canonical allowed/exposed
headers, reject **unknown-origin OPTIONS**, and let actual requests reach their
ordinary handlers without granting unknown origins response access.

This removes the migration's blanket actual-request rejection, rather than
creating an origin-based identity or authorization exemption. Protected/admin
authentication and throttling still run (`ApiKernel.php:69–80,114–117`); webhooks
still require source/timestamp/raw-body HMAC (`155–163`). Demo payment still checks
the payment-bound HMAC capability before mutation and exists only outside
production with no configured real gateway (`58–64`). No credentials/CORS wildcard
permission or test allowlist workaround was added.

`backend-yii2/tests/cors.php` covers the seven documented boundary groups.
The amended `tests/security.php:13–19,35–42` also tests webhook HMAC/source with
the emulator Origin and rejects cookie/forged-identity authorization across allowed,
unknown, `null` and empty origins. The report records **7 CORS + 7 unit + 6 security
cases** after deployment; the installed Chrome and Safari POSTs then complete.
The older 69-case full-suite log is not relabeled a post-CORS full-suite run.

### 4. Installed mobile harness / selectors / evidence — requested path established

Both tests drive the actual installed common CMP application, not a duplicated
screen implementation: unique UI signup, native six-digit PIN setup, authenticated
Home identity, logout/password login, Catalog search → Product → AddToCart → Cart,
address/CARD selection, local confirmation, native checkout, system-browser
payment, return, Orders → Order Details → Profile → Devices → Logout. Thus all
**eight SDUI destinations** have actual installed UI assertions on both platforms.

The independent verifiers perform real password authentication and business GETs;
they do not register users, write carts/addresses/orders/payments, fetch demo
capabilities, read application tokens/storage or inject sessions. Canonical reads
require zero orders before confirmation, one order afterward, exact decimal total,
quantity, stock decrement, empty cart and CAPTURED/PAID. iOS additionally checks
exact snapshot/address and payment ID/order/method/amount/transaction correlation.
Browser submission is one action, not an automatic payment retry; success return
uses the actual link/native prompt. Specific-order card assertions avoid confusing
the PAID filter with a paid order.

| Saved execution | Evidence inspected |
| --- | --- |
| Android, 2026-10-19 12:14:05Z | `evidence/android/instrumentation.txt`: **1 test, 29.907s, OK**; final journey milestone; order `f513dcf2-1dc8-4bbe-a930-2ad2ab907af8` |
| iOS, 2026-10-19 13:43:59Z | `evidence/ios/xctest-summary.txt`: **1 passed / 0 failed**, xcodebuild0; **156.525s test / 164.212s wall**; all final milestones; order `b819b898-830f-445f-ad00-b27b86de06c4` |
| Native/settings regression | Security report records **22 native + 5 Android/common cases** and linked simulator framework; the 5 JVM cases are corroborated by current XML |

All four PNGs per platform show nonsecret product, checkout confirmation and paid
order/detail content consistent with the recorded journey. No credential, PIN,
token or capability URL was found in the retained mobile artifacts inspected.

Android checks actual JUnit success and failure output, not adb exit alone
(`scripts/run_android_installed_journey.py:51–67`). iOS requires xcodebuild0,
exactly one passed test and the final full-journey milestone, so a passing temporary
diagnostic/selector test cannot become full-journey success
(`scripts/run_ios_installed_journey.py:147–167`). iOS withholds raw activities,
uses a private temporary result directory and exports only named app attachments
before cleanup. This is evidence hygiene, not a claim of erasing global simulator
logs, browser history or legitimate secure application storage.

Selectors are additive semantics on existing UIKit/commonMain controls and actual
renderer cards/chips; Android exports resource IDs at its root. The app uses its
ordinary DI/network/security paths. The simulator reset helper deletes only
`shopkmp/backend_device_id`, retaining tokens and PIN metadata; subsequent login
is still driven through UI. Test URL overrides target Debug/build inputs, with
ordinary defaults retained. No fixture-backed production screen or test-auth bypass
was identified.

## Minor findings / follow-ups

### M1 — Current-status documentation lags the installed evidence

The latest mobile report correctly leads with both passes, but several other
documents still present mobile verification as pending. Update the following
status references to link to `MOBILE_E2E_REPORT.md` and this verdict:

| Location | Needed clarification |
| --- | --- |
| `README.md:72–95` | Add current installed Android/iOS results and runner links |
| `docs/MIGRATION_PLAN.md:209–214` | Close the demonstrated installed journey gate; retain hardware/provider/cutover gates |
| `docs/IMPLEMENTATION_PLAN.md:115–122,164` | Reconcile Task5 checkboxes with its execution evidence |
| `docs/VERIFICATION_REPORT.md:293–305,498–504` | Installed journeys are now evidenced; distinguish Task4 history from current state |
| `docs/IOS_SECURITY_BRIDGE_REPORT.md:5–9,216–225,240–242` | Mark Login-only statements as that earlier scope; full CARD was subsequently authored/executed |
| `docs/CMP_IMPLEMENTATION_REPORT.md:267–279,408–411` | Append the later installed verification link |
| `docs/CMP_REVIEW.md:38–42,352–356`; `docs/DEPLOYMENT_REVIEW.md:225–228` | Preserve historical reviews; annotate the mobile gap's later closure |
| `docs/DEPLOYMENT.md:390–392`; `docs/ROUTE_MATRIX.md:90` | Keep HTTP-only scope statements accurate, but add the separate mobile evidence link |

`MOBILE_E2E_REPORT.md:425–431` also links the now-replaced `orders-created.png`;
label it as a historical unavailable capture or point readers to current paid
evidence. Its older incomplete/NOT VERIFIED entries are a debugging ledger,
superseded by the latest-result section, not current failures. Physical biometric
and real-provider payment claims must not be upgraded along with simulator results.

**Impact:** confusing handoff/status and one historical dead link; no runtime
regression. **Expected documentation check:** current summaries agree on both
installed passes while dated earlier results remain attributable to their scope.

### M2 — Android preflight failures can leave prior successful evidence in place

**Location:** `scripts/run_android_installed_journey.py:36–57`.
Build/install/clear use `check=True` before the evidence reset at lines46–49.
After an earlier successful run, a later build or install failure exits before
replacing `instrumentation.txt` or the PNGs. The command correctly fails, but the
evidence directory still presents the previous run; iOS instead publishes a
RUNNING/no-verdict marker before its build (`run_ios_installed_journey.py:41–45`).

**Correction:** initialize run identity/pending evidence before Android preflight
and record a nonsecret failed-preflight result on failure. **Expected regression:**
start with prior PASS artifacts, fail build/install, and require nonzero exit plus
an explicitly current non-PASS record. This is a source-derived evidence-freshness
issue, not an observed false-green instrumentation run, and does not invalidate
the timestamped successful execution reviewed here.

## Final coverage and release boundaries

Against the full migration plan, the independent business implementation, finite
BFF contract, shared client and deployment boundaries are integrated. The saved
Task4 evidence supports successful/access coverage of **54 operations = 15 public
+ 32 protected + 7 admin**, eight independently schema-validated live screens,
six HTTP commands, transactional/race/replay checks, original Go regressions,
bidirectional persistence compatibility, TLS mechanics and successful scheduled
workers. Task5 now adds the previously missing installed authenticated CARD path.

Remaining coverage is explicit, not additional speculative blocking findings:

* The differential suite is **14 GET DTOs + 3 errors**, not all 54 operations'
  malformed/null/legacy inputs and transitions. Remaining mixed commerce/payment
  interleavings, full JWKS rotation/outage and inherited opaque-session revocation
  limits remain documented. Preserve the original reference.
* One CARD journey per platform does not exercise all six commands through UI,
  every payment method, account-switch/error recovery, manual PIN/biometric login
  after logout, or physical hardware. Simulator native tests establish bridge
  behavior and fail-closed rejection, not Secure Enclave success/cancel/invalidation,
  signed-device entitlements or first-unlock device binding.
* Android's installed PASS predates the shared startup guard. Later JVM/native
  startup regressions and APK build cover that change; an Android post-guard
  cold-process UI run/process-death restoration is not supplied. iOS cold relaunch
  after logout is supplied. Browser return is established; a real payment app or
  production provider's protocol/settlement/ambiguous outcome is not.
* Targeted post-CORS checks are supplied; a new whole-system/full PHP run after
  that narrow correction is not claimed. No concrete integration defect found
  here justifies repeating the already documented suites for this review.
* Existing-data/identity-volume restore and single-writer production cutover,
  backup/PITR/rollback rehearsal, real provider TLS/trust/settlement, sustained
  load/reconciliation/failover and operational alerting remain release work.
  Disposable development/TLS stacks do not establish those outcomes.

**Disposition:** the requested integrated development migration and installed
mobile milestone are acceptable with the two minor follow-ups above. There is
no identified Critical/Important implementation defect requiring another repair
cycle. Exhaustive parity and production release remain separate, honestly open
verification objectives.

---

## Scoped final follow-up — 2026-10-20

**Specification: ACCEPT. Quality: ACCEPT with one residual documentation-only
minor note below. No Critical or Important finding identified.** The Android
evidence-freshness defect is closed; the misleading mobile-unverified status has
been corrected in the current handoff documents. The narrow refresh-wording
compatibility correction and comparator tightening are accepted. This section
supersedes the earlier findings/coverage statements only where described here;
the original review remains a dated record.

### Scope and evidence discipline

Inspected the amended runner, SessionService, parity comparator/scenario and
normalizer, route generator/document, current handoff documents and
`API_PARITY_REVIEW.md`. Reviewed the refreshed Android output/four PNGs, parity
JSON/contracts/PHP/cleanup logs and current startup XML. No tests, builds, journeys
or failure probes were rerun. The only additional runtime checks were read-only
container metadata and deployed-file hashing. Only this appendix and the scoped
closure in `API_PARITY_REVIEW.md` were written; no code/config/test edits, commits
or subagents.

### Prior M2 — Android stale evidence: closed

`scripts/run_android_installed_journey.py:36–61` clears only declared captures,
assigns the current run/device and writes RUNNING **before** build/install/clear.
`CalledProcessError`/`OSError` produces a current, fixed nonsecret FAIL record,
explicitly says instrumentation did not run and returns1. A stopped/interrupted
preflight can no longer leave the previous PASS as current. Actual instrumentation
still requires JUnit `OK (1 test)`, no failure marker and process success.

The parent reports the deliberate `opencode-no-such-device` install failure:
exit1, current FAIL/no instrumentation and no PNGs, followed by a genuine good
run. The helper intentionally replaces prior evidence, so the failed preflight
is not a separately retained artifact inspected here. The current good run is:

* `docs/evidence/android/instrumentation.txt:1–52`: started
  **2026-10-20T11:37:06Z**, **1 test, 30.789s, OK**, all journey milestones;
  order **`bbf0fce7-eb88-4948-b0c2-a1f7db0993ca`**, CAPTURED/PAID and final Login.
* All four current PNGs were visually inspected; paid list/detail show order141,
  quantity1, CARD, exact50.01 and the expected address, consistent with this run.

This supplies installed Android execution after the shared startup correction.
It does not turn launcher re-entry into a cold-process/process-death test.

### Prior M1 — Current mobile status: addressed; small provenance residue

`README.md:72–86`, `MIGRATION_PLAN.md:209–229`, the Task1–5 completion boxes in
`IMPLEMENTATION_PLAN.md`, `DEPLOYMENT.md:390–398`, the iOS bridge result, the CMP
report's subsequent-verification appendix, `VERIFICATION_REPORT.md:3–28`, and
`COMPLETION_REPORT.md` now distinguish delivered mobile verification from hardware,
provider and cutover gates. `scripts/route_matrix.py:117` emits the same mobile
link as `ROUTE_MATRIX.md:92`; the documentation addition is reproducible rather
than an edit that the generator would discard. Historical review conclusions
remain historical; this appendix supplies their later mobile-gap closure.

**Residual Minor — Android summary provenance:**
`docs/MOBILE_E2E_REPORT.md:3–15` still calls the older **29.907s / f513dcf2…** run
“Latest” and links the now-refreshed **30.789s / bbf0fce7…** artifacts. Its historical
`orders-created.png` link at425–431 is also still unavailable. Update that latest
summary to the October20 run and label/remove the obsolete historical attachment
link. Expected check: the summary's time/order agrees with current instrumentation;
historical runs remain clearly dated. This is provenance/link cleanup, not a
missing mobile stage or reason to rerun the journey.

### Refresh wording / comparator / deployed runtime: accepted

`backend-yii2/application/SessionService.php:30–37` now returns Go's exact
`invalid or expired refresh token` on the missing/consumed/expired opaque-refresh
row branch (`backend/internal/shop/postgres/store.go:113–119`). Wrong-token-kind
wording, prefix rejection, hashed lookup, expiry predicate, atomic consumption
and replacement-token transaction remain intact. The rejection remains401 with
`INVALID_REFRESH_TOKEN`; this correction neither issues tokens on failure nor
changes replay/concurrency behavior.

`backend-yii2/tests/parity/compare.php:43–68` has **no** `pin.refresh-replay`
exception. The existing real replay request in `scenarios.php:45–46` therefore
must compare normally; the normalizer does not remove error message/code/details.
Only the five previously reviewed, exact image-array/search-stock/historical-
address corrections remain pinned. They cannot supply matching-success coverage.
The documented exception-removal → message-only RED → runtime correction → GREEN
sequence is in `API_PARITY_REPORT.md:241–257`; the final green artifacts were
inspected, not recreated by this review.

The running `shopkmp-migrated-e2e-yii2-1` was independently inspected as
running/healthy, started **2026-10-20T12:02:17Z**, image
`sha256:70bdd0049ba126aece7c6ddd41c054376a1dab637bf69b4af5db6b773e6b5142`.
Its `/app/application/SessionService.php` and the workspace file have identical
SHA256 **`392358878a33d2afce9a74eaee94b9ae485b94a27c2dce21dd33ed353b3b16a0`**.
This establishes deployed-source provenance and health, not a newly executed
root HTTP regression suite.

### Updated evidence and coverage disposition

The final parity JSON capture is **2026-10-20T11:48:38Z**. Contracts, PHP and cleanup
logs identify the same disposable **`shopkmp-parity-2c8ced471f-e2e`** project:

| Inspected evidence | Current recorded result |
| --- | --- |
| `deploy/evidence/api-parity.json:2–27,526–532,1653–1712` | **54 matching successful operations,194 pairs,189 matches,5 explicit differences,0 unexpected**; `pin.refresh-replay` MATCH/401;39 missing-bearer and7 admin denials;13 mutation probes |
| `api-parity-contracts.log:391–406` | All13 mutations rejected;54/54 matching successes;5 scoped differences;0 unexpected;1 test,0 failures |
| `api-parity-php-all.log:4–142` | **77 tests,0 failures;66 syntax checks**; CORS/browser-form, refresh concurrency and unskipped differential/cross-runtime phases included |
| `api-parity-cleanup.log:1–30` | Final project's seven long-running containers and network removed |
| Current `InitialSessionRouterTest` JVM XML | Six startup cases,0 failures/errors/skips, timestamp **2026-10-20T11:43:49Z** |

The fresh full Android unit task set, Kover **76.5252%**, APK and iOS framework
successes remain parent-recorded results in the handoff; no fresh executions are
claimed here. No raw credential, token, key or payment capability was found in
the refreshed evidence inspected.

The earlier review's **14 GET +3 errors**, pre-startup-fix Android timing and
no-post-CORS-full-PHP statements describe its original evidence, and are now
superseded by the expanded sampled parity, new installed Android run and final
77-case PHP suite. The older six-difference/188-match parity review likewise
predates this accepted message correction. Current handoff counts consistently
use **189 matches/five differences**. The independent parity review's two minors
(run provenance and avoidable wording drift) are closed in its appended scope.

All54 operations now have matching successful **samples**. The five disclosed
corrections remain observable nonmatches; exhaustive input/state/interleaving
parity, physical biometric/signed-device behavior, Android process-death,
real-provider settlement/JWKS outage and restored-data/cutover/rollback/load
verification remain the previously stated release boundaries. No new blocking
repair or broad rerun is warranted by this scoped assessment.

### Coordinator documentation closure

After the scoped review, the mobile latest-result summary was updated to the
actual October20 Android run:30.789s/order `bbf0fce7-eb88-4948-b0c2-a1f7db0993ca`.
The obsolete unpaid screenshot link is now explicitly historical and points to
the current paid-order evidence. This closes the residual provenance/link minor
by documentation-only edits; it is not a claim of an additional independent review.
