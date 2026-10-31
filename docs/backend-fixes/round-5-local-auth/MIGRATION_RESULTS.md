# V6 task 3: native local-identity migration results

Scope: selected-user migration service, console controller/TTY helper, console
mapping, new 016 SQL/checksum entries, native migration tests and five local-auth
runbooks. Shared tree was explicitly requested. No commits or production actions.
Execution clock reported `2026-10-30T17:37:41Z`; results below are observations from
this execution, not historical release claims. Whole-stack `TEST_RESULTS.md` is
owned by the coordinator.

## Runtime / source binding

- Native PHP: `shopkmp-round5-baseline-php`, PHP **8.3.36**, existing image vendor.
  Image ID: `sha256:e3a5ce15c6bd56b62c23568718f070e7bfa6f4f8f9acd19fe3fc90db70ba6665`.
- PostgreSQL: existing disposable `shopkmp-round5-e2e-postgres-1`, network
  `shopkmp-round5-e2e_data`, service host `postgres`, login/database `shopkmp`.
  Password was supplied privately from `.env.example`'s `POSTGRES_PASSWORD`;
  no secret value was printed or interpolated into command arguments.
- Fresh-upgrade database: **`local_auth016_fresh`**, on the same disposable cluster.
  Created only if absent; existing databases were not dropped/truncated.
- Rebuilt existing migrator: `shopkmp-round5-e2e-migrate`, image ID
  `sha256:258ccf2bf85a5540ec84c5310d44e6b4074c46f5012d1dc97f3c74a957ba1ad1`.
  Build used the repository's pinned Go **1.26.10** Alpine build image and the
  existing `cmd/migrate` entry point. No alternate forward-migration runner.
- Production source directories were mounted read-only at `/app`; vendor remained
  the native image's vendor. Test fixture construction itself uses real PDO/PG.

Final task-owned implementation SHA256:

```text
c41bd8b8b203e417d94f5851dad298ee4e4d378ab60ce342036968dc565d0298  backend-yii2/application/LocalIdentityMigration.php
281370d92eb579f9f4e3ceb221060126c81d440aa41a3d02aaa42e900cf6738c  backend-yii2/controllers/AuthController.php
bbf9e1e17db7be60b87c05ee874cd7611ae51d75a08624fc6d1c6d1741e7248c  backend-yii2/controllers/AuthPasswordInput.php
b2322451560d1628d398cb8481596ea7cc4aeaf4f2b727a72b40e74ad509006d  backend-yii2/tests/local_auth_migration.php
f777f6173e3868331c723bb63002184fec13733ce4f4b05482365eea13e27de1  tools/ops/migrations/016_local_identity_journal.up.sql
3245c90a07e9c97569e794bb77dc5c7264baf53885d8b388847a1a59c15563cb  tools/ops/migrations/016_local_identity_journal.down.sql
```

Core auth/session/recovery files were concurrently owned by the coordinator. The
above hashes bind task 3; this report does not assert a frozen whole-tree source
or a rebuilt final PHP release image.

## Exact command notation

Commands were issued as direct `docker run` invocations from repository root. The
following arrays expand their shared arguments literally (Bash/Zsh syntax):

```sh
MOUNTS=(
  --mount "type=bind,source=$PWD/backend-yii2/application,target=/app/application,readonly"
  --mount "type=bind,source=$PWD/backend-yii2/domain,target=/app/domain,readonly"
  --mount "type=bind,source=$PWD/backend-yii2/repositories,target=/app/repositories,readonly"
  --mount "type=bind,source=$PWD/backend-yii2/infrastructure,target=/app/infrastructure,readonly"
  --mount "type=bind,source=$PWD/backend-yii2/controllers,target=/app/controllers,readonly"
  --mount "type=bind,source=$PWD/backend-yii2/config,target=/app/config,readonly"
  --mount "type=bind,source=$PWD/backend-yii2/tests,target=/app/tests,readonly"
)
NATIVE=(docker run --rm --network shopkmp-round5-e2e_data
  --env-file .env.example -e LOCAL_AUTH_DISPOSABLE=shopkmp-round5-e2e "${MOUNTS[@]}")
UNIT=(docker run --rm --network none "${MOUNTS[@]}")
```

The test entry point privately constructs `DATABASE_URL` from the supplied
password, or inherits an explicitly supplied URL for its children. Both the
disposable opt-in and the fresh database name are constrained by the test fixture.

### Migration sequence actually executed

```sh
# Initial RED, while the database was at schema015 and the service/controller absent:
"${NATIVE[@]}" shopkmp-round5-baseline-php php tests/local_auth_migration.php

# Rebuild, seed nonempty schema015, then use the EXISTING migrator:
docker compose --env-file .env.example -p shopkmp-round5-e2e build migrate
"${NATIVE[@]}" shopkmp-round5-baseline-php php tests/local_auth_migration.php seed015
docker compose --env-file .env.example -p shopkmp-round5-e2e run --rm --no-deps migrate

# Repeat migration: checksum/history validation, no new action:
docker compose --env-file .env.example -p shopkmp-round5-e2e run --rm --no-deps migrate

# Fresh database, all canonical migrations 001–016 through the same binary:
"${NATIVE[@]}" shopkmp-round5-baseline-php php tests/local_auth_migration.php create-fresh
docker compose --env-file .env.example -p shopkmp-round5-e2e run --rm --no-deps \
  -e 'DATABASE_URL=postgres://shopkmp@postgres:5432/local_auth016_fresh?sslmode=disable' migrate

# Latest populated and fresh runs were sequential:
"${NATIVE[@]}" shopkmp-round5-baseline-php php tests/local_auth_migration.php
"${NATIVE[@]}" -e LOCAL_AUTH_TEST_DATABASE=local_auth016_fresh \
  shopkmp-round5-baseline-php php tests/local_auth_migration.php
```

`seed015` asserted max migration version 15 and inserted a fixed UUID plus cart
metadata before 016. After applying 016, the test verified that same UUID, empty
credential, unready flag and cart link. Additional populated tests compare actual
address/order/payment/review/cart/default-address rows before and after selected
attestation/reset. There is no favorites table in the canonical backend schema;
no synthetic business table was added to invent such coverage.

### Additional checks actually executed

```sh
"${UNIT[@]}" shopkmp-round5-baseline-php composer test
"${UNIT[@]}" shopkmp-round5-baseline-php composer lint
go -C tools/ops test ./...
git diff --check
```

In working directory `tools/ops/migrations`:

```sh
shasum -a 256 -c SHA256SUMS
```

Forward-only guard, executing only the down-file rejection (never an alternate
forward runner):

```sh
docker run --rm --network shopkmp-round5-e2e_data --env-file .env.example \
  --mount "type=bind,source=$PWD/backend-yii2/infrastructure,target=/app/infrastructure,readonly" \
  --mount "type=bind,source=$PWD/tools/ops/migrations,target=/app/sql-migrations,readonly" \
  shopkmp-round5-baseline-php php -r 'require "vendor/autoload.php"; $db=new Shop\infrastructure\Database("postgres://shopkmp:".rawurlencode(getenv("POSTGRES_PASSWORD"))."@postgres:5432/shopkmp?sslmode=disable"); try { $db->execute(file_get_contents("sql-migrations/016_local_identity_journal.down.sql")); exit(1); } catch (PDOException $e) { if ($e->getCode()!=="P0001" || !str_contains($e->getMessage(),"forward-only")) { exit(2); } print "PASS 016 down rejects rollback and preserves evidence\n"; }'
```

## RED → GREEN and retained intermediate outcomes

| Phase / command above | Observed status |
|---|---|
| Initial native suite | **RED**, exit 1, **10/10 failed**: schema was 15 rather than 16, service absent, console mapping absent |
| Migrator rebuild | Exit 0, new binary/image includes 016 |
| First seed attempt with only tests mounted | Exit 255: `PasswordHasher` absent from baseline image; corrected to full native production mounts before seeding/applying |
| Corrected `seed015` | Exit 0, populated schema015 fixture recorded |
| Existing migrator apply, rerun, fresh apply | Each exit 0, `database schema is up to date` |
| First service implementation | 10/1: biometric fixture omitted existing required key_algorithm; corrected fixture to EdDSA, then **10/0** |
| Extended 15-test intermediate | One shared-count delta changed during parallel work; one fixture assumed nonexistent favorites table. Inventory moved to an isolated schema and unsupported favorite fixture removed, then **15/0 on both DBs** |
| Parallel 17-test DB runs | Each exit 1: main worker exit 255; fresh `CREATE TABLE ... LIKE` SQLSTATE 57014. `docker stats --no-stream` also exceeded 30s; later PG diagnostic showed no remaining lock waiter. Exact worker cause was not retained in that old fixture. No production timeout was raised |
| Sequential 17-test reruns | Both exit 0, **17/0**. Worker fixture then gained safe SQLSTATE diagnostics and an explicit barrier proving both workers wait on the common user lock; both DBs passed again |
| Review regression: mismatched reset password on same ticket | **RED**, 17/1, then **18/2** after adding the competing-reset winner case: previous receipt-only logic did not reject a different typed secret |
| Receipt verification fix | Checks the current normal verifier outside SQL, then revalidates under user lock; no password fingerprint added to journal. Later security mutations reject the old ticket as superseded |
| First 18-test main run after fix | 18/1: SQLSTATE 57014 on a repository user SELECT. Follow-up PG activity had no remaining lock waiter. Cause not proven; retained as a shared-environment transient, not silently counted as green |
| **Latest sequential main and fresh runs** | **Each exit 0, 18 tests / 0 failures**, including both review regressions |

The intermediate timeouts are not a load/performance certification. Sequential
passing runs did not change SQL, lock or test production deadlines to mask them.

## Real TTY verification

Command:

```sh
python3 /var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/local-auth-tty-check.py
```

Retained ephemeral driver SHA256:
`3f9424bd798b7d9d17fed54a09e4797ba6c9eaddd1777bfef7ada438aff3b832`.
The driver lives outside the repository and uses real host PTYs and `docker run
-it`, the same native mounts/network/image, and the test file's `tty-probe`,
`tty-target`, `tty-verify` modes. It sends only disposable fixed test secrets via
the PTY after seeing each prompt; input is never an argv/env/stdin-pipe password.
It asserts secret text is absent from captured output and compares terminal modes
before/after the helper. The real console command receives `DATABASE_URL` through
a private in-process launcher built from `POSTGRES_PASSWORD`.

Observed exit 0, four explicit PASS results:

1. No-echo confirmation with original terminal mode restored.
2. Mismatched confirmation rejected, no echo, original mode restored.
3. Ctrl-C input rejected, original mode restored.
4. Real `auth/reset-password` TTY flow persisted a native verifier and journal row.

An intermediate driver run passed the first three but failed the console step
because it had not supplied `DATABASE_URL`; the driver environment was corrected
and all four then passed. Production password handling was not weakened.

## Final check summary

| Check | Latest observed result |
|---|---|
| Populated-schema native migration suite | **18/0**, exit 0 |
| Fresh-schema native migration suite | **18/0**, exit 0 |
| Composer default suite | **32/0**, exit 0 (7 domain + 7 transaction + 1 request + 17 inventory) |
| Composer lint | **116 PHP files passed**, exit 0 |
| Existing Go ops suite | Exit 0; migrate has no tests, migrations package passed |
| SHA256SUMS | All **32** migration SQL entries OK, including coordinator's unchanged 015 entries |
| 016 down guard | Exit 0 from assertion wrapper; actual down raises PostgreSQL **P0001** |
| Real TTY driver | **4 checks passed**, exit 0 |
| `git diff --check` | Exit 0 |

The transaction fault tests intentionally printed two fixed `Database transaction
cleanup failed; connection quarantined` diagnostics. These are expected fault-path
output, not leaked driver arguments or unreported test failures.

## Review / rulings / remaining gates

- Final review was an author self-review; no subagent tool was available. The
  stricter same-ticket password mismatch finding was fixed with native RED→GREEN,
  including the concurrent winner case and superseded-ticket protection. The
  coordinator still owns independent whole-tree/release review.
- Ruling: work in the requested shared tree, without a separate worktree or
  commits. Isolated test schemas avoid altering canonical indexes to synthesize
  conflicts/backlogs. Cost: shared-host test interference remains visible above.
- Ruling: 016 stores only the missing operator evidence; existing user/session/
  historical operation tables remain the mutation truth. Rollback is explicitly
  forward-only because deleting a journal cannot undo reviewed security changes.
- No deferred code-review minors. Production inventory, external identity/admin/
  ban evidence, per-operation outcomes, orphan-fence investigation, separated
  operator DB roles, backup/restore and the coordinated drain/cutover are **operator
  gates**, not executed actions. Coordinator exact-release HTTP/security/runtime/
  dependency/publication gates are outside this task's completion claim.
