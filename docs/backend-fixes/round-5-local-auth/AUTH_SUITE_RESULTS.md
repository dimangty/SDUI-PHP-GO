# V6 auth-suite/recovery migration results

This records this agent's owned task. **Focused auth/recovery migration is GREEN;
whole-suite/deployment acceptance is NOT GREEN.** No commits were made.

## Scope and rulings

- Shared checkout explicitly requested; coordinator-owned auth/session/schema015 files are read-only for this task.
- Recovery retains the existing count fields. All unfinished historical identity operations are manual; completed/failed/pending automatic session counts are zero. Payment work retains its existing deadline and one-dispatch bound.
- The legacy session-recover entrypoint always refuses; trusted `auth/` tooling owns individually audited local resolution.
- Recovery tests now use an owned committed schema so a previous interrupted payment
  fault suite cannot supply unrelated READY work. Its readiness-negative transactions
  exclude `public` from search_path, preventing renamed fixture tables from falling
  back to a healthy public schema.
- Native work uses `shopkmp-round5-baseline-php`, real PostgreSQL on
  `shopkmp-round5-e2e_data`, and the existing owned OpenSearch on
  `shopkmp-round5-e2e_application`. Source directories are bind-mounted; the image's
  native vendor/runtime remains intact. Scheduler tests additionally mount
  `deploy/php` at `/opt/scheduler`.
- No production hasher barriers/hooks were added. Test-only PasswordHasher
  subclasses perform real verification/hashing and pause subprocesses over pipes.

## Interfaces and retirement

- `RecoveryService(Database)` replaces `(Database, Keycloak)`.
  `run()` retains all seven integer report fields. `sessionsCompleted`,
  `sessionsFailed`, `sessionsPending` are zero; `sessionsManual` counts every
  unfinished historical identity operation. The worker performs no identity
  updates, claims, retries, evidence writes, or fence clearance. Payment dispatch
  remains one per scan with the original SQL/transaction/parent work bounds.
- `recoverSession(string, bool, string): void` retains the call contract but
  always raises `BAD_REQUEST`, directing the trusted `auth/` tool. The real legacy
  console refuses confirmed tickets too. Its output was checked with exception
  argument suppression disabled and a secret sentinel; the sentinel was absent.
- Maintenance actions: `search-bootstrap(limit=100000, seconds=900)`,
  `reindex(limit=100000, seconds=900)`, `search-stage(target, limit=100000,
  seconds=900)`, `search-inventory(limit=100000, seconds=120)` call the adapter
  with those bounds in that order. Fractional inventory seconds and nonzero exits
  on invalid limits/budgets were exercised through real Yii console subprocesses.
- All PHP consumers were replaced before deleting `PasswordSessionRecovery.php`,
  `Keycloak.php`, `OidcVerifier.php`, `SessionProviderFixture.php`,
  `recovery-provider.php`, `recovery-scheduling-provider.php`. A final content
  search found no remaining PHP references to the retired types/provider settings.
- `all.php` registers local auth/concurrency/recovery, R9 order summary, search
  protocol and explicitly opted-in native search/regression/maintenance suites.
  `scripts/backend_fixes.py` changes only its suite identifiers, adding the new
  local concurrency/recovery suites. Composer changes belong to the dependency agent.

## Reproduction

Run from the repository root. DATABASE_URL must be exported privately using the
owned disposable DB and the example environment password; the commands below do
not print it. The following prefix is the source-mounted native runtime used here:

```sh
native() {
  docker run --rm \
    --network shopkmp-round5-e2e_data \
    --network shopkmp-round5-e2e_application \
    -e DATABASE_URL -e OPENSEARCH_URL=http://opensearch:9200 \
    -e ROUND5_SEARCH_DISPOSABLE=shopkmp-round5-e2e \
    -v "$PWD/backend-yii2/application:/app/application:ro" \
    -v "$PWD/backend-yii2/domain:/app/domain:ro" \
    -v "$PWD/backend-yii2/infrastructure:/app/infrastructure:ro" \
    -v "$PWD/backend-yii2/repositories:/app/repositories:ro" \
    -v "$PWD/backend-yii2/controllers:/app/controllers:ro" \
    -v "$PWD/backend-yii2/tests:/app/tests:ro" \
    -v "$PWD/tools/ops/migrations:/migrations:ro" \
    -v "$PWD/deploy/php:/opt/scheduler:ro" \
    --entrypoint php shopkmp-round5-baseline-php "$@"
}
```

For the final auth-only run, omit the application network, OPENSEARCH_URL and the
search opt-in. `native_suites.php core` starts its disposable HTTP adapter fixture
and supplies the demo payment/webhook configuration; `payment` starts the real
database-journaled payment HTTP fixture and callback listener. It never retries
failed suites and returns nonzero if any supplied suite fails.

## RED / GREEN evidence

Counts below are **tests / failures**, not a claimed overall project pass.

| Command / phase | Observed status |
|---|---|
| `php tests/local_recovery.php`, new tests against old RecoveryService | **RED 2 / 2**: old provider argument still mandatory; local-only worker interface missing |
| Same tests after local-only implementation | **GREEN 2 / 0** |
| `php tests/maintenance_search.php`, new tests with baseline image's old controller (controller mount omitted) | **RED 1 / 1**: explicit bootstrap console command missing |
| Same tests with current controller | **GREEN 1 / 0**, including bootstrap, stage, reindex, inventory, fractional budget and invalid bounds |
| Recovery schema isolation first attempt | **RED 9 / 1**: renamed fixture table fell through to public; corrected the readiness-negative fixture's search_path |
| `php tests/native_suites.php payment recovery.php`, final run | **GREEN 9 / 0**, including count-only console, no-secret refusal, payment no-replay, readiness and migration014 round trip |
| `php tests/native_suites.php core auth.php local_auth_core.php local_auth_concurrency.php local_recovery.php signed_oidc.php session_password_recovery.php session_concurrency.php logout_regression.php uuid_recovery.php api.php readiness.php`, final run | **GREEN 71 / 0** (per-suite counts below) |
| `php tests/lint.php`, final run | **GREEN 116 PHP files** |
| `git diff --check` | **GREEN**, no whitespace errors |

The newly added concurrency tests characterize the already implemented coordinator
core; they were not reported as an invented RED→GREEN production change here.

### Final focused auth/API run

| Suite | Tests / failures |
|---|---:|
| auth | 3 / 0 |
| local_auth_core (coordinator-owned, executed only) | 10 / 0 |
| local_auth_concurrency | 3 / 0 |
| local_recovery | 2 / 0 |
| signed_oidc (historical signed-token rejection and SQL role authority) | 2 / 0 |
| session_password_recovery (atomic rollback, snapshot fencing, connection loss) | 4 / 0 |
| session_concurrency | 25 / 0 |
| logout_regression | 7 / 0 |
| uuid_recovery | 5 / 0 |
| api | 9 / 0; **all 54 routes exercised successfully** |
| readiness | 1 / 0 |

Concurrency uses independent native PHP processes and real PostgreSQL queues:
same normalized email registration, single-use parallel refresh, native login and
legacy rehash snapshot versus password change, refresh versus device revoke,
password change and logout, plus password/PIN/biometric issuance versus disable
in **both lock orders**. Existing dot-device, PIN, biometric and ownership races
remain covered. SQL failure tests preserve hashes/sessions on a rejected commit
and prohibit stale verification from issuing or overwriting a changed hash.

## Broader runs — failures retained, not waived

The shared Docker runtime exhibited severe scheduling/DNS/SQL stalls. At one
point read-only `docker stats` and an owned-DB `psql` probe both exceeded 30s.
No other project was stopped or changed. Two timed-out **own test containers**
were explicitly stopped; no unrelated containers were targeted. Deadlines and
assertions were not raised, removed, or converted to skips.

| Run | Exact outcome |
|---|---|
| First API attempt without webhook environment | 9 / 3 failures; corrected test invocation, final API 9 / 0 above |
| Initial search replay before explicit bootstrap | round4_catalog 9 / 3 SEARCH_UNAVAILABLE; explicit bootstrap subsequently created verified targets, later standalone catalog 9 / 0 |
| First `all.php` with a custom selected index | Failed at round4_search_query 1 / 1 because protocol metadata fixtures intentionally use the default name; reran with default selected target |
| Default-target `all.php` | Failed at session_revocation 7 / 1, SQLSTATE 57014 on biometric challenge; later focused session_revocation **7 / 0**, but no complete all.php GREEN is claimed |
| Remaining focused core replay | session_password_recovery 4 / 0; session_concurrency then 22 / 0 (subsequently expanded/final 25 / 0); logout 7 / 0; upgrade 2 / 0; concurrency 7 / 0; address_race 2 / 0; search 2 / 0; readiness 1 / 0; deadlines 4 / 0 |
| Same core replay, API | 9 / 2: SQLSTATE 57014 deleting a review and consequent 54-route coverage assertion; final API is 9 / 0 above |
| Same core replay, adapters | **4 / 1**, `external HTTP deadline bounds unresponsive upstream` exceeded its existing 2.5s assertion; unresolved latest result |
| R9 order summary via all.php | **9 / 0**, two SELECTs/no order_items, 0/1/10/20/100 orders and large Unicode payloads |
| Search protocol via all.php | **32 / 0** |
| Inventory protocol replay | **17 / 0** |
| Native stage replay | **2 / 0** |
| Native R9 search lifecycle | **15 / 0** |
| round5_search_regressions | **FAILED / INCOMPLETE**: search 2 / 0, round3_reads 27 / 0, then round4_catalog 3 observed SEARCH_UNAVAILABLE failures (absent/true/false admin visibility); outer 300s deadline terminated run before completion |
| Full payment replay | payment-migration **13 / 0**, payments **113 / 4**, payment-http **4 / 0**, uuid_identity **16 / 2**, uuid_recovery **5 / 0**; outer 300s deadline later interrupted recovery. Recovery subsequently isolated and GREEN 9 / 0 above |
| First scheduling invocation without scheduler source mount | **14 / 3**: missing scheduler file in two tests; long payment apply hit statement timeout before expected transaction timeout |
| Scheduling after source mount | **14 / 1**: both real scheduler tests passed; retained long payment apply failed backend-termination assertion after earlier statement cancellation |
| Isolated long payment apply | **1 / 1**, transaction timeout did terminate backend but elapsed **9.242s** exceeded unchanged `<8s` assertion |
| Final whole scheduling replay | **14 / 4**, detailed below; this is the latest scheduling status |

### Remaining failed cases by name

`payments.php` (latest whole run, 113 / 4):

1. `cancel CARD AUTHORIZED performs correct remote effect and restores once` — SQLSTATE 57014.
2. `expiry excludes SBP FAILED from reservation cancellation` — SQLSTATE 08006, Docker DNS could not resolve postgres.
3. `F1 no-effect refund stays manual without replay for completed purchase` — SQLSTATE 57014 during owned schema cleanup.
4. `F1 NO_EFFECT is distinct from an authorization FAILED outcome` — SQLSTATE 08006, Docker DNS.

`uuid_identity.php` (latest whole run, 16 / 2):

1. `UUID lower confirm is repeatable and reversals keep one external effect` — payment HTTP timeout.
2. `UUID upper ES256 biometric identity retains nonce signature and ownership checks` — SQLSTATE 57014.

`recovery-scheduling.php` (final whole run, **14 / 4**):

1. `a long multi-statement payment apply is bounded without replaying its uncertain remote effect` — native transaction timeout killed backend, but elapsed **21.813s** exceeded the retained 4.5..8s assertion.
2. `real scheduler preserves manual sessions and progresses payments across bounded ticks despite backlog` — SQLSTATE 57014 on the test observer's count of SUCCEEDED operations. Earlier source-mounted run passed, elapsed 31.992s and four one-payment reports.
3. `F3 local expiry transaction timeout preserves committed retention and rolls back stock and intent` — statement timeout, elapsed **23.470s** outside retained 4.5..8s bound. Earlier source-mounted run passed with a 5.014s backend termination.
4. `F3 real maintenance scheduler retains cleanup progress and queues expiry without payment HTTP` — SQLSTATE 57014 during fixture authorization setup. Earlier source-mounted run passed with 100 expirations in 0.909s and durable payment intents.

The adapter deadline and incomplete search-regression failures listed in the
table are also unresolved broad-suite results. Runtime instability is evidence
for an environment contribution, not proof of application correctness and not a
waiver. Re-run these gates on a stable owned runtime before claiming acceptance.

## Review and remaining coordinator gates

- Task-level review: self-review. Independent whole-diff review is recorded separately. Checked constructor consumers,
  absence of identity writes/retries, unchanged payment work bounds, generation
  fences, fresh SQL roles, retirement references, suite registration, owned schema
  cleanup, and the original 54-route assertion. This is not an independent review.
- Final source-bound PHP rebuild, full all.php/payment/scheduling/search-regression
  GREEN, dependency-image scans and deployment acceptance are **NOT_RUN / NOT_GREEN**
  in this task and remain coordinator gates. Production cutover is NOT_EXECUTED.
