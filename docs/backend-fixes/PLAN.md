# Backend hardening implementation plan

**October 28 continuation:** the original backend-only scope below describes the
B1–B7 plan. A subsequent explicit user request authorized the shared mobile UI
work (checkpoint `32675b249`, expanded installed evidence). Backend checkpoint
`b9def939` exists. The active runtime A1 remediation, actual current scans and
root/TLS status are in [A1_RUNTIME_RESULTS.md](A1_RUNTIME_RESULTS.md). Publication
remains blocked by Java vendor findings; historical checkpoint text below is not
a current worktree/commit assertion.

**Goal:** repair B1–B7 in the existing Yii2/PostgreSQL + Go SDUI backend.
**Architecture:** PHP remains the business owner. PostgreSQL stores payment operation intents and session revocation state. Go only builds SDUI from HTTP business data. External payment calls occur after intent commit, never while holding SQL locks.
**Scope/spec:** the B1–B7 requirements supplied with this task. No mobile changes, no framework replacement, no new broker. Existing migration files 001–011 remain immutable. Dependency changes require concrete audit evidence and reproducible locks.

## Baseline (2026-10-26)

- Branch: `refactor/backend-production-hardening`; HEAD `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`.
- Remote: `origin`, `https://github.com/dimangty/SDUI-PHP-GO.git`. Index, tracked working files and untracked files clean.
- `ALL_BACKEND_REVIEW.md` and its diagnostic attachments are absent. Task text supplies the findings; existing `deploy/evidence` is historical, not fresh verification.
- `python3 scripts/route_matrix.py --check`: exit 0, 54 operations, eight screens, six commands.
- `go -C backend-sdui test ./...`: exit 0; actual Go module toolchain auto-selection retained.
- `go -C tools/ops test ./...`: exit 0 (database-dependent tests may skip without DATABASE_URL).
- `make compose-config`: exit 0.
- Initial `make test-php`: exit 2; Docker credential helper missing from PATH. Installed helper is under `/Applications/Docker.app/Contents/Resources/bin`; retry with that directory on PATH.
- Host Go 1.27.2, Python 3.14.7, Docker 29.6.2, Compose 5.3.1. Host PHP/Composer/psql absent; use the project's Docker images. PHP Dockerfile selects 8.3; BFF go.mod selects 1.27.3; ops go.mod 1.26.0.
- Dedicated read/glob tools fail with `Bun is not defined`; shell/Python reads are the fallback. All edits use apply_patch.

## Design decisions

1. One payment lifecycle for confirm, cancellation, expiry, admin reversal and callback. A unique logical operation per payment/kind, a single live operation per payment, leased ownership and durable SENT/UNKNOWN prevent competing effects. A sent request is never automatically replayed without a documented provider guarantee. Callback correlation binds to initiated authorization and provider transaction ID, with late capture compensation. Stock restoration has a persisted once-only guard.
2. Provider contract is explicit: authorize accepts its documented structured result; reversal requires a confirmed synchronous result, including the existing empty 204 contract. 202/unexpected statuses/malformed results are UNKNOWN, not REFUNDED. No invented status endpoint. Recovery dispatches unsent intents and escalates ambiguous sent operations for manual reconciliation when lookup is unavailable.
3. Opaque sessions have server-derived credential linkage and family/security version. Password reset fences concurrent issuance before remote work; revocation is durable even if later Keycloak cleanup fails. Legacy unlinked tokens require reauthentication. Credential removal/terminal lockout revokes related families, not another user's sessions. Offline JWT expiry remains a separate mechanism.
4. Local logout always executes; Keycloak response errors are explicit and cannot promise global success. No stored plaintext refresh token.
5. Original client context is accepted only across explicitly trusted peers, overwritten at the edge and forwarded through a finite header allowlist; it never proves user identity or role. Preserve webhook source validation.

## Execution stages (each RED → implementation → GREEN)

- [x] **Payments B1–B4:** add adapter and PostgreSQL lifecycle regressions; add migration 012, payment operation coordinator, expiry integration and recovery console/scheduler; preserve public enum/status schema. Verify races, durable restarts, once-only stock and HTTP outside transactions against a journaled provider fixture.
- [x] **Sessions/Keycloak B5–B6:** add revocation/concurrency/provider response regressions; add migration 013; implement family/credential revocation and password-change fencing; document legacy forced login and partial remote success.
- [x] **Trust/rate limits B7:** add spoofing/independent-network tests; implement explicitly authenticated original-client forwarding across edge/BFF/PHP and deployment trust boundaries; verify direct API and SDUI.
- [x] **Migration/compatibility:** regenerate manifest only for new migrations; clean + populated upgrade, parallel migrate, constraints; inspect CMP readers and keep money strings/enums/schemaVersion and previous Go regressions.
- [ ] **Verification:** PHP suites; Go test/schema/golden/vet/race/build/format; ops/database tests; Compose build/up with separate project/ports/networks/volumes; signed HTTP callbacks, Keycloak realm, live multi-client rate limiting; trusted-CA TLS; dependency/image/secret scans. Record exact commands, exit codes, skips and blockers in TEST_RESULTS.md.
- [ ] **Review and publish:** separate reviewer examines the complete diff; address Critical/High, rerun affected checks. Update required documents. Only if required checks pass, inspect complete outgoing range and staged diff, commit intended paths, push explicit verified branch/ref, compare local/remote SHA and report CI separately.

## Final checkpoint — 2026-10-27

Functional root verification passed with the F1–F3 corrections (exit 0,
1,867.87 seconds), with 154 shared and 150 focused PHP cases, live Go contracts,
worker probes and shutdown/restart. Separate reviewers closed confirmed source
findings; the subsequent string-valued error-detail correction and PKI allocation
fix each have executed RED/GREEN and affected-suite evidence. Ops' two concrete
Go advisories were fixed with a targeted locked dependency update. All required
summary/design/rollout/result documents exist.

Verification/publication remains **BLOCKED on A1**, the unresolved runtime-image
HIGH/CRITICAL inventory. Detailed state: `TEST_RESULTS.md`, `FINAL_REVIEW.md`,
`SECURITY_POSTCHECK.md`. No staging, commit or push; remote CI not run. Old SQL
001–011 and all mobile/Gradle files remain unchanged. Earlier progress/report
handoffs describe historical snapshots and do not override this final checkpoint.

## Ownership boundaries for parallel work

- Payment work owns PaymentService, PaymentGateway, CommerceService stock/locks, MaintenanceService, migration 012, payment tests/controller/job and PAYMENTS.md.
- Session work owns AuthService, SessionService, CredentialService, Keycloak, migration 013, dedicated session/logout tests and SESSIONS.md.
- Trust work owns Go forwarding/config/tests, Network, Nginx trust/config, dedicated rate tests and TRUST_AND_RATE_LIMITS.md. Shared Compose edits are coordinated.
- Coordinator owns migration checksums, test runner integration, shared deployment integration, baseline/results/rollout/final documents and final verification. No commits or push until the verification gate.
