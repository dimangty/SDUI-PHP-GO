# Yii2 + Go SDUI + CMP Implementation Plan

> **Historical execution ledger — before decommissioning.** Checkboxes, commands
> and results below retain their original milestone scope. Earlier source/archive
> retention and reference-writer instructions are superseded by the explicitly
> authorized removal; removed comparisons are not current rerun commands. See
> [decommissioning](LEGACY_REMOVAL.md) and [deployment](DEPLOYMENT.md).

> **For agentic workers:** use subagent-driven-development for subsystem tasks
> and executing-plans for integration. Steps use checkboxes for tracking.

**Goal:** replace Go business ownership with Yii2 and render real business data
through an independent Go BFF and shared CMP renderer.

**Architecture:** unchanged business API and PostgreSQL; PHP application services
behind thin Yii controllers. Go has an HTTP-only upstream and typed SDUI v1.
Four commonMain modules integrate with the current network/navigation/UIKit.

**Tech Stack:** PHP 8.3+, stable Yii2, PostgreSQL 17; Go 1.26 stdlib; existing
Kotlin 2.3.10 / CMP 1.10.3 / Ktor 3.5 / Koin 4.2.

**Spec:** [MIGRATION_PLAN.md](MIGRATION_PLAN.md).

## Global constraints

* Preserve `/api/v1` business routes, DTOs and authorization.
* Exact decimal strings in JSON; integer kopecks for calculations.
* Keycloak JWT plus separate hashed opaque application tokens; no replacement JWT.
* Existing migration checksums/tables/data remain; no destructive migrations.
* No business PostgreSQL access from Go BFF.
* All new client rendering/state/actions in commonMain; reuse current executor.
* No commits, pushes or production cutover without explicit user request.

## Review focus

1. Auth failure counters must commit even when the request returns an error.
2. Concurrent checkouts and same-key retries must leave one coherent SQL result.
3. Authenticated responses and state must never leak across users or caches.
4. Unknown SDUI types/versions and oversized/deep payloads must fail safely.
5. Payment/gateway timeout and cancellation must not trigger blind mutation retry.

## Task 1: Yii2 business service

**Files:** create `backend-yii2/{composer.json,Dockerfile,config,controllers,domain,application,infrastructure,repositories,migrations,tests,web,README.md}`.

**Consumes:** canonical OpenAPI, migrations 001–011, original domain rules and
PostgreSQL behavior. **Produces:** same business HTTP interface; independent
PHP-FPM image and executable tests. API never proxies business logic to Go.

- [x] Write money/hash compatibility and commerce/auth integration tests first.
- [x] Run failing tests in PHP Docker environment.
- [x] Implement framework/bootstrap, compatible SQL repositories, thin controller,
  Keycloak/opaque auth, PIN/biometric, catalog/search/profile/commerce services.
- [x] Run unit, PostgreSQL, API and concurrency tests; compare original responses.
- [x] Review security, transaction order, all canonical route coverage and gaps.

## Task 2: Independent Go SDUI service

**Files:** create `backend-sdui/{go.mod,cmd/server,internal,contracts,tests,Dockerfile,README.md}`.

**Consumes:** Yii2 canonical HTTP DTOs. **Produces:** eight
`GET /api/v1/screens/{home,catalog,product/{id},cart,orders,order/{id},profile,devices}`
and finite `/api/v1/sdui/actions`, JSON Schema and fixtures.

- [x] Write httptest upstream client and builder tests including cancellation,
  bearer/header allowlist, bounded bodies, partial failures and safe retries.
- [x] Observe tests failing, implement typed client and v1 component/action model.
- [x] Implement builders using real upstream DTOs and authenticated requests;
  state, stable IDs, pagination/query propagation and action validation.
- [x] Add configurable server, readiness/liveness and graceful shutdown.
- [x] Run `go test -race ./...`, `go vet ./...`; review schema/action boundaries.

## Task 3: CMP SDUI integration

**Files:** create four `core/sdui-*` modules; modify settings, feature/main,
core/network public facade/DI and navigation serializers only as needed.

**Consumes:** Task 2 fixtures/schema and existing RequestExecutor.
**Produces:** typed SduiScreen/components/actions/state/form/error; UIKit renderer;
host for all eight real destinations and finite existing navigation commands.

- [x] Write commonTest decoding/fallback/action/form/navigation cases first.
- [x] Observe failing tests; implement bounded tolerant tagged decoding.
- [x] Add a narrow JSON facade reusing the existing executor and refresh mutex.
- [x] Render finite types using Eps*; implement controlled fields, lazy lists,
  loading/error/retry; bound depth/count and support unsupported version fallback.
- [x] Wire existing shop/profile/auth use cases, checkout, tabs/product/order/back,
  cancellation and duplicate submit guards without remote executable instructions.
- [x] Run common/Android tests, APK and iOS framework build; record tool failures.
- [x] Review auth/logout/native security and account-scoped state lifecycle.

## Task 4: Deployment and end-to-end contracts

**Files:** create root `docker-compose.yml`, production overlay, `deploy/nginx/*`,
env templates, contract tests; modify Makefile and requested documentation.

**Consumes:** three independent runtime artifacts. **Produces:** one public edge,
internal Yii HTTP for BFF, preserved integration services, reproducible checks.

- [x] Configure PHP-FPM + internal Nginx, BFF and edge route priorities; no
  publicly published business DB/BFF/PHP ports.
- [x] Preserve original migrator/history and Keycloak/OpenSearch; development
  seed is explicit; production has HTTPS/certificate/env checks.
- [x] Add Make targets for build/up/down/tests and contract comparison.
- [x] Run Docker config/build/up, real login→SDUI→cart→checkout→payment→orders→logout.
- [x] Verify concurrent checkout, permissions, replay and differential contracts.
- [x] Update route parity matrix, architecture/contract/deployment/readmes with
  actual evidence; review the complete change set and fix significant findings.

## Execution ledger

### Task 5: Installed mobile live-stack journeys

**Files:** platform UI-test targets/scripts and stable commonMain accessibility
selectors as needed; `docs/MOBILE_E2E_REPORT.md`. Screen implementations remain
commonMain. No authentication bypass, seeded client tokens or mock API.

**Consumes:** running shared edge at `http://127.0.0.1:58095` (Android emulator
uses `http://10.0.2.2:58095`) and the built common CMP application.

- [x] Add repeatable UI flows using real registration/login and existing PIN flow.
- [x] Install/launch Android app and iOS app with the shared edge configuration.
- [x] Verify rendered Home → Catalog → Product → AddToCart → Cart → native
  checkout → test payment → Orders/Details → Profile/Devices → Logout.
- [x] Assert visible outcomes and server order/payment state; retain nonsecret
  evidence. Use stable selectors and explicit waits, not fixed sleeps.
- [x] Record actual platform execution, regressions and exact rerun commands;
  review changes and rerun affected checks.

Audit complete; original Go suite green. Shared interfaces reviewed: Task 1→2 is
the unchanged OpenAPI; Task 2→3 is published JSON Schema/fixtures; Task 1/2→4 are
Dockerfiles and env/readiness endpoints. No file ownership overlap is required.

Ruling: work in the supplied workspace because its Git repository is unborn and
all original files are untracked. Creating a worktree would require an unsolicited
initial commit. The unchanged zip is the recoverable baseline.

Ruling: proceed from the user-provided detailed specification without additional
approval pauses, as explicitly requested. Keep implementation/review evidence in
these documents; do not perform an unverified production switch.

### Task4 execution evidence

Pre-flight: reviewed Task1/Task2/Task3 implementation reports and latest scoped
reviews (all prior findings closed), actual service configs/Dockerfiles, PHP
same-origin JWKS and proxy/client rules, original realm/migrator/router/E2E and
the current SDUI contract. Shared boundaries match: Yii FPM9000 + internal
HTTP8080; BFF fixed HTTP origin and separate admin9090; CMP same shared edge.

Ruling: execute in the supplied unborn/untracked workspace without commits,
worktrees or subagents, as requested. No business/BFF/CMP rewrite was necessary.
The only service-source addition is the allowed explicit empty-only development
seed command, console registration and preservation/production-refusal tests.

Task4 configuration/build/real-stack checks passed on the fresh isolated
`shopkmp-migrated-e2e` project, port58095. Production overlay was also built and
started on **separate disposable** `shopkmp-migrated-tls-e2e`, port58096, using
explicit short-lived test certificates/secrets. This is not a real production
deployment. Both DBs reject network plaintext; actual PHP provider TLS trust,
private issuer/JWKS and public operational-path blocking have execution evidence.

Current commands/evidence: [DEPLOYMENT.md](DEPLOYMENT.md), exact54-operation
[ROUTE_MATRIX.md](ROUTE_MATRIX.md), and [VERIFICATION_REPORT.md](VERIFICATION_REPORT.md).
The checked Task4 contract item means the documented representative differential
and race cases ran, not that every operation/input/state is equivalent. Task1–3
checkboxes above remain their original implementation-stage ledger; their later
review/remediation evidence is in the corresponding reports/reviews.

- [ ] Exhaustive full-matrix differential parity and production cutover.
- [x] Installed authenticated Android/iOS live journey; see MOBILE_E2E_REPORT.md.
- [ ] Actual production-provider settlement, backup/restore/rollback/load/failover.

### Final verification ledger — 2026-10-20

Task1–5 implementation, stage reviews/remediation and installed mobile milestones
are complete for the development integration. Direct sampled parity now requires
all54 matching successful operations; the final standalone command passed194
pairs/189 matches/five documented corrections/no unexpected mismatch and77 PHP
regressions. Removing the refresh-wording exception first produced the intended
failure, then the message-only correction passed. Root Yii was rebuilt/recreated
healthy; business/identity data was preserved.

Fresh final checks: full Android unit task set, Kover76.5252%, APK, iOS framework;
original Go suite; live BFF race suite and vet against58095; generated route/
policy/parity evidence check; development Compose config; shared-edge Home200.
Android full installed CARD repeated after startup change:30.789s,1 passed. iOS
installed CARD:156.525s,1 passed. Native security/startup suites are recorded in
their own reports. The parent fixed the final evidence-freshness minor with a
real failed-install probe followed by the successful Android run.

Final independent review and parity review have no remaining Critical/Important
finding. The mobile summary provenance and historical attachment link were
subsequently updated to the current Android output. No commits/pushes or
production cutover were performed. Full handoff: COMPLETION_REPORT.md.
