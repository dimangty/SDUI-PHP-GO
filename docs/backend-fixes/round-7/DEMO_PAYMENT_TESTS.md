# F3 — canonical demo payment identity: native RED/GREEN evidence

## Scope and execution ledger

Implements Task 4 / F3 of `AUDIT_PLAN.md`. Owned production file:
`backend-yii2/application/PaymentService.php`; new native suite and HTTP/SQL
fixture: `tests/demo_payment_identity.php`, `tests/DemoPaymentIdentityFixture.php`.
This report is the F3 execution ledger. No staging, commits, pushes or subagents.

Execution decisions:

- Use the existing approved audit plan, inline RED→GREEN, with this requested
  evidence report as the ledger. Shared coordinator work remains in the same
  workspace under the explicit file-ownership split.
- Canonicalize the **verified repository payment UUID** for confirm identity,
  demo path and signing. `demoToken()` is a deliberate UUID-only cryptographic
  boundary: `Ids::canonical()` validates its argument and makes the existing
  verifier coherent without editing coordinator-owned `ApiKernel.php`. Never
  normalize signatures, opaque session tokens, nonce/provider transaction IDs,
  webhook IDs, request bodies, or provider URLs.
- HTTP tests spawn the real `web/index.php` with Composer/Yii, against committed
  PostgreSQL fixtures. No fake kernel, fake database, hidden UI action or HTTP
  transport substitution. Only explicit test POSTs complete demo payments.
- Disposable opt-in: `DEMO_PAYMENT_TEST_DISPOSABLE=shopkmp-round7-demo-e2e`.
  Never point this fixture at production/shared data. Retain the owned database
  volume for evidence; manage only the two named owned containers/network.

Plan:

1. Create the bounded, explicitly subnetted PostgreSQL/PHP stack; migrate with
   current ops and Yii migrations and verify the real autoloader/toolchain.
2. Write native/HTTP tests and run against original `PaymentService` (RED):
   **done, 15 tests / 9 expected failures**, including real upper/mixed GET 403.
3. Apply the minimal stored-UUID/signing-boundary fix (GREEN): **done, 15/15**.
4. Run focused and existing appropriate suites: **done**, results and the
   remaining whole-core OpenSearch prerequisite are recorded below.

## Environment and setup

Executed 2026-11-01 on macOS/Docker Desktop. Baseline HEAD:
`160d2a5a535791c75c36c2f70a90515153339e87`.
Original `PaymentService.php` SHA-256:
`2383dbbe4a8b4835606a71463cbf899a944e7092deb40d02fc58af8b3fb74c4c`.
`git diff -- backend-yii2/application/PaymentService.php` was empty during RED.

Dedicated resources (no foreign lifecycle operations):

- Network `shopkmp-round7-demo-e2e`, `10.252.213.0/24` (all existing Docker
  subnets inspected first; no overlap).
- PostgreSQL `shopkmp-round7-demo-postgres`, `postgres:17-alpine`, image
  `sha256:742f40ea20b9ff2ff31db5458d127452988a2164df9e17441e191f3b72252193`.
  0.5 CPU, 192 MiB RAM/swap cap, 64 PIDs, 32 MiB shm, shared buffers 16 MiB,
  work memory 1 MiB, max 20 connections. Loopback-only dynamic port `53388`.
- Persistent volume `shopkmp-round7-demo-pgdata`, retained.
- PHP `shopkmp-round7-demo-php`, baseline image
  `shopkmp-round6-acceptance-e2e-yii2`,
  `sha256:c3fafb30783031b7c4a0115aa8ce03020e075206fce596af2d01d81344ca95ae`.
  1 CPU, 384 MiB RAM/swap cap, 96 PIDs. Current source bind-mounted read-only
  at `/app`, real vendor copied from the image into an anonymous Docker volume;
  `/app/runtime` is a 32 MiB tmpfs. Processes listen only on container loopback.
- Verified actual PHP **8.5.12**, Yii **2.0.55**, Composer **2.11.1** and real
   `Composer\Autoload\ClassLoader`. No fabricated autoload/shim.

### Reproducible setup commands

Run from repository root unless otherwise noted. Values below are isolated
test-only credentials, not production secrets.

```sh
docker network inspect $(docker network ls -q) \
  --format '{{.Name}} {{range .IPAM.Config}}{{.Subnet}} {{end}}'
docker network create --driver bridge --subnet 10.252.213.0/24 \
  --label shopkmp.owner=round7-demo shopkmp-round7-demo-e2e
docker volume create --label shopkmp.owner=round7-demo shopkmp-round7-demo-pgdata
docker run -d --name shopkmp-round7-demo-postgres \
  --network shopkmp-round7-demo-e2e --label shopkmp.owner=round7-demo \
  --cpus .5 --memory 192m --memory-swap 192m --pids-limit 64 --shm-size 32m \
  -p 127.0.0.1::5432 -e POSTGRES_USER=demo_test \
  -e POSTGRES_PASSWORD=demo-test-only -e POSTGRES_DB=demo_test \
  --mount type=volume,source=shopkmp-round7-demo-pgdata,target=/var/lib/postgresql/data \
  postgres:17-alpine postgres -c shared_buffers=16MB -c max_connections=20 -c work_mem=1MB
docker port shopkmp-round7-demo-postgres 5432/tcp
docker exec shopkmp-round7-demo-postgres pg_isready -U demo_test -d demo_test
docker run -d --name shopkmp-round7-demo-php \
  --network shopkmp-round7-demo-e2e --label shopkmp.owner=round7-demo \
  --cpus 1 --memory 384m --memory-swap 384m --pids-limit 96 \
  --mount "type=bind,source=$PWD/backend-yii2,target=/app,readonly" \
  --mount "type=bind,source=$PWD/tools/ops/migrations,target=/migrations,readonly" \
  --mount "type=bind,source=$PWD/deploy/php,target=/opt/scheduler,readonly" \
  --mount type=volume,target=/app/vendor \
  --tmpfs /app/runtime:rw,mode=1777,size=32m --workdir /app \
  -e 'DATABASE_URL=postgres://demo_test:demo-test-only@shopkmp-round7-demo-postgres:5432/demo_test?sslmode=disable' \
  -e APP_ENV=development -e PAYMENT_GATEWAY_URL= \
  -e PAYMENT_WEBHOOK_SECRET=demo-identity-test-secret \
  --entrypoint sleep shopkmp-round6-acceptance-e2e-yii2 14400
DATABASE_URL='postgres://demo_test:demo-test-only@127.0.0.1:53388/demo_test?sslmode=disable' \
  go -C tools/ops run ./cmd/migrate
docker exec shopkmp-round7-demo-php php yii migrate --interactive=0
docker exec shopkmp-round7-demo-php php -r \
  'require "vendor/autoload.php"; require "vendor/yiisoft/yii2/Yii.php"; printf("PHP %s Yii %s ComposerLoader %s\n", PHP_VERSION, Yii::getVersion(), Composer\Autoload\ClassLoader::class);'
docker exec shopkmp-round7-demo-php composer --version
```

All corrected setup commands exited 0. Ops history contains all versions 1–16;
Yii applied `m261029_000001_credential_remediation_audit` with base history.
No historical migration changed. Initial setup-only mistakes: unquoted `?` in
the zsh Docker environment argument was rejected before creating PHP; running
the ops command without `DATABASE_URL` exited 2. Both corrected above, before
regression execution.

The setup block includes the **final** read-only migration/scheduler mounts.
Initially those two mounts were omitted. Only the owned PHP container was
stopped/removed/recreated to add them, retaining all volumes. The regression
runs below distinguish those initial setup failures from product failures.

The wider legacy suites used separate databases in the **same owned** tiny
PostgreSQL instance to keep F3 evidence in `demo_test` independent:

```sh
docker exec shopkmp-round7-demo-postgres createdb -U demo_test demo_core
docker exec shopkmp-round7-demo-postgres createdb -U demo_test demo_payment
DATABASE_URL='postgres://demo_test:demo-test-only@127.0.0.1:53388/demo_core?sslmode=disable' \
  go -C tools/ops run ./cmd/migrate
DATABASE_URL='postgres://demo_test:demo-test-only@127.0.0.1:53388/demo_payment?sslmode=disable' \
  go -C tools/ops run ./cmd/migrate
docker exec \
  -e 'DATABASE_URL=postgres://demo_test:demo-test-only@shopkmp-round7-demo-postgres:5432/demo_core?sslmode=disable' \
  shopkmp-round7-demo-php php yii migrate --interactive=0
docker exec \
  -e 'DATABASE_URL=postgres://demo_test:demo-test-only@shopkmp-round7-demo-postgres:5432/demo_payment?sslmode=disable' \
  shopkmp-round7-demo-php php yii migrate --interactive=0
```

All exited 0, applying current ops 001–016 plus the current additive Yii migration.

## RED — unchanged production implementation

```sh
docker exec -e DEMO_PAYMENT_TEST_DISPOSABLE=shopkmp-round7-demo-e2e \
  shopkmp-round7-demo-php php tests/demo_payment_identity.php
```

**Exit 1; 15 tests, 9 failures.** Important observed output:

```text
FAIL demo upper HTTP confirm link GET and explicit POST capture the same payment: Expected 200, got 403
FAIL demo mixed HTTP confirm link GET and explicit POST capture the same payment: Expected 200, got 403
15 tests, 9 failures
```

The native lower test's first lowercase confirm worked; its uppercase repeat
failed with uppercase `paymentId` and a different token. Native upper/mixed
tests failed canonical DTO/HMAC assertions. Lower HTTP reached the original
link successfully, then its uppercase repeated confirm changed the DTO/HMAC.
Token-boundary, on-delivery canonical response and opaque-provider repeat tests
also detected the raw-caller identity. Six existing-security behavior tests
passed: tampering/substitution, ownership, production with/without provider,
development with provider and absent signing secret.

Example of original uppercase output: canonical path
`/demo/payments/ab12cd34-56ef-4a78-9b01-a0232ab9d329` paired with response ID
`AB12CD34-56EF-4A78-9B01-A0232AB9D329`; HMAC was over that uppercase caller,
not over the stored/path UUID. The GET verifier signed the lowercase path and
returned `DEMO_PAYMENT_FORBIDDEN` / 403.

## GREEN and regression verification

Minimal production patch (four added / three removed lines including import):

- Keep the existing operation execution and ownership-filtered repository read.
- Set `$id=Ids::canonical($p['id'])` **after** that verified read.
- Build demo path and HMAC from that ID; return it as `paymentId`.
- Validate/canonicalize only the UUID in `demoToken()` before HMAC. Do not
  canonicalize the received signature. Invalid UUIDs are still rejected with
  existing HTTP 403 by the verifier's `Ids::valid()` short circuit.

The unchanged lowercase capability remains valid. Previously issued broken
upper/mixed links were already unusable (403); the fix does not add fallback
acceptance for raw-caller signatures. `demoToken()` is intentionally not a
generic signer for opaque identifiers. Normal provider URLs and transaction
IDs, including UUID-shaped provider strings, remain byte-exact.

### Focused native SQL + real HTTP

```sh
docker exec -e DEMO_PAYMENT_TEST_DISPOSABLE=shopkmp-round7-demo-e2e \
  shopkmp-round7-demo-php php tests/demo_payment_identity.php
```

**Exit 0; 15 tests, 0 failures**, including a final rerun after rebuilding the
owned PHP container with the canonical regression mounts. Coverage:

| Cases | Actual verified behavior |
|---|---|
| Lower / upper / mixed native confirm | Canonical response UUID, path and independently computed HMAC; all casing repeats return identical DTO; exactly one succeeded authorization operation/key |
| Lower / upper / mixed HTTP chains | Real authenticated POST confirm; returned-link GET 200; canonical ID/path/token; GET leaves payment/order/operation rows unchanged; all repeated confirms preserve the exact operation snapshot |
| Demo path UUID case variants | GET and explicit POST with the canonical token work for caller path casing; capture changes the same payment to CAPTURED and order to PAID; subsequent confirm has null URL and creates no new operation |
| Tampering | Missing token, changed first hex byte, uppercase signature, truncated signature, substituted existing payment UUID and malformed UUID all return 403 on GET **and POST**, without state/operation changes |
| Ownership | Missing bearer 401 and foreign owner 404 for all case variants; foreign attempts create no payment operation |
| Closed modes | Production with/without provider and development with configured provider return 404 for GET/POST despite otherwise-valid links; absent secret returns 403; no state/operation changes |
| UUID-only signing boundary | Fixed UUID vector signs identically for all cases; opaque non-UUID input is rejected |
| On-delivery | Same canonical confirm DTO with null URL for all cases; no provider operation/transaction |
| Provider data | Mixed-case external URL, opaque query token/signature and UUID-shaped uppercase provider transaction remain byte-exact on repeat confirm/read |

First GREEN attempt exposed a **fixture** problem: `demo HTTP confirm keeps
owner checks and leaves foreign payment uninitiated` received 429 instead of
401 after accumulated repeats. Payment confirms use `SENSITIVE_RATE_LIMIT`,
not `MUTATION_RATE_LIMIT`. Set the fixture's isolated child HTTP process
`SENSITIVE_RATE_LIMIT=10000` (alongside existing test read/mutation budgets).
No production rate policy or assertion changed. Complete subsequent runs pass.

### Existing regression commands and results

```sh
docker exec shopkmp-round7-demo-php composer test
docker exec shopkmp-round7-demo-php composer lint
docker exec \
  -e 'DATABASE_URL=postgres://demo_test:demo-test-only@shopkmp-round7-demo-postgres:5432/demo_payment?sslmode=disable' \
  -e PAYMENT_MIGRATION_DIR=/migrations -e SENSITIVE_RATE_LIMIT=10000 \
  shopkmp-round7-demo-php php tests/native_suites.php payment
# Rerun only the scheduler suite after adding its missing /opt/scheduler mount:
docker exec \
  -e 'DATABASE_URL=postgres://demo_test:demo-test-only@shopkmp-round7-demo-postgres:5432/demo_payment?sslmode=disable' \
  -e PAYMENT_MIGRATION_DIR=/migrations -e SENSITIVE_RATE_LIMIT=10000 \
  shopkmp-round7-demo-php php tests/native_suites.php payment recovery-scheduling.php
docker exec \
  -e 'DATABASE_URL=postgres://demo_test:demo-test-only@shopkmp-round7-demo-postgres:5432/demo_core?sslmode=disable' \
  -e PAYMENT_MIGRATION_DIR=/migrations \
  shopkmp-round7-demo-php php tests/native_suites.php core
docker exec \
  -e 'DATABASE_URL=postgres://demo_test:demo-test-only@shopkmp-round7-demo-postgres:5432/demo_core?sslmode=disable' \
  -e PAYMENT_MIGRATION_DIR=/migrations \
  shopkmp-round7-demo-php php tests/native_suites.php core \
  adapters.php readiness.php deadlines.php local_auth_migration.php session_retention.php
# Explicit opt-in corrected for the separately guarded migration suite:
docker exec \
  -e 'DATABASE_URL=postgres://demo_test:demo-test-only@shopkmp-round7-demo-postgres:5432/demo_core?sslmode=disable' \
  -e LOCAL_AUTH_DISPOSABLE=shopkmp-round7-demo-e2e \
  shopkmp-round7-demo-php php tests/local_auth_migration.php
git diff --check
```

| Gate / suite | Result |
|---|---|
| `composer test` | Exit 0, **74/74** across its eight existing suites |
| `composer lint` | Exit 0, **136 PHP files** passed at that source snapshot |
| `payment-migration.php` | **13/13** |
| `payments.php` | **113/113**, including physical provider journal call counts, concurrent confirm, no replay, webhook dedup and upgrade/compensation cases |
| `payment-http.php` | **4/4** |
| `uuid_identity.php` | **16/16**, retaining opaque/biometric/provider boundaries |
| `uuid_recovery.php` | **5/5** |
| `recovery.php` | **9/9** |
| `recovery-scheduling.php` | **14/14** on corrected scheduler-mount rerun (exit 0); actual scheduler and ~5.01-second transaction deadline checks retained |
| Existing payment suites total | **174/174 across the main run plus corrected scheduling rerun**, not a claim that the first orchestration command exited 0 |
| Native full `core` / `all.php` | Exit 1: all preceding suites through `api.php` passed; stopped at **two missing-live-OpenSearch failures**, detailed below |
| `adapters.php` / `readiness.php` / `deadlines.php` / `session_retention.php` | **4/4**, **1/1**, **4/4**, **1/1** in explicit follow-up invocation |
| `local_auth_migration.php` | Initial follow-up stopped at disposable-opt-in guard; corrected explicit opt-in: exit 0, **21/21** |
| `git diff --check` | Exit 0 |
| Historical SQL checksum baseline | Exit 0, **all 32** existing up/down migration files match `SHA256SUMS` |

Final native-runner registration smoke test also exited 0, **15/15**:

```sh
docker exec -e DEMO_PAYMENT_TEST_DISPOSABLE=shopkmp-round7-demo-e2e \
  shopkmp-round7-demo-php php tests/native_suites.php core demo_payment_identity.php
# From tools/ops/migrations:
shasum -a 256 -c SHA256SUMS
```

For transparency, initial wider payment invocation before `/migrations` was
mounted exited 1: all 13 payment-migration tests, 13 legacy-schema payments
tests, the recovery bootstrap and all 14 scheduling tests failed their missing
migration-file fixture checks; payment HTTP had one accumulated-rate-limit
failure. Those prerequisites were corrected with the read-only SQL mount,
separate database and test-only sensitive budget. The next complete payment
run passed every suite except these **two missing-scheduler-file** checks:

- `real scheduler preserves manual sessions and progresses payments across bounded ticks despite backlog`
- `F3 real maintenance scheduler retains cleanup progress and queues expiry without payment HTTP`

Both failed `is_file('/opt/scheduler/scheduler.php')`; the corrected mount and
14/14 scheduler rerun resolve them without source/assertion/deadline changes.
Expected negative-path stderr in the legacy suites includes deliberately
failed provider calls, connection quarantine and readiness diagnostics.

### Whole-core prerequisite / remaining limitation

The full native core run reached `tests/search.php` and failed:

1. `real OpenSearch indexes SQL products and applies name boost descendants and exact price filters` — `OPENSEARCH_URL not configured`.
2. `operator-configured OpenSearch basic-auth URL is supported without exposing credentials in request URLs` — invalid origin built from the absent URL; the suite also emitted missing scheme/host warnings.

This stack deliberately contains PostgreSQL and PHP only. No foreign search
stack was contacted or managed. Full `all.php` therefore did **not** pass;
its live search/reconciliation/index gates need the coordinator's owned
OpenSearch-enabled environment. Non-search suites after its fail-fast stop
were run explicitly as listed above. `search_reconcile.php` and
`round4_index.php` were not run here. No F3 implementation blocker remains.

## Historical coordinator handoff / registration required

**Superseded by [latest integrated results](TEST_RESULTS.md).** Registration and
integrated acceptance below describe the original F3 checkpoint. The current
native registry runs this suite through `round7_native.php`; original RED and
partial-stack evidence remain historical.

The suite is intentionally **not yet registered**, respecting ownership of
`tests/all.php`, `tests/native_suites.php` and `composer.json`.

- Register `demo_payment_identity.php` **once in the native core/database
  lane**, after migrations, with
  `DEMO_PAYMENT_TEST_DISPOSABLE=shopkmp-<owned-project>-e2e` inherited by the
  suite. Parent process must be development with empty `PAYMENT_GATEWAY_URL`.
  Do not put it in the provider-enabled `native_suites.php payment` lane;
  the fixture deliberately refuses that parent configuration.
- The suite owns ephemeral loopback HTTP processes and creates only committed,
  unique SQL fixtures in the selected disposable database. It needs normal
  `proc_open`, curl, PDO PostgreSQL and the real installed Composer/Yii tree.
  It sets a fixed **test-only** webhook secret in its own process and HTTP
  children. No external payment provider or OpenSearch is needed.
- Coordinator's planned `ApiKernel.php` demo verification boundary edit can
  explicitly canonicalize the **already validated UUID** while leaving
  `$token` untouched. The `demoToken()` helper now enforces the same boundary,
  so current GET/POST paths already pass without editing that shared file.
  Keep malformed UUID/signature denial and production/provider gating intact.
- Rerun this suite after the coordinator's boundary edit and register it in
  the coordinator-owned test/CI orchestration. No DTO or contract update is
  required.

## Final scoped review and source binding

Self-review only (explicit no-subagent instruction): compared the scoped
production diff and both new test files with F3. Confirm mutation/ownership
ordering is unchanged; canonicalization starts with the verified repository
UUID; no opaque values are normalized; HTTP assertions cover visible status
and committed financial state, not replacement implementations. The change
does not modify `ApiKernel.php`, suite registries, Composer manifests or any
migration. No critical/important scoped findings or deferred minor findings.

SHA-256 at final F3 verification:

```text
8268db5e9cc7eb84efa57ca35bdea2f9f011954fa2af76b122810b169eecdbd1  backend-yii2/application/PaymentService.php
6a0637f09a1e2a9193d765737638c4d4525ebb8fbfb8f84e31150407f26e0271  backend-yii2/tests/demo_payment_identity.php
5453b41cd736009ebdbe44bf60a2aeeedda3ab03ffd30f4329f96187cbb353b6  backend-yii2/tests/DemoPaymentIdentityFixture.php
c0b8f59c6012e19e6fa3202bdfb01b58aaf086cdda9b83237dd523af3fd316f8  backend-yii2/infrastructure/ApiKernel.php (read-only dependency at verification)
```

The only deliberate boundary decision is validated UUID canonicalization in
`demoToken()`; accepting raw-case UUID signatures would reintroduce identity
ambiguity, so no compatibility fallback is provided. If its callers were to
pass opaque identifiers, they would now get `InvalidArgumentException`;
current callers are the verified confirm UUID and guarded demo HTTP UUID.

## Resource handoff

After verification, stopped **only** the two owned containers to release shared
capacity (exit 0):

```sh
docker stop shopkmp-round7-demo-php shopkmp-round7-demo-postgres
```

Containers, the dedicated network, `shopkmp-round7-demo-pgdata` (all three
test databases), and every anonymous vendor volume are retained. No volume
removal/pruning or foreign lifecycle command was executed. To reuse evidence:
start the owned PostgreSQL container, wait for `pg_isready`, start the owned
PHP container, then run the registration smoke command above.
