# Task 6 — search execution ledger and native results

Plan: `PLAN.md`, Task 6; controlling design: `ADR.md`, R9 reads.
Baseline: `6f002deb391146195cb63790e0b70e7f1001e8c4`.

## Scope and rulings

- Execution is in the explicitly assigned shared worktree. Only search-owned
  files are edited; orchestration/core readiness/CLI wiring belongs to coordinator.
- Pre-flight interface: search readiness must be optional for local identity and
  payments. Coordinator must decouple OpenSearch capability readiness from core
  ApiKernel readiness; changing the adapter alone cannot establish this property.
- Pre-flight interface: existing CLI `reindex()` must cease implicitly creating an
  index. Explicit bootstrap wiring is required before application startup.
- Ruling: record this task's ledger here instead of creating a separate shared
  skill workspace or commits, respecting the user's file ownership/no-commit scope.
- Ruling: maintenance keeps a bounded session mutex across short per-product
  transactions, while ordinary writers use transaction locks and SET LOCAL.
  This prevents timeout-setting leakage and avoids accumulating catalog-sized
  transaction-lock sets. Failed maintenance sessions must be discarded by CLI.
- Ruling: private builds perform one final refresh before verification, retaining
  wait-for-refresh for ordinary writes. Native RED proved per-document waits could
  exhaust a four-second eight-product build budget; no production deadline rose.
- Ruling: use native temporary tables for whole-catalog SQL budget fixtures instead
  of globally deactivating shared public products or publishing uncommitted caller
  fixtures. Interrupted/writer races also use real, owned public rows/processes.

## Commands and results

Native runtime: `shopkmp-round5-baseline-php`, arm64,
image ID `sha256:e3a5ce15c6bd56b62c23568718f070e7bfa6f4f8f9acd19fe3fc90db70ba6665`.
No host PHP is installed. Native DB network: `shopkmp-round5-e2e_data`.
Secrets are loaded from the example environment and never printed.
Final direct runtime probes reported PHP **8.3.36**, PostgreSQL **17.11**,
OpenSearch **2.19.7**.

- RED: `docker run --rm --network none --mount type=bind,source=$PWD/backend-yii2/tests,target=/app/tests,readonly shopkmp-round5-baseline-php php tests/round5_search_protocol.php`
  → exit 1, **32 tests / 28 failures** on baseline production code. Four existing
  validations already rejected their negatives. Initial attempt exposed the old
  SocketUpstream exception escaping into the child test runner; the search-only
  fixture now exits failed children immediately. The corrected RED is authoritative.
- GREEN: same command with the additional read-only mount
  `source=$PWD/backend-yii2/infrastructure,target=/app/infrastructure,readonly`
  → exit 0, **32 / 0**. Expected sanitized negative-fixture diagnostics only.
- Owned OpenSearch startup:
  `PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH python3 -B scripts/compose.py --env .env.example --project shopkmp-round5-e2e up -d --build --no-deps --wait --wait-timeout 180 opensearch`
  → exit 1, unhealthy at the wait boundary; own-container logs show native 2.19.7
  starting/restarting. No other service was selected or altered.

## Native environment recovery

`docker events --since 10m --until 1s --filter container=shopkmp-round5-e2e-opensearch-1 --filter event=die --filter event=oom`
confirmed OOM termination / exit 137. The initial 1 GiB service with its default
512 MiB heap repeatedly restarted; this was an environment failure, not a GREEN.

Only the owned service was recreated with this invocation:

```sh
PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" \
OPENSEARCH_JAVA_OPTS="-Xms256m -Xmx256m -XX:ActiveProcessorCount=2" \
python3 -B scripts/compose.py --env .env.example --project shopkmp-round5-e2e \
  up -d --no-build --no-deps --force-recreate --wait --wait-timeout 180 opensearch
```

Exit 0 / healthy, no restarts subsequently observed. Production source/config
heap limits and deadlines were not changed. Native distribution: OpenSearch 2.19.7
from the repository's pinned/hardened image; only disposable JVM options differ.
Own indices/volume/service are retained for coordinator inspection; no unrelated
running project was stopped, reconfigured or removed.

## Reproduction commands

From repository root, the following is the native prefix used for all commands
labelled `NATIVE` below. The environment file supplies the password without printing
or interpolating it into command text; the test fixture constructs the canonical
`postgres` / `shopkmp` user/database URL privately.

```sh
docker run --rm \
  --network shopkmp-round5-e2e_data \
  --network shopkmp-round5-e2e_application \
  --env-file .env.example \
  -e ROUND5_SEARCH_DISPOSABLE=shopkmp-round5-e2e \
  -e OPENSEARCH_URL=http://opensearch:9200 \
  --mount "type=bind,source=$PWD/backend-yii2/tests,target=/app/tests,readonly" \
  --mount "type=bind,source=$PWD/backend-yii2/infrastructure,target=/app/infrastructure,readonly" \
  shopkmp-round5-baseline-php php tests/round5_search_native.php
```

For `UNIT`, replace both network arguments/environment options by `--network none`,
retain both mounts/image, then run the specified Composer/PHP command. The initial
baseline RED omitted the infrastructure mount to exercise baseline adapter code.

## RED → GREEN evidence and retained intermediate failures

| Command / phase | Observed outcome |
|---|---|
| Baseline protocol RED above | Exit 1, 32 tests / 28 failures |
| Initial native attempt before service recovery | Exit 1, 7/7 failed; transport unavailable plus missing bootstrap. Not accepted as a valid semantic-only RED |
| NATIVE `php tests/round5_search_native.php`, recovered service, before lifecycle implementation | Exit 1, 7/6: absent bootstrap and reindex implicitly creating a target |
| First lifecycle implementation | Exit 1, 7/2: OpenSearch reordered `_meta` object keys; diagnostic showed `[build,state,version]` versus insertion order. Strict value equality now ignores property order |
| Corrected initial lifecycle | Exit 0, 7/0 |
| Extended interrupted/writer/native semantics checks | Exit 0, 11/0; one intermediate best-effort scroll-cleanup warning retained, not presented as an error-free run |
| Extended 13-test run | Exit 1, 13/2: worker fixture incorrectly imposed 5s on a 20s writer, plus build refresh timeout. Harness now allows the actual work+cleanup budget |
| NATIVE eight-product/four-second build before single final refresh | Exit 1, 14/2: constrained build and fresh recovery exhausted per-document refresh waits |
| After final-refresh change | Eight-product build passed; the old short-deadline fixture failed because the build now finished within 300ms. Replaced its timing assumption with a real PostgreSQL `pg_sleep(1)` cancelled by the production remaining statement_timeout |
| Error-envelope scroll cleanup regression | UNIT inventory exit 1, 17/1: usable returned scroll ID was not cleared when decoding rejected the error envelope; capture precedes common envelope rejection now |
| Corrected scroll cleanup | UNIT inventory exit 0, 17/0 |
| Intermediate Composer suite | Exit 1, inventory 17/2: generic fixture's 400ms idle close raced parent scheduling. Search-only server now uses explicit control-channel shutdown; no production timeout was raised |
| First legacy native replay | Search 2/0, round3 27/0, round4 catalog 9/0; stage 2/1, create-index HTTP timeout. Retained |
| Later legacy native replay | Search/round3 passed; catalog 9/1 (expected parser 400, got SQLSTATE 57014 / 503) after failed search SQL cleanup. Stage was not reached |
| NATIVE `php tests/round5_search_native.php 'business session'` | **RED exit 1, 1/1**: native injected cleanup cancellation left caller settings `1s/1ms` instead of `10s/8s` |
| Transaction-scoped normal writes / SET LOCAL fix | Native all-test GREEN below includes the same regression; no session-wide search timeout restoration is needed for normal writes |

The metadata-order diagnostic had an additional unmarked-index warning while
inspecting a prior intentionally created target. An early cleanup diagnostic used
unsupported `size:0` with scroll (400); the corrected native 500-hit request
returned cleanup 200 / `succeeded:true` / one freed context. These diagnostics are
not substituted for the actual test suite outcomes.

## Latest verified results

| Command | Exit / result |
|---|---|
| UNIT `composer test` | 0; 7 domain + 7 transaction + 1 request + 17 inventory = **32/0** |
| UNIT `php tests/round5_search_protocol.php` | 0; **32/0** |
| NATIVE `php tests/round5_search_native.php` | 0; **15/0**, real PG/OpenSearch, real worker termination, native lock contention and SQL cancellation |
| NATIVE `php tests/round5_search_regressions.php` | 0; search **2/0**, round3 reads **27/0**, round4 catalog **9/0**, round4 index **2/0** = **40/0** |
| UNIT `composer lint` | 0; **109 PHP files** at that mounted snapshot |
| Scoped `git diff --check` | 0 |

After adding staging counter output, a focused native replay of
`php -r 'putenv("DATABASE_URL=postgres://shopkmp:".rawurlencode(getenv("POSTGRES_PASSWORD"))."@postgres:5432/shopkmp?sslmode=disable"); require "/app/tests/round4_index.php";'`
under the NATIVE Docker prefix exited 0, **2/0**, with both fixtures reporting
`STAGE BUDGET data=4 controls=10`. Composer lint was rerun afterwards: exit 0,
109 files. No password value appears in this command or its output.

The last 15-test run measured the eight-document build at **3.718 seconds** within
its explicit 4s work budget. An earlier post-refresh observation was 0.251s. These
are raw shared-host observations, not a latency percentile, benchmark or new SLA.
Expected negative fixtures print fixed integration/transaction diagnostics. Latest
legacy replay did not print the previously observed search cleanup/update errors.

## Budgets and assertions retained

- Public full/card search hydration: **two native SELECTs** for the 20-hit fixture;
  rank, current SQL values, stale inactive exclusion, projection and totals retained.
- Existing catalog/order/popular 0/1/20/100 fixtures retain their prior query budgets
  and payload checks. Their execution is a compatibility regression, not a claim of
  implementing/coordinating the separate order-summary task.
- One-active-product/root-category staging retains **four DATA SELECTs** plus
  **10 measured admission/fence/timeout control statements**. Inactive history
  is not reconciled by a stage build. Native fixture prints both counts.
- Maintenance defaults: 100,000 rows/documents, 900s work; explicit maximum
  1,000,000 and 1,800s. Inventory defaults 120s, 500-hit pages, 1m scroll lifetime.
- HTTP 5s maximum per normal operation call, 8 MiB response cap; shared remaining
  work time is propagated. SQL uses remaining transaction-local time capped at 10s;
  readiness shares its configured budget; normal writer work is bounded to 20s.
- Scroll/maintenance cleanup uses separate bounded 1s work. No mutation replay,
  automatic target adoption, index deletion, alias or config switch.

## Completion boundary / coordinator actions

Task 6 search-owned implementation and focused native acceptance are verified.
`SEARCH_ADR.md` contains the exact six wiring groups: CLI bootstrap/bounds,
bootstrap-vs-reconcile orchestration, optional search capability readiness, common
index/auto-create config, test registration, and final source-bound acceptance.
No MaintenanceController, ApiKernel, Composer/all.php, Compose/env or other
coordinator-owned file was edited by this task. Their concurrent work is retained.

NOT_RUN here: rebuilt final all-task PHP image/full native `composer test:all`,
actual local-auth/payment HTTP behavior with search down/building, fresh final
Compose startup/bootstrap, production TLS-loaded configuration, multi-role
configuration cutover/rollback, 100k/1m catalog stress, maximum ancestry fixture,
lost final marker/SQL commit acknowledgement fault, full architecture/security
release gates and independent fresh-context review. Native tests apply then restore
the auto-create value dynamically on the owned test node; development orchestration
still needs its owner to wire the setting. Benchmark fixture was updated for
explicit bootstrap but its performance run was not repeated.

Final review: self-review of the search-only diff/helpers and failure paths (no
subagent tool available). Coordinator's independent integration review is pending.
No commit, push, production rollout or cleanup of retained owned search resources.

Task 6: search-owned implementation verified; latest unit 32/0, protocol 32/0,
native lifecycle 15/0, native legacy 40/0, staged-data budget 4+10 and syntax 109.
Integration completion is pending the coordinator wiring/NOT_RUN gates above.
