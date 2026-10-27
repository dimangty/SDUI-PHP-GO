# Deployment and verification guide

## Local full stack

Requirements: Docker/Compose 2.24.4+, Python 3.9+; Go 1.26 for host tests and
OpenSSL CLI for production certificate validation/test PKI. Linux
OpenSearch needs `vm.max_map_count=262144`; allow enough Docker memory for both
Keycloak and OpenSearch. PHP/Composer are provided by the independent Yii image.

```sh
cp .env.example .env
make run
curl --fail http://127.0.0.1:8080/api/v1/screens/home
```

`make run` builds and starts, waits for probes, explicitly seeds an empty
development catalog and explicitly reindexes. `make up` omits the seed. Catalog
seeding is not an entrypoint, migration or HTTP fallback. `make seed` is safe to
repeat against a populated development catalog: it returns `Catalog already
populated; no changes`. Production refuses the command. Seed data is ordinary
persisted business data, with real price/stock/media rows, not mock responses.

Useful targets: `build`, `up`, `down`, `logs`, `migrate`, `seed`, `reindex`,
`test-php`, `compose-config`, `test`, `test-race`, `vet`, `e2e`. `down` retains
volumes. To use another port set `EDGE_PORT` in `.env`; `EDGE_BIND` defaults to
loopback. Network addresses must be nonoverlapping with other Docker projects.

**Generic lifecycle targets and ENV_FILE are development-only.** Selecting
`ENV_FILE=.env.prod` does not select a production overlay and is rejected before
Docker is invoked. Production commands use the explicit `prod-*` targets and
`PROD_ENV_FILE` (default `.env.prod`), or `scripts/compose.py --env ... --prod`.
The wrapper also rejects custom production-marked/TLS environment files without
`--prod`; raw Docker Compose remains the operator's responsibility.

Only edge is published. Keycloak, both DBs, OpenSearch, FPM, internal Yii HTTP
and BFF have no host mappings. Inspect privately with
`python3 scripts/compose.py exec -T yii2 curl -fsS http://yii2-http:8080/health/ready`
or `python3 scripts/compose.py exec -T yii2-http wget -q -O - http://sdui:9090/health/ready`.
Development-only Swagger is at `/swagger/`;
its YAML is the unchanged `backend-yii2/contracts/openapi.yaml` mounted read-only.
The UI loads a pinned Swagger asset from unpkg; the YAML itself is served locally
and works offline. No retired source tree or archive is needed to launch.

## Reproduce the isolated integration run

These commands create/use project `shopkmp-migrated-e2e` and loopback port 58095:

```sh
make integration-up
make integration-check
```

Override `E2E_PORT` / `E2E_PROJECT` together when needed (project must end `-e2e`).
Use different EDGE_SUBNET/EDGE_IP/YII_HTTP_IP/SDUI_IP and YII_FPM_SUBNET/YII_FPM_HTTP_IP
if running multiple migrated projects concurrently; update TRUSTED_BFF_IPS to
match the allocated BFF addresses. The verification runner requires explicit `--disposable`
and creates real random accounts/addresses/orders. It preserves normal app
security ceilings, runs PHP HTTP tests against `http://edge:8080` with a test-only
provider fixture, and records logs plus noncredential captures in
`deploy/evidence/`. The fixture is stopped after the suite. No reference origin,
reference mount, Go business writer or differential harness is required.

Checks: frozen 54-operation route manifest, scheduler regressions, seed
preservation, explicit initial reindex, CARD capture, all eight BFF schemas/six
commands and live BFF races/vet, ops tests/vet, and `composer test:all`. The PHP
suites retain native canonical checkout, replay/same-key and different-key HTTP
races, all 39 protected (including 7 admin) access policies, spoofing/body/header/
timeout boundaries, all 54 business operations, SQL, Keycloak, search, security
and bounded-provider protocol checks. This does not test every possible input/
interleaving or launch a mobile app. Historical Go/Yii comparisons and their
recorded counts remain in the [parity report](API_PARITY_REPORT.md); their removed
commands are not part of current verification.

The final runtime check correlates the edge's actual socket client with PHP's
PostgreSQL rate principal, probes internal live/ready/FPM, verifies private
ports/history, stops the disposable application services and requires exit0,
then restarts them. Do not run the verification overlay against a real database.

For checks without a running stack, use `make check`: it builds a standalone
`shopkmp-php-tests` image (`PHP_TEST_IMAGE` override), runs Composer unit tests and
lint with no network or `.env`, then checks BFF/ops tests, races, vet and Compose
configuration. Go 1.26 is needed on the host; PHP/Composer are container-only.
`make coverage` reports BFF coverage without a percentage gate. The old Go
business/financial PostgreSQL coverage gates were removed with that implementation;
they are not replaced by a claimed equivalent metric. The KMP 70% gate is unchanged.

CI runs BFF/ops formatting, module consistency, tests/races, vet and builds;
standalone PHP unit/lint; canonical OpenAPI lint and route-manifest checks; image
builds; and the root disposable `integration-up` / `integration-check` sequence.
Existing Android and iOS checks remain. To run just the CARD HTTP journey against
an already-running disposable edge:

```sh
SHOPKMP_E2E_URL=http://127.0.0.1:58095 make e2e
```

`make e2e` defaults to `http://127.0.0.1:8080`; it creates test business data.

## Production inputs and validation

Copy `.env.prod.example` to `.env.prod`. Its required values are deliberately
empty: there are no usable production defaults/placeholders. Set unique random
32+ character URL-safe secrets (`openssl rand -hex 32` is suitable), actual domain,
bounded provider webhook source CIDRs, real HTTPS provider URL/API key and absolute
TLS paths. URL-safe passwords are required because the existing PHP contract
consumes DB/search credential URLs. Protect `.env.prod`; never publish resolved
Compose output containing secrets. The root ignore rules already exclude it.

`TLS_CERT_DIR` must contain:

| File pair | Required DNS identity |
| --- | --- |
| `api.crt`, `api.key` | exact PUBLIC_API_HOST; full served certificate chain |
| `postgres.crt`, `postgres.key` | postgres |
| `keycloak-postgres.crt`, `keycloak-postgres.key` | keycloak-postgres |
| `keycloak.crt`, `keycloak.key` | keycloak |
| `opensearch.crt`, `opensearch.key` | opensearch; subject CN=opensearch; server/client TLS EKUs; PKCS#8 key |
| `ca.crt` | trusted CA chain(s) for those identities |

`CA_BUNDLE_FILE` is a PEM trust bundle including the internal authority **and the
payment provider's trusted CA roots** (plus public roots if needed). It is mounted
as `/run/tls/ca-bundle.crt`, referenced by both PHP `curl.cainfo` and
`openssl.cafile`. PHP adapter verification is never disabled. DB clients use
`PGSSLROOTCERT=/run/tls/ca.crt` with verify-full; JDBC also verifies hostname/CA.
The DB wrapper installs private keys with postgres ownership/mode0600 and rejects
all network plaintext connections. Provision Keycloak/search key files readable
by their service UIDs (Keycloak1000, OpenSearch1000), through restricted host
directories/ACLs; do not give other host users access. Keep CA signing keys offline,
outside TLS_CERT_DIR; only service leaf keys and trusted public roots belong there.

```sh
make prod-config
# After the staged single-writer procedure below:
make prod-up
```

The wrapper validates secrets, distinct network addresses, HTTPS provider/CORS,
source CIDRs, required files, key/certificate matches, CA chains, SAN identities
and >24h validity before production config/up, including OpenSearch node DN,
server/client certificate purposes and unencrypted PKCS#8 key format. Docker interpolation independently
requires secrets. Use the wrapper/Make targets for these checks; raw `docker
compose config` alone does not inspect certificate files. No real provider network
call is made by `prod-config`; reachability/protocol/settlement must be verified
against the operator's provider before release.

Production has exactly the same services as development, no reference server.
Only edge HTTPS is mapped (`EDGE_TLS_PORT=443` by default). Unknown SNI is rejected,
HTTP is not published, and `/health*`, `/metrics*`, `/swagger*` and all other
nonapplication paths are hidden. Bind PUBLIC_API_HOST to the edge using the
deployment's real DNS; the private Keycloak name does not become a public IDP URL.

### Private issuer, proxy chain and search security

CMP uses PHP password flows, not browser redirects to Keycloak. Therefore the
issuer is consistently `https://keycloak:8443/realms/shopkmp`; Keycloak hostname,
JWT `iss`, discovery and JWKS all agree. PHP's verified same-origin JWKS constraint
is preserved. The unchanged realm/client secret placeholder is expanded by
Keycloak import; import ignores an existing realm, so changing the environment
secret does **not** rotate an existing realm client. Rotate it through private
Keycloak administration and update PHP together. Admin management remains private.
Keycloak26.3.3 inherits HTTPS for port9000; its Docker probe uses HTTPS and CA trust.

The edge reconstructs headers; only its fixed address can set client IP at Yii
HTTP. Go accepts original-client context only from the exact edge, and PHP
accepts rate context only from exact `TRUSTED_BFF_IPS`. BFF egress is ingress-only;
FPM admits only the internal Nginx's exact dedicated-network IP and local probe.
Production retains the FPM mount/environment and these network maps. Network
limits follow the original IPv4 /24 or IPv6 /64 through direct API and BFF calls;
verified-user limits remain separate. Webhook source trust remains independent.
See [trust wiring](backend-fixes/TRUST_AND_RATE_LIMITS.md); do not replace exact
peer allowlists with shared Docker CIDRs or arbitrary forwarded chains.

OpenSearch enables security/TLS, disables demo installation and uses the supplied
node certificate. `search-start.sh` builds an admin hash for **fresh** security
index initialization and removes all demo users. An existing security index is
not overwritten by startup; rotate its password using its authenticated security
API/private administrator procedure, update `.env.prod`, then verify readiness.
Search data is reconstructible from SQL. Initial reindex must succeed before FPM
starts. Full reconciliation is scheduled every five minutes, separately from the
one-minute reservation/session maintenance worker and 30-second
`payment-recovery` worker. The latter runs `maintenance/recover`, dispatches
unsent payment intents and retries only safe stored-user session revocation.
Uncertain payment sends and password resets remain manual work; see
[recovery/rollout](backend-fixes/MIGRATION_ROLLBACK.md). Large-volume throughput and
search disaster recovery are not established by small-fixture tests.

### Scheduled worker readiness and bounded execution

Docker's worker probe is `php /opt/scheduler/scheduler-health.php ready`. A fresh
scheduler is **not ready until a child completes successfully**. The state file
`/app/runtime/scheduler-state.json` is atomically replaced and records the parent
heartbeat, running PID/start, last completion/result/exit code and last success.
Starting a retry does not erase a failure or create a new success timestamp.

* `live` checks a running parent and heartbeat younger than5s, independent of job
  success. A responsive parent whose jobs all fail is live and **not ready**.
* `ready` additionally requires the latest result to be successful, a non-stale
  last success, and any running job to remain within its finite runtime budget.
  First attempts, failed retries, expired success, stopping and timed-out jobs
  fail readiness. A completed successful retry restores readiness.
* A timed-out child receives TERM, then KILL after5s if necessary. Timeout result
  is124 even if a TERM handler returns0; `childExitCode` retains the actual child
  exit. Normal cadence/retries continue without overlapping children. Parent
  TERM/INT forwards TERM to the active child, bounds shutdown by the same5s grace,
  and exits0 after recording `stopped`; interrupted shutdown never records success.

Operator inputs in both env templates:

| Setting | Default | Meaning |
| --- | ---: | --- |
| MAINTENANCE_JOB_TIMEOUT_SECONDS |120 | Maximum maintenance attempt runtime |
| REINDEX_JOB_TIMEOUT_SECONDS |1800 | Maximum reconciliation attempt runtime |
| RECOVERY_JOB_TIMEOUT_SECONDS |120 | Maximum payment/session recovery scan runtime |
| MAINTENANCE_SUCCESS_MAX_AGE_SECONDS |blank →185 |60s cadence +120s runtime +5s |
| REINDEX_SUCCESS_MAX_AGE_SECONDS |blank →2105 |300s cadence +1800s runtime +5s |
| RECOVERY_SUCCESS_MAX_AGE_SECONDS |blank →155 |30s cadence +120s runtime +5s |

Explicit timeout/success-age values must be1..86400 seconds. A deliberately
shorter success-age budget can make a live waiting/in-progress worker unready;
normally budget for cadence plus the measured legitimate job duration. Monotonic
time governs freshness/deadlines; wall timestamps are retained for inspection.
Initial Docker `start_period` equals the configured attempt budget, allowing a
legitimate long **first** attempt to remain `starting`, never falsely healthy.
The readiness command already returns nonzero for first/failed attempts during
that grace. After Docker observes the first success, a failing readiness probe
makes the worker unhealthy (one failure;10s probe interval). A first attempt that
never succeeds cannot pass a deployment wait and has bounded runtime/grace.

Inspect privately, using the intended overlay:

```sh
python3 scripts/compose.py --env .env.prod --prod exec -T maintenance \
  php /opt/scheduler/scheduler-health.php status
python3 scripts/compose.py --env .env.prod --prod exec -T reconcile \
  php /opt/scheduler/scheduler-health.php live
```

`status` prints `live`, `ready` and the last result/success snapshot. It is an
inspection command; use `ready`/`live` exit codes as probes. Make lifecycle waits
default to2100s (`WAIT_TIMEOUT` override); staged waits below isolate long initial
reindex from the first scheduled run. Size budgets/waits from measured catalog
duration; a bounded failure is preferable to falsely healthy reconciliation.

Repeatable isolated regression commands:

```sh
make test-scheduler
make test-infra PRODUCTION_TEST_ENV="$TEST_ENV"
```

The scheduler tests execute the actual deployed script and exact Compose probe
for all three commands. Real unreachable business dependencies and controlled CLI child
processes cover first completion, repeated failure/recovery, bounded legitimate/
TERM-ignoring jobs, stale success, frozen parents and graceful child signalling.
They have `--network none`, no production credentials/data access and clean up
only their uniquely named test containers. `make integration-check` also runs
this regression and checks real worker success before/after application restart.

## Test production TLS configuration without production writes

`scripts/test_pki.py` creates a short-lived **test-only** CA, leaf keys and random
secrets in a private temporary directory. It prints a test environment path:

```sh
python3 scripts/test_pki.py
# Use the printed absolute path as TEST_ENV below.
python3 scripts/test_infrastructure.py --production-env "$TEST_ENV"
python3 scripts/compose.py --env "$TEST_ENV" --prod config --quiet
python3 scripts/compose.py --env "$TEST_ENV" --prod up -d --build --wait --wait-timeout 300
python3 scripts/production_smoke.py --env "$TEST_ENV" --disposable
```

This uses project `shopkmp-migrated-tls-e2e`, independent volumes/network and
loopback58096 with DNS/SNI `api.shopkmp.test`; the smoke driver resolves it locally
while verifying its real certificate. It verifies private-issuer registration/
login/JWKS/profile, blocked operational paths, spoofed webhook source rejection,
actual PDO TLS and both DB plaintext rejections. It does not call a real provider
or seed production-mode catalog data. `deploy/tls-probe.php` separately exercises
the **actual production HttpClient** with trusted, wrong-host and untrusted-CA
HTTPS fixtures; [verification report](VERIFICATION_REPORT.md) gives its exact run.

To remove only this disposable TLS stack:
`python3 scripts/compose.py --env "$TEST_ENV" --prod down --volumes`.
The test certificates expire after three days. Never reuse this PKI for deployment.

## Migration, backup, rollout and rollback

1. Retain an **immutable prior release outside this checkout**, its deployment
   configuration and verified business/identity database backups for actual
   rollback. The user-authorized source/archive removal is complete after
   extracting contracts, all 22 SQL files, checksum migrator/probe and realm;
   see [preserved artifacts](LEGACY_REMOVAL.md). The current checkout cannot
   rebuild or start the retired business implementation.
2. Rehearse on a restored disposable backup. Record existing `schema_migrations`
   versions/checksums and metadata, row counts and representative read contracts.
   For a real deployment, take/verify a business DB backup plus Keycloak DB/realm
   backup and retain deployment secrets/certificates. Example business dump via
   the private container: `... exec -T postgres pg_dump -U shopkmp -Fc shopkmp`;
   stream to the operator's protected backup destination. Test restoring elsewhere.
3. Stop/drain the currently deployed business writer **before** schema jobs/new
   business writes. Run the extracted checksum-validating forward migrator
   (`migrate`, built from `tools/ops`), then
   `yii-migrate`. Yii records only `yii2_migration_history`; no down/destructive
   migration or rewrite of existing `schema_migrations` versions/names/checksums/
   applied-at values is performed. Never truncate/reset history or rebaseline an
   existing database. Historical `legacy_import_status=required` remains a
   readiness blocker: it applies to old snapshot-only data. There is no bundled
   Go snapshot importer. Use a separately retained immutable release and its
   controlled offline import procedure on a restored copy first; confirm the
   resulting normalized schema, import status and data before cutover.
   The current SQL release includes 012–014: payment outbox/correlation, session
   families/revocation, and bounded recovery scheduling. Migration 013 deletes
   unlinked legacy opaque tokens and requires reauthentication. Old binaries
   cannot safely write alongside this schema. Readiness checks the combined
   shape. See [migration and rollback details](backend-fixes/MIGRATION_ROLLBACK.md).
4. Start infrastructure/jobs, require initial reindex and internal readiness, run
   read-only contract/data checks and provider/TLS checks. Shift the one edge to
   Yii/BFF as a **single-writer** staged switch; do not distribute writes across
   releases on the same production DB. Keep the prior release available offline.
5. Monitor errors/locks, failed auth/network budgets, reservation expiry, search
   reconciliation and provider outcomes using private operational access/logs.
   Access logs omit query strings, bearer tokens and demo capability tokens.
6. To roll back, drain/stop Yii business writes and workers first, reconcile any
   in-flight provider outcomes, confirm schema/data compatibility, then restore
   the externally retained prior routing/release as sole writer. Retain additive
   tables and history;
   do not drop customer/commerce data or silently restore over newer writes.
   Backup restore/PITR is an explicit incident procedure, not `migrate down`.

### Exact production-aware stages

First map/restore the **existing** business and identity volumes in the approved
release configuration and rehearse on a restored disposable copy. Project-scoped
default volumes are for fresh installs; these commands do not discover or import
an existing installation and do not authorize switching to a new empty identity
database. Preserve verified backups and the external immutable prior release
before real writes.

For the root production model, execute stages in order (not parallel Make goals):

```sh
make prod-config PROD_ENV_FILE=.env.prod
make prod-build PROD_ENV_FILE=.env.prod
make prod-drain PROD_ENV_FILE=.env.prod
# Stop/drain the old writer in its actual deployment as described below.
make prod-infra PROD_ENV_FILE=.env.prod
make prod-migrate PROD_ENV_FILE=.env.prod
make prod-reindex PROD_ENV_FILE=.env.prod
make prod-app PROD_ENV_FILE=.env.prod
make prod-workers PROD_ENV_FILE=.env.prod WAIT_TIMEOUT=2100
# Require internal readiness, successful workers, read-only contract/provider checks.
make prod-edge PROD_ENV_FILE=.env.prod
```

Every `prod-*` target selects `--prod`. `prod-drain` first gracefully stops edge,
then BFF/internal HTTP/workers/FPM. `prod-migrate` explicitly runs the extracted
checksum job then the separate Yii history job; `prod-reindex` explicitly runs
initial reconciliation. These jobs use `run --rm --no-deps` and the production
TLS/CA environment. `prod-app`, `prod-workers` and `prod-edge` use `--no-deps` so
the operator's staged sequence cannot implicitly relaunch migration jobs or a
writer that was deliberately drained. `prod-infra` selects only the two DBs,
private Keycloak and search. Advance only after each prior command succeeds.

Equivalent explicit job/drain commands are:

```sh
python3 scripts/compose.py --env .env.prod --prod stop edge
python3 scripts/compose.py --env .env.prod --prod stop sdui yii2-http maintenance reconcile payment-recovery yii2
python3 scripts/compose.py --env .env.prod --prod run --rm --no-deps migrate
python3 scripts/compose.py --env .env.prod --prod run --rm --no-deps yii-migrate
python3 scripts/compose.py --env .env.prod --prod run --rm --no-deps reindex
```

The root model contains no retired business server, so `prod-drain` cannot stop
a writer owned by a different deployment. Drain/stop that writer through its
actual orchestrator and the separately retained immutable release's runbook
before new migration/business work. Confirm in-flight requests/workers are gone;
do not run both releases as writers. The external release's deployment paths,
environment and volume mappings must come from that release, not this checkout.
No root target starts the retired writer or discovers existing volume mappings.

Rollback stop is production-aware and preserves infrastructure/data:

```sh
make prod-rollback-stop PROD_ENV_FILE=.env.prod
python3 scripts/compose.py --env .env.prod --prod ps --all
```

Then reconcile provider outcomes, verify schema/data compatibility and restore
the external immutable prior routing/release as sole writer through its original
deployment, using verified backups/PITR if required by the incident procedure.
Do not start the old writer until the new writer/workers are stopped. This target
does not reverse SQL or restore over later writes. `prod-down` retains volumes;
`prod-logs` selects production for inspection. Backup/restore/real rollback remains
a release gate, not a claim established by command-boundary tests.

Graceful shutdown: edge/internal Nginx and FPM receive SIGQUIT; BFF drains within
10s; schedulers forward SIGTERM to the active child. Compose gives40–60s to
Nginx/FPM/DB and20s to BFF. Request bounds: edge6MiB, BFF command32KiB, app JSON/
webhook1MiB and avatar5MiB; headers5s, body idle10s, upstream3s connect/15s send/
35s read, FPM total30s. BFF upstream/request/write budgets3/8/12s remain unchanged.
These bounds are intentionally layered; Nginx idle-body timeouts are not a claim
of a total upload deadline.

## Android and iOS endpoints / builds

Use the **same edge origin** for canonical business and SDUI; there is no second
mobile BFF URL. Defaults remain Android emulator `http://10.0.2.2:8080` and iOS
simulator `http://127.0.0.1:8080`. Custom Task4 port:

```sh
SHOPKMP_DEBUG_SERVER_URL=http://10.0.2.2:58095 ./gradlew :composeApp:assembleDebug
adb install -r composeApp/build/outputs/apk/debug/composeApp-debug.apk

xcodebuild -project iosApp/iosApp.xcodeproj -scheme iosApp -configuration Debug \
  -sdk iphonesimulator -destination 'generic/platform=iOS Simulator' \
  BACKEND_SERVER_URL=http://127.0.0.1:58095 build
```

Android USB with `adb reverse tcp:58095 tcp:58095` can use debug URL
`http://127.0.0.1:58095`. A physical iOS device needs a reachable development
host/LAN address and appropriate local-network policy; loopback refers to the
device. Production builds use trusted HTTPS: Android
`SHOPKMP_RELEASE_SERVER_URL=https://<actual-domain>` and iOS
`BACKEND_SERVER_URL=https://<actual-domain>`. Provision any additional allowed
payment origins locally (`SHOPKMP_PAYMENT_ORIGINS` / iOS `PaymentOrigins`), never
from SDUI. Test CA certificates must be explicitly trusted by a test device;
do not disable client/provider TLS checks to run verification.

Task3's APK/framework and91 real simulator cases are recorded in its report.
The subsequent installed Android/iOS journeys now pass real signup/login/PIN,
all eight SDUI destinations, native checkout, Chrome/Safari demo CARD payment,
paid orders/details/devices and logout. Exact commands and sanitized outputs:
[MOBILE_E2E_REPORT.md](MOBILE_E2E_REPORT.md). Repeat against the disposable58095
stack with `scripts/run_android_installed_journey.py --serial <device>` and
`scripts/run_ios_installed_journey.py --udid <simulator>`.
These do not establish physical biometric prompts, real payment-app/provider
settlement or Android process-death restoration.
