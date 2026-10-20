# Task4 infrastructure / integration verification

> **Historical verification — before decommissioning.** Recorded results/counts
> below remain evidence from their original runs. Commands using the retired Go
> source, reference profiles or parity runner are not current rerun instructions.
> No removed comparison has been rerun. See [decommissioning](LEGACY_REMOVAL.md)
> and [deployment](DEPLOYMENT.md) for the current checks and rollback procedure.

## Current handoff status — 2026-10-20

The dated Task4 sections below retain their original execution scope. Subsequent
work closed the installed-mobile and mandatory-endpoint sampled comparison gaps:

* Installed Android CARD journey:1 test passed,30.789s on final shared startup
  code; actual Chrome capture/return and all eight screens/logout verified.
* Installed iOS CARD journey:1 test passed,156.525s; Safari capture/return and
  cold relaunch at Login verified. Native Keychain/bridge tests:22 passed;
  affected feature/main native regressions:31 passed.
* `python3 scripts/parity.py --disposable --regressions`:54/54 matching successful
  operations,194 HTTP pairs,189 matches,5 explicit corrections,0 unexpected
  mismatches;39 unauthenticated and7 admin denials match. PHP77 tests passed,
  including post-CORS/browser-form coverage and bidirectional compatibility.
* Final full Android unit task set, root Kover76.5252%, APK and iOS simulator
  framework passed. Original Go suite and independent BFF suite/vet passed.
* Independent subsystem, mobile/native/CORS and parity reviews found no remaining
  Critical/Important issue. Their historical opening findings are followed by
  scoped remediation dispositions.

Exact source, commands and artifacts: [mobile](MOBILE_E2E_REPORT.md),
[API parity](API_PARITY_REPORT.md), [final review](FINAL_REVIEW.md),
[parity review](API_PARITY_REVIEW.md), [consolidated handoff](COMPLETION_REPORT.md).
These are development/test-provider outcomes. Physical security hardware,
production-provider settlement and restored-data/cutover/rollback remain release
work; no production switch has been performed.

Executed **2026-10-18** in `/Users/dmitrijbykov/Documents/GoLang/SDUI`.
Final recorded CARD capture: **09:35:58Z**; final BFF capture: **09:36:03Z**.
Scope: root Compose/env/Makefile/readme, `deploy/**`, `scripts/**`, requested docs,
small service-README additions and the allowed PHP development seed command,
console registration and tests. No commits, subagents or actual production writes.

## Result and limits

**Task4's implemented local infrastructure and documented integration gates pass.**
The functioning default is one public edge → Yii business API / independent Go
HTTP-only BFF. No original Go business server is in the production runtime.
The original source, canonical OpenAPI, SQL migrations/checksums and Keycloak
realm are retained. Reviewed business/BFF/CMP implementation code was not changed.

This is **not full production cutover approval or exhaustive parity**. Successful
54-operation coverage, representative differential checks and HTTP native-adapter
boundaries do not establish every input/state/transition or an installed mobile
journey. Android/iOS builds and91 real simulator cases are prior Task3 evidence,
not rerun or relabeled as live-device E2E here.

## Actual commands and results

### Fresh isolated development runtime

Started with **new project/network/volumes**, not the existing Task1 resources:

```sh
docker compose --env-file .env.example config --quiet
docker compose --env-file .env.example -p shopkmp-migrated-e2e build
EDGE_PORT=58095 python3 scripts/compose.py --env .env.example \
  --project shopkmp-migrated-e2e up -d --build --wait --wait-timeout 300
EDGE_PORT=58095 python3 scripts/compose.py --env .env.example \
  --project shopkmp-migrated-e2e run --rm seed
EDGE_PORT=58095 python3 scripts/compose.py --env .env.example \
  --project shopkmp-migrated-e2e exec -T yii2 php yii maintenance/reindex
```

Actual initial seed output: `Created 2 development categories/products; run
maintenance/reindex`; reconciliation initially reported `SQL products reconciled:
2`. These are persisted ordinary SQL products/media/categories, not mocks. After
the real SQL suites created more fixtures, a repeated seed reported **`Catalog
already populated; no changes`**. The preservation test compares complete product
and category row JSON before/after and checks production refusal.

The single documented full-stack Make command was also executed successfully
against the existing isolated project, with its custom port:

```sh
EDGE_PORT=58095 COMPOSE_PROJECT_NAME=shopkmp-migrated-e2e \
  make run ENV_FILE=.env.example
```

The final output included healthy services, the no-change seed result and
`SQL products reconciled: 71` at that point. Later test reruns created additional
unique fixtures; that number is not a fixed expected catalog size.

### Final complete integration invocation — exit0

```sh
make integration-check
```

This expands to:

```sh
EDGE_PORT=58095 python3 scripts/verify.py --env .env.example \
  --project shopkmp-migrated-e2e --url http://127.0.0.1:58095 --disposable
```

Actual final phase results:

```text
route-matrix: PASS
seed-preservation: PASS
initial-reindex: PASS
edge-card: PASS
original-e2e: PASS
bff-race: PASS
bff-vet: PASS
reference-setup: PASS
reference-start: PASS
php-all: PASS
original-tests: PASS
original-postgres-tests: PASS
runtime: PASS
PASS Task4 disposable verification. Evidence: deploy/evidence/. No mobile or production cutover claim.
```

| Check | Executed scope / result |
| --- | --- |
| Route manifest / docs drift |54 exact Go/PHP method/path/access entries;15 public,32 protected,7 admin; eight BFF routes/six commands |
| New seed tests |2 tests,0 failures; production command refuses before DB access; populated catalog unchanged |
| Shared-edge CARD integration |Real Keycloak register/login → native PIN opaque session → home/catalog/product → BFF AddToCart/replay → native canonical address/order → confirm → GET/POST demo CARD payment → PAID order → orders/detail/profile/devices → logout/revoked-session401 |
| Checkout races through edge |Concurrent same key returns identical successful snapshots/one order; different keys on one cart yield201 +400 `CART_IS_EMPTY`, one additional reservation; exact money/stock/cart/order readbacks; cancellation restores only that reservation |
| Shared-edge policies |All39 protected operations reject missing bearer with forged identity/proxy headers; all7 admin operations reject ordinary user; protected BFF routes/actions reject spoofed identity |
| Wire/body/timeouts |BFF32769-byte command413; app1048577-byte JSON413; >6MiB edge body413; incomplete header/body connections close or408 after5s/10s configured bounds; no weakened rate/security limits |
| Original unchanged E2E |`go run ./cmd/e2e -base-url http://127.0.0.1:58095`: PASS |
| BFF |`go test -race ./... -count=1 -v` with **both live origins equal to58095**: four test packages PASS; actual8-screen independent schema checks and all6 commands;13 deterministic fixture/golden/schema tests; security/content/collection/retry/cancellation regressions |
| BFF vet |`go vet ./...`: PASS, no diagnostics |
| PHP full suite |`composer test:all`: **69 tests,0 failures;60 PHP syntax checks**; real SQL/Keycloak/search, signed claims, throttling, mixed controlled address race, concurrency, all54 HTTP successes, deadlines, differential and cross-runtime; no optional cross-runtime skip |
| Original Go suite |Host `go test ./... -count=1`: PASS; also full suite in verification-only Go image with `TEST_DATABASE_URL` targeting private **backend_regression**, separate from application DB: PASS including PostgreSQL and unchanged Kotlin/OpenAPI comparisons |
| Actual probes/history |FPM FastCGI ping, PHP live/ready, BFF internal live/ready, Nginx/FPM syntax: PASS; original migration history11 rows; separate Yii history1 initial row |
| Client-IP trust |Edge access record for actual socket172.30.95.1 correlates with PHP SQL principal `read:network-172.30.95.0/24`; forged203.0.113.77 produces no corresponding new network principal |
| Graceful shutdown |Edge, internal Nginx, FPM, BFF, maintenance and reconciliation stopped with **exit0**, then restarted healthy |

The PHP full-suite test runner uses `ADMIN_EMAILS=admin@test.example` for the
existing bootstrap test's explicit fixture identity. Runtime defaults/security
ceilings are not lowered. The runner connects to the edge on the private ingress
network; all canonical HTTP successes therefore traverse edge → Yii HTTP → FPM.
Direct SQL fixtures are part of the existing disposable regression suites.

Original Go E2E output:

```text
ShopKMP E2E passed: register, login, PIN, catalog, search, cart, checkout and demo payment
```

The separate original PostgreSQL regression DB was created privately with
`createdb -U shopkmp backend_regression`. `deploy/reference-tests.Dockerfile`
provides the original Go toolchain/tests; the verification overlay mounts
unchanged `core/` read-only for its Kotlin/OpenAPI relative-path comparisons.
No database port was published to run these tests.

### Differential contract scope

`backend-yii2/tests/differential.php` passed exact route/policy comparison,
**14 parsed GET DTO comparisons** (catalog/category/product/reviews/profile/cart/
addresses/orders/order/payment/PIN+biometric devices/session) and **3 representative
errors**, excluding trace IDs only on error comparisons. `tests/cross_runtime.php`
passed Go→PHP and PHP→Go persisted cart/order replay, exact stock/order/replay
counts and both directions of Argon2 PIN/hashed opaque access/refresh/logout.

The original writer existed solely on the new disposable project for these
tests. It was stopped in `finally`; it is absent from base/production Compose.
This is stronger than readonly DTO comparison but remains representative,
not an exhaustive successful/error/input/state differential for all54 operations.

### Production overlay — disposable test PKI only

Generated explicit short-lived test certificates and random secrets:

```sh
python3 scripts/test_pki.py --parent /var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode
```

Printed environment path (contains no secret values in this report):

```text
/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/shopkmp-task4-pki-078hrfzo/test.env
```

With `TEST_ENV` set to that absolute path, these commands were executed:

```sh
python3 scripts/test_infrastructure.py --production-env "$TEST_ENV"
python3 scripts/compose.py --env "$TEST_ENV" --prod config --quiet
python3 scripts/compose.py --env "$TEST_ENV" --prod up -d --build --wait --wait-timeout 300
python3 scripts/production_smoke.py --env "$TEST_ENV" --disposable
```

The production-overlay stack reached **all runtime services healthy**, separate
successful migration/reindex jobs and only one public TLS port58096. It used
project **shopkmp-migrated-tls-e2e**, separate data/network, domain/SNI
**api.shopkmp.test** and a deliberately empty production-mode catalog. No real
deployment or external payment-provider mutation occurred.

Actual static/config results:

```text
development resolved Compose boundaries: PASS
Production secrets, TLS chains/hostnames/keys, CA bundle and source CIDRs validated
production resolved Compose boundaries: PASS
Seven unsafe production configurations rejected: PASS
```

The rejected configurations covered placeholder/short secrets, duplicate secrets,
HTTP provider, all-source CIDR, absent CA bundle, out-of-subnet edge address and
wrong certificate hostname. Server/client certificate purposes and OpenSearch's
exact node DN/unencrypted PKCS#8 key format are also validated.
Resolved models have no direct PHP/BFF/DB/Keycloak/search publications or BFF
DB/identity credentials; production has verifiedTLS DB/provider inputs, no Swagger
mount, and the same service set as development.

Actual TLS smoke result:

```text
PHP PDO verify-full connection uses TLS
FATAL: pg_hba.conf rejects connection ... no encryption
FATAL: pg_hba.conf rejects connection ... no encryption
PASS disposable TLS stack: trusted single-domain HTTPS, private issuer password/JWKS/profile, operational routes hidden, both PostgreSQL plaintext connections rejected
```

The two FATAL lines are the **expected rejected plaintext probes**, not failures.
All seven health/metrics/Swagger/FPM operational paths returned404. Unknown
HTTP Host closes the connection; unknown TLS SNI fails. Categories and anonymous
BFF home returned200, registration/login201/200, authenticated BFF home/profile200,
logout204. A request spoofing an allowed provider IP through all forwarding
headers still returned403 **WEBHOOK_SOURCE_DENIED**. Issuer/discovery/JWKS stayed
on `https://keycloak:8443`; real JWT profile access passed PHP's verified boundary.

The actual production HttpClient was separately exercised with TLS fixtures:

```sh
docker run --rm -e APP_ENV=production \
  -v "$PWD/deploy/tls-probe.php:/opt/tls-probe.php:ro" \
  -v "$TEST_PKI/provider.crt:/run/tls/provider.crt:ro" \
  -v "$TEST_PKI/provider.key:/run/tls/provider.key:ro" \
  -v "$TEST_PKI/ca.crt:/run/tls/ca.crt:ro" \
  shopkmp-migrated-e2e-yii2 php -d curl.cainfo=/run/tls/ca.crt /opt/tls-probe.php
```

Here `TEST_PKI` was the directory containing TEST_ENV. Output:
**`Actual production HttpClient: trusted TLS 200, wrong hostname and untrusted CA
rejected`**. The untrusted-CA case starts a new PHP process because `curl.cainfo`
is system-level configuration. It does not disable adapter/client verification.

## Evidence files

* `deploy/evidence/integration.json`: final CARD journey/policies/race metadata;
  account89303299-5e2d-4fae-8a30-1b28ad42ad28, paid order
  467f65d3-2c6a-4528-99a0-c26ff04e76a7. Credential/demo-query bodies are not saved.
* `deploy/evidence/bff/manifest.json` and captures: final shared-edge BFF run;
  accountb3368e7f-12fc-4033-b449-99f28abe4346, order
  5b932780-911c-4069-b485-fccf622ba34a, unit"0.10", total"0.20".
  Auth/token/key bodies are neither saved nor hashed by the existing live test.
* `deploy/evidence/runtime.json`: actual client-IP/principal,11+1 histories,
  runtime image IDs/statuses and all six graceful exit codes. Stopped test
  reference/fixture entries are explicitly distinguishable from runtime services.
* `deploy/evidence/tls-smoke.json`:15 actual HTTP outcomes plus verified DB TLS;
  failed unknown-Host/SNI and plaintext connection assertions are in the script.
* Ignored `deploy/evidence/*.log`: final command outputs for each named phase,
  including `php-all.log`, `bff-race.log`, `original-e2e.log`,
  `original-tests.log`, `original-postgres-tests.log` and `runtime.log`.

The runtime was initially created with two seed products. Repeated SQL tests
deliberately leave synthetic data; final live tests may select those persisted
products through canonical APIs. They do not invent runtime business responses.

## Failures discovered and corrected during integration

1. Fixed edge .2 initially collided with Docker's dynamic allocation to Yii HTTP.
   Allocated explicit distinct .2/.3/.4 ingress addresses; fresh config/up passed.
2. Seed registration initially absent (observed2 failing tests); added the narrow
   controller/registration. Corrected test bootstrap to use the real Yii CLI and
   used the original BIGSERIAL product-image ID default. Empty seed and unchanged
   populated/production-refusal cases then passed.
3. HTTP race assertion initially expected `CART_EMPTY`; the reviewed canonical
   code is `CART_IS_EMPTY`. Corrected only the integration test expectation.
4. PHP auth bootstrap fixture needs admin@test.example, not the development
   template's optional admin identity. Supplied its fixture env only to the test
   runner; service auth/security was not weakened.
5. A reused disposable DB had direct SQL fixtures not yet indexed. Added explicit
   reconciliation before live search, preserving production indexing behavior.
6. PHP verification container initially lacked the ingress network for `edge` DNS.
   Attached only the **verification runner** to ingress; runtime PHP stays private.
7. Keycloak26.3.3 inherited HTTPS for management despite the HTTP management env
   inherited from development. Fixed the production probe to HTTPS+CA trust,
   observed healthy and real private-issuer password/JWKS success.
8. Tightened the test PKI root's keyUsage for Python's strict verifier. Fixed the
   TLS smoke parser to handle Nginx's HTML404. Production trust was not bypassed.
9. Split maintenance/reconciliation workers so a long reindex cannot starve
   minute maintenance; initialized scheduler heartbeat before its potentially
   long first job. An intermediate make invocation exceeded the tool's180s limit;
   the final complete `make run` passed with real probes and populated seed.
10. Original Go tests in the isolated image initially could not find the existing
    Kotlin DTO source relative paths. Mounted unchanged `core/` read-only; the
    final complete PostgreSQL-enabled suite passed. No original test was skipped
    or modified to obtain that result.

Nginx now re-resolves Docker DNS for recreated upstream containers, logs no query
strings and has finite header forwarding/no automatic upstream mutation failover.
Both request-size layers and actual client-IP correlation are verified.

## Review and remaining release gates

Final review: **author self-review**, honoring no-subagent/no-commit instructions
in the unborn/untracked repository; there is no commit-range diff or independent
Task4 review. Reviewed source/config/test/evidence against every Task4 boundary:
route precedence, no legacy production executable, credentials/networks, proxy/IP,
issuer/JWKS/CA trust, migrations/seed, scheduling, request limits and shutdown.
No outstanding Critical/Important implementation finding was identified in this
review. The earlier service scoped reviews are independent historical evidence,
not an independent approval of this new infrastructure.

Rulings: retain private issuer/header allowlist semantics instead of editing
reviewed service code; use a temporary original writer only for disposable
bidirectional tests; keep the supplied unborn workspace without commits/worktrees.
Costs/constraints are recorded in MIGRATION_PLAN/IMPLEMENTATION_PLAN. No deferred
minor implementation finding is being hidden as a completed release gate.

Still unverified / not claimed:

* Exhaustive differential parity over every54-route input/error/legacy/null/state
  transition and every mixed commerce/payment interleaving; retain original source.
* Real production provider trust chain/reachability/protocol/settlement, external
  ambiguous outcomes/refund/void reconciliation. Local TLS and fixture protocol
  tests prove mechanics, not the operator's actual provider integration.
* Installed authenticated Android/iOS live journey; physical biometrics/key
  prompts, payment-app return and Android process-death instrumentation.
* Production data migration/cutover, backup/restore/PITR/rollback rehearsal,
  sustained load, large-catalog reconciliation, failover/HA and operational alerting.
* Complete OIDC/JWKS key rotation/outage/revocation-latency matrix and inherited
  opaque-session/device/password-revocation limitations documented by Task1.

Existing Task1 `shopkmp-migration-test`/58088 etc and unrelated containers were
not removed. After recording TLS evidence, the additional
`shopkmp-migrated-tls-e2e` stack was removed with its exact test environment and
`--prod down --volumes`; only that newly created project's containers, three
networks and three disposable volumes were removed. The main migrated development
stack remains healthy at **http://127.0.0.1:58095**; a final screen request returned
HTTP200. Its temporary reference/fixture containers are stopped. No actual
production switch was performed.

---

## Independent deployment-review remediation — I1 / M1 (2026-10-18)

Read `docs/DEPLOYMENT_REVIEW.md` completely and verified both findings against
the deployed scheduler/Compose predicates and actual Make/wrapper expansion.
**The independent review is preserved unchanged.** This appendix is implementer
remediation evidence, not an independent re-review/approval. The earlier author
self-review's no-outstanding-finding conclusion and heartbeat-only worker-health
description are superseded by this section and the independent I1 finding.

Edits are confined to deployment scripts/configuration/env templates, Make and
deployment/verification documentation. **No business, BFF or CMP code changed;
no commits/subagents or production writes.**

### I1 — result-aware health, deadlines and actual RED → GREEN

The root cause was not a missing heartbeat: failed jobs updated the same
heartbeat used for readiness. Replaced it with atomic structured state and
separate `live`, `ready` and `status` commands in
`deploy/php/scheduler{,-state,-health}.php`.

* Before the first successful child completion, readiness fails. Last result,
  actual child exit, last success, running PID/start and heartbeat are distinct.
* Failed attempts leave last success unchanged and fail readiness even while
  the parent continues running/retrying. Starting a recovery attempt does not
  clear the failed result; a completed successful retry restores readiness.
* A legitimate in-budget job may remain ready after a prior fresh success.
  Success freshness and each attempt's runtime are finite and monotonic-clock
  based. A stopped/starved parent also loses liveness after5s.
* On runtime expiry, readiness fails, the parent sends TERM and then KILL after5s
  if needed, reaps the child and preserves retries/no-overlap. Timeout result124
  cannot become success merely because the child returned0 during termination.
  A completion observed past its budget after a paused parent is also rejected.
* TERM/INT during ordinary shutdown reaches the active child; parent shutdown is
  bounded and exits0. An interrupted shutdown does not publish a new success.

Defaults: maintenance cadence60s/runtime120s/success age185s; reindex
cadence300s/runtime1800s/success age2105s. Blank success age derives from
cadence + runtime +5s. Env templates expose the budgets. Docker's first-start grace
equals the attempt budget: a legitimate long first run can remain **starting**,
but cannot become healthy until success. The readiness command is already
nonzero for failures during that grace. After the first Docker health success,
a failed probe makes the worker unhealthy (10s interval, one failure).

Observed RED commands:

```sh
python3 scripts/test_scheduler.py
# Against the previous heartbeat-only implementation: 10 tests, 10 failures.
python3 scripts/test_scheduler.py --test overdue_completion
# Against the first remediation: 2 tests, 2 failures (late exit0 accepted).
```

Both modes reproduced false health with **actual original Yii CLI processes**
on `--network none`, an unreachable loopback PostgreSQL URL and unreachable
search. Deterministic duration/recovery cases replace only `/app/yii` with the
test child fixture in `deploy/tests/scheduler-job.php`; the actual scheduler,
process signalling and **exact resolved Compose health predicate** execute.
Only cadence/probe timings and explicit runtime/freshness budgets are shortened.
Fixtures have no business-network access or real credentials/data and remove
only their unique `shopkmp-task4-scheduler-test-*` containers.

An intermediate regression-fixture correction selected Compose's SIGTERM rather
than the PHP image's default SIGQUIT for `docker stop`. The frozen-parent check
was also corrected to wait for the independent5s liveness budget instead of
assuming it equals the shorter readiness/job budget. Neither correction weakened
the required behavior. After the additional late-completion fix, the full
**12 process regressions passed** (six cases for each worker mode):

| Case in each mode | Actual final result |
| --- | --- |
| Unreachable real business dependencies, repeated failed children, live parent | Unhealthy; live probe0; no success recorded |
| First held attempt → success → repeated exit7 → held recovery → success | No early health; failure persists through retry start; completed success recovers |
| Valid in-budget long child → TERM-ignoring overrun → retry/recovery → graceful stop | Healthy only in budget; overrun unhealthy; bounded forced reap; child receives shutdown TERM; parent exits0 |
| Paused parent observes overdue child exit0 | Timeout124; no new success; readiness nonzero |
| Stale success with responsive waiting parent → next scheduled success | Unhealthy while stale; live probe0; recovers after next successful completion |
| Frozen parent after success → resume | Readiness/liveness expire within their bounds; resumed successful work recovers |

### M1 — explicit production stages and wrong-overlay guard

Added `prod-build`, `prod-infra`, `prod-drain`, `prod-migrate`, `prod-reindex`,
`prod-app`, `prod-workers`, `prod-edge`, `prod-rollback-stop`, `prod-down` and
`prod-logs`, using `PROD_ENV_FILE` and **`--prod`** consistently.
Drain first stops/drains edge, then stops BFF/internal HTTP/workers/FPM. Staged
jobs and app/worker/edge starts use `--no-deps`; they cannot implicitly restart a
deliberately stopped writer or rerun jobs outside the explicit sequence.

Generic lifecycle targets remain development-only. The wrapper refuses a
production-named file, `APP_ENV=production`, or production TLS/domain inputs
without `--prod` **before invoking Docker**. Both env templates declare APP_ENV.
Existing `prod-config`/`prod-up` now also support `PROD_ENV_FILE` consistently.
`WAIT_TIMEOUT` defaults to2100s so the new first-success requirement can account
for the configured long reconciliation attempt; the runbook explains sizing it.

`docs/DEPLOYMENT.md` now gives exact production-aware drain/jobs/app/workers/edge
and rollback-stop commands, with explicit single-writer ordering and existing-data
volume/restore prerequisites. It also gives a stop-only command for the preserved
old Compose deployment when that is the actual current writer. Root targets do
not start a legacy writer or select/restore an existing production volume.

Observed RED:

```sh
python3 scripts/test_deployment_commands.py --production-env "$TEST_ENV"
# 4 test methods, 19 failing subcases: wrong-overlay calls reached the Docker
# boundary and production stages were absent.
```

Final result: **4 test methods passed**, including nine rejected generic
lifecycle targets, two production-name/marker cases, and eight production-stage
command sequences. Tests execute actual Make, wrapper and certificate/secret
validation, capturing only Docker's side-effect boundary in a private test shim.
The resolved production model is independently obtained from real
`docker compose config` and checked for production/verify-full/CA settings.
**No production migration, drain or rollback write was executed by these tests.**

### Exact final covering commands and worker recreation

`TEST_ENV` was the previously generated explicit test-PKI environment:

```text
/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/shopkmp-task4-pki-078hrfzo/test.env
```

The normal Docker Desktop credential helper hung during public base-image
metadata resolution; a direct registry probe succeeded while `docker pull`
reported `error getting credentials ... signal: terminated` after tool timeout.
Used a **separate** anonymous CLI configuration for public-image builds, without
changing existing Docker configuration, credentials or TLS verification:

```sh
export DOCKER_CONFIG=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/shopkmp-task4-docker-anonymous
export DOCKER_HOST=unix:///Users/dmitrijbykov/.docker/run/docker.sock
```

That private temporary config contains empty auths plus the installed Docker CLI
plugin directory. With those per-command values, the following completed:

```sh
EDGE_PORT=58095 python3 scripts/compose.py --env .env.example \
  --project shopkmp-migrated-e2e build maintenance reconcile
EDGE_PORT=58095 python3 scripts/compose.py --env .env.example \
  --project shopkmp-migrated-e2e up -d --no-deps --force-recreate \
  --wait --wait-timeout 2100 maintenance reconcile

make test-infra PRODUCTION_TEST_ENV="$TEST_ENV"
make compose-config
python3 scripts/compose.py --env "$TEST_ENV" --prod config --quiet
make integration-check
```

Results:

* Worker images rebuilt successfully from the unchanged Yii Dockerfile; only the
  two owned workers were explicitly recreated for the remediation. Both became
  healthy **after actual successful jobs**, with lastResult success/exit0 and
  non-null success timestamps.
* `make test-infra`: **12 scheduler regressions,0 failures** (82.978s in the final
  covering run); **4 deployment-command tests,0 failures** (4.249s); development/
  production resolved boundaries and seven unsafe-production rejections passed.
* Development and explicit test-certificate production `compose config --quiet`
  passed. Production config/staged command tests did not start a new TLS stack.
* Full `make integration-check`: **exit0**, now including the12 scheduler
  regressions (82.672s in its saved phase log), original E2E, eight live screen
  schemas/six commands, CARD flow/races/access/spoof/bounds, PHP full suite,
  differential/cross-runtime, original Go suites including private PostgreSQL,
  BFF race/vet and runtime graceful-stop checks. No business/BFF/CMP test or
  security limit was modified to obtain a pass.
* Runtime evidence now explicitly checks successful worker readiness **before
  and after** application shutdown/restart. All six stopped services exited0.
  The post-restart SQL reconciliation took approximately **168.9s**, within its
  configured1800s budget; deployment waited for its first real completion rather
  than accepting a fresh heartbeat.

Persistent evidence: `deploy/evidence/scheduler-regression.log` and the updated
system phase logs; `deploy/evidence/runtime.json` adds `workerReadiness` and
`workerReadinessAfterRestart` with actual result/success snapshots. Worker
inspection commands and repeatable `make test-scheduler` / `make test-infra`
are documented in DEPLOYMENT.md. Test containers are removed; reference/fixture
containers remain stopped; existing Task1/unrelated resources are preserved.

### Remediation disposition / remaining limits

I1 and M1 have covering RED→GREEN and final full-system evidence above. This
remediation was self-reviewed; no independent deployment re-review is claimed.
The independent review's release gates remain: real installed mobile journeys,
exhaustive parity, restored existing-data/backup/rollback rehearsal, actual
production-provider integration, load/HA/alerting and JWKS rotation/outage.
No production cutover or real rollback was performed. The migrated development
stack is healthy with only edge published at **http://127.0.0.1:58095**; the final
public SDUI request returned **HTTP200**.
