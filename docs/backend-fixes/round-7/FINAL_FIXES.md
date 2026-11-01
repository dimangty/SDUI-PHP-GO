# Round 7 — focused final-review fixes, 2026-11-01

**I1 and I2 fixed. Fresh canonical backend verification: PASS.** Baseline HEAD
remains `160d2a5a535791c75c36c2f70a90515153339e87`; changes are uncommitted and
the Git index is empty. This wave implements only the two Important findings and
the requested historical-handoff corrections.

## I1 — bound actual downstream readiness work

Root cause: Go's two-slot semaphore bounds the lifetime of `up.Ready(ctx)`, not
PHP work. Cancelling the HTTP client releases that semaphore while FPM can keep
probing until the existing 600ms active deadline. The old 750ms inter-pair sleeps
hid this distinction.

### Small design

- `ReadinessAdmission.php` takes `LOCK_EX|LOCK_NB` on one of two fixed application
  files, opened **`rb`**, before the probe can resolve/connect/query the DB.
  Local Linux Docker supports exclusive flock on read-only descriptors; verified
  with real packaged PHP as **www-data on a read-only root filesystem**.
- Files are precreated in `infrastructure/readiness-slots/`; Docker makes the
  directory **0555** and files **0444**. Runtime/CLI/root never creates or writes
  them. Keep these stable local inodes with the application, not on NFS/SMB.
- Policy receives admission explicitly alongside the existing budget; the
  existing explicit clock/cancel callbacks remain. Admission is cross-process,
  nonblocking, per container, and uses no health cache or background task.
- The resource closes in policy `finally`, after the required probe's own native
  cleanup has ended. Errors, deadlines and unobserved client disconnects cannot
  release it early. Saturation is **503 with zero probe I/O**. Required failures
  remain 503; admitted healthy requests retain 200 and capability negotiation.
- Liveness bypasses admission. FPM pool **12**, **600ms active / 700ms PHP core /
  2s BFF** budgets, and the corrected native pending-connect poll are preserved.
  Go has only a corrected comment about the scope of its semaphore.
- Healthy native concurrency now asserts **two admitted callers × five**, all
  200. The former arbitrary 8×5 all-200 expectation is not valid under deliberate
  overload; saturation is explicitly tested at policy and real kernel boundaries.

### RED → GREEN

Private evidence root and execution helper:

```sh
T=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode
E="$T/round7-final-fixes"
H="$T/round7_final_fix_runner.py"
P=shopkmp-round7-acceptance-e2e
```

| Executed command | Exit and result |
| --- | --- |
| `docker run --rm --network none --entrypoint php -v "$PWD/backend-yii2/tests/readiness_policy.php:/app/tests/readiness_policy.php:ro" "$P-yii2" tests/readiness_policy.php` before the fix | **1**; 6 tests, 2 failures: third probe returned 200 instead of 503, including across independent PHP processes |
| `python3 "$H" live rapid-red "$P-yii2"` against unchanged old package `4693bfd8…bb8851` | **1**; 80 cancellations/replacements, **50ms**, no drain gaps; peak native sockets **13**; PHP liveness **2603.927ms**, overload response **2748.520ms** |
| `docker run --rm --network none --read-only --user www-data --entrypoint php shopkmp-round7-final-fixes-php tests/readiness_policy.php` | **0**; 6/6, shared admission, zero callback I/O on saturation, independent process exclusion, error/cancel release |
| `python3 "$H" native shopkmp-round7-final-fixes-php` | **0**; policy 6/6, native readiness 15/15, demo identity 15/15 at that checkpoint |
| Final canonical `php-all` phase after adding real kernel saturation transport assertions | **0**; policy **6/6**, readiness **16/16**, demo identity **15/15**; saturation **0 TCP attempts / 0 bytes**, live 200; healthy 2×5 all 200 |
| `python3 "$H" live rapid-green shopkmp-round7-final-fixes-php` | **0**; peak native/session counts **2/2**, zero retained work/sessions, healthy recovery |
| `python3 "$H" live rapid-canonical-green "$P-yii2"` against the final canonical image | **0**; final-package results below |

The persistent regression is `scripts/test_readiness_cancellation_live.py`.
`--help` documents its explicit fixture/config/stats/database ownership inputs.
The helper uses the retained owned Go → Nginx/FPM → packaged PHP fault fixture;
actual PostgreSQL authentication succeeds, then the peer suppresses real SQL
replies. Neither PHP application source nor native return values are replaced.
The script checks Compose project labels and loopback URLs, starts/stops only
the five explicitly owned transport services, and does not manage the parent DB.

Final `rapid-canonical-green/result.json`:

| Observation | Actual |
| --- | --- |
| Two continuous cancel/replace lanes | **80 requests**, client closes at **50ms**, **zero inter-replacement drain sleeps** |
| Peak active native connections / peak observed real SQL sessions | **2 / 2** |
| Go / PHP liveness maximum during storm | **2.555 / 2.755ms**, all 200 |
| Required-stall PHP responses during storm | **21 samples**, all 503, maximum **605.050ms**; prompt saturation also asserted `<100ms` |
| Native connections / readiness SQL sessions after final drain | **0 / 0** |
| Optional accepts / application bytes | **0 / 0** |
| Healthy ready recovery | **20/20 HTTP 200**, each `<2s` |

## I2 — make privilege regressions mandatory

- `make test-service-contracts` executes **all three deterministic runners**:
  `test_service_privileges.py`, `test_service_command_config.py`, and
  `test_search_schema_protocol.py`. It uses the explicitly selected, already-built
  `PHP_TEST_IMAGE`, exported correctly to runner shells (and overriding the legacy
  service-test alias). It never builds an image itself.
- `test-infra` requires that target. The PHP CI job runs it immediately after
  `make test-php`, with the same `PHP_TEST_IMAGE`. The separate contracts CI job
  runs the Docker-free wiring guards via `test-runtime-graph`; it does not depend
  on a PHP image built in another job.
- `verify.py` resolves **immutable `.Image` IDs from the actual current project
  containers** for PHP/Postgres/OpenSearch. Missing/ambiguous image bindings fail
  immediately. After standard verification it requires all three deterministic
  runners and the actual live `test_search_privileges_live.py --run` invocation,
  passing all three image flags explicitly. Every nonzero runner exit fails
  canonical acceptance, so `backend_artifacts.py` cannot mark that run tested.
- The live subharness requires an explicit PHP image, inspects all supplied
  images, disables build/pull on node startup, and rejects an occupied generated
  project. It uses its own random project/TLS material, exercises real allowed/
  denied actions, PHP catalog paths and restart persistence, propagates failures,
  stops/removes only its own nodes/network, and **retains its volumes**. It never
  stops the main stack or rebuilds a tested artifact before security verification.
- Existing source binding already includes `scripts/`, Makefile, CI, application
  and deployment inputs. All new gate inputs are included in the fresh receipt.

### RED → GREEN wiring and real gates

| Executed command | Exit and result |
| --- | --- |
| `python3 scripts/test_verify_lifecycle.py` before wiring | **1**; required runner invocation missing and injected runner failures did not fail verification |
| `python3 scripts/test_privilege_wiring.py` before Make target | **1**; missing target; no required runners executed |
| `python3 scripts/test_verify_lifecycle.py` after wiring | **0**, **8/8**; executes real `verify.main()` through subprocess boundaries; asserts all image arguments/env, each gate failure exit **7**, fail-fast, no rebuild, absent binding failure |
| `python3 scripts/test_privilege_wiring.py` after wiring | **0**, **2/2** including three failure subcases; executes actual Make recipes with recording executable; image propagation and each runner's nonzero failure verified |
| `make test-service-contracts PHP_TEST_IMAGE=shopkmp-round7-final-fixes-php` | **0**; service privileges **7/7**, command configuration **2/2**, schema runner **1/1**, **28** actual TLS protocol scenarios |
| `make test-runtime-graph` | **0**; new guards plus existing backend layer/graph/runtime tests; zero findings |
| `python3 "$H" search` | **0**; actual disposable live TLS OpenSearch roles/catalog/restart matrix using explicit owned PHP/Postgres/OpenSearch images |
| Fresh canonical verification | **0**; all four actual gates invoked and passed after the standard runtime phase; see `canonical-test.log` and `canonical/{service-privileges,service-command-config,search-schema-protocol,search-privileges-live}.log` |

## Fresh canonical receipt and remaining release gates

Executed using the retained project's explicit isolated **220–224/24** network
environment in `$T/round7-verification/environment.json`, through
`python3 "$H" canonical`:

```sh
make integration-up E2E_PROJECT="$P" E2E_PORT=58207 \
  ARTIFACT_RECEIPT="$E/artifacts.json"
make integration-check E2E_PROJECT="$P" E2E_PORT=58207 \
  ARTIFACT_RECEIPT="$E/artifacts.json" VERIFY_EVIDENCE_DIR="$E/canonical"
```

Both **exit 0**. Build **06:57:37–06:58:16Z**; successful canonical test finished
**07:06:32Z**. Full PHP/search/native, BFF race/live/vet, ops, focused backend fixes,
worker restoration and actual runtime stop/restart all passed before the four
new mandatory gates. No new skips or increased budgets were introduced.

- Receipt: **`$E/artifacts.json`**, `buildExitCode:0`, `testExitCode:0`,
  **`tested:true`**, **503** bound source inputs.
- Sorted compact-JSON source-map SHA-256:
  **`023f7403f3dbc97165e3a553eef55ab43473d107a249b1bf815ecb8328769bf3`**.
- `python3 "$H" summary`: **exit 0**; independently called
  `verify_receipt(..., require_tested=True)` against fresh source snapshot and
  **all 12** required real service/container image bindings before shutdown.
- Final PHP image: **`sha256:bfc051b09f7811c49368e7481ff74ffc90eb7c1db23be8512a445ba9c0339778`**.
  Final BFF: **`sha256:9b164c78539bbd8be3b8368a8ae5c134ef560d71b3faa71f278238040f296b53`**.
- Actual Postgres: `sha256:e5d9360ae1753d6451b92d18024afe3746235883edcfdf9ac52954783fa480dc`;
  OpenSearch: `sha256:9e8e4dbcce0c9899d421bf46ad9c0b33143765e04b57f514313e6bc2b316ea27`.
  These are the IDs passed to the canonical live subharness, not historical tags.
- Supplemental `make test-php PHP_TEST_IMAGE=shopkmp-round7-final-fixes-php`:
  **exit 0**, existing **74** unit tests, **6** policy tests, PHP lint **143 files**.
  Build retains existing PSR-4 diagnostics for pre-existing test/fixture classes.
- Full configured PHPStan max / PHP 8.3 target, current infrastructure/application
  and config mounted read-only into the existing tools image: **exit 0**, no
  errors or suppressions. Exact command:
  `docker run --rm --network none --entrypoint php -v "$PWD/backend-yii2/infrastructure:/app/infrastructure:ro" -v "$PWD/backend-yii2/application:/app/application:ro" -v "$PWD/backend-yii2/dev-tools/phpstan.neon:/app/dev-tools/phpstan.neon:ro" shopkmp-round7-tools-task5 /app/dev-tools/vendor/bin/phpstan analyse -c /app/dev-tools/phpstan.neon --no-progress`.

**Backend fix blockers: none observed.** Coordinator's scoped re-review and fresh
canonical/exact-artifact security rerun remain the next integration gates.
The seven-image scanner was not rerun in this wave; this functional receipt
does not supersede the security failure recorded in `TEST_RESULTS.md`.
Native Safari/keychain/Android/production remain **release-blocked and outside
this backend judgement**, including the final reviewer's declined-to-judge scope.
No UI, keychain, platform source or installed-device state was edited.

## Exact source scope and resource handoff

- Runtime: new `ReadinessAdmission.php` and two lock files; policy admission;
  Docker immutable-file permissions; Go semaphore comment only.
- Tests: `tests/{readiness_policy,readiness}.php`,
  `scripts/{test_privilege_wiring,test_verify_lifecycle,test_readiness_cancellation_live}.py`.
- Mandatory wiring: `Makefile`, `.github/workflows/ci.yml`, `scripts/verify.py`;
  explicit-image/ownership startup guards in `scripts/test_search_privileges_live.py`.
- Analysis/docs: add admission to `dev-tools/phpstan.neon`; append application
  README/READINESS; mark obsolete dev-tools README and DEMO_PAYMENT_TESTS handoff
  prose historical/superseded by `TEST_RESULTS.md`; this report. RED history stays.

`python3 "$H" cleanup`: **exit 0**; only the owned acceptance project was
stopped again. Rapid fixture stopped itself after RED and both GREEN runs.
Both live-search subharnesses removed only their own containers/network and kept
SQL/search volumes and private TLS material. Main project containers/networks and
all volumes are retained; foreign running stacks were not managed. Existing
root `integration.json` and `local-auth-runtime.json` historical evidence was
restored to its pre-wave bytes; the existing preservation check passed **exit 0**.
Fresh per-phase logs/receipt/rapid reports remain under `$E`.

`python3 "$H" final-check`: **exit 0** after shutdown; current source map still
matches the receipt, all new runner inputs are bound, all 12 retained service
containers are stopped with their tested immutable images. Application/worker/SQL
containers exited 0; OpenSearch retains its normal signal exit **143**. The raw
transport fault fixture required force-stop and retains **137**, separately from
the application services. Both fresh TLS subharness SQL/search volumes were
independently listed and retained.

Final review was inline against the two findings and resource/source boundaries;
no subagents, staging, commit, push or production operation. `git diff --check`
and empty-index check passed **exit 0**.
