# Task 4 deployment subset — runtime evidence

Source baseline: `6f002deb391146195cb63790e0b70e7f1001e8c4`, 2026-10-30.
Execution is in the explicitly requested shared tree. No commits are made.

## Scope and interface decisions

- Deployment/config/scripts/docs only; application, Composer, migration SQL,
  BFF and mobile changes belong to the coordinator/other workers.
- The retained one-shot `reindex` service invokes `maintenance/search-bootstrap`;
  the controller is coordinator-owned. The recurring reconciliation command
  remains `maintenance/reindex`.
- Application startup waits for SQL migration, not the search initialization job.
  PHP readiness must independently enforce search capability isolation.
- Retired storage is not deleted. No volume removal, prune or production mutation.
- The full dependency guard remains mandatory even while coordinator-owned
  application/dependency references make it RED. No live-source exceptions.

## Commands and results

- `git status --short && git rev-parse --short HEAD`: exit 0; only new coordinator
  ADR/PLAN directory initially untracked; HEAD `6f002de`.
- `docker version && docker compose version`: exit 0; native Docker client/server
  29.6.1, arm64 daemon, Compose v5.3.0.

Further RED/GREEN and integration observations are appended during execution.

## RED → GREEN (deployment/scripts)

- `python3 scripts/test_backend_integration.py`: initially exit 1, 8 tests with
  4 failures: old identity graph in all three overlays and old initial search
  command. After removal: exit 0, **8/8**. The first new scheduler assertion had
  incorrectly expected direct `yii`; corrected to the unchanged deployed
  scheduler wrapper after inspecting the resolved command (not a runtime change).
- `python3 scripts/test_test_pki.py`: initially exit 1 on retired secret generation;
  after removal exit 0, **1/1**. Remaining API/SQL/search/provider PKI is preserved.
- `python3 scripts/test_runtime_graph_guard.py`: initial RED for missing CLI;
  subsequent individual RED for unsupported historical inventory and an archive
  mounted into live Compose; GREEN **8/8** after implementation. A real false
  positive in `AndroidConvention` was reproduced and fixed with word boundaries,
  without changing mobile source or allowing it as an exception.
- `python3 scripts/test_runtime_checks.py`: RED for missing inventory validator;
  GREEN **3/3**. It rejects missing/unhealthy workers, incomplete init jobs,
  retired orphan/env state and private published ports, without leaking env text.
- `python3 -m unittest discover -s scripts -p 'test_*.py' -v`: exit 0,
  **31 tests, 0 failures** (2026-10-30 15:20 UTC run). Scanner timeout/unhealthy
  artifact messages are expected fail-closed policy fixtures, not suite failures.
- `make compose-config`: exit 0.
- `python3 scripts/route_matrix.py --check`: exit 0, **54 exact operations/policies**.
- `python3 scripts/test_pki.py --parent <approved temporary directory>
  --project shopkmp-v6-runtime-tls-e2e --port 58219
  --edge-subnet 172.31.218.0/24 --fpm-subnet 172.31.219.0/24`: exit 0.
  Generated test env: `/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/shopkmp-task4-pki-wgd8ztqd/test.env`.
- `python3 scripts/test_infrastructure.py --production-env <that test.env>`:
  exit 0; dev/prod boundaries and **seven unsafe configurations rejected**.
- `python3 scripts/test_deployment_commands.py --production-env <that test.env>`:
  exit 0, **5/5** real Make/wrapper/production-validation boundary tests.

## Native image/runtime observations

Raw command receipts and logs are in the approved temporary directory
`/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/v6-runtime-evidence/`.
No credentials or resolved environment dumps are recorded.

- `python3 scripts/compose.py --env .env.example --project shopkmp-v6-runtime-e2e
  build postgres migrate`: exit 0. BuildKit cache used; new tags were built under
  the updated graph without an identity image. Receipt: `infra-build.json`.
- `go -C tools/ops test ./...`: exit 0 (migration package cached).
- `go -C tools/ops vet ./...`: exit 0.
- `python3 scripts/test_php_runtime.py --image shopkmp-round5-e2e-yii2`:
  exit 0, **5/5**, actual image
  `sha256:7fa49062abf5f925ef423877a1a244badcb738e35b48ef2340bcb12d7d25bd44`.
  This is the existing shared PHP image, not a claim that coordinator-owned auth
  or Composer changes were rebuilt/tested. It exercises extension/TLS/codecs,
  library pruning, Composer/CLI availability and actual FPM admission/ping.
- `python3 scripts/test_scheduler.py --image shopkmp-round5-e2e-yii2`:
  first invocation interrupted by harness 120s timeout, no suite/exit PASS claimed.
  Its single uniquely named test container was removed; other projects/data were
  untouched. Second invocation completed with exit 1, **18 tests / 2 failures**:
  `maintenance_run.test_stale_success_fails_with_live_parent_then_recovers`
  (expected transient unhealthy not observed) and
  `maintenance_reindex.test_first_success_failure_retries_and_recovery_drive_health`
  (expected child exit 7, observed timeout 124). Timing scale was 1; assertions
  were not removed or weakened. Receipt/log: `scheduler-complete.*`.
- Fresh `up -d --no-build --wait --wait-timeout 60 postgres` on the new disposable
  project: exit 1, Docker **all predefined address pools have been fully subnetted**.
  Dependent migration command was NOT_RUN in that chained invocation. Receipt:
  `fresh-postgres.*`. No network pruning or old volume deletion was attempted.

### Follow-up native results (failures retained)

- `SCHEDULER_TEST_TIME_SCALE=2 python3 scripts/test_scheduler.py --image
  shopkmp-round5-e2e-yii2`: exit 1, **18 tests / 3 failures**, all assertions enabled.
  Failures: `maintenance_run.test_frozen_parent_cannot_reuse_recent_success`,
  `maintenance_run.test_legitimate_running_job_is_bounded_and_shutdown_signals_child`,
  and `maintenance_reindex.test_legitimate_running_job_is_bounded_and_shutdown_signals_child`.
  Receipt/log: `scheduler-scale2.*`. The scheduler gate is **FAILED**, not waived;
  production scheduler scripts and their tests were not modified by this subset.
  Resource/timing sensitivity is observed, not a proven root-cause diagnosis.
- First explicit disposable data subnet `172.31.214.0/24` failed with overlap.
  Read-only `docker network inspect` showed an existing `172.31.0.0/16` network.
  No existing networks were stopped/pruned. Receipt: `fresh-postgres-explicit-network.*`.
- With the temporary overlay
  `/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/v6-runtime-network.yml`
  selecting unused data subnet `10.250.214.0/24`:

  ```sh
  docker compose --env-file .env.example -p shopkmp-v6-runtime-e2e \
    -f docker-compose.yml -f <temporary-overlay> \
    up -d --no-build --wait --wait-timeout 60 postgres
  docker compose --env-file .env.example -p shopkmp-v6-runtime-e2e \
    -f docker-compose.yml -f <temporary-overlay> run --rm --no-deps migrate
  ```

  Both exit 0. Actual PostgreSQL container healthy; actual migrator reports
  `database schema is up to date`. Receipts: `fresh-postgres-network250.*` and
  `fresh-migrate-network250.*`. This proves only the fresh **SQL/migrator subset**,
  not the full API/search/payment stack. The overlay changes only data-network
  allocation, not services, trust/security, commands, ports or dependencies.
- Own disposable project `down` (without volume deletion): exit 0; only its
  PostgreSQL container and test network removed. Existing stacks/volumes untouched.
- Final `python3 -m unittest discover -s scripts -p 'test_*.py' -v`:
  exit 0, **31/31**, including tools-profile seed environment checks.
- Final route-matrix check and `git diff --check`: exit 0.
- Built image IDs (both `linux/arm64`): PostgreSQL
  `sha256:4a3754b621f2d772473d7fea2bdd1ad850edc0fec97c478ce0eccff7d0e4369b`;
  migrator `sha256:563fddacd2c986e298775ad984f89209f37802871fbef011dcb3658a0371bebe`.
  `docker volume inspect shopkmp-v6-runtime-e2e_postgres_data`: exit 0 after
  teardown, confirming the disposable data volume is physically retained.

## Review and handoff

Self-review of owned Compose/CI/Make/script/doc diffs; no independent reviewer
tool was available. The coordinator retains whole-change review ownership.
Archive hashes match the originals. Current guides and executable files cannot
be allowlisted, and a mounted historical policy is rejected by a RED→GREEN test.

Rulings: keep `reindex` as the existing service/receipt identity while changing
only its initial command; retain all payment/SQL/search/image/security gates;
retain old report bytes in explicit history instead of rewriting their outcomes.
If these interface choices differ from coordinator composition, the controller
name/job invocation must be aligned before an exact-image integration run.

Final self-review correction: a provider-named binary could previously bypass the
text detector. Added a RED→GREEN test and moved the filename check before binary
content detection. The original realm/client screenshot is now explicitly
content-hashed historical evidence, not an implicit binary-file exception.
After that correction, full script discovery exits 0: **32/32** (guard **9/9**).
The real source guard still exits 1: **111 findings, 62 exact historical files**;
no owned runtime/config/script dependency finding remains, and no failed
integration/native scheduler gate has been relabelled as passing.

## Exact owned file set

Paths below are repository-relative. Other shared-tree changes belong to the
coordinator/search/order workers and were not edited by this runtime subset.

Modified:

```text
.env.example
.env.prod.example
.github/workflows/ci.yml
Makefile
README.md
backend-sdui/README.md
backend-yii2/.env.example
backend-yii2/README.md
docker-compose.yml
docker-compose.prod.yml
docs/ARCHITECTURE.md
docs/DEPLOYMENT.md
docs/ROUTE_MATRIX.md
docs/backend-fixes/MIGRATION_ROLLBACK.md
docs/backend-fixes/SESSIONS.md
scripts/backend_artifacts.py
scripts/backend_evidence_secrets.py
scripts/backend_security.py
scripts/integration.py
scripts/probe_java_runtime.py
scripts/production_smoke.py
scripts/route_matrix.py
scripts/runtime_checks.py
scripts/test_backend_integration.py
scripts/test_deployment_commands.py
scripts/test_infrastructure.py
scripts/test_php_runtime.py
scripts/test_pki.py
scripts/test_test_pki.py
scripts/validate_production.py
scripts/verify.py
tools/ops/README.md
```

New:

```text
scripts/runtime_graph_guard.py
scripts/runtime_history_allowlist.json
scripts/test_runtime_graph_guard.py
scripts/test_runtime_checks.py
docs/backend-fixes/round-5-local-auth/RUNTIME_RESULTS.md
docs/backend-fixes/local-auth/history/README.md
```

Byte-preserving archive destinations (original paths remain visible in Git diff):

```text
docs/backend-fixes/local-auth/history/shopkmp-realm.json
docs/backend-fixes/local-auth/history/SHA256SUMS
docs/backend-fixes/SESSIONS.md → docs/backend-fixes/local-auth/history/SESSIONS.pre-v6.md
docs/backend-fixes/MIGRATION_ROLLBACK.md → docs/backend-fixes/local-auth/history/MIGRATION_ROLLBACK.pre-v6.md
```

Removed executable files:

The retired identity image Dockerfile (exact original path in the Git deletion),
plus:

```text
tools/ops/cmd/healthcheck/main.go
tools/ops/cmd/healthcheck/main_test.go
```

## Integration gates still open

- Full source guard currently exits 1 for coordinator-owned auth adapters,
  Composer, old PHP suites/OpenAPI metadata and current plan references. It scans
  tracked plus new nonignored files; **62 exact historical files**, no live-source
  exceptions. This RED is intentional evidence, not a completed integration gate.
- `maintenance/search-bootstrap` controller wiring and search-independent PHP
  readiness are coordinator integrations; Compose removal alone does not prove
  native auth/payment survival during a search outage.
- Fresh complete stack/54-route HTTP/auth/payment/trust/restart/TLS smoke and
  exact rebuilt-image receipts/scans on both architectures remain NOT_RUN here.
- Mobile/build source diff against `6f002de`: exit 0, unchanged. Backend CI diff
  removes only the retired image matrix entry and adds the graph gate; Android/
  iOS job content is preserved. No production cutover, commit or push occurred.
