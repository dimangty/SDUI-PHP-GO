# Scoped final re-review — F1–F3

**Date:** 2026-10-27. **Baseline:** `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`; current uncommitted source identified below.

## Verdict

**Specification: PASS for the F1–F3 correction set, with the documented bounded-look-ahead and manual-settlement policies.**

**Quality: ACCEPTABLE within this scope. F1, F2 and F3 are ADDRESSED. No remaining or fix-introduced Critical, High or Medium defect confirmed in the reviewed corrections.** This is scoped source approval, not overall release approval: **A1 remains a security-audit blocker**, and the final rebuilt full-stack verification of these new bytes is pending.

This separate AI-agent re-review inspected actual application/test changes and their immediate dependencies; it is not independent human review. Only this report was written. No source edits, subagents, commits, pushes, stack operations or broad suite reruns were performed. `FINAL_CODE_REVIEW.md` remains the historical review; the findings dispositions below supersede its F1–F3 status for this snapshot.

## Per-finding assessment

### F1 — ADDRESSED: verified no-effect reversal disposition

**Source:** `backend-yii2/application/PaymentOperations.php:221–255,283`; `backend-yii2/application/RecoveryService.php:59`.
**Regressions:** `backend-yii2/tests/payments.php:332–397`; `backend-yii2/tests/recovery-scheduling.php:200–208`.

- `NO_EFFECT` is console-only, restricted to `UNKNOWN` reversals with a recorded send, the original transaction and a still-owned transaction link. It requires evidence and is explicitly rejected for authorization. It is not inferred from HTTP failure, timeout or lease expiry.
- Under the existing aggregate/operation locks, disposition records `ABORTED` plus structured outcome/evidence/time, preserving the key, send timestamp, lease ownership and observations. The same transaction invokes compensation, so a failed void can release one owed refund without changing `CAPTURED` into a fictitious successful reversal. An intent-commit failure rolls back both changes.
- A failed refund remains `CAPTURED`, cannot be replayed through recovery/admin/direct dispatch, and remains visible in both detailed manual review and combined count-only reporting. The unique kind/key and `UNKNOWN`-only reconcile entry prevent rewriting its final no-effect evidence as later success.
- Inspected tests cover CARD/SBP, actual console reconciliation, missing evidence/foreign transaction rejection, commit failure, audit preservation, repeat rejection, fresh-connection recovery, one request per effect, stock restoration, and paid/completed failed-refund policy. The prior unresolved-void blocking case remains.

**Residual policy:** operator attestation must establish final provider no-effect and sender quiescence; source cannot verify those external facts. A separately executed manual settlement of a failed refund has no current command to record that distinct effect as local completion. The documented policy preserves the original audit and leaves the financial/manual alert outstanding until a separately reviewed forward reconciliation (`PAYMENTS.md:110–118`). This limitation is explicit, not a claim that money was returned.

### F2 — ADDRESSED: skip advisory-busy candidates without consuming another attempt

**Source:** `backend-yii2/application/RecoveryService.php:35–54`; `backend-yii2/application/PasswordSessionRecovery.php:55–79`.
**Regressions:** `backend-yii2/tests/recovery-scheduling.php:165–199,210–224`.

The materialized CTE first selects/sorts at most **25 due candidates**. The outer volatile advisory-lock filter has `LIMIT 1` and no consuming sort above it, so selection skips busy owners and stops at the first acquired lock. The prior one-row-before-lock starvation is removed within that window, without 25 separate round trips or 25 acquired locks.

The deadline is rechecked inside the selected job's `try/finally`; even admission deferral releases the selected lock. Reservation revalidates eligibility, and the existing nested recovery lock is balanced with the outer selection lock. One actual attempt per scan, retry/backoff/crash reservation, no transaction across HTTP, and atomic fence release remain intact.

Inspected regressions exercise two real CLI replicas over three scans while the oldest job is locked, verify later progress and the head's unchanged attempts/fence/provider count, check other candidates' locks are obtainable, and verify no advisory locks remain on a reused worker connection.

**Residual bound:** **25 busy heads can still defer row 26 indefinitely while those owners remain busy.** This is bounded look-ahead, not unrestricted queue fairness. The implementation and `MIGRATION_ROLLBACK.md:92` now say so; the original recommendation allowed a bounded candidate sequence. Monitor prolonged busy owners and overdue jobs rather than bypassing their locks or claiming a universal rank-based progress guarantee.

### F3 — ADDRESSED: retention first; scheduled expiry performs no provider HTTP

**Source:** `backend-yii2/application/MaintenanceService.php:11–49`; `backend-yii2/application/PaymentOperations.php:84–93`.
**Regressions:** `backend-yii2/tests/recovery-scheduling.php:225–312`; `backend-yii2/tests/payments.php:45–56,398–406`.

Maintenance now commits one **≤1,000-row slice per seven retention tables first**, selects at most **100 expiry candidates**, and checks a monotonic deadline before each local expiry transaction. Its scoped 1-second statement / 800ms lock / 5-second transaction limits are restored on completion or ordinary error; disconnect cleanup preserves the original failure. The documented minimum job budget is 40 seconds, with blank/default 120.

`expire(..., false)` keeps cancellation, stock restoration and reversal intent atomic but performs no dispatch. The trailing full recovery pass is removed. Ordinary internal `expire()` callers retain synchronous dispatch by default; the updated expiry matrix explicitly checks durable intent first and actual recovery afterward, preserving its eventual reversal assertions.

Inspected tests use the actual maintenance scheduler with 105 expired payments and delayed provider scenarios, retention backlogs exceeding a slice, live records, and `SENT`/`UNKNOWN` sentinels. They require a successful bounded scan, 100 then five local expirations, no maintenance reversal POST, subsequent dedicated-worker dispatch, and once-only stock. A slow-stock transaction test verifies timeout rollback while earlier retention commits survive, followed by successful retry and setting restoration.

**Residual bound:** compensation now depends on the dedicated recovery worker. Cleanup throughput is capped per tick and local SQL failure still fails a scan; a persistently failing expiry needs operator attention. These limits do not promise production-sized backlog drainage or capacity. Payment HTTP can no longer consume the maintenance retention slice.

## Evidence and gate chronology

The following are **coordinator-reported actual current-source executions**, supplied with this re-review request, not suites independently rerun by this reviewer:

| Suite | Result |
| --- | --- |
| Recovery scheduling | **11/0, exit 0**; actual bounded apply **5.035s**, maintenance scheduler **0.506s**, recovery scheduler **8.84s** |
| Payments | **113/0, exit 0** |
| Existing recovery | **9/0, exit 0** |
| Payment migration | **13/0, exit 0** |
| Payment HTTP | **4/0, exit 0** |

**150 passing cases** across those five suites support this scoped verdict. Test source was read to confirm meaningful financial, ownership, timeout, lock-release and persistence assertions, rather than accepting counts alone.

- The accidental integration invocation inherited the external gateway and produced **11 cases / 1 failure** in a suite expecting demo mode. Preserve that setup-mismatched attempt; the clean-environment rerun was pending at the request checkpoint. No successful integration rerun is assumed here.
- `LIVE_TEST_RESULTS.md:417–501` records a genuine earlier full `make integration-check` **exit 0 in 1308.67s**, including runtime shutdown/restart, after resource headroom was restored by stopping only the coordinator's TLS stack. This supersedes the earlier failures **for that older snapshot**. Its payment/scheduling counts were **107/0 and 6/0**; it predates these F1–F3 changes and is not a current-final-source root pass.
- Final rebuilt-source root/runtime verification is **forthcoming**, not failed by inference and not passed by inheritance. Remote CI and release authorization are separate gates.
- The approved ops `x/text 0.41.0` / `x/sync 0.22.0` correction and reported clean source govulncheck are recognized. **A1's unresolved runtime-image inventory remains blocking**; source dependency green and an older functional root pass do not clear it. No push/release approval is given.

## Review verification and snapshot

Read the complete current three production classes, changed payment/scheduling tests, existing recovery tests, console entry points, transaction wrapper and password recovery lock handling; cross-checked the updated payment/rollout policies and earlier full-root chronology. No migration or public payment-enum change is needed for these fixes; `ABORTED`'s expanded audit meaning is documented (`PAYMENTS.md:18–24`).

Fresh checks: `git diff --check` and `python3 -B scripts/route_matrix.py --check` both **exit 0**; the latter retains **54 operations/policies, eight screens and six commands**. Source hashes below identify the reviewed bytes. These checks are not represented as fresh behavioral/full-stack execution.

```text
a28fc2baab6516a564462144c77d363264256e8717101211f5217a920fc1e426  backend-yii2/application/PaymentOperations.php
3d18e0bc02859bd6220f61f8d2b1562b3c1aec716ddcb5cebbbf5b4a61fda337  backend-yii2/application/RecoveryService.php
e744ffa7f74a8d2da35f1aa41fc3ece3135beb6520ea034a1b1e4154543aa240  backend-yii2/application/MaintenanceService.php
c157bbc98541003cca391e17fed030a79eda4089474688cf4614769468947325  backend-yii2/tests/payments.php
2340a82bd13c4c6a3b395d48b610a0be72a0a4e4a7aea0337eae9378577e49f2  backend-yii2/tests/recovery-scheduling.php
```

The interrupted fix agent's linked `FINAL_FIX_REPORT.md` was absent at inspection; it was not used as evidence. This report supplies the scoped assessment without inventing that handoff. Complete current-snapshot rebuilt verification and A1 disposition remain required for overall sign-off.

## Narrow final follow-up — error-detail wire type, OpenAPI and PKI (2026-10-27)

**Scoped specification: PASS. Quality: ACCEPT with one minor documentation clarification below. No Critical/High/Medium regression confirmed in these changes.** F1–F3 approval remains intact. **Overall release remains BLOCKED by A1; no commit/push approval.** This appendix updates the earlier pending-verification chronology without erasing it. Only this report was appended; no source edits, subagents, commits or full-suite reruns were performed.

### Source and regression assessment

- **Recovery detail: corrected.** `PasswordSessionRecovery.php:41–47` now emits `recoveryRequired: "true"` and the string operation UUID. This matches unchanged `ErrorEnvelope.details.additionalProperties: string` (`contracts/openapi.yaml:336–347`) and the existing CMP `ApiError.details: Map<String,String>?` (`core/network/src/commonMain/kotlin/com/shopkmp/network/ApiError.kt:11–15`). The HTTP boundary forwards these values without conversion (`ApiKernel.php:95–99`). A hash reconstruction confirms the production class differs from the previously reviewed version only by this literal-type correction and its comment; recovery/fence/error classification behavior is unchanged.
- **Regression assertions are meaningful.** `tests/session_password_recovery.php:29,72` require the exact string and verify every detail value is a string across partial remote outcomes and SQL-disconnection recovery. Existing durable-state, token rejection, restart and safe recovery assertions remain.
- **OpenAPI: additive documentation.** Logout/password operations now explicitly describe provider 502/503, password 409, local/remote scope and JWT limitations (`openapi.yaml:27–33,121–127`). Authentication requirements, request references and operation IDs remain. The entire `components` section is byte-identical to baseline: no new model/public enum or widened error-details type.
- **PKI range: corrected.** `scripts/test_pki.py:46–50` derives the upper-half allocation CIDR from the requested FPM subnet, rather than inheriting an unrelated example range. Existing minimum-eight-address validation ensures fixed Nginx offset 3 is outside that half. The regression expects `172.31.223.128/25` for the custom `/24` (`scripts/test_test_pki.py:23–35`) and retains private-file/CA checks. Fresh in-memory arithmetic checks confirmed containment, half-size and offset-3 exclusion for every accepted IPv4 prefix `/1` through `/29`; peer trust/admission is not broadened.

**Minor documentation clarification — `backend-yii2/contracts/openapi.yaml:31`:** qualify “502 or 503 means local cleanup committed” as applying to the post-cleanup `IDENTITY_PROVIDER_ERROR` / `IDENTITY_PROVIDER_UNAVAILABLE` path. A generic 503 is not proof of committed logout: `DATABASE_UNAVAILABLE` also maps to 503 (`domain/ApiError.php:20`), and SQL failure can precede local revocation. The implementation remains correct; consumers should inspect the error code rather than infer cleanup from status alone. This is a wording follow-up, not a reason to repeat the full backend suites.

### Evidence and current gate distinction

Coordinator-reported actual executions, accepted as attributed evidence rather than independently rerun here:

- Wire regression **RED: 10 cases / 5 failures** (expected string, received boolean), then password recovery **10/0**, session revocation **7/0**, logout **41/0**, PHP lint **79 files**, route matrix **54 operations**, all GREEN exits 0.
- PKI regression **RED: 1 failure** for missing allocation environment, then **GREEN: 1/0**; integration-model checks **6/0**, exits 0.
- The final F1–F3 root run subsequently completed with actual **exit 0 in 1867.87s**, superseding this report's earlier pending-root checkpoint. **That root snapshot predates the narrow string correction**; affected suites were rerun afterward. No complete post-correction root run is claimed or required by this narrow source review.

Read `TEST_RESULTS.md` and `FINAL_REVIEW.md`. Their summary claims are appropriately qualified: `TEST_RESULTS.md:35,52–64` distinguishes the root snapshot from the subsequent wire/PKI corrections, avoids adding overlapping reruns into a unique-test total, and preserves the failed attempts. Both summaries retain **A1 BLOCKED**, resource-qualified TLS, remote CI/production gaps and no commit/push. No inaccurate overall-success or fresh-root claim was identified.

Fresh reviewer checks: `git diff --check` and route matrix **exit 0**; prior F1–F3 source/test hashes unchanged; OpenAPI components unchanged; PKI arithmetic checked as above. Reviewed narrow production/input hashes:

```text
b8ece73383278948663af6c5052b8129b0f3d4e5ff1ce66580b0903fdd8e313b  backend-yii2/application/PasswordSessionRecovery.php
4ed5c6f8da273da2c7c93dc10cd62a002cfe9c5e461d21da330baba67d7a1d9f  backend-yii2/contracts/openapi.yaml
ef573b000e759e311ec65d2219c652ff79a825195900ae7be590130fd3679548  scripts/test_pki.py
```
