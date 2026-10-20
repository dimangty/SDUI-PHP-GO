# Independent scoped review — legacy source/archive removal

Date: 2026-10-20. Scope: the explicitly authorized deletion of `backend/` and
`Shop-Go-master.zip`, extracted contracts/ops tooling, and affected build,
deployment, verification and operational documentation.

## Verdict

**Changes requested: 0 Critical, 1 Important finding.** The documented
`make test-infra` path retains an assertion incompatible with the new standalone
PHP test target. Correct I1 before treating the affected verification surface as
fully working.

No active dependency on the deleted tree/archive, missing retained SQL/realm/
OpenAPI bytes, destructive data/history reset, or expansion of BFF database
access was identified. The deletion's runtime and artifact-preservation paths
are otherwise consistent with the requested decommissioning.

## Important finding

### I1 — Standalone `test-php` breaks the deployment-command regression

**Locations:** `scripts/test_deployment_commands.py:36–42`;
`Makefile:44–52`; documented invocation at `docs/DEPLOYMENT.md:232–237`.

The deployment-command test still includes `test-php` among lifecycle targets
that must reject `ENV_FILE=<production environment>` before invoking Docker.
The updated `test-php` deliberately ignores `ENV_FILE`: it builds directly from
`backend-yii2` and runs Composer unit/lint in two `--network none` containers.
That is consistent with its new standalone contract, but contradicts this test.

**Impact:** with a valid production-test environment and successful PHP checks,
`make test-infra PRODUCTION_TEST_ENV=...` fails in its deployment-command phase.
The failure is an obsolete expectation, not an unsafe production connection.
Neither `make check` nor the current CI workflow exercises this regression file,
so their reported passes do not cover it.

**Independent focused reproduction:** ran only
`DeploymentCommands.test_generic_lifecycle_rejects_production_environment_before_docker`
through the existing test factory, using `.env.prod.example` for the filename
guard and the test's existing fake-Docker executable. No real image build,
Composer suite or deployment was executed. The other lifecycle subtests passed;
the `test-php` subtest failed at line 40:

```text
(target='test-php') ... FAIL
AssertionError: 0 == 0 : docker build -t "shopkmp-php-tests" backend-yii2
docker run --rm --network none "shopkmp-php-tests" composer test
docker run --rm --network none "shopkmp-php-tests" composer lint
Ran 1 test
FAILED (failures=1)
```

**Required correction:** remove `test-php` from the production-environment
rejection set and cover its standalone contract separately: direct build, both
network-isolated Composer invocations, and no Compose/environment-file use.
Retain the production rejection assertions for actual lifecycle targets.

## Reviewed boundaries

| Boundary | Assessment |
| --- | --- |
| Active builds and paths | Root Make/CI, development/production Compose, the fixture-only verification overlay, service Dockerfiles, current scripts and application source resolve to retained paths. Migrator and Keycloak use `tools/ops` contexts with the correct context-relative Dockerfile paths; realm and Swagger mounts point to their new locations. No active legacy writer, source mount, archive extraction or retired Go test command was found. |
| Contracts and route policy | The OpenAPI SHA-256 independently matches the supplied pre-deletion value: `0ccb20a41fc09164ba72c23515ad5eb03587c76c77e6a37fbc505a8c163731fb`. The frozen registry has 54 operations, split 15 public / 32 protected / 7 admin. The generator compares method/path/policy sets and OpenAPI operation membership; the PHP unit test compares the exact canonical policy map and handler existence. |
| SQL and migration history | All 22 SQL files pass the retained `SHA256SUMS` baseline. The checksum test also checks embedded forward SQL. `migrate.go` retains advisory lock `6002424246314344528`, the existing `schema_migrations` table, filename/checksum validation and atomic SQL/history transactions. Legacy detection is limited to empty history; recorded history is not rebaselined. Yii uses `yii2_migration_history`. No down-migration execution or reset is wired into the new CLI. |
| Identity and production probe | The realm passes its retained checksum baseline and retains the client-secret placeholder. Production supplies the verified-CA mount, `SSL_CERT_FILE` and `https://keycloak:9000/health/ready`; the Go probe uses normal certificate verification. The pinned Keycloak **26.3.3** management mapper inherits the main HTTPS certificate/key. Its source has no `http-management-scheme` option/mapper, so the inherited environment variable bearing that name is not evidence of a plaintext management listener in this pinned release. No production probe defect was established. |
| Data and media | Normal `down` targets retain volumes. Seed refuses production and leaves a populated catalog unchanged under table locks. Product media/icons are generated from catalog-backed paths; avatar bytes remain in `avatar_files`, without a deleted-tree filesystem dependency. The snapshot-import marker still blocks readiness; current runbooks require the external immutable release for offline import/rollback rather than clearing markers or overwriting newer data. |
| Business ownership and isolation | Yii retains the business routes and application services. The BFF module has no PostgreSQL driver; its Compose environment contains only its Yii origin and it has no data-network membership. Among retained Go runtimes, database access is confined to the one-shot ops migrator. No root alias redirects business traffic to a database-backed Go runtime. |
| Current verification and claims | `verify.py` runs retained PHP suites, shared-edge CARD/policies, live BFF race/vet, ops checks, scheduler/seed/reindex and runtime probes. `tests/all.php` has no retired differential/cross-runtime invocation or reference-origin skip. The saved current PHP log records **73 tests / 58 syntax checks**; historical **77 / 66** counts are explicitly labeled as earlier results. Removing the four reference-only cases does not remove business operations. The old Go coverage gates are disclosed as retired; BFF coverage is informational. |
| Historical evidence and CI | The retained parity JSON records 194 pairs, 189 matches, five corrections and 54 successful operations. The matrix's explicit evidence option reads saved captures; current verification does not claim to execute a new Go comparison. Historical report banners distinguish removed commands from current instructions. CI includes both Go modules, PHP, contracts, images and integration, plus the retained Android/iOS jobs and KMP coverage gate. I1 is the uncovered command-regression mismatch. |

Pinned-version probe reference:
[Keycloak 26.3.3 management mappings](https://github.com/keycloak/keycloak/blob/26.3.3/quarkus/runtime/src/main/java/org/keycloak/quarkus/runtime/configuration/mappers/ManagementPropertyMappers.java)
and [management options](https://github.com/keycloak/keycloak/blob/26.3.3/quarkus/config-api/src/main/java/org/keycloak/config/ManagementOptions.java).

## Method and evidence limits

The repository is unborn/untracked and the authorized deletion was already
complete. Files were inspected directly; no commit-range/source-baseline diff
or new comparison with the deleted ZIP is claimed. Original-byte provenance is
the supplied pre-deletion baseline/check; the independent checksum commands
confirm the retained files still match those recorded values.

The parent's post-deletion `make check`, standalone image builds, existing-DB
migration, healthy recreation and full `make integration-check` passes are
supplied execution evidence. Saved current PHP/runtime logs were inspected;
those suites were not rerun by this review. Independent execution was limited
to SQL/realm/OpenAPI checksum checks, read-only Compose/container/version
inspection, and the focused existing command-regression test establishing I1.
No new full fresh-stack or production-TLS pass is claimed.

This review's sole workspace edit is this document. No application/config/test
changes, commits or subagents were used.

---

## Scoped re-review — I1 remediation (2026-10-20)

**Verdict: I1 addressed; approved within this scope. 0 outstanding Critical /
Important findings, and no new Critical / Important regression identified in
the fix.** This disposition supersedes the opening changes-requested verdict
for I1; the original finding and RED evidence remain historical.

### Remediation assessment

* `scripts/test_deployment_commands.py:36–42` removes only standalone `test-php`
  from the lifecycle rejection list. All eight actual lifecycle targets retain
  the nonzero-exit, `requires --prod` and zero-Docker-call assertions.
* The distinct test at `scripts/test_deployment_commands.py:44–61` invokes the
  real Make target with a production environment path and requested image name,
  replacing only Docker with the existing command-recording shim. It requires
  success and exactly three calls: the exact direct PHP image build, followed
  by both Composer unit and lint invocations. Each run must use `--network none`,
  `--rm` and the requested image, with no environment/mount/volume options.
  Neither Compose nor the production environment path may reach Docker.
* These assertions match `Makefile:44–47`. The remaining deployment-stage,
  production filename/marker and development-configuration tests are retained.
  The production rejection and overlay-selection logic remains in
  `scripts/compose.py:21–38`; the correction does not weaken a runtime guard.

### Execution evidence and review method

Read the appended actual post-removal checks in
`docs/LEGACY_REMOVAL.md:82–110` and the parent's supplied execution result:

* `make test-infra PRODUCTION_TEST_ENV=<fresh private disposable-PKI env>`
  passed all **12 scheduler regressions**, development/production model checks,
  **7 unsafe-configuration rejections**, and **5 command-boundary tests**
  (the command-test runner reported **4.548s**).
* The earlier failed four-test command run and this review's original focused
  failure establish RED. The corrected five-test pass establishes the supplied
  GREEN result; the former failure is not an outstanding failure of this fix.
* The post-deletion `make check` and full `make integration-check` passes remain
  recorded evidence, including **73 PHP tests / 58 syntax checks**. They are not
  relabeled as new executions by this re-review.

This re-review inspected only I1's correction, its Make/wrapper boundary and the
appended execution account. No tests were rerun. Only this closure was appended;
no application/config/test edits, commits or subagents were used.
