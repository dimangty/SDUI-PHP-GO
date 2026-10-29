# Round 3 — executed verification

Execution dates: **2026-10-29 UTC** (local timezone crossed October 30). Every named
label below resolves in the portable evidence archive to `.json` argv/cwd/UTC
start/end/raw exit plus `.log`. Scanner subcommands additionally retain stdout,
stderr and process exits. Reports do not override those receipts.

Toolchains: native module Go **1.27.3 darwin/arm64**, ops **1.26** / delivered
**1.26.10**; Docker PHP **8.3.36**, Composer **2.10.3**, PostgreSQL **17.11**.
Focused early PHP tests also used **17.10**, explicitly identified in their report.
Host PHP/Composer are unavailable. Docker **29.6.1**, Compose **5.3.0**, Python
**3.14.3**. No Go downgrade or compatibility bridge.

| Verification / command | Exit / outcome | Evidence label/path |
|---|---|---|
| Original `make test-php`, both module race suites, route matrix | 0 / PASS | `baseline-php`, `baseline-go`, `baseline-ops`, `baseline-contracts` |
| Native original F1/F2 replay, correct-behavior tests | 1 / expected RED | `go-valid-native-red`; `python3 scripts/backend_round3_red.py` |
| Corrected valid-URL F1/F2 regressions | 0 / GREEN | `go-valid-native-green` |
| Original/current PHP reads and credentials | RED → GREEN | `php-*`; PHP_IMPLEMENTATION.md |
| Strict empty-projection/protocol-span/escaped-path fixes | RED → GREEN | `review-*`; REVIEW_FIXES.md |
| `sh scripts/check.sh` from backend-sdui | **0 / PASS** | `final-native-check`: format, tidy diff, mod verify, vet, unit, race, build, contracts, **golangci-lint**, govulncheck |
| Command fuzz target, four workers, 10s | 0 / PASS, 338,438 executions | `review-readme-fuzz` |
| Initial real edge F1/F2, 24 cases | 0 / PASS | `edge-live-final`; payloads/manifest `edge-payloads-final` |
| Final generalized real-edge test on rebuilt artifacts | 0 / PASS | `complete-live/round3-edge.log` |
| Eight screens/six commands, actual HTTP 200 schema/CMP checks, race/vet | 0 / PASS | `complete-live/bff-race.log`, `bff-vet.log` |
| Composer `test:all`: lint/unit/integration/API/auth/session/search/ownership | 0 / PASS | `complete-live/php-all.log`, **91 PHP files** linted |
| Native PDO counts, same-data 0/1/20/100, plans and timing | 0 / PASS | `perf-comparison-final`, `perf-final/`; PERFORMANCE.md |
| Payment migrations/payments/HTTP/UUID/recovery suites | 0 / PASS, **160 tests** | `recovery-independent-suites` |
| Scheduler fixture suite | 0 / PASS, **18 tests**, supported test time scale 2 | `complete-live/scheduler-regression.log` |
| Recovery scheduling, full restored stack | **FAIL** | `complete-live/backend-fixes.log`: 14/4; restored original current 14/2 |
| Original/current recovery scheduling, own-project service isolation | 0 / PASS, **14/14 each** | `recovery-isolated-compare`; not substituted for failed acceptance |
| Final full `make integration-up integration-check` | **2 / FAIL** | `final-bound-stack`: reconcile readiness prevents complete acceptance |
| Runtime/graceful shutdown suite | **1 / FAIL before shutdown** | `runtime-independent`: reconcile ready=false; no graceful-shutdown PASS claimed |
| Existing security gate, exact local image observations | **1 / FAIL** | `security-final-arm64`; SECURITY.md |
| Policy/process/provenance negatives | 0 / PASS, **11 tests** | `security-partial-evidence-green` |
| Public/mobile/migration integrity | 0 / PASS | `source-final-integrity`, `protected-hashes.json` |

## Failure boundaries

Full acceptance remains **NOT PASS**. Native recovery failures are resource-sensitive
PostgreSQL statement cancellations reproduced by immutable baseline code. Both
images pass in the controlled own-project isolation; restored memory/I/O PSI and
failures return. No production budget, assertion or SQL locking was weakened.
See RECOVERY_DIAGNOSTICS.md for actual SQLSTATE/backend/scheduler evidence.

Earlier scheduler start-event races were corrected by mode/PID acknowledgements,
not production timeout changes. All intermediate failures remain visible. A later
small-heap operator experiment initially selected two JVM garbage collectors and
failed; its corrected configuration was only for this disposable stack. It did
not make the complete gate green or change repository production defaults.

NOT_RUN: native amd64 full-stack/scans, remote CI, mobile builds/device UI E2E,
production rollout/provider settlement, a successful final complete runtime
shutdown suite. Existing trust/unit/security tests passed within PHP/Go suites;
the separate live multi-replica trust target was not rerun successfully here.

No final source-bound successful acceptance receipt exists. Build/scan observations
and focused passes are evidence of their exact scope only.

Secret review receipts: `checkpoint-secret-scan-fixed` (source exit 0),
`evidence-secret-scan` (233 public-data false positives; raw exit 1),
`evidence-secret-triage-complete` (all 233 classified, no unresolved) and
`evidence-secret-negative` (credential shapes remain unresolved). Archive byte
integrity is verified by `scripts/backend_evidence.py` and the shipped INDEX.
Final staged scan: raw exit 1, five public-reference literals in the classification
utility, individually accounted in `source-secret-classification.json`; not called
a scanner PASS.
Rebuilt-package scan: `final-package-secrets`, 234 raw public-reference findings;
233 classified by structure and one repeated public package name reviewed
manually (`final-evidence-secret-review.json`). No credential remains unresolved.
