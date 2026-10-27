# Final separate backend code review

**Date:** 2026-10-26
**Baseline:** `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`
**Revision:** current uncommitted working tree, including untracked implementation, SQL, tests and documentation.

## Verdict

**Specification: NOT YET SATISFIED for the complete deliverable.** The B1–B7 implementation substantially follows the intended architecture and preserves the existing business/API boundaries. The original R1–R5 and M1 corrections remain present. There is an additional financial reconciliation gap, two recovery/maintenance liveness defects, and incomplete release verification.

**Quality: REQUEST CHANGES; not ready for merge/release sign-off.** The durable-payment, credential-family and exact-peer designs are substantial improvements. They do not require a backend rewrite. Close the focused defects below, retain the financial/security fences, and complete the outstanding verification and image-audit gates.

| Classification | Count | Meaning |
| --- | ---: | --- |
| Critical source defects confirmed | 0 | No new confirmed Critical application defect. This does not clear scanner-classified CRITICAL image findings. |
| High source defects | 1 | F1: independently verified failed void cannot be reconciled to release an owed refund. |
| Medium source defects | 2 | F2: locked session queue head blocks other jobs; F3: maintenance can starve retention behind payment HTTP. |
| High carried-forward security blocker | 1 | A1: unresolved runtime-image vulnerability inventory. |
| Medium verification blocker | 1 | V1: latest strict focused/fairness/runtime/root verification is not green. |

This is an actual separate **AI-agent review**, not an independent human review. No subagents, source changes, commits or pushes were used. The only repository file written by this review is this report.

## Scope and method

Reviewed the complete tracked diff against the baseline and read the untracked additions directly: **77 changed tracked paths and 55 untracked paths before this report**. Scope includes PHP application/infrastructure/controllers, Go BFF/runtime/Yii forwarding, migrations 012–014 and checksums, deployment/network/FPM/scheduler configuration, runners, fixtures, CI changes and generated deployment evidence. No mobile implementation changes are in this diff.

Read `PLAN.md`, `PAYMENTS.md`, `SESSIONS.md`, `TRUST_AND_RATE_LIMITS.md`, `MIGRATION_ROLLBACK.md`, both payment review reports, both session/trust review reports, fix reports, all workstream test-result documents, `RR1_FIX_REPORT.md`, and the latest `LIVE_TEST_RESULTS.md` and `SECURITY_TLS_RESULTS.md`. Older green reports are attributed evidence, not a replacement for later failures. Earlier workstream documents still contain historical coordinator handoffs; the actual integrated source and latest results determine this verdict.

Traced the new `PaymentOperations`, `PasswordSessionRecovery` and `RecoveryService` classes through the existing database transaction wrapper, HTTP client, repository, Commerce, authentication/API and scheduler boundaries. Inspected all new behavioral tests and provider/network fixtures. Parsed and compared all 31 changed evidence JSON files: their changes are generated run identities, data/pagination/component content and journey records, not mobile source or a new public schema contract. Their successful child journeys do not establish root verification success; the runtime artifact was not refreshed by the failed runtime gate (`LIVE_TEST_RESULTS.md:262–269`).

Dedicated read/glob tools failed with `Bun is not defined`; inspection used Python/bash fallback. Full suites were not repeated on the loaded Docker VM. Two small isolated PostgreSQL probes tested newly identified state-machine/queue defects; their provenance and limits are below.

## Strengths and specification assessment

| Area | Assessment of actual source |
| --- | --- |
| B1–B4: payment ownership and durability | PHP retains business ownership; the facade and integer-cent/string-money representation remain. Intent commit, `SENT` claim commit, HTTP outside SQL transactions, guarded application and separate observations are coherent (`PaymentOperations.php:29–46,114–159`). Stable keys are not misrepresented as a production replay guarantee. |
| Cancellation, expiry, reversal and stock | Common lifecycle, persistent cancellation/refund intent, claim-time supersession of an unsent void, and transactional `stock_restored_at` substantially repair the baseline. Completed purchases keep consumed stock. F1 and F3 remain. |
| HTTP/provider correctness | Authorize requires structured, correlated fields and safe redirects; 202 is accepted only as `PENDING`. Reversal requires empty 204. Unexpected/ambiguous responses do not become successful financial reversals. Existing verified-TLS, total HTTP deadline and no mutation retry remain. The missing manual negative-outcome path is F1, not permission to treat a 4xx as automatically safe. |
| Webhook and transaction correlation | Initiation/linkage checks, early callback quarantine, post-lock deduplication and atomic webhook/stock changes remain. Late capture is retained and compensation intent survives. No provider lookup endpoint or automatic `SENT`/`UNKNOWN` replay was added. |
| B5: opaque sessions | Server-derived credential UUIDs, persistent families/security versions, same-family refresh, user-first serialization and credential/user-wide revocation are present. Terminal lockout/deletion revokes descendants without revoking unrelated credentials/users. Token storage is digest-only. |
| Password-change partial outcomes | Committed opaque revocation/fence precedes remote reset; safe stored-user cleanup, advisory locking, durable uncertainty and atomic completion/unblocking remain. `RESET_PENDING` is not automatically replayed or unblocked. F2 concerns progress of other due jobs, not a fence bypass. |
| B6: logout/Keycloak | Local cleanup precedes remote work; remote targeting uses the authenticated subject rather than an arbitrary refresh token. Exact response semantics and M1's service-account error mapping remain. Live 26.3.3 online/offline cleanup is reported green. Offline JWT acceptance until expiry is explicitly separate from immediate opaque revocation. |
| B7: original client/rate trust | Exact edge/BFF peers, typed request context, ingress-only BFF egress, finite proxy/FastCGI headers, dedicated FPM admission and `NET_RAW` drops survive the overlays. Webhook source trust is separated from BFF rate context. Latest seven-check dual-stack/two-BFF/raw-FastCGI harness is reported green. |
| Migration constraints/backfill | One logical operation/kind, one live operation/payment, unique transaction ownership and family/user composite FKs are present. All legacy external `PENDING` rows are quarantined; cancelled-order stock guards are backfilled; unlinked legacy opaque tokens are deleted. 014 preserves pending fences and adds bounded scheduling metadata. Original 001–011 bytes are unchanged and all 28 manifest entries match. |
| Rollout/rollback | Draining old writers and forced legacy-token reauthentication are explicit. 012 refuses every downgrade before destructive work; 013/014 refuse pending session recovery. Roll-forward policy is appropriate for irreversible provider effects. |
| Integration/CI | Recovery is separate from search and included in start/drain/health/runtime paths. Focused fixtures use child-only gateway settings, exact callback peers, read-only migration/scheduler mounts and finally cleanup/worker restoration. CI invokes the combined runner and corrected wrapper argv. Remote CI has not been executed for this snapshot. |

### Prior findings and RR1

- **R1–R5: remain addressed in their reviewed scope.** Current anchors: legacy quarantine `012_payment_operations.up.sql:51–61`; refund intent `PaymentOperations.php:48–58,100–104`; READY-void supersession `:120–127`; separate response observations `:138–147`; unconditional downgrade refusal `012_payment_operations.down.sql:1–10`. F1 below is a different case: a **sent** void whose failure has subsequently been independently established.
- **M1: remains addressed** at `Keycloak.php:43–55`; user-grant error behavior is retained. The current Keycloak hash matches the re-reviewed fix.
- **RR1: the original payment-before-session starvation is corrected in source.** `RecoveryService.php:35–53` admits a session first, then one payment; bounded dispatch suppresses chained HTTP. This is not an unconditional fairness approval: F2 is independently reproduced, and the rebuilt six-case RR1 suite most recently failed three cases. Producer source-mounted 6/0 and rebuilt 6/3 are separate evidence.

## Findings — Critical

No additional Critical application-source defect confirmed in this review. See A1 for unresolved scanner-classified CRITICAL runtime findings.

## Findings — High

### F1 — Verified failure of a sent void has no supported reconciliation outcome; captured funds remain blocked from refund

**Primary:** `backend-yii2/application/PaymentOperations.php:228–242`, especially `:232`.
**Related:** `:27–34,100–104,133–136,200–209`; `backend-yii2/tests/payments.php:237–240`; `docs/backend-fixes/PAYMENTS.md:89–117`.

The single-live-operation fence correctly prevents a refund while a sent void is uncertain. However, the console reconciliation path can close a reversal only with **`REVERSED`**. It cannot record the independently verified fact that the original void was declined/not executed and the capture still needs a refund. Although `FAILED` is accepted by the outer outcome allowlist, a void/refund with that outcome is rejected at line 232 as `INVALID_TRANSACTION_ID`.

A supported lifecycle reaches this state:

1. Authorization succeeds; cancellation commits local cancellation/stock restoration and sends a void.
2. The void receives an unconfirmed response or loses its response, becoming `UNKNOWN`.
3. A correlated capture arrives. It is correctly retained as `CAPTURED`; cancellation intent remains and refund dispatch is blocked by the uncertain void.
4. The stale sender is quiesced and the provider independently establishes that the void failed without reversing the captured funds.
5. There is no truthful supported reconciliation value that closes that void. `FAILED` is rejected; `REVERSED` would assert an effect that did not happen. Recovery never creates the refund while this `UNKNOWN` row remains live.

**Fresh isolated reproduction:** actual Database/Commerce/PaymentOperations with only the gateway replaced by a deterministic boundary double, in a disposable schema:

```json
{"verified_failed_void_reconcile_error":"INVALID_TRANSACTION_ID","payment_status":"CAPTURED","void_state":"UNKNOWN","refund_operation":null,"provider_attempts":["authorize","void"]}
```

The probe confirms the application transition gap, not a real provider's negative-outcome guarantee. Existing real-HTTP tests already establish that unconfirmed reversal responses enter `UNKNOWN`. The current uncertain-void test asserts blocking/manual visibility but does not test resolution after independently verified failure.

**Impact:** an owed refund cannot proceed through the documented recovery interface even after uncertainty is resolved. Operators must leave captured funds pending or bypass the lifecycle with manual data/provider intervention. This is financial recovery correctness, not merely slow verification.

**Action:** add an explicit, audited console-only disposition for an independently verified, final **no-effect/failed** reversal. Require quiesced senders and independent evidence; preserve transaction ownership and the original send/observation history. Closing a verified failed void must atomically release/materialize the required refund intent. Do not automatically derive this disposition from a timeout/4xx, mark an unsuccessful reversal as `REVERSED`, reset it to `READY`, or replay the void.

**Regression:** sent/uncertain void → correlated capture → verified failed/no-effect void reconciliation → fresh-worker recovery. Assert one void attempt, exactly one eventual refund, retained evidence, no competing sends and once-only stock. Retain the unresolved case as blocked and verify completed-order stock policy where applicable.

### A1 — Runtime-image security findings remain an uncleared release blocker

**Evidence:** `docs/backend-fixes/SECURITY_TLS_RESULTS.md:76–102,124–160`.
**Runtime references:** `backend-yii2/Dockerfile:1–5`; `deploy/keycloak/Dockerfile:10`; `docker-compose.yml:31,69,119,170,269`.

This is a **carried-forward audit blocker**, not a newly demonstrated exploit introduced by B1–B7. The recorded final scans still contain these package/advisory occurrences:

| Runtime | CRITICAL | HIGH |
| --- | ---: | ---: |
| PHP Yii2 | 7 | 291 |
| Keycloak 26.3.3 | 8 | 81 |
| nginx | 2 | 58 |
| PostgreSQL | 1 | 34 |
| OpenSearch | 19 | 431 |

The audit already distinguishes architecture-inapplicable nginx X.509 findings, Debian package classifications, build/header detections and non-network gosu code from demonstrated reachability. Those distinctions do not clear the remaining inventory. In particular, the Keycloak reset-flow account-takeover advisory and Java/runtime findings require explicit vendor/applicability review. Private networks and verified TLS do not remove them.

**Approved ops dependency correction is recognized:** current `tools/ops/go.mod:11–12` has `x/sync v0.22.0` and `x/text v0.41.0`; the user reports compatible official module metadata and green govulncheck after that correction. Fresh `go mod tidy -diff` and `go mod verify` pass. The old audit's `x/text v0.29.0` source finding is therefore superseded, not a current source defect. Its earlier migrate-image/binary scan describes the old artifact; carry the rebuilt artifact/rescan provenance into the final evidence rather than assuming the source update clears every image finding.

**Action:** obtain an explicit disposition/remediation for the unresolved runtime findings, using supported image/base refreshes and targeted compatibility/TLS/provider tests where changes are required. Refresh exact artifact identities and scans after remediation. Do not present Composer/govulncheck green or functional TLS smoke as a clean runtime-image audit. This report does not authorize an unreviewed runtime replacement.

## Findings — Medium

### F2 — A busy first session operation prevents later due sessions from being considered

**Primary:** `backend-yii2/application/RecoveryService.php:36–42`.
**Related:** `backend-yii2/application/PasswordSessionRecovery.php:16–24,55–59`; `docs/backend-fixes/RR1_FIX_REPORT.md:108–116`; `backend-yii2/tests/recovery.php:52–58`.

The due query selects **one row before trying its advisory lock**. If that row is locked, `continue` exhausts the one-element list. No reservation, retry-time change or continuation selects the next due row. Every subsequent scan, including another replica, can select the same head again.

An active live password flow publishes `REVOKE_PENDING` while retaining this advisory lock, without an automatic worker reservation. A slow/paused live or manual recovery owner can therefore hold the queue head eligible but unavailable. The configured SQL transaction timeout does not bound a session-level advisory lock held outside a transaction. Worker crash leases help already-reserved automatic jobs, but do not solve this pre-reservation/live-owner case.

**Fresh real-PG/actual-worker reproduction:** two due jobs; hold only the older job's advisory lock on a second connection; healthy Keycloak fixture for the second job:

```text
scan 1: completed=0, failed=0, pending=2, attempts=[0,0]
scan 2: completed=0, failed=0, pending=2, attempts=[0,0]
scan 3: completed=0, failed=0, pending=2, attempts=[0,0]
control, exclude head with a live reservation: completed=1,
    second job COMPLETE, recovery_attempts=1
```

All three starved scans return successfully. Existing two-worker tests cover one reserved job; they do not prove progress past an unreserved busy head. The documented rank-based fairness bound does not hold in this case.

**Impact:** unrelated users remain fenced and provider cleanup remains unattempted even while scans report success and healthy capacity exists. Opaque revocation stays fail-closed; this is liveness, not an authentication bypass.

**Action:** keep the one-actual-session-attempt/time budget, but consider a bounded sequence of candidates and skip advisory-busy entries within the admission deadline. Alternatively implement an equivalent lock-aware claim scheme without holding SQL transactions across HTTP. Do not consume another job's retry allowance merely because its owner is active.

**Regression:** hold the oldest eligible job's live-flow advisory lock across several scans and two worker replicas; verify a later healthy due job progresses within a bounded number of ticks, the held job is untouched and no session is completed/unblocked without confirmed cleanup.

### F3 — The maintenance worker can exhaust its deadline before any retention cleanup

**Primary:** `backend-yii2/application/MaintenanceService.php:14–25`.
**Related:** `backend-yii2/application/PaymentOperations.php:84–91,106–111,249–269`; `docker-compose.yml:211–228`; `deploy/php/scheduler.php:32–39`; `backend-yii2/infrastructure/HttpClient.php:8,26–35`.

RR1 bounds the dedicated combined recovery worker, but scheduled `maintenance/run` still synchronously dispatches reversals for up to **1,000 expired orders**, then invokes the unbounded/default **1,000-candidate** payment recovery pass. Only afterward does it clean idempotency entries, processed webhooks, expired access/refresh rows, challenges, rate hits and login attempts.

Its default parent budget is **120 seconds**; each allowed payment HTTP attempt can consume **10 seconds**, and an expiry can also perform chained work. Thirteen sequential timeout attempts already exceed that budget before cleanup. Under sustained expired/payment backlog, each tick can repeat this ordering and never reach retention, while the dedicated recovery worker does not perform that cleanup. Durable progress on some cancellations does not make the maintenance scan complete.

**Evidence:** source-confirmed call order and runtime bounds; no new 120-second outage/kill reproduction was run on the loaded VM. This is distinct from the reported mixed-backlog session test failures and is not attributed to VM load.

**Impact:** recurring maintenance failure and indefinite retention/expired-row accumulation during a payment incident. The payment send fences and stock guard still protect completed transactions; the defect is the maintenance worker's work sizing and cleanup progress.

**Action:** make scheduled expiry/recovery admission budget-aware and guarantee a bounded retention slice, or defer committed reversal intents to the dedicated payment worker. Bound both expiry dispatch and the trailing recovery call; fixing only one leaves the other starvation path.

**Regression:** actual maintenance scheduler with enough delayed/failed payment work to exceed a reduced supported budget, plus expired retention records. Verify an in-budget successful scan, cleanup progress across ticks, remaining intents durably eligible, and no replay of `SENT`/`UNKNOWN` effects.

### V1 — Strict rebuilt-source/root/runtime verification remains incomplete or failed

**Evidence:** `docs/backend-fixes/LIVE_TEST_RESULTS.md:147–196,293–320`; `docs/backend-fixes/SECURITY_TLS_RESULTS.md:56–64`.
**Gate locations:** `scripts/verify.py:35–64`; `scripts/backend_fixes.py:37–40`; `scripts/runtime_checks.py:32–38,71–88`; `.github/workflows/ci.yml:84–117`.

This is an **acceptance/verification blocker**, not a claim that every observed timeout proves an application state-machine defect. The latest evidence explicitly records:

- Root `make integration-up`: passed with final RR1 source. Root `make integration-check`: **no successful exit**; the last full invocation exceeded the 1,200-second outer limit.
- Payment lifecycle: **107 cases / 2 failures**, then **107 / 3 failures, exit 1**. Failures include real SQL/connect timeouts and unchanged strict 10.8-second bounds.
- RR1 scheduling: **6 cases / 3 failures, exit 1**. A diagnostic still failed with SQLSTATE **57014**; the healthy session completed in that diagnostic, but the scan failed. Earlier rebuilt execution left it pending with zero attempts.
- Search reconcile readiness failed; the automatic runtime gate exited 1 before its shutdown/restart portion. Healthy empty-queue recovery replicas/restart do not replace this missing gate.
- Full scheduler evidence is **18/0 at fixture timing scale 3**; a complete final default-scale-1 rerun is not recorded. Remote CI is not recorded as run.
- Trusted-CA TLS functional smoke passed with an explicit **test-only resource override**; default resource readiness/capacity remains unproven on the shared 8 GB VM.

The independently passing shared PHP **154/0**, live Keycloak **4/0**, Go race/live contracts, trust **7 checks**, payment migration **13/0**, payment HTTP **4/0** and existing recovery **9/0** are valid reported successes for those phases. They cannot be combined into a fictional root pass. Likewise, RR1 producer source-mounted **139 passing cases** do not supersede the later rebuilt failures.

**Action:** after the focused corrections, rerun affected strict regressions and the required coherent root/runtime gate on a responsive isolated environment. Diagnose any persistent SQL/time failures without weakening financial assertions or disabling health/deadline checks. Preserve actual exits, skips, artifact hashes and resource qualification. Obtain remote CI results separately when it is actually run. Keep this gate blocked until those results exist; do not classify all failures as harmless load without evidence.

## Financial facts under bounded recovery timeouts

This interaction was specifically inspected, rather than inferred from a green producer report:

1. `RecoveryService.php:28–32` temporarily sets 1-second statements, 800ms lock waits and PostgreSQL-17 5-second whole-transaction timeout. `PaymentOperations.php:249–269` admits one candidate and bounded dispatch sends at most one effect.
2. The send claim commits before HTTP (`PaymentOperations.php:116–133`). An accepted response is appended in a separate autocommit observation before financial application (`:138–142`).
3. If a multi-statement stock/inbox/apply transaction is terminated, its operation/payment/order/stock changes roll back together. An already committed observation and `SENT` claim survive. Fresh recovery converts expired `SENT` to `UNKNOWN`, not `READY`; it does not resend. The producer's slow-stock test explicitly checks these facts (`tests/recovery-scheduling.php:78–110`), but its latest rebuilt elapsed-time run is still failed evidence under V1.
4. **The observation insert is itself fallible.** The latest live logs report its statement timing out (`LIVE_TEST_RESULTS.md:190–196`). In that case, the committed send fence survives, but this code cannot promise the normalized response was retained. Independently obtained provider facts/manual reconciliation remain necessary. Never infer no provider effect from missing observation/result fields.
5. Connection termination is surfaced as scan failure; restoration attempts preserve the original failure (`RecoveryService.php:60–64`). It is not valid to call a timeout a successful cleanup. F1 remains relevant when independently recovered facts establish a negative reversal outcome that the console cannot currently represent.

No new blind-replay, stale-result authority or double-stock-restoration defect was established in this timeout path. That source assessment does not turn the failing strict live regression into a pass.

## Fresh checks performed by this review

| Check | Result / limits |
| --- | --- |
| Full diff/untracked inspection and generated-JSON comparison | Completed source/schema/config/test/document review; all 31 changed evidence JSON files parsed and compared. |
| Payment F1 isolated state-machine probe | Exit 0 **confirmed the defect**, not a passing payment suite. Actual application/SQL paths; deterministic gateway double only; temporary `final_review_*` schema. |
| Session F2 isolated queue probe and positive control | Exit 0 **confirmed starvation** over three actual `RecoveryService::run()` calls, then successful second-job completion when the head was excluded. Real PG connections/advisory lock and real loopback Keycloak HTTP fixture. |
| `python3 -B scripts/test_backend_integration.py` | Exit 0, **6/0**; resolved overlays and mocked runner subprocess boundaries, no application stack restart. |
| `python3 -B scripts/route_matrix.py --check` | Exit 0; **54 exact operations/policies, eight screens, six commands**. |
| `go -C tools/ops mod tidy -diff` | Exit 0; current module requirements/sums are tidy, no source edits. |
| `go -C tools/ops mod verify` | Exit 0; all modules verified. This is not a vulnerability scan. |
| Migration byte/checksum comparison | All **22 original SQL files** byte-identical to baseline; **28/28** current manifest hashes match. No migration history was rewritten. |
| Isolated DB provenance/cleanup | `payment_fixes` has normal versions 001–014; review schemas are dropped, with final read-only namespace cleanup check. No other stack was stopped/rebuilt. |
| Whitespace | `git diff --check` passed before report creation; report checked separately afterward. |

The first queue-probe invocation was rejected by zsh's unquoted `?` before Docker ran; the correctly quoted retry produced the evidence above. It is not counted as an application failure. Review probes used read-only source/vendor mounts and did not write test files or alter existing public business rows. Temporary table clones were for focused reproductions, not a fresh canonical-migration/constraint rehearsal.

## Reviewed snapshot and remaining gaps

The two RR1 production hashes match the latest rebuilt/producer evidence:

```text
03a402f44568cc2b2d58f31d360dc6a36766f853ca54b8d56f4ec4b29df64093  backend-yii2/application/PaymentOperations.php
6a06fb880af738907bfc8fd93a6505ffc5f2ed0e33795fd409cafad49833dc2b  backend-yii2/application/RecoveryService.php
bb33c4c6731fe38438ba097351f78c37e9002bf4af236df444a58c99a7bdb8df  backend-yii2/application/PasswordSessionRecovery.php
7dc9d4069c001108e5e88d415c1b551f00557578cb1738c17299d19a6976ffe7  backend-yii2/application/SessionService.php
d5b11f76ca9ecbfaafae36ee051ac6fd8b504a0eb0bc91a9d4cc8f91c0e11df3  backend-yii2/infrastructure/Keycloak.php
1cc8e928a4aa27bbafbc95cafd17dd3d98b4e366bd0c862bf14b0ca54aac9346  tools/ops/go.mod
b90c1ce39e44b933baf4b2d6c8674e41f9d66d8b71b1d94e93923d3441c5f469  tools/ops/go.sum
```

Reported migration/concurrency/provider/TLS/security results were trusted as attributed evidence where permitted. This review did not rerun the full PHP/Go/live suites, scanner fleet, production-sized migration/capacity tests, live five-minute JWT-expiry wait, production cutover/rollback or remote CI. No real payment provider's lookup, replay or negative-outcome contract was independently certified. The absent historical `ALL_BACKEND_REVIEW.md` noted in `PLAN.md:11` was not reconstructed; supplied task boundaries and the current specification documents guided this review.

**Completion criteria:** resolve F1–F3 with focused regression evidence; obtain an explicit A1 disposition/remediation and current artifact scans; complete V1 with honest root/runtime/CI outcomes. Preserve the current coherent backend, existing public contracts, financial audit and security fences throughout.
