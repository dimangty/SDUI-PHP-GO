# ShopKMP — Yii2 business API, Go SDUI, Compose Multiplatform

A persistent Android/iOS shop with Keycloak authentication, catalog/search,
cart, checkout, payments, orders, profile and device credentials. The root
runtime uses **PHP 8.3 / Yii2 for business logic** and an **independent Go 1.27.3
HTTP-only SDUI BFF**. The shared commonMain client renders eight destinations
with the existing UIKit and keeps authentication/security/checkout native.

```text
Android / iOS → one Nginx edge
                  ├─ /api/v1/screens/*, /api/v1/sdui/* → Go BFF
                  │                                      ↓ internal HTTP
                  └─ business API, media, callbacks → yii2-http:8080 → Yii FPM:9000
                                                                       ├─ PostgreSQL
                                                                       ├─ Keycloak → separate PostgreSQL
                                                                       ├─ OpenSearch
                                                                       └─ configured payment provider
```

Only the edge publishes a host port. The BFF has no database or identity-provider
credentials. The canonical business contracts live in `backend-yii2/contracts/`;
the independent `tools/ops` module provides the checksum-validating one-shot
migrator and Keycloak HTTP probe. The retired Go business source and original
archive have been removed after extracting these essentials; see
[decommissioning and preserved artifacts](docs/LEGACY_REMOVAL.md).

## UI design

![ShopKMP frontend design sketch for Android and iOS](docs/images/frontend-sketch.png)

## Run locally

Run the following commands from the repository root, where `Makefile` and
`docker-compose.yml` are located.

### 1. Start Docker and check the tools

Requires Docker Engine with BuildKit, Docker Compose **2.24.4+**, Python **3.9+**
and `make`. Docker builds PHP and Go inside containers; host PHP/Composer/Go
installations are not required to start the stack. Host-side Go verification
uses the module toolchains: BFF **1.27.3**, ops **1.26**.

On **macOS with Docker Desktop**, launch the application and expose its bundled
CLI tools and credential helper in the current terminal:

```sh
open -a Docker
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
command -v docker-credential-desktop
docker version
docker compose version
```

Wait until `docker version` shows a working **Server** section. The helper check
should print `/Applications/Docker.app/Contents/Resources/bin/docker-credential-desktop`.
To retain this PATH in new zsh terminals, add the same `export PATH=...` line to
`~/.zshrc`, then open a new terminal or run `source ~/.zshrc`.

Give Docker Desktop enough memory for Keycloak, OpenSearch and the image builds;
**8 GiB** is a practical starting allocation for local development. Additional
running stacks need additional resources.

On **Linux**, start Docker Engine and ensure `docker version` and
`docker compose version` succeed. OpenSearch needs `vm.max_map_count` of at least
**262144**; check it with `sysctl vm.max_map_count` and, if lower, set it with
`sudo sysctl -w vm.max_map_count=262144`.

### 2. Prepare the local configuration and start the stack

```sh
if [ ! -f .env ]; then
  cp .env.example .env
fi
python3 scripts/compose.py --env .env config --quiet
make run
```

This keeps an existing `.env` and its database/client credentials intact.
The example configuration is for **local development**. For production TLS and
secrets, follow [the deployment guide](docs/DEPLOYMENT.md).

`make run` builds/starts the stack, waits for real probes, then explicitly runs
the development seed and search reconciliation. The seed creates two sample
products only when **both catalog tables are empty**; it never overwrites a
populated catalog and is forbidden in production. `make up` starts without seeding.

The first launch downloads images/dependencies and initializes the databases,
Keycloak and search. Readiness can take several minutes; the default wait is
**2100 seconds (35 minutes)**, in addition to image build time.

### 3. Check the running services

* API and SDUI: **http://127.0.0.1:8080**
* Development Swagger: **http://127.0.0.1:8080/swagger/**; serves the unchanged
  [canonical OpenAPI](backend-yii2/contracts/openapi.yaml).
* No direct database, Keycloak, search, FPM or BFF host ports.

```sh
python3 scripts/compose.py --env .env ps --all
curl --fail http://127.0.0.1:8080/api/v1/screens/home
make logs
make down                    # retains persisted volumes
```

Long-running services should become `healthy`. Initialization jobs `migrate`,
`yii-migrate` and `reindex` finish with `Exited (0)`. `make logs` follows logs;
press Ctrl+C to leave the log view while containers keep running. `make down`
stops/removes the project's containers and networks while retaining its data
volumes. Start it again with `make run`.

Set `EDGE_PORT` in `.env` for another port. The isolated integration stack runs at
**http://127.0.0.1:58095** under project **`shopkmp-migrated-e2e`**.

### Troubleshooting: `docker-credential-desktop` is missing from PATH

If the build stops with:

```text
error getting credentials - err: exec: "docker-credential-desktop": executable file not found in $PATH
```

Docker is trying to run the credential helper selected in its client
configuration. A CLI installed separately, for example through Homebrew, can be
available while Docker Desktop's helper directory is absent from PATH. This can
surface at `# syntax=docker/dockerfile:1`, when BuildKit fetches its Dockerfile
frontend; other parallel image steps may then show `CANCELED`.

For the standard macOS Docker Desktop installation, retry from the same terminal:

```sh
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
command -v docker-credential-desktop
make run
```

Or apply the PATH just to this invocation:

```sh
PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" make run
```

If the helper is also absent from `/Applications/Docker.app/Contents/Resources/bin`,
check the Docker Desktop installation/location and repair it before retrying.

### Troubleshooting: connection error in the iOS Simulator

Check the **published host port** of the running project's edge:

```sh
docker ps --format 'table {{.Names}}\t{{.Ports}}'
curl --fail http://127.0.0.1:8080/api/v1/categories
```

The standard `.env` project is `shopkmp-migrated`; `make run` starts its API on
**127.0.0.1:8080**, matching the default Xcode Debug `BACKEND_SERVER_URL`.
An isolated fixture such as `shopkmp-backend-fixes-e2e` on **58196** is a separate
project. A mapping `127.0.0.1:58196->8080/tcp` is reached from the simulator using
host port **58196**. Keep the app's `BACKEND_SERVER_URL` aligned with the edge's
published host port. Once the matching API responds, retry registration in the
app; the generic connection-error message can result from a stopped local API.

## Verification

```sh
make compose-config
make integration-up          # isolated project; loopback 58095
make integration-check       # writes only disposable test data
make check                   # Docker PHP unit/lint, BFF + ops tests/races/vet, Compose config
make test-php                # standalone Docker build/tests; no .env or host PHP needed
# Against an already-running disposable stack:
SHOPKMP_E2E_URL=http://127.0.0.1:58095 make e2e
```

`integration-check` checks the frozen 54-operation route manifest, scheduler
regressions, seed preservation and initial reindex, the CARD demo-payment journey,
all eight schema-validated BFF screens/six commands, live BFF races/vet, ops
tests/vet and the complete PHP business/API/concurrency/security suite through the
shared edge. A test-only provider fixture is used; normal security limits remain
enabled. Runtime probes check private services, history and graceful restart.

The prior Go/Yii comparison is retained as **historical evidence**: 54 operations,
194 paired HTTP cases, 189 matches and five documented compatibility corrections.
Its comparison harness and reference writers have been retired. Recorded PHP
counts and mobile/Kover results in the reports describe those earlier runs, not
new decommissioning passes. [Exact historical parity scope](docs/API_PARITY_REPORT.md) ·
[Historical verification](docs/VERIFICATION_REPORT.md) ·
[Handoff and current checks](docs/COMPLETION_REPORT.md).

Installed Android/iOS journeys exercise the same edge, including UI signup/PIN,
SDUI, native checkout, Chrome/Safari CARD demo capture, paid orders and logout.
[Mobile evidence and rerun commands](docs/MOBILE_E2E_REPORT.md).

CI checks both Go modules, standalone Docker PHP unit/lint, contracts, runtime
image builds and root full-stack integration. Android/iOS jobs remain in place;
the KMP **70%** coverage gate is unchanged. `make coverage` reports BFF coverage
only, without replacing the retired Go business/financial coverage metric.

Real production-provider settlement, physical biometric hardware and production
restore/cutover remain separate release gates. No production cutover has occurred.

## Deployment and mobile endpoints

[DEPLOYMENT.md](docs/DEPLOYMENT.md) covers local/production TLS, private services,
secrets, issuer/JWKS, database histories, scheduled jobs, backup/rollback and
single-writer staged rollout. Start from `.env.prod.example`, provision the
required real secrets/certificates, then use `make prod-config` / `make prod-up`.
Production publishes only HTTPS on one domain; Swagger/operational routes are hidden.

Android emulator custom-port build:

```sh
SHOPKMP_DEBUG_SERVER_URL=http://10.0.2.2:58095 ./gradlew :composeApp:assembleDebug
```

iOS simulator uses `BACKEND_SERVER_URL=http://127.0.0.1:58095` in the Xcode build
settings. Exact build/install commands and physical-device routing are in the
deployment guide. Normal client defaults remain port 8080. Platform builds and
91 real simulator cases passed in Task3. Later native-security/startup regressions
and installed CARD journeys have their own evidence in the mobile report.

```sh
# Running disposable stack at58095 and the indicated booted devices are required.
python3 scripts/run_android_installed_journey.py --serial emulator-5554
python3 scripts/run_ios_installed_journey.py --udid <booted-iPhone-simulator-UDID>
```

### HTTP and SDUI screen logs in Xcode

1. Open `iosApp/iosApp.xcodeproj` and select the **iosApp** scheme.
2. In **Product → Scheme → Edit Scheme → Run → Info**, select **Debug**.
3. Build and run with **Cmd+R** so the app includes the current Kotlin framework.
4. Show the Debug Area with **Shift+Cmd+Y**, select the console and **All Output**.
5. Filter the console by **`SDUI`**, then open Home, Catalog or another SDUI
   destination after signing in. Native authentication requests use **`HTTP`**.

Debug startup prints `[HTTP] Backend HTTP logging enabled (Debug)`. No additional
Info.plist key is required; an existing Boolean `NetworkDebugLogging=false`
explicitly disables diagnostics. Release binaries do not enable these logs.
HTTP/SDUI diagnostics use the iOS system logger (`NSLog`) to reach Xcode's
console. Long iOS messages are split into numbered UTF-8 chunks to avoid
system-log truncation. Android uses the same diagnostics through Logcat's
HTTP/SDUI tags.

Example screen-loading log shape:

```text
[SDUI] SDUI --> screen=home GET http://127.0.0.1:8080/api/v1/screens/home requestId=...
[SDUI] SDUI <-- 200 screen=home GET ... 120ms serverRequestId=...
[SDUI] SDUI RESPONSE JSON screen=home GET ... receivedBytes=...
{
    "schemaVersion": 1,
    "screenId": "home",
    ...
}
```

Each call includes the method, safe URL, status, duration and correlation IDs;
failures include the exception type. Passwords, PINs, tokens and credential
fields are masked; authorization/cookie headers are not dumped and query values
are hidden. JSON previews are capped at **8 KiB**. Larger accepted SDUI documents
are redacted first, then clipped with a `JSON truncated` marker; malformed,
non-JSON or out-of-policy bodies are omitted. The SDUI document limit remains
**1 MiB**. Match `BACKEND_SERVER_URL` in the Xcode build settings to the running
backend's `EDGE_PORT`.

## Contracts and design

| Artifact | Purpose |
| --- | --- |
| [Architecture](docs/ARCHITECTURE.md) | Current runtime and trust boundaries |
| [Canonical OpenAPI](backend-yii2/contracts/openapi.yaml) / [frozen routes](backend-yii2/contracts/routes.json) | 54 business operations; original names/access policies and decimal-string money |
| [Route matrix](docs/ROUTE_MATRIX.md) | Exact methods/policies, PHP coverage, 8 screens + 6 commands |
| [SDUI v1 contract](docs/SDUI_CONTRACT.md) | Finite wire types, schemas, 13 shared fixtures, native actions |
| [Decommissioning](docs/LEGACY_REMOVAL.md) / [ops tools](tools/ops/README.md) | Preserved SQL/checksums, realm, migrator/probe and external-release rollback |
| [Migration strategy](docs/MIGRATION_PLAN.md) | Historical compatibility invariants and cutover planning |
| [Implementation plan](docs/IMPLEMENTATION_PLAN.md) | Historical Task4 checkboxes and execution ledger |
| [Yii2 service](backend-yii2/README.md) / [BFF service](backend-sdui/README.md) | Build/configuration and focused test suites |
| [CMP report](docs/CMP_IMPLEMENTATION_REPORT.md) | Shared renderer, native integration and platform evidence |

Client stack: Kotlin 2.3.10, CMP 1.10.3, Ktor 3.5, Koin 4.2, Navigation3,
SQLDelight and existing UIKit. Infrastructure: PostgreSQL 17.11, Keycloak 26.8.1,
OpenSearch 2.19.7 and Nginx 1.30.6. Historical screenshots/draw.io and migration reports
describe earlier stacks; the root Compose files define the current runtime.
Actual rollback uses an independently retained immutable release and verified
business/identity database backups, as described in the deployment guide.
