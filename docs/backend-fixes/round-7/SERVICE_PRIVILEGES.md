# F2 — service privileges (2026-11-01)

Scope: task 3 of `AUDIT_PLAN.md`, existing feature branch, baseline `160d2a5`.
No production operations, foreign stack lifecycle operations, index/volume deletion,
staging, commits, pushes or child agents. This file is the task ledger and report.

## Investigation and execution ledger

- Read round-7 F2 requirements and round-6 SQL-role ADR, rollout and native evidence.
  Preserve distinct SQL runtime/search/indexer/recovery/maintenance/owner/operator
  logins and required preflight. Search reconciliation is SQL read-only; explicit
  bootstrap needs the existing bounded table-lock capability.
- Root cause: base `x-yii-env` injects payment/webhook secrets into all PHP services;
  overlay `x-production-env` adds the admin search URL to all of them. Docker merges
  environment mappings by key, so merely omitting keys in the overlay cannot remove
  inherited capabilities. Existing `search-start.sh` generates an admin user during
  ordinary starts; default security initialization is enabled in the mounted config.
- Docker available: VM memory 8,320,954,368 bytes; many foreign stacks active.
  Read-only `docker info`, `docker stats --no-stream`, `docker network ls`: exit 0.
  Additional OpenSearch, if run, will have its own explicit unused subnet and memory
  limit. No foreign stack will be managed.
- Ruling: user-selected existing branch is the execution workspace; this report is
  the ledger. User scope/no-commit/no-agent constraints supersede generic plan
  workspace, commit and independent-agent workflow instructions.

## RED → GREEN ledger

| Command / experiment | Actual result |
| --- | --- |
| `python3 -m unittest discover -s scripts -p test_service_privileges.py -v` before Compose edits | Exit **1**, 0.237 s: **12** base/merged-production distribution failures. Reconcile/indexer/Yii migrator/maintenance/seed inherited payment key+webhook; recovery also inherited search+webhook. Only key names were printed. |
| Expanded same test before fix | Exit **1**, 0.240 s: **19** failures, including actual production `admin` search identity and validator accepting credential leaks. |
| Same merged-model test after fix | Exit **0**, **3/3**, 0.352 s. Tests use actual `docker compose --profile '*' config --format json` with synthetic inputs; they do not print rendered credentials. |
| `python3 -m unittest discover -s scripts -p test_test_pki.py -v` | RED exit **1**, missing independent operator certificate; GREEN exit **0**, **1/1**, 0.340 s. |
| `python3 -m unittest discover -s scripts -p test_service_command_config.py -v` | Actual PHP baseline, `--network none --memory 128m`: RED exit **1** (missing role-specific startup policy); GREEN exit **0**, **1/1**, 0.392 s. Owner/operator/maintenance require no external config; recovery only provider; search/indexer only search; HTTP runtime payment requirements remain. Disabled runtime search remains permitted. |
| `python3 -m unittest discover -s scripts -p 'test_*.py' -v`, first broad run | Exit **1**, 49 tests: six config-test errors from missing new fixture passwords and newly explicit production indexer profile. Fixture/profile expectations updated, without removing prior SQL/transport/worker assertions. |
| Same broad discovery | Exit **0**, **49/49**, 4.376 s. The two `FAILED ...` lines are deliberate negative-policy fixtures whose tests passed, not live scanner/acceptance successes. |

### Live experiments (owned resources only)

Command: `python3 scripts/test_search_privileges_live.py --run --parent
/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode --subnet 10.252.212.0/24`.
The harness refuses any overlap with existing Docker networks, generates its own
private short-lived PKI and unique project, derives services from the actual merged
production model, uses baseline images with current source/admin artifacts mounted,
and caps OpenSearch at **768 MiB / 256 MiB heap**, PHP at **128 MiB**, and its optional
PostgreSQL fixture at **192 MiB**. It stops/removes only its own containers/network;
all created volumes and private material are retained. No index DELETE is issued.

- First project `shopkmp-round7-search-6e7ccf61-e2e`, private directory
  `shopkmp-task4-pki-507v5zi5`: initial harness exit **1**, fresh TLS returned 503
  after 6.523 s; initialization probe wrongly assumed REST roles also returned 503.
  Actual operator `GET /_plugins/_security/api/roles` returned **500**, message
  `Security index not initialized`; operator `HEAD /.opendistro_security` returned
  **404**. Bootstrap now uses the latter existence check and never overwrites an
  existing security index. The first securityadmin upload also found the pinned
  2.19.7 tool requires the legacy `whitelist.yml`; added it. This partial test
  security index was retained, never reset or deleted.
- Actual runtime document PUT first returned **403** for
  `indices:data/write/bulk`, including after adding only index-scoped bulk. Added
  the non-administrative outer bulk transport cluster action; foreign document
  PUT still returns **403** for `indices:data/write/index`.
- The next real PUT returned **403**, `indices:admin/mapping/put`, because full
  catalog DTOs contain fields outside the application's explicit searchable mapping.
  **Ruling:** do not grant runtime mapping administration. An explicit operator
  `catalog-schema` phase installs a `dynamic:false` product template and applies
  only that flag to an existing selected index. `_source`, existing fields/types,
  UUID and application `_meta` stay intact. New searchable fields require explicit
  reviewed mapping. Actual runtime create/update/search/delete and native bootstrap/
  reconcile/stage were subsequently executed successfully; this is not a read-only
  runtime workaround.
- Reconcile refresh first returned **403**, `indices:admin/refresh[s]`; changed
  refresh permission to include shard subactions (`indices:admin/refresh*`).
- Repeat upgrade initially rewrote the roles security document: test exit **1**.
  Root cause was API-default empty `fls`/`masked_fields` fields; the desired policy
  now includes them. GREEN live probe exit **0** proved unchanged security document
  `_seq_no` for roles, mappings and users after repeat upgrade. No hashes logged.
- First complete action probe on the retained project exited **0**: all three
  identities performed real catalog PUT/search/document DELETE; runtime/reconcile
  lifecycle and every identity's security/cluster/foreign actions were denied.
  Rotation proved old runtime credential **401**, new **200**, independent operator
  mTLS **200**; explicit credential rollback revoked the new value (**401**) and
  restored the expected disposable value (**200**).
- Second fresh project `shopkmp-round7-search-19906767-e2e`, directory
  `shopkmp-task4-pki-g9gvg6rx`: fresh TLS 503 after **4.377 s**; full initial security
  upload exit **0**, **1.928 s**. Immediate provisioning returned exit **1**; a later
  identical role PUT returned **201**. Added bounded read-only REST visibility wait
  after the bootstrap acknowledgment and fail-closed policy-read status handling;
  no configuration-write retry. A clean end-to-end rerun remains required below.
- On that owned second project, targeted `upgrade`, `catalog-schema`, and the actual
  PHP catalog probe each exited **0**. Actual `OpenSearch::bootstrap`, runtime
  `CatalogService::createProduct/updateProduct/products/deleteProduct`, read-only SQL
  reconciliation, and `OpenSearch::stage` all passed against real TLS OpenSearch and
  PostgreSQL. Stage exercised SHARE locks, create, mapping, refresh, scroll and ready
  marker publication. The SQL fixture is intentionally small; it is not a replacement
  for the unchanged complete SQL privilege/migration/HTTP acceptance gates.

### Final clean run and checks

Final clean live command above: **exit 0**, project
`shopkmp-round7-search-253cc496-e2e`, private directory `shopkmp-task4-pki-rk8pt462`.

| Phase | Exit / measurement |
| --- | --- |
| Fresh uninitialized HTTPS | HTTP **503** after **4.407 s**; ordinary start created no security users |
| Explicit fresh security bootstrap | **0**, **14.177 s**, OpenSearch **2.19.7**, verified operator DN, complete upload and REST visibility |
| Targeted upgrade / check / repeat upgrade / check | **0 / 0 / 0 / 0**, **3.117 / 1.049 / 0.561 / 0.619 s** |
| Explicit catalog writer schema | **0**, **0.584 s** |
| Repeat fresh bootstrap on existing security index | **0**, **0.494 s**, no bootstrap writes |
| Real allowed/denied actions + upgrade + rotation/rollback | **0**, **10.874 s** |
| Actual PHP catalog/bootstrap/reconcile/stage paths | **0**, **5.403 s** |
| OpenSearch memory sample | **573 MiB / 768 MiB** (256 MiB Java heap), `docker stats --no-stream` exit **0** |
| Ordinary node restart | **0**, **0.328 s**; subsequent read-only policy/authentication check **0**, with no provisioner upgrade/bootstrap |
| Stop own nodes / remove own containers / remove own network | **0 / 0 / 0**, **0.218 / 0.146 / 0.157 s**; both volumes retained |

The existing-index test first gave a managed legacy runtime role cluster health
access (**200**), then explicitly upgraded it and observed **403**. Independent
existing operator role/mapping/internal-user objects were compared unchanged,
including the user hash in memory only; that operator still received health
**200**. Repeat upgrade proved unchanged `_seq_no` for the three security documents.

Source execution used actual arm64 baseline images (no rebuilt-image or fresh
vulnerability-scan claim):

- PHP: `sha256:c3fafb30783031b7c4a0115aa8ce03020e075206fce596af2d01d81344ca95ae`
- OpenSearch: `sha256:fb875f3487b0626704426975335c4a183f2c43695ddfe9a2a5d780babe15a50b`
- PostgreSQL: `sha256:8dbc49ea8c114247011934e5f8495b489aca690aa92977e363fc61c7b53f1564`

Final checks, each exit **0**:

- `python3 -m unittest discover -s scripts -p 'test_*.py' -v`: **49/49**, **4.522 s**
  before the final self-review regressions; latest full result is recorded below.
- `python3 scripts/test_deployment_commands.py --production-env <private-final-test.env>`:
  **5/5**, **4.566 s**. Its earlier RED had a `prod-reindex` model KeyError because
  an interim indexer profile removed it from default rendering. The final zero-scale
  decision below preserves the original default-model fixture. Real Make/wrapper/
  validation and Docker config rendering run;
  lifecycle side effects in this test are deliberately intercepted.
- `python3 scripts/test_infrastructure.py --production-env <private-final-test.env>`:
  actual dev/prod merged boundaries and **seven** unsafe production inputs rejected.
  Includes operator certificate chain/subject/key checks.
- `docker run --rm --network none --memory 256m --name shopkmp-round7-f2-php-check-e2e
  --entrypoint sh` with current infrastructure/bin/tests and deploy read-only mounts,
  baseline PHP image, `-ec 'composer test && composer lint && php -l ...'`:
  Composer unit suites **74 tests, zero failures**, **132** PHP files linted, plus
  explicit bootstrap and four new PHP admin/test artifacts linted. Initial command
  had a duplicate `sh` argument (exit **2**, `can't open sh`); corrected invocation
  passed. Native fixture diagnostics for rejected upstream/transaction cases remain
  visible and were not suppressed.
- `bash -n deploy/search-start.sh deploy/search-security-bootstrap.sh`.
- `git diff --check`.
- `python3 scripts/route_matrix.py --check`: **54 exact operations/policies, eight
  screens, six commands**, unchanged contract matrix.

## Effective capability matrix

| Service | SQL capability | External credentials injected |
| --- | --- | --- |
| `yii2` | runtime | `shopkmp_runtime` search; provider API key; webhook secret |
| `reconcile` | search (SELECT only) | `shopkmp_reconcile` search only |
| explicit `reindex` | indexer (bounded catalog locks) | `shopkmp_indexer` search only |
| `payment-recovery` | recovery | provider API key only |
| `maintenance` | maintenance | none |
| `yii-migrate` / `migrate` | owner | none |
| `identity-operator` | operator | none |
| `database-provision` | explicit DBA | no search/payment credentials |
| production `seed` | no DSN/role/credentials | none; `development-only`, production seed refuses |
| `opensearch` | none | no internal admin/service password; node TLS key only |
| `search-security-bootstrap` | none | independent operator mTLS only; `search-admin` profile |
| `search-security-provision` | none | operator mTLS + three managed search passwords; `search-admin` profile |

Base development also separates external credentials by service. Its preexisting
automatic development bootstrap dependency is retained for canonical acceptance;
production removes that dependency and sets ordinary indexer replicas to **zero**.
The service remains visible in default config for the existing SQL-role/artifact
gates, and `compose run reindex` explicitly starts a one-off despite zero replicas.
Recurring production workers never trigger privileged provisioning/bootstrap.
Scheduler and FPM mounts survive real base/overlay volume merging.

### Exact live action evidence

All HTTP requests below used verified CA/hostname TLS, actual credentials and real
OpenSearch **2.19.7**. No responses containing internal-user data were logged.

| Action / endpoint | Runtime | Reconcile | Indexer |
| --- | --- | --- | --- |
| `indices:admin/get`, `GET /shopkmp-products` | **200** | **200** | **200** |
| Document index transport, `PUT /shopkmp-products/_doc/<id>?refresh=wait_for` | **201** | **201** | **201** |
| `indices:data/read/search*`, `POST /shopkmp-products/_search` | **200**, hit verified | **200**, hit verified | **200**, hit verified |
| `indices:data/write/delete`, document DELETE | **200** | **200** | **200** |
| Security REST administration, `GET /_plugins/_security/api/roles` | **403** | **403** | **403** |
| `cluster:monitor/health`, `GET /_cluster/health` | **403** | **403** | **403** |
| `cluster:admin/settings/update`, `PUT /_cluster/settings` | **403** | **403** | **403** |
| Foreign index get/search/document PUT | **403** for `indices:admin/get`, `indices:data/read/search`, `indices:data/write/index` | same **403** | same **403** |
| Foreign creation, `PUT /outside-products-scope` | **403**, `indices:admin/create` | **403** | **403** |
| Live index settings, `PUT /shopkmp-products/_settings` | **403**, `indices:admin/settings/update` | **403** | **403** |
| Product namespace index creation | **403**, `indices:admin/create` | **403** | **200**, new stage index |
| `indices:admin/mapping/put`, product `_mapping` | **403** | **403** | **200**, stage publication |
| `indices:admin/refresh*`, product `_refresh` | **403** | **200** | **200** |
| Stage metadata access before configured cutover | **403**, `indices:admin/get` | **403** | **200** |
| HEAD + scroll search/advance/clear | not granted | not granted | **200 / 200 / 200 / 200** |

Runtime document PUT took **916.62 ms**, search **46.77 ms**, document DELETE
**958.27 ms** in the final sample; refresh wait dominates writes. Denied actions
were approximately **3–7 ms**. These are shared-host observations, not capacity
benchmarks. Each role has only the outer `indices:data/write/bulk` cluster transport
action (plus indexer's scroll/clear), **no cluster administration**. Index-specific
bulk/shard actions remain constrained to the role's product patterns; live foreign
PUT proves the outer transport cannot bypass document authorization. Runtime and
reconcile use exactly the selected live target, not the stage wildcard. Indexer
receives only the product namespace's create/mapping/refresh/document/read actions;
no index removal, settings/alias/template/security/cluster administration is granted.
Index removal was neither requested nor tested by sending DELETE.

## Operator fresh install, upgrade, recovery and rollback

These are operator instructions for a separately controlled deployment, **not
operations performed on production by this task**. Run only against the intended
project and keep normal migration/grant/backup and acceptance gates in force.

Exact explicit administration commands (use bootstrap only for a fresh index;
`upgrade`, `catalog-schema` and `check` also apply to an existing initialized index):

```sh
COMPOSE_PROFILES='*' python3 scripts/compose.py --env .env.prod --prod config --quiet
COMPOSE_PROFILES=search-admin python3 scripts/compose.py --env .env.prod --prod run --rm --no-deps search-security-bootstrap
COMPOSE_PROFILES=search-admin python3 scripts/compose.py --env .env.prod --prod run --rm --no-deps search-security-provision upgrade
COMPOSE_PROFILES=search-admin python3 scripts/compose.py --env .env.prod --prod run --rm --no-deps search-security-provision catalog-schema
COMPOSE_PROFILES=search-admin python3 scripts/compose.py --env .env.prod --prod run --rm --no-deps search-security-provision check
python3 scripts/compose.py --env .env.prod --prod run --rm --no-deps reindex
```

1. Supply distinct SQL/search/provider/webhook secrets through the existing secret
   store, matching `.env.prod.example`. Create the separately held operator client
   certificate/key `search-admin.crt` / `search-admin.key`, CA-signed with exact DN
   `CN=shopkmp-security-admin`, clientAuth usage and unencrypted PKCS#8 key. Keep its
   key readable only by the operator/provisioner UID. It must not be the node key.
   Node config already trusts this admin DN. Only profiled admin containers mount it.
   Ordinary deployment validation does not read or require this operator key; it
   may be offline. Certificate/key validation occurs only with search-admin selected.
2. Validate **all profiles** without displaying the merged JSON:
   `COMPOSE_PROFILES='*' python3 scripts/compose.py --env .env.prod --prod config --quiet`.
   This checks inactive command identities as well as runtime. Ordinary wrapper
   rendering includes only active profiles; use explicit profiles for command runs.
3. On a **fresh** installation, start infrastructure without waiting for initialized
   search auth: `... --prod up -d --no-deps postgres opensearch`. Fresh OpenSearch
   intentionally stays uninitialized (503) until the operator acts; a generic fresh
   `prod-infra --wait` cannot complete initialization itself. Wait for verified TLS,
   then invoke `COMPOSE_PROFILES=search-admin ... --prod run --rm --no-deps
   search-security-bootstrap`. It checks existence of `.opendistro_security`; if
   present it returns without upload. No ordinary start invokes `securityadmin.sh`.
4. For **fresh or existing initialized security indices**, explicitly invoke
   `COMPOSE_PROFILES=search-admin ... --prod run --rm --no-deps search-security-provision
   upgrade`. It GETs and compares policy, PUTs only its three named roles/mappings
   when different, and changes only managed internal users whose supplied password/
   backend-role state differs. Unknown HTTP outcomes fail without mutation retries.
   It never uploads a whole security configuration on upgrade, resets the existing
   admin, changes the auth domain, or replaces unrelated mappings/users. Repeating
   an unchanged successful upgrade performs no security-document writes.
5. Before switching existing runtime writers, explicitly run the same provisioner
   with `catalog-schema`. This installs the product-only writer template and sets
   `dynamic:false` on an existing selected target, preserving existing field types,
   `_meta`, documents and UUID. The app's explicit searchable properties remain
   mapped; incidental DTO fields stay in full `_source`. Before adding searchable
   fields, update the explicit bootstrap mapping/template through reviewed indexer/
   operator work. The actual bootstrap/stage create body explicitly sets
   `dynamic:false`, overriding an inherited `dynamic:true` even from a higher-priority
   template. External templates are preserved. The schema command requires true
   JSON acknowledgements and read-only template/index readback; unknown outcomes
   stop without write retry or a completed message.
6. Apply the existing SQL migrations/grants. Explicitly run
   `... --prod run --rm --no-deps reindex` for initial index
   bootstrap, or that service's `php yii maintenance/search-stage <target>` override
   for a staged build. Writers must remain drained for the established stage/cutover
   procedure. For cutover, update `OPENSEARCH_PRODUCTS_INDEX`, explicitly upgrade
   the managed runtime/reconcile policies and apply `catalog-schema` to the selected
   target, then restart the correctly scoped writers. No alias/index deletion is
   part of this procedure.
7. Run `COMPOSE_PROFILES=search-admin ... --prod run --rm --no-deps
   search-security-provision check`, plus real-role action tests against an owned
   clone and the existing required backend acceptance gates before ingress resumes.
   Check authenticates every supplied credential and requires exactly the managed
   role with no backend-role escalation. An existing wildcard mapping (for example
   default `own_index.users: ['*']`) that adds privileges causes a **failure**. The
   operator must inventory and explicitly narrow that unrelated mapping while
   preserving approved human/operator access; the upgrader will not rewrite it.

**Backup/recovery:** keep an independent verified mTLS operator session before any
credential change. Export current managed role/mapping/user configuration to an
operator-owned **0600** backup outside the repository (internal-user exports contain
hashes). Also inventory unrelated operators/mappings and the selected index metadata.
Use trusted-host `curl --cacert ... --cert ... --key ... --output <private-file>`
or the security tool's backup operation; never print internal-user exports, generated
Compose JSON, netrc contents or passwords. A rejected/unknown command outcome is
inspected with read-only `check` and operator TLS before another reviewed action.
An existing but partially initialized/corrupt security index is never treated as
fresh: recover only the reviewed affected configuration via operator API, or a
specific securityadmin `-f ... -t ...` restore preserving the other configuration.
Never delete/recreate the security index or apply the fresh directory to an existing
index as recovery.

**Rotation/revocation:** in a controlled drain, change the intended search password
version in the secret store and explicitly run `upgrade`; unchanged other users are
not rewritten. Check the new credential succeeds and the previous credential now
gets **401** using private netrc/input, not logged argv values. Recreate only the
affected runtime/worker and rerun its real operations. Operator mTLS remains usable
even when all internal passwords are lost. The live test actually proved that path.
Retire the former application admin credential only after independent operator
access and complete old-client inventory have been verified; no automatic admin
demotion or production credential rotation was executed here.

**Rollback:** retain least-privileged Compose identities. Reapply a reviewed previous
managed policy or compatible image and the approved secret-store credential version
through the targeted operator workflow, then verify allowed/denied actions again.
For credential incidents use a fresh version rather than resurrecting revoked
credentials. The disposable rollback test proved old/new **401/200** transitions
and independent operator access; it did not restore a production security snapshot.
Preserve existing catalog/security data and SQL migration history. Do not revert
application services to `admin`, widen roles, bulk-restore unrelated users, or remove
indices/volumes to make rollback work.

## Completion / integration boundary

### Final self-review corrections

- **RED → GREEN:** a parent TLS directory named `search-admin-pki` was incorrectly
  interpreted as an operator-key mount (exit **1**, 0.405 s). Mount checks now use
  the actual source/target filename. The regression also proves a real operator
  key renamed at its target is still rejected on runtime. Broad discovery then
  passed **50/50**, **4.496 s**, deployment command tests **5/5**, **4.684 s**, and
  infrastructure/negative-config tests passed.
- **Ruling:** keep production `reindex` in the default merged model with `scale: 0`
  instead of an inactive profile. The unchanged required
  `scripts/database_roles_native.py` reads its DSN from that model. An added actual
  renderer test reproduced the missing-service failure (exit **1**, 0.457 s).
  The final validator again requires the indexer DSN in the default model and
  rejects nonzero ordinary replicas. The earlier config-only fixture/profile
  changes to `test_deployment_commands.py` and `test_infrastructure.py` were removed.
  Cost if unsupported: ordinary lifecycle or explicit bootstrap would fail; actual
  Compose proved `up` creates **zero** indexer containers and explicit `run` succeeds.
  That proof uses its own network-none, memory-128-MiB PHP container, with no volumes.
  First fixture attempt assumed empty `ps --format json` returns `[]`; Compose
  returns empty output. The corrected quiet-ID query passed. Focused six-test
  privilege suite: **6/6**, **0.898 s**, exit **0**.
- Final verification after those corrections: full Python discovery **52/52**,
  **5.247 s**, exit **0**; unchanged deployment-command gate **5/5**, **4.624 s**,
  exit **0**; unchanged infrastructure gate (dev/prod resolved graph and seven
  rejected unsafe inputs) exit **0**. Shell syntax and `git diff --check` exit **0**.
  A filtered `docker ps -a` for only this task's search/scale/config/PHP-check names
  returned no containers, exit **0**. `git diff --cached --name-only` was empty.

### Final file inventory

- `docker-compose.yml`, `docker-compose.prod.yml`, `.env.prod.example`.
- `backend-yii2/bin/database-preflight.php`, `backend-yii2/infrastructure/Env.php`.
- `deploy/search-start.sh`, `deploy/search-roles.php`,
  `deploy/search-security-bootstrap.sh`, `deploy/search-security-provision.php`,
  `deploy/search-security/*.yml`, `deploy/search-privileges-test.php`,
  `deploy/search-catalog-test.php`.
- `scripts/validate_production.py`, `scripts/test_backend_integration.py`,
  `scripts/test_pki.py`, `scripts/test_test_pki.py`, new
  `scripts/test_service_privileges.py`, `scripts/test_service_command_config.py`,
  `scripts/test_search_privileges_live.py`.
- This report only under round-7 docs. No staged files.

Task-3 scoped implementation and real-role verification are GREEN. Final review was
**self-review (no child agents, per user instruction)**; coordinator independent
review/integration remains required. Changed files are limited to the assigned
Compose/search-admin/bootstrap/config-validator/config-tests/PKI/example-env/report
scope. `ApiKernel.php`, `PaymentService.php`, Go, PHP dev tooling, Makefile and other
round-7 reports were not edited by this task.

The complete SQL-role/migration/auth/payment/Go→Nginx→FPM acceptance, exact rebuilt
image vulnerability/SBOM gates and platform gates were **not rerun by this F2 task**.
Their existing required assertions remain intact. The new small real SQL fixture
and baseline-image source mounts do not satisfy those broader publication gates.
No F2 live-capacity blocker remains; whole-round acceptance/security/platform and
independent coordinator review remain integration blockers until executed/passed.

## Independent-review fix pass — 2026-11-01

One requested fix pass for three Important and two Minor findings, with systematic
reproduction before fixes. Coordinator readiness/Go/tooling/payment work was present
in the worktree and was not edited. The sole newly authorized application change is
the explicit `dynamic:false` field in `OpenSearch::build`'s mapping body.

| Finding | RED evidence / correction / current verification |
| --- | --- |
| I1 ordinary lifecycle depends on offline operator key | New actual merged-wrapper config regression: exit **1**, **2.012 s**, ordinary production config rejected when the test operator key was offline. Operator TLS validation moved out of unconditional `validate(env)` into validation of actually selected search-admin services/mounts. Ordinary config with missing operator key now succeeds; explicit search-admin config rejects missing/invalid keys and accepts valid material. Focused suite **7/7**, exit **0**, **2.343 s**. |
| I2 higher-priority external template defeats low-priority dynamic policy | Actual priority-**1000** external template with `dynamic:true`; genuine empty `OpenSearch::bootstrap` and `OpenSearch::stage`; stage readback **dynamic="true"**; actual `CatalogService::createProduct` logged index-update failure and operator document readback returned **404**. Native probe exit **1**. Minimal correction adds explicit `dynamic:false` to the actual index create body, overriding template inheritance without modifying the external template. Clean live GREEN is recorded below when run. |
| I3 false/malformed acknowledgement and no readback | New deterministic real curl + verified loopback mTLS protocol test: **27 scenarios / 27 failures**, exit **1**, **1.131 s**. False/missing/string/numeric/error-envelope/malformed/scalar/array acknowledgements all incorrectly reported completion. Added strict object JSON parsing, exact boolean acknowledgements, installed-template readback and existing-index pre/post readback checking dynamic policy, UUID and unchanged fields/metadata. Request traces prove no mutation retry and no completion output for unknown outcomes. |
| Minor: startup diagnostic labels config as SQL | New actual PHP preflight regression exit **1**: missing runtime external configuration emitted SQL diagnostic. Split fixed, non-sensitive diagnostics; unchanged SQL failure text preserved for native SQL-role gate. Focused config suite **2/2**, exit **0**, **0.882 s**. |
| Minor: machine-local test image defaults | Shared image selection now uses `SERVICE_PRIVILEGES_PHP_IMAGE` override, then canonical `PHP_TEST_IMAGE`, then `shopkmp-php-tests` built by existing `make test-php`. Live runner accepts explicit PHP/PostgreSQL/OpenSearch images or `*_TEST_IMAGE` env, with no round-6 local tag defaults. No Makefile/CI/tooling edits. Execution below explicitly opts into the available baseline images. |

The wrapper's existing argparse does not accept a leading `--profile` argument.
The real merged regression caught that test-invocation issue (exit **2**) rather
than mistaking it for key rejection. Use Docker's supported explicit environment
selector with this wrapper: `COMPOSE_PROFILES=search-admin python3 scripts/compose.py
--env .env.prod --prod ...`, or `COMPOSE_PROFILES='*' ... config --quiet`. The runbook
command spellings are corrected below. No wrapper changes outside F2 ownership.

### Live reproduction and protocol follow-through

Owned project `shopkmp-round7-search-abdda6ef-e2e`, directory
`shopkmp-task4-pki-1hrwqizn`, isolated subnet **10.252.212.0/24**, bounded memory as
above. Same explicitly selected baseline image IDs as the initial task. Foreign
stacks, including coordinator demo containers, were inspected read-only only.

- Full live command used `scripts/test_search_privileges_live.py --run --parent
  /var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode --subnet 10.252.212.0/24
  --php-image shopkmp-round6-acceptance-e2e-yii2 --postgres-image
  shopkmp-round6-acceptance-e2e-postgres --opensearch-image
  shopkmp-round6-acceptance-e2e-opensearch`.
- Fresh node reached uninitialized HTTPS **503** in **6.460 s**; bootstrap exit
  **0**, **13.187 s**. First upgraded strict parser exposed OpenSearch's non-JSON
  Basic-auth **401** (provisioning exit **1**, **0.933 s**). A diagnostic-only follow-up
  confirmed HTTP **401**, without logging its body or credentials. One immediate
  restart probe also returned transport-not-ready; it was not counted passing.
- Added a 28th real TLS protocol regression for non-JSON 401: exit **1** before
  correction, then **28/28**, exit **0**, **1.198 s**. A 401 remains an explicit
  authentication denial, never an acknowledgement; malformed **200** remains fatal.
  Initial Basic-auth fixture required an operator client certificate that normal
  Basic clients do not send; corrected only that fixture's optional-client mode.
- After that correction, targeted `upgrade` and `catalog-schema` each exited **0**,
  then the native conflicting-template reproduction failed as described for I2.
- Expanded real role probe exited **0**. It uses OpenSearch's actual reserved static
  `own_index` role and installs the realistic legacy `users: ['*']` mapping. The
  initial attempt to PUT that reserved role was rejected; the fixture now reads the
  real role and changes only the disposable mapping. Wildcard-enabled runtime
  `PUT /shopkmp_runtime` returned **200**. Both `check` and `upgrade` rejected the
  unexpected effective privileges. Explicit operator narrowing returned **200**;
  runtime `GET /shopkmp_runtime` then returned **403**, `indices:admin/get`, while
  the independent existing operator still got cluster health **200**.
- Managed-policy rollback is now **actually tested**: read/retain original safe
  runtime permission config; apply controlled policy removing document delete;
  runtime document DELETE **403**, `indices:data/write/delete`; explicitly PUT the
  recorded prior safe policy; same DELETE **200**. Policy readback matched exactly;
  independent role/mapping/internal-user objects remained byte-equivalent in memory.
  Final `check` passed. Credential rotation/revocation/rollback still produced
  **401 / 200 / 401 / 200**, with independent operator mTLS **200**.
- Applied `catalog-schema` to the owned failed `dynamic:true` empty stage: exit
  **0**, strict acknowledgement and actual UUID/fields/metadata-preserving readback
  passed. Only that flag changed. Stop/remove own containers/network each exit **0**;
  both volumes retained, no index deletion.

### Final clean review-pass verification

The exact explicit-image live command above completed with **exit 0** on new owned
project `shopkmp-round7-search-915e4b54-e2e`, directory
`shopkmp-task4-pki-18y9lzib`. Same unused-checked subnet and memory caps; no foreign
stack or production operation. This run supersedes the failed reproduction for the
review corrections; the failures above remain recorded as RED/debugging evidence.

| Final live phase | Actual exit / measurement |
| --- | --- |
| Fresh uninitialized TLS | HTTP **503**, **6.414 s** |
| Explicit fresh bootstrap | **0**, **13.345 s** |
| Upgrade / check / repeat upgrade / check | **0 / 0 / 0 / 0**, **3.025 / 1.082 / 0.564 / 0.612 s** |
| Acknowledged catalog schema + actual template readback | **0**, **0.574 s** |
| Repeat fresh bootstrap, no security-index overwrite | **0**, **0.501 s** |
| Full real role matrix + legacy wildcard + policy/credential rollback | **0**, **13.410 s** |
| Actual PHP bootstrap, empty stage, conflicting-template runtime writes, schema readback, reconcile and populated stage | **0**, **6.378 s** |
| OpenSearch memory | **582.1 MiB / 768 MiB**, stats command exit **0** |
| Ordinary restart and subsequent unchanged-policy/auth check | Restart **0**, **0.352 s**; read-only check passed |
| Stop own nodes / remove own containers / remove own network | **0 / 0 / 0**, **0.230 / 0.146 / 0.162 s**; volumes retained |

I2 GREEN readback was **dynamic="false"** in the newly built **empty stage** despite
the priority-1000 external template's `dynamic:true`. Runtime catalog document
readback was **HTTP 200**, including full source/price/images, with real
create/update/search/deactivation/document-delete. The external template's complete
readback was unchanged before/after. Actual `catalog-schema` acknowledgement and
readback passed both with the selected target absent and with it present. Existing
UUID, explicit fields and application `_meta` were preserved. No index was removed.

Legacy wildcard proof in the clean run: reserved default `own_index` plus wildcard
mapping allowed `PUT /shopkmp_runtime` **200**, **286.43 ms**; check/upgrade rejected
unexpected privileges. Explicit operator mapping narrowing **200**; runtime GET
then **403**, `indices:admin/get`, **5.25 ms**, independent operator health **200**.
Managed-policy rollback proof: restricted document DELETE **403**,
`indices:data/write/delete`, **219.19 ms**; targeted restoration of recorded safe
policy **200**; same DELETE **200**, **228.89 ms**; complete policy and independent
operator object readbacks unchanged. Runtime security/cluster/foreign/lifecycle
denials and reconcile/indexer allowed actions from the matrix all passed again.

Affected checks after corrections (all actual **exit 0**):

- `PHP_TEST_IMAGE=shopkmp-round6-acceptance-e2e-yii2 python3 -m unittest discover
  -s scripts -p 'test_*.py' -v`: **55/55**, **8.843 s**, including the **28**
  deterministic real TLS protocol cases. This explicitly selects an available image;
  no local baseline tag is required by the test source.
- `python3 scripts/test_deployment_commands.py --production-env
  <private-final-review-test.env>`: unchanged gate **5/5**, **4.728 s**. This test
  intercepts lifecycle side effects and uses actual Make/wrapper/model validation.
- `python3 scripts/test_infrastructure.py --production-env
  <private-final-review-test.env>`: actual dev/prod merged configuration and seven
  unsafe-input rejections passed. Explicit admin PKI validation is covered by the
  new actual merged-wrapper profile regression, not ordinary lifecycle validation.
- Offline baseline-image PHP command with current infrastructure/bin/tests/deploy
  mounted read-only, network none, **256 MiB** cap: `composer test && composer lint`
  passed **74 tests, zero failures / 140 PHP files**, plus explicit syntax checks
  for preflight and four changed/new deploy PHP artifacts. Baseline Composer unit
  command and scoped source mounts do not replace coordinator native integration.
- `bash -n deploy/search-start.sh deploy/search-security-bootstrap.sh`,
  `git diff --check`, and `python3 scripts/route_matrix.py --check` passed. Contract
  matrix remains **54 / 8 / 6**. Index/schema mutation retry policy was not relaxed.
- Final scoped diff confirms `OpenSearch.php` changed only the authorized create
  mapping field. `git diff --cached --name-only` was empty. Filtered `docker ps -a`
  for this task's search/schema/scale/config/PHP-check names returned no containers,
  exit **0**. Source searches found no hardcoded round-6 acceptance image defaults
  in the F2 config/protocol/live test scripts.

### Clean CI / developer test prerequisites

The canonical PHP image is the existing `make test-php` output. For a clean checkout:

```sh
make test-php
PHP_TEST_IMAGE=shopkmp-php-tests python3 -m unittest discover -s scripts -p 'test_*.py' -v
```

To run the explicit live fixture on another host, build pinned test images using
the existing Dockerfiles, then select them explicitly; these are instructions, not
additional builds performed by this fix pass:

```sh
docker build -t shopkmp-f2-opensearch-tests -f deploy/opensearch.Dockerfile .
docker build -t shopkmp-f2-postgres-tests -f deploy/postgres.Dockerfile .
python3 scripts/test_search_privileges_live.py --run --parent /private/test-parent --subnet 10.252.212.0/24 --php-image shopkmp-php-tests --opensearch-image shopkmp-f2-opensearch-tests --postgres-image shopkmp-f2-postgres-tests
```

Use an existing approved private parent and an actually unused subnet. Image
selection may also use `PHP_TEST_IMAGE`, `POSTGRES_TEST_IMAGE`,
`OPENSEARCH_TEST_IMAGE`; the existing `SERVICE_PRIVILEGES_PHP_IMAGE` PHP override is
retained. No test silently selects a machine-local round-6 image tag.

### Review-pass scope and handoff

All **three Important and two Minor findings are fixed**. Added files are
`deploy/search-schema-protocol-test.php`, `scripts/test_search_schema_protocol.py`
and `scripts/test_service_support.py`. Additional edits are limited to the owned
validator, preflight, F2 configuration/live fixtures, this report, and the expressly
authorized single mapping-body field in `backend-yii2/infrastructure/OpenSearch.php`.
No coordinator readiness/Go/payment/tooling/Makefile/CI or other report changes.

No new F2 blocker remains. Full coordinator readiness/Go/HTTP/native SQL-role and
rebuilt-image security/platform publication gates were **not run by this fix pass**
and are not claimed passing. No child agents, stage/commit/push, production action,
foreign lifecycle operation or index/volume deletion was performed. Independent
coordinator review/integration remains the handoff.
