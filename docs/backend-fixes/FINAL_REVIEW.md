# Backend fixes — final disposition

**2026-10-27: B1–B7 application fixes implemented and functionally verified.
Overall task/release remains BLOCKED by runtime-image security gate A1.
A subsequent explicit user request authorizes a local checkpoint commit only;
no push or production-readiness claim.**

Separate AI reviewers examined the source, reproduced defects and re-reviewed
corrections. This is an actual separate-agent review, not an independent human
review. See `PAYMENT_REVIEW.md`, `PAYMENT_REREVIEW.md`,
`SESSION_TRUST_REVIEW.md`, `SESSION_TRUST_REREVIEW.md`,
`FINAL_CODE_REVIEW.md`, `FINAL_REREVIEW.md`, and `FINAL_FIX_REPORT.md`.
Historical request-changes verdicts are preserved; the scoped final re-review
closed F1–F3 with no confirmed remaining source Critical/High/Medium.

## B1–B7

| Group / cause | Implemented correction / principal files | Tests and actual result |
|---|---|---|
| **B1:** local cancellation/expiry omitted remote completion | `PaymentOperations.php`, `PaymentService.php`, `MaintenanceService.php`, `CommerceService.php`, SQL 012. Durable cancellation/refund intent, method distinction, once-only stock, late-capture compensation. Scheduled expiry commits local intent without HTTP; dedicated recovery sends it. | CARD/SBP/ON_DELIVERY matrix, callbacks/expiry/races, actual maintenance scheduler. Final payment **113/0**, scheduling **11/0**. |
| **B2:** arbitrary successful HTTP class treated reversal as final | `PaymentGateway.php`. Explicit structured authorize contract; reversal requires confirmed empty 204. 202/unexpected/malformed/error outcomes remain uncertain. | Both reversal matrices, adapter validation, true timeout/lost-response journal. Included in **113/0**; real HTTP **4/0**. |
| **B3:** initiated PENDING could not accept callback | Initiation/transaction ownership and durable inbox in `PaymentOperations.php`, SQL 012; HTTP HMAC/time/source boundary retained. Early correlation waits for verified facts; late capture queues compensation. Existing duplicate 409 retained. | Genuine signed HTTP, bad signature/time, unknown/foreign transaction, early/reordered/duplicate callbacks and restart. **113/0**, HTTP **4/0**, live CARD journey passed. |
| **B4:** HTTP inside transactions and no uncertain-result recovery | Durable READY→SENT intent/lease, HTTP after commit, guarded apply, append-only normalized response observations, UNKNOWN/manual reconciliation. Unique per-kind/key/live-operation constraints. `RecoveryService.php`, controller/Compose job and SQL 012/014. Verified NO_EFFECT disposition can release an owed refund without replay. | Real PG commit failures, killed processes, two workers, leases, exact amount/stock/authorization checks. Recovery **9/0**, scheduling **11/0**, root/runtime passed. |
| **B5:** password/device revocation did not invalidate opaque sessions | `SessionService.php`, `CredentialService.php`, `AuthService.php`, `PasswordSessionRecovery.php`, SQL 013/014. Credential families, user security fence, serialized issuance/rotation/revocation; legacy forced reauth; durable partial-reset recovery by safe identifier. | Password recovery **10/0**, credential/family policy **7/0**, concurrency **11/0**, legacy upgrade **2/0**, real realm auth **4/0**. Final error-detail wire regression **RED 10/5 → GREEN 10/0**. |
| **B6:** Keycloak logout ignored failed status and caller got false success | Exact adapter result validation, safe authenticated-user provider targeting, local cleanup first, explicit 502/503 on unconfirmed provider completion. Client-credentials failures remain provider errors. No persisted refresh secret. | Logout/provider suite **41/0**, live online/offline cleanup **4/0** within auth suite; offline JWT not claimed instantly invalid. |
| **B7:** BFF socket address merged client network quotas | Go typed client IP plus exact trusted edge peers; finite Nginx/FastCGI context; exact trusted BFFs; isolated FPM admission. `trusted_client.go`, runtime/yii client, `Network.php`, nginx/FPM/Compose/env files. Webhook source context remains separate. | Go regression/race/schema/golden, PHP trust/rate, real two-replica IPv4/IPv6 clients and untrusted FastCGI/spoofing. All seven live checks passed. |

## Compatibility and migrations

- Real route count is **54 PHP operations, eight screens, six commands**. No new
  public enum or SDUI schemaVersion; money stays decimal strings.
- Existing CMP readers were inspected, not changed. Security-policy changes
  include expected legacy/revoked-token 401, all-user opaque password/logout
  revocation, credential-specific device removal, and explicit upstream logout
  errors. A cancelled order can retain unresolved PENDING/AUTHORIZED/CAPTURED
  financial status. Error details remain `Map<String,String>`; the new recovery
  flag is serialized as `"true"`. OpenAPI records changed provider-error semantics.
- SQL **001–011 byte-identical**, original checksums/history preserved. Additions:
  **012 payment operations**, **013 session revocation**, **014 recovery schedule**.
  Clean and populated upgrade and two actual concurrent migrators passed; all
  14 history rows and constraints/indexes verified. No history was forged.
- Rollout requires drained old writers, schema application, compatible code and
  recovery workers, then traffic. Legacy device linkage is not guessed.
  **012 downgrade is prohibited by SQLSTATE 0A000**; preserve financial audit and
  roll forward. 013/014 refuse pending recovery and cannot resurrect old tokens.

## Changed source areas

- PHP application services: payments/operations, commerce stock guard,
  maintenance/recovery, auth/sessions/credentials/password recovery.
- PHP adapters/boundary: gateway, Keycloak, network context, schema readiness,
  maintenance console and OpenAPI error documentation.
- Go: trusted-client boundary, request identity forwarding, runtime configuration
  and regressions; native live fixture follows real credential revocation.
- Operations: SQL 012–014/manifest, targeted ops `x/text`/`x/sync` locks,
  dedicated recovery job, exact networks/FPM admission, finite forwarding,
  test overlays/runners/CI/PKI and backend documentation.
- No mobile/Gradle changes. No vendor/cache/build/secret dumps in intended source
  changes. Generated historical JSON evidence has been restored after comparison.

## Residual limitations and blocking gate

1. **A1 — unresolved runtime-image vulnerabilities: BLOCKED.** Seven-image scans
   found inventory requiring supported runtime/base remediation and applicability
   review, particularly Keycloak reset-flow and other Java/native packages.
   Ops' two concrete Go dependency findings were fixed and rescanned; that does
   not clear unrelated images. `SECURITY_TLS_RESULTS.md` retains severity counts,
   exact artifacts and non-exploitability qualifications without suppressing them.
2. Production provider exposes no documented lookup or safe mutation-replay
   guarantee. SENT/UNKNOWN remains manual until independent evidence and quiesced
   sender are established. No end-to-end exactly-once claim. A verified failed
   refund remains a visible manual settlement item, not an automatically replayed
   POST; failed void can release one compensation refund.
3. Session fairness skips busy owners within a bounded **25-candidate** window.
   New families cannot bypass fences, but an entirely busy window may defer later
   users. Worker cadence/candidate limits and manual alerts are documented.
4. TLS functional smoke passed with explicit test resource settings. Default
   production capacity was not established in the shared 8-GB Docker VM. Real
   production cutover/rollback and remote CI were not run.

## Git / publication

- Branch: `refactor/backend-production-hardening` (existing suitable work branch).
- Local HEAD and last verified remote ref:
  `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`.
- Remote: `origin`, `refs/heads/refactor/backend-production-hardening`.
  `git ls-remote` matched; local outgoing range was empty before any commit.
- At the verification checkpoint the index was empty and HEAD was unchanged.
  The user subsequently explicitly requested a **local commit**. That checkpoint
  records the reviewed B1–B7 changes and their tests/docs; unfinished A1 PHP
  runtime Dockerfile/test changes remain outside its index. The resulting commit
  is identified by `git log -1`; the baseline/remote SHA above remains historical.
- **Push NOT RUN**, intentionally, because the user prohibits publication when
  required checks fail. **CI NOT RUN**; no commit/pipeline URL is fabricated.
- Next release action is A1 remediation/disposition with current artifact scans
  and relevant regression/TLS checks. Only afterward inspect full outgoing/staged
  diff, commit remaining intended backend paths and push the explicit verified ref.

The local checkpoint does not close A1 or the in-progress iOS smoke test. The iOS
Debug app built successfully and reached Login on an iPhone 17 Pro/iOS 26.2
simulator against the disposable backend, but the full UI journey had not yet
completed at commit time. No mobile source was changed.
