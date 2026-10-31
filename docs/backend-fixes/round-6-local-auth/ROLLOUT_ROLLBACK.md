# Controlled SQL-role rollout and rollback

**No production rollout, password rotation or migration was executed in V7.**
The commands below require the separately approved deployment window and backup/
restore rehearsal. Initializer scripts alone never upgrade an existing volume.

## Roles and credentials

| Connection | SQL login | Capability |
| --- | --- | --- |
| Web / ordinary application | `shopkmp_runtime` | Explicit business/auth DML; restricted users UPDATE columns; no schema/table ownership, journal capability or DDL |
| SQL and Yii migrations | `shopkmp_owner` | Managed application schema ownership; no superuser/CREATEDB/CREATEROLE/BYPASSRLS; absent from web and recurring workers |
| Selected identity commands | `shopkmp_identity_operator` | Reviewed per-UUID state/password changes and revocation; append-only journal; addressed legacy-device remediation with its audit |
| Recurring search reconciliation | `shopkmp_search` | Catalog SELECT only |
| Controlled one-shot index bootstrap | `shopkmp_indexer` | Catalog SELECT plus table UPDATE required by PostgreSQL SHARE locks; no identity/session rights |
| Retention / order expiry worker | `shopkmp_maintenance` | Bounded cleanup and local financial expiry; no user verifiers, credential writes or token issuance |
| Payment recovery worker | `shopkmp_recovery` | Financial operations and historical state counts; no verifier reads, token issuance or token cleanup |
| Explicit DBA provisioning | existing `shopkmp` bootstrap administrator | Only the profiled `database-provision` service / trusted administrator environment |

All login secrets must be distinct random URL-safe values, supplied by the
deployment secret store/environment using the keys in `.env.prod.example`.
Populated files, rendered secret-bearing Compose JSON and credentials must not be
committed or logged. Store any local deployment env file with mode 0600. Runtime
does not fall back to the bootstrap password. Provisioning is an explicit DBA
command, not an initdb hook or HTTP operation. `roles` sets the explicitly supplied
role passwords; `grants` does not rotate them.

## Existing database / fresh database

1. Inventory actual connections/owners/audit dependencies; verify backup and
   restoration. Drain ingress and all known old application/worker connections
   with the controlled deployment process. Do not target unrelated projects.
2. Inject the new secrets; build approved images and run the production validator
   with `config --quiet` through `scripts/compose.py --prod`. The wrapper captures
   and validates Docker's merged model without printing its credentials.
3. In the approved environment run the profiled `database-provision` service with
   `php /opt/database-provision.php roles`. This creates/validates distinct roles,
   fixes public schema permissions and DB CREATE/TEMP defaults. Existing unsafe
   roles/memberships require explicit DBA review; provisioning fails closed.
4. For an existing schema run `database-provision ... grants` before switching the
   migrator. It transfers only manifest-listed application objects from the known
   bootstrap/owner roles, preserving unrelated audit objects and triggers.
   On a fresh database run migrations with the new owner first, then grants.
5. Run `prod-migrate` (SQL followed by Yii) and rerun the explicit `grants` phase.
   Future new tables/functions require a reviewed manifest/grant update, not a
   blanket runtime default grant. Global default PUBLIC function EXECUTE is revoked.
6. Run actual runtime/operator/worker preflights and native role/functional gates
   against an owned disposable clone first; verify current/session user, ownership,
   denied DDL/SET ROLE/journal operations, preserved business data/audit execution
   and unchanged migration history. Inspect source-bound final artifact security.
7. Only after those gates and the separate deployment approval, switch all service
   DSNs, start workers/app and restore ingress. The entrypoint runs bounded catalog
   preflight; readiness checks the actual runtime identity without catalog scans.
8. Audit remaining old-role sessions. After separately provisioning and testing an
   independent DBA recovery path, restrict old login/network access in a separately
   approved action. Never demote the sole recovery administrator automatically.

The operator command root is the `identity-operator` profile service. For example,
invoke its `php yii auth/inventory` or an individually reviewed `auth/*` command;
password reset still requires TTY ownership proof and existing explicit flags.
Addressed legacy-device commands use this same operator connection, not web or
migrator credentials. Their lock needs UPDATE(last_used_at) on device tables, not
permission to rewrite PIN hashes/public keys. Journal UPDATE/DELETE/TRUNCATE remain
denied and the existing immutable trigger remains installed.

## Rollback

Rollback an application image only after verifying compatibility with the current
local identity/schema. Retain the new role configuration and grants; reverting
Compose to a shared owner DSN is not an acceptable rollback. Preserve journal,
security versions, session revocations and migration history. Do not drop roles,
users, tables or volumes, rewrite 001–016/checksums, resurrect old tokens, or roll
back to retired identity trust. Prefer a reviewed roll-forward ACL/code correction.
An interrupted provisioning transaction rolls back its own DDL/ACL changes; unknown
transport/commit outcome must be independently inspected rather than blindly reset.

`scripts/database_roles_native.py` uses a random task-owned project, private
three-day test PKI and actual base+production Compose with only test network/memory
overrides. Cleanup removes that project's containers/networks and retains volumes.
Its `--resume-test-env` option requires the matching disposable test-PKI project.

## Runtime and acceptance handoff

The default backend image is now the verified PHP **8.5.12** digest; Composer lock,
Argon2id and legacy PIN/token encodings are unchanged. Both actual PHP architecture
images passed extension/TLS/FPM/pruning and complete native PHP suites; amd64 was
executed under Docker emulation, not measured as native deployment capacity. Final
arm64 source-bound acceptance and all live trust groups pass. The exact seven-image
security gate still fails: current OpenSearch HIGH findings and stale official Go
vulnerability DB. The unchanged native iOS journey now also actually fails at
Safari submission (2 GET / 0 POST); its assertion is preserved. These are release
conditions, not a request to execute production cutover now.

For owned development acceptance on hosts whose default Docker address pools are
exhausted, `BACKEND_DISPOSABLE_NETWORKS=1` enables only the network overlay. Set an
explicit `shopkmp-...-e2e` project, `APPLICATION_TEST_SUBNET` and `DATA_TEST_SUBNET`,
plus the existing ingress/FPM/payment-test subnet/IP variables. Carry the same
explicit environment through `backend_artifacts.py`, `verify.py`, trust and
security commands. The opt-in is excluded from production and rejects ordinary
project names. It does not replace application dependencies, weaken network trust
checks or authorize stopping other projects. This run used 10.252.188–192 subnets
and loopback port 58477, with actual canonical scripts and source/image receipts.

Do not substitute the examined 3.9.0 image as a remediation: its comparable scan
has 32 HIGH and it failed qualification before API/data migration. The current
2.19.7 source/index volume was not upgraded in place. A later vendor-fixed candidate
must still undergo the documented compatibility, snapshot/restore and controlled
cutover rehearsal before any production change.

Latest continuation receipt is `acceptance-artifacts-continuation.json`, with
current source fingerprint in TEST_RESULTS. It supersedes the pre-fixture-fix
receipt. The actual unchanged native iOS test was run on a new task-owned simulator;
it failed at Safari completion, and that simulator was shut down. UI source and
the required payment assertion were not changed to make the rollout gate pass.
