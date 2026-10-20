# Independent final review — Task4 infrastructure/integration

> **Historical review — before decommissioning.** Results, archive comparisons and
> source/line references below describe the reviewed milestone. The source/archive
> and reference comparison profiles have since been retired; no new review pass is
> claimed. See [decommissioning](LEGACY_REMOVAL.md) and [deployment](DEPLOYMENT.md)
> for extracted ops tools, current commands and external-release rollback.

Date: 2026-10-18. Scope: Task4 in `IMPLEMENTATION_PLAN.md:86–102`, the latest
`MIGRATION_PLAN.md`, root Compose/production overlay/env/Makefile, `deploy/**`,
deployment/verification scripts, the narrow development-seed addition and the
requested documentation/evidence. Prior subsystem remediation approvals stand;
this review assesses their deployment boundaries.

## Verdict

**Specification: substantially implemented; Task4 acceptance requires I1.** The
single public edge, independent PHP business runtime and HTTP-only Go BFF,
preserved checksum migrator, explicit seed, TLS overlay and representative
integration contracts are present. Worker health does not establish successful
maintenance/reconciliation.

**Quality: changes required — 0 Critical, 1 Important finding.** One minor
operational-documentation finding is separate. The recorded integration results
are useful and corroborated by their logs/captures; they do not cover I1.

## Important finding

### I1 — Failed scheduled jobs remain healthy indefinitely

**Locations:** `deploy/php/scheduler.php:7–14`;
`docker-compose.yml:201–229`, especially the health predicates at 210 and 225.
Deployment waits rely on these checks (`Makefile:18–23,58–59`).

The scheduler touches the heartbeat before launching a job and again after
`proc_close()`, including when the job exits nonzero. A failure only produces a
log line; both worker healthchecks inspect the heartbeat's age, never the job's
result. A worker can therefore become and remain healthy without one successful
job. Retrying the job does not correct this false health signal.

**Failure scenario:** a maintenance worker loses its DB connectivity while the
API remains reachable, or its job repeatedly fails on SQL/lock errors. The parent
continues refreshing its heartbeat, so reservation expiry/stock release and
cleanup can stop while deployment health stays green. Scheduled reindex failures
are masked identically. The initial one-shot reindex correctly gates startup,
but does not establish later worker success.

**Independent reproduction:** ran the existing Yii image
`sha256:95ec9ddbd9b416624c1adc7e18926841c318624ae791af1b889d77f453293f8d`
in a temporary container with `--network none`, the unchanged scheduler mounted
read-only, and `DATABASE_URL=postgres://review:unused@127.0.0.1:1/review?sslmode=disable`.
Invoked `php /opt/scheduler.php maintenance/run 1` with the exact maintenance
health predicate. Only the retry/probe intervals were accelerated to one second:

```json
{
  "successfulJobs": 0,
  "failedJobs": 2,
  "containerStatus": "running",
  "dockerHealth": "healthy",
  "lastHealthExitCode": 0,
  "failureLine": "Scheduled maintenance/run failed: 1"
}
```

The container was removed afterward; it had no business-network access. This
tests the deployed scheduler/health interaction, not the already reviewed
maintenance business logic.

**Required correction:** distinguish a live scheduler from a successful worker.
Require a first successful completion before healthy, retain last-success/result
state, and make persistent failures/stale success fail the worker healthcheck.
Keep retries and account for each job's legitimate running interval. Verify a
failed child with a live parent, recovery after success, and both worker modes.

## Minor finding

### M1 — Production staged-operation commands need explicit overlay selection

**Locations:** `docs/DEPLOYMENT.md:176–204`; `Makefile:3–6,25–39,55–59`;
`scripts/compose.py:21–29`.

The rollout names the migration services and requires stopping writers/workers,
but does not give executable production commands for those stages. The generic
Make targets always select the base model: even
`make migrate ENV_FILE=.env.prod` omits `--prod`, resolving development application
settings and a plaintext migration DSN. Selecting the production env file alone
does not select the production overlay. A dry run confirmed this expansion; no
production command was executed.

Add exact `scripts/compose.py --env .env.prod --prod ...` commands for the staged
jobs and writer/worker drain/stop, or equivalent production-aware Make targets.
State explicitly that generic lifecycle targets are development-only. Existing
`prod-config`/`prod-up` do select the overlay correctly.

## Boundary assessment

| Boundary | Assessment and evidence |
| --- | --- |
| One edge; route priority/actions/media/callback | `deploy/nginx/routes.conf:17–31` sends screen/SDUI prefixes to BFF before the canonical business prefix; uploads, product images, icons and demo callbacks reach Yii. Webhooks remain canonical business routes. Production demo rejection remains PHP-owned. `integration.json` records CARD capture, native checkout, policies and races; `bff/manifest.json` records all eight screens/six commands. PHP's full API log includes owner-only avatar download and signed webhook coverage. |
| Operational isolation and actual trust | Only edge publishes a port (`docker-compose.yml:231–255`; production override 94–104). Production blocks operational/Swagger paths, and unmatched application paths return 404. `proxy.conf:1–20` reconstructs headers; `yii2.conf.template:6–9,18–25` trusts only the fixed edge address and passes resolved `REMOTE_ADDR`; PHP's forwarded trust is empty. `runtime.json:2–4` correlates the actual socket with the SQL rate principal; `tls-smoke.json` plus its driver support hidden paths and spoofed webhook-source rejection. No new auth/identity-spoof bypass identified at this boundary. |
| No legacy business server; Go has no DB credentials | Base/production service definitions contain no reference business service. `deploy/migrate.Dockerfile:5–11` copies only the original migration CLI/library into its final image. BFF receives only `YII_ORIGIN` and has no data-network membership (`docker-compose.yml:186–199`). `deploy/compose.verify.yml:8–27` explicitly scopes the temporary legacy writer to disposable compatibility testing; `verify.py:48–57` stops it in `finally`. Recorded runtime evidence distinguishes its stopped container. |
| Migration history, seed and existing data | Original migrator precedes the separate Yii history job (`docker-compose.yml:48–67`). Archive byte comparison independently confirmed all 11 forward SQL files, migrator library/CLI and realm are unchanged: 14 files. Existing checksum validation remains in `backend/migrations/migrate.go:175–188`; Yii selects `yii2_migration_history` in `backend-yii2/config/console.php:7`. Seed refuses production before DB access, locks both catalog tables and exits if either is populated (`DevelopmentSeedController.php:13–24`); recorded preservation tests pass. The 11+1 history counts are fresh-stack evidence, not a restored-production-data rehearsal. |
| Single writer and rollback | `DEPLOYMENT.md:178–204` requires legacy drain before new writes, no dual production writers, stopping Yii/workers before rollback, provider reconciliation and retaining additive tables/history. No destructive rollback is wired into startup. Actual backup restoration, volume mapping and cutover remain release gates, not proven by disposable compatibility tests. |
| Startup/readiness/lifecycle | Original migration → Yii history → required initial reindex → FPM → internal HTTP readiness → BFF/edge is encoded in Compose. FPM uses a real FastCGI ping, internal HTTP checks application readiness, BFF has its image probe. Independent maintenance/reconcile processes prevent reindex from monopolizing minute maintenance. Recorded private probes and six graceful exit codes support the normal path; ongoing worker-success health is defective as I1 describes. |
| HTTPS, issuer, secrets and dev/prod override | Production explicitly changes PHP/jobs to production/verify-full, enables authenticated search TLS, requires provisioned secrets/provider CIDRs, replaces edge ports/mounts and keeps issuer/discovery/JWKS on the private HTTPS origin. PHP's provider/identity/search trust uses the mounted CA bundle. `validate_production.py:13–62` checks secrets, addresses, certificates/keys and TLS identities. Recorded TLS smoke supports actual private-issuer login/profile, DB TLS/plaintext rejection and public isolation; provider TLS-fixture results establish mechanics, not real settlement. Existing realm/search-password rotation caveats are documented. |
| Reproducible checks and claims | Make expansions, wrapper/verification source, route generator and evidence logs are mutually consistent for the documented development run. `php-all.log` supports 69 tests/60 syntax checks and unskipped differential/cross-runtime phases; the BFF log/manifest supports same-edge live execution. Original Go/E2E logs support their recorded results. The reports clearly distinguish 54-route success/access coverage from 14 GET/3-error differential coverage and representative persistence interoperability. M1 addresses staged production command clarity. |

## Method, evidence limits and completion gates

The repository is unborn/untracked, so there is no commit-range diff. Reviewed
the supplied files directly and checked the latest appended subsystem-review
verdicts. Inspected current container metadata: the isolated migrated stack was
running healthy at review time with only edge published. That snapshot is not a
new end-to-end execution or evidence against I1.

No documented suite, mobile build or TLS-stack run was repeated. New checks were
limited to the worker failure probe above, read-only runtime/image inspection,
Make dry runs and archive byte comparison. No code/config/test edits, commits or
subagents were used; this document is the sole workspace file written.

The author self-review's no-outstanding-finding statement in
`VERIFICATION_REPORT.md:276–285` is superseded by I1 for independent Task4 review;
the recorded passing normal-path checks are not invalidated.

The following acknowledged gaps remain **completion/release gates**, not newly
discovered implementation defects:

* Installed authenticated Android/iOS journeys, physical credential prompts,
  payment-app return and Android process-death instrumentation.
* Exhaustive 54-operation input/error/state differential parity and the remaining
  concurrency/payment interleavings; representative coverage is not full parity.
* Restored existing-data rehearsal, explicit business/Keycloak volume or restore
  mapping, checksums/row contracts, backup/PITR/rollback and a single-writer
  production cutover. Root defaults create project-scoped volumes; fresh-stack
  evidence does not authorize pointing an edge at a new empty identity database.
* Real provider trust/reachability/protocol/settlement/ambiguous outcomes,
  sustained load, large-catalog reconciliation, failover/alerting and full JWKS
  rotation/outage behavior.

**Disposition:** correct I1 and recheck that narrow failure/recovery boundary;
address M1 before using the staged production runbook. Retain the explicit release
gates and the original reference artifacts.

---

## Scoped independent re-review — I1 / M1 remediation (2026-10-18)

### Scope and method

Reviewed only the two findings above and their remediation: scheduler/state/
health scripts, worker Compose/env wiring, Make/wrapper production stages,
deployment instructions, process/command regression tests and the appended
`VERIFICATION_REPORT.md:318–507`. Inspected the saved scheduler log and updated
runtime success snapshots. Earlier review text remains historical evidence.

No suites, prior probes, builds or deployments were rerun: source/test inspection
and the supplied execution evidence resolved this scope without a concrete reason
for another execution. Reported test results below are the implementer's recorded
runs, not independent executions by this re-review. Only this review appendix was
written; no code/config/test edits, commits or subagents were used.

### I1 — Addressed

* `deploy/php/scheduler.php:11–15,40–52,59–67` initializes without success, retains
  failure while a retry starts, and updates last success only after an in-budget,
  successful child completion. Atomic state publication is separate from success
  (`deploy/php/scheduler-state.php:11–16`).
* `scheduler-state.php:18–30` requires a live parent, fresh heartbeat, latest
  successful result, non-stale monotonic success and an in-budget running attempt.
  First attempts, failed/timeout results and stopping/stopped phases cannot pass.
  `scheduler-health.php:4–12` exposes distinct live/ready/status behavior and fails
  closed on unavailable/malformed state.
* Timeout/shutdown handling bounds active children with TERM then KILL and reaps
  them before another starts (`scheduler.php:25–57`). A timeout or overdue exit0
  cannot publish success; ordinary shutdown records stopped without refreshing
  success. Legitimate running work can retain readiness after a prior fresh
  success, within both runtime and freshness bounds.
* Both Compose workers now execute the result-aware **ready** probe, use finite
  configurable budgets and allow long first work to remain starting rather than
  falsely healthy (`docker-compose.yml:201–237`). Production merges preserve this
  worker wiring alongside production TLS inputs. `Makefile:5,22–27,88–95` sizes
  waits for first-success readiness; deployment guidance documents the budgets.

`scripts/test_scheduler.py:101–193` covers six substantive process cases for each
mode: actual dependency failure, first success/failure/recovery, legitimate work/
overrun/child signalling, stale success, frozen parent and overdue completion.
It uses the resolved Compose probe, actual scheduler and isolated processes;
only duration/outcome cases replace the business CLI. The saved
`deploy/evidence/scheduler-regression.log:1–17` records **12 passing tests**.
`runtime.json:84–133,142–191` records live/ready, exit0, successful results and
non-null success timestamps for both real workers before and after restart;
the recorded restarted reindex completed in approximately 168.9s. The assertions
in `scripts/runtime_checks.py:32–38,70,79–87` require that success explicitly.

These changes close the original false-health failure and cover recovery and the
principal deadline/shutdown risks introduced by replacing the blocking scheduler.

### M1 — Addressed

`Makefile:4–10,65–103` consistently uses `PROD_ENV_FILE` and `--prod` for explicit
production build/infra/drain/jobs/app/workers/edge/rollback-stop/down/logs stages.
Drain stops edge first; jobs and staged application starts use `--no-deps`, so
they do not implicitly restart drained writers or execute dependency jobs outside
the sequence. `scripts/compose.py:21–38,49–54` rejects production-named/marked/TLS
inputs without the overlay before invoking Docker, retains production validation
for starts/jobs and rejects the verification-writer overlay in production.

`docs/DEPLOYMENT.md:28–33,284–355` supplies executable staged and equivalent wrapper
commands, separates the old deployment's stop-only operation, and documents
single-writer ordering, existing-volume/restore prerequisites and rollback stop.
It explicitly distinguishes command execution from real backup/rollback approval.

`scripts/test_deployment_commands.py:36–94` checks wrong-overlay rejection before
Docker, ordered production service arguments, `--no-deps`, production TLS/CA
resolution and continued development selection. The remediation appendix records
**4 passing test methods** using actual Make/wrapper/validation and a Docker
side-effect shim; it does not claim those tests performed a production rollout.

### Introduced-regression assessment and updated verdict

**No newly introduced Critical or Important defect identified in this scoped
remediation.** The worker fix retains bounded, non-overlapping retries and
shutdown; the staged commands retain production validation and single-writer
ordering. The supplied failure/recovery and runtime evidence supports closure.

**Specification verdict: I1 and M1 are addressed; the prior Task4 review blocker
is cleared in this scope. Quality verdict: acceptable for the reviewed remediation
— 0 outstanding Critical/Important findings, with M1 closed.**

Installed mobile journeys, exhaustive parity, restored existing-data/cutover/
backup/rollback rehearsal, actual provider integration, load/HA/alerting and JWKS
rotation/outage remain the previously acknowledged completion/release gates. This
verdict does not convert those gaps into executed checks or authorize cutover.
