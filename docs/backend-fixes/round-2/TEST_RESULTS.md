# Round 2 — executed verification

Date: **2026-10-29 UTC**. Final functional run: **12:38:33–12:46:34**, exit **0**.
Verified artifact security run: **12:46:50–12:47:49**, scanner exit **1**, policy
**FAILED**. The exact local Make target was subsequently run end-to-end at
**13:06:43–13:13:37**: Make exit **2**, scanner exit **1**, same blocking finding
counts; E/verified-local-security-command.* and E/security-make-verified/.

Evidence root **E**:
`/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/round2`.
Only the named receipts below belong to this session. Pre-existing directories
(especially `E/security-arm64`) are not this task's evidence. Each `backend_check`
receipt contains exact argv, cwd, UTC start/end, raw exit and adjacent `.log`.
Scanner subcommands have separate `.command.json`, `.stdout`, `.stderr` receipts.

Toolchains: PHP **8.3.36**, Composer **2.10.3**, PostgreSQL **17.11**, Keycloak
**26.8.1**, OpenSearch **2.19.7**, Nginx **1.30.6**; Docker **29.6.1**, Compose
**5.3.0**, daemon **linux/arm64**, Python **3.14.3**. Native Go auto-selected
**1.27.3** for BFF and **1.26.0** for ops; delivered ops binaries/source scans use
the repository Dockerfile's **1.26.10**. No Go downgrade or validator bridge.

## Initial state and TDD evidence

| Check / command | Exit | Result | Evidence |
|---|---:|---|---|
| `make test-php PHP_TEST_IMAGE=shopkmp-round2-agent-php` | 0 | PASSED, original 7 tests / 79 PHP files | E/baseline-php.{json,log} |
| `go -C backend-sdui test -race -count=1 ./...`; corresponding ops command | 0 each | PASSED | E/baseline-go.*, E/baseline-ops.* |
| `python3 scripts/route_matrix.py --check` | 0 | PASSED, actual 54/8/6 | E/baseline-contracts.* |
| Original `make integration-check E2E_PROJECT=shopkmp-round2-agent-e2e E2E_PORT=58207` | 0 | PASSED | E/baseline-integration.*; baseline logs deploy/evidence/*.log |
| `php tests/uuid_identity.php` through verification Compose, original classes | 1 | RED, 16 tests / 8 failures | E/r1-identity-red.* |
| `php tests/uuid_recovery.php`, original scopes / version predicate | 1 | RED, 3/3 | E/r1-recovery-red-interleaving.* |
| `php tests/network_cidr.php` | 1 | RED, 46/31 | E/r2-cidr-red-serial.* |
| `php tests/security.php`, malformed CIDR / trusted rate-source cases | 1 each | RED | E/r2-security-red.*, E/r2-source-red.* |
| Original immutable PHP image, current native tests, two scans × two CLI workers, A order locked | 1 | RED: both scans A=SENT/B=SENT, all workers 55P03 | E/original-r3-repeated-native.* |
| `python3 scripts/test_backend_security.py` before policy / before receipt and timeout work / wrong-platform report | 1 | RED | E/r4-policy-red.*, E/review-r4-bindings-timeout-red.*, E/review-r4-platform-red.* |
| Review: actual logout during provider HTTP, both owner directions | 1 | RED, 5/2; version-only predicate stranded fence | E/review-r1-logout-red.* |
| Review: real PDO statement barrier takes A order between preparation and READY claim | 1 | RED, 55P03 in actual dispatch claim | E/review-r3-ready-red.* |

Original-image replay mounts only test source; it uses the original application
classes, real Composer vendor, real PDO PostgreSQL and HTTP provider fixtures.
It is not the user-described modeled-PDO diagnostic.

## Final mandatory functional checks

Final disposable project **shopkmp-round2-agent-final-e2e**, loopback **58209**,
ingress **10.239.209.0/24**, FPM **10.239.208.0/24**. Prior owned project was stopped
with volumes preserved; unrelated running projects were not operated.

| Command / suite | Toolchain | Exit | Result / evidence |
|---|---|---:|---|
| `make integration-up E2E_PROJECT=shopkmp-round2-agent-final-e2e E2E_PORT=58209 WAIT_TIMEOUT=240` | pinned backend images | 0 | PASSED, fresh business/Keycloak DBs, schema 001–014, actual build receipt; E/verified-fresh-stack.* |
| `make integration-check E2E_PROJECT=shopkmp-round2-agent-final-e2e E2E_PORT=58209 VERIFY_EVIDENCE_DIR=E/agent-verified-root` | PHP/PG/Go/vendor | 0 | PASSED; E/verified-root.* |
| `composer test:all` in verification Yii container | PHP/PG/Keycloak/search | 0 | PASSED, **202/0**, lint **82** files; E/agent-verified-root/php-all.log |
| Focused `php tests/payment-migration.php`, `payments.php`, `payment-http.php` | PHP/PG/HTTP fixtures | 0 each | PASSED **13/0, 113/0, 4/0**; E/agent-verified-root/backend-fixes.log |
| Focused `php tests/uuid_identity.php`, `uuid_recovery.php` | native PG, Ed25519/P-256, real HTTP | 0 each | PASSED **16/0, 5/0**; same focused log |
| Focused `php tests/recovery.php`, `recovery-scheduling.php` | two PG connections / CLI workers | 0 each | PASSED **9/0, 14/0**; same focused log |
| `python3 scripts/test_scheduler.py --image shopkmp-round2-agent-final-e2e-yii2` | actual production scheduler scripts | 0 | PASSED **18/0**, timing scale 1; E/agent-verified-root/scheduler-regression.log |
| `go test -race ./... -count=1 -v` with live Yii/BFF origins | Go 1.27.3, real Go JSON Schema validator | 0 | PASSED; E/agent-verified-root/bff-race.log |
| `go vet ./...` BFF; `go test ./... -count=1`, `go vet ./...` ops | native module toolchains | 0 each | PASSED; bff-vet.log, ops-tests.log, ops-vet.log in same directory |
| `test -z "$(gofmt -l backend-sdui tools/ops)"`; both modules `go mod tidy -diff`, `go vet ./...`, `go test -race -count=1 ./...`, `go build ./...` | Go 1.27.3 / 1.26.0 | 0 sequence | PASSED; E/final-go-checks.{json,log}; no later Go edits |
| `make test-trust-live ENV_FILE=.env.example E2E_PROJECT=shopkmp-round2-agent-final-e2e` | real Nginx/FPM, two BFF replicas, isolated trust_ DB | 0 | PASSED source/signature 204/401/403, 409 dedup, spoofing, IPv4/IPv6 quotas, FastCGI denial; E/verified-trust.* |
| `python3 scripts/runtime_checks.py ... --shutdown` within root verification | actual FPM/Nginx/dependencies/workers | 0 | PASSED probe, trust, readiness and graceful stop/restart; E/agent-verified-root/runtime.log |
| `python3 scripts/backend_integrity.py --base ebea663fd22daf6b1e44e05aa6d2ac6bf933f077 --output E/verified-integrity-details.json` | Git + SHA-256 | 0 | PASSED **33 original migration files**, frozen client/contracts; E/verified-integrity.* |
| `python3 scripts/test_infrastructure.py --production-env <private test.env>` | Docker Compose + disposable PKI | 0 | PASSED dev/prod boundaries and seven negatives; E/prod-compose-boundaries.* |
| `python3 scripts/test_deployment_commands.py --production-env <private test.env>` | actual Make/wrapper, mocked side-effect boundary only | 0 | PASSED 5/0; E/prod-deploy-boundaries.* |
| Vacuum `lint --fail-severity error /contracts/openapi.yaml` | installed Vacuum **0.30.0** | 0 | PASSED; E/openapi-vacuum.* |
| `python3 scripts/test_backend_security.py`; `test_backend_integration.py`; route matrix; `git diff --check` | Python/Git | 0 sequence | PASSED **10 policy/process/provenance tests**, **6 runner/model tests** (3 focused runner + 3 Compose model), matrix 54/8/6; E/review-policy-final.*; repeated before commit in E/commit-preflight.* |
| `probe_java_runtime.py --mode opensearch-truststore --image sha256:02778e…`; `--mode opensearch-inventory` | actual final bundled JVM / vendor CLI | 0 each | PASSED default JSSE CA mitigation, required security/transport/analysis retained; E/final-opensearch-ca.*, E/final-opensearch-inventory.* |
| Gitleaks stdin over owned tracked diff + all owned untracked additions | verified module **v8.30.1**, full redaction | 0 | PASSED, zero detections; E/owned-secrets-scan.*, owned-diff-secrets.json |

Migration tests exercise clean predecessor and populated upgrade, retained payment
uncertainty, constraints and rollback guards. No new migration is required.
Live auth tests retain the separate offline JWT policy: logout removes online/
offline provider sessions, but an already signed access JWT lasts until expiry.

## Security / external boundaries

| Check | Exit | Status | Evidence / limitation |
|---|---:|---|---|
| `backend_security.py --env .env.example --project shopkmp-round2-agent-final-e2e --platform linux/arm64 --output E/security-agent-verified --cache <fresh-checked cache>` | **1** | **FAILED policy; publication BLOCKED** | E/verified-security.* and all raw/SBOM/command receipts under E/security-agent-verified |
| Composer production / Go source / four delivered Go binaries | 0 each | PASSED | Same security directory; exact tested artifact/source receipt matches all 14 services |
| `make security-backend ENV_FILE=.env.example E2E_PROJECT=shopkmp-round2-agent-final-e2e SECURITY_PLATFORM=linux/arm64 SECURITY_OUTPUT=E/security-make-verified` | **2** (scanner 1) | **FAILED policy, as required** | E/verified-local-security-command.*; fresh default-cache DB downloads, 10/0 policy fixtures, complete raw/SBOM receipts; no scanner execution errors |
| amd64 runtime scan + native amd64 live integration | — | **NOT RUN locally** | CI matrix added; no remote execution claimed |
| Remote GitHub CI and required-check settings | — | **NOT RUN / NOT APPLIED** | Source workflow only; branch protection unchanged |
| New production-mode TLS deployment / production data | — | **NOT RUN** | Production not touched. Dev live auth/search and exact final OpenSearch default CA probe passed; production Compose tested statically |
| Local commit | — | **Explicitly user-authorized checkpoint** | Follow-up “Make commit” request; containing commit identified by `git log -1`; no additional runtime verification implied |
| Push / publication | — | **BLOCKED / NOT PUSHED** | Required security/publication gate remains failed; no owner-approved vulnerability exception |

Earlier setup failures remain recorded: subnet collision/mismatched dynamic range,
parallel CLI fixtures competing for one fixed test IP, a security suite incorrectly
invoked without DATABASE_URL, a nonexistent test-filter spelling, initial missing
receipt/parser stages, and asynchronous FPM denial logging. They are not passing
suite evidence. All are superseded by named successful final runs where applicable.
