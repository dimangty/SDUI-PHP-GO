# V6 verification results

Execution: **30–31 October 2026**. Overall release: **BLOCKED**.
**Latest continuation:** complete source-bound backend acceptance now **PASS**,
receipt **`tested:true`**. See [CONTINUATION_RESULTS](CONTINUATION_RESULTS.md) for
authorized isolation, verified harness fixes, full green backend phases and
current Safari/security blockers. Earlier failures remain historical evidence.
Source base/remote branch: `6f002deb391146195cb63790e0b70e7f1001e8c4`,
`refactor/backend-production-hardening`. Changes remain uncommitted; no push.
No production account migration, password reset, deployment or PR was performed.

## Actual runtime and baseline

Initial checkout `f8f5f33` was clean with no staged work. Two inspected upstream
backend commits were included by fast-forward to `6f002de`. Baseline route matrix,
PHP unit/lint and Go/ops tests passed on that checkout. Host PHP/Composer were
absent; verification used the native production Dockerfiles, PHP **8.3.36**,
Yii2 **2.0.55**, PostgreSQL **17.11**, OpenSearch **2.19.7**, Go **1.27.3** and
ops/gosu **1.26.10**, Docker **29.6.1**, Compose **5.3.0**, linux/arm64.
No review shim, alternate production go.mod, no-op telemetry or lowered Go was used.

The final disposable project is `shopkmp-round5-final-e2e`, shared edge
`http://127.0.0.1:58452`. It builds/starts without identity-provider service,
environment, image, discovery, signing library or network fallback. All twelve
required application/init/worker roles were observed healthy or exited 0 after
build and after fault-test worker restoration. Production volumes were untouched;
only this run's own round5 containers/networks were recreated, retaining volumes.
The final binding preflight again caught an unhealthy maintenance worker after
scan load. Restoring only the owned workers and waiting on their actual probes
produced a successful read-only source/image comparison and twelve-role health
snapshot, with `tested=False` retained. This recovery is not a claim of sustained
health or a substitute for the failed scheduler acceptance.

## Results: executed commands, not historical PASS

Commands requiring SQL run inside the actual PHP image and explicitly disposable
networks, using `compose.py --verify` and the existing runner. Do not run fixture
commands against production. Full invocation/environment details and original
RED/intermediate failures are in the task reports and evidence index.

| Check / command | Observed result |
| --- | --- |
| `python3 scripts/route_matrix.py --check` | PASS: **54** operations (15 public/32 authenticated/7 admin), eight screens, six commands |
| `composer test` / `composer lint` in final PHP image | PASS: **68 tests**, **122 PHP files** |
| Native final `local_auth_core.php` | PASS **11/0**; initial implementation RED **10/10** with provider absent |
| `local_auth_concurrency.php`, `session_concurrency.php` | PASS **3/0 + 27/0**, real processes/PostgreSQL queues |
| `session_password_recovery.php`, `opaque_authorization.php` | PASS **4/0 + 2/0**, atomic rejected/uncertain commits and stored role authority |
| `local_auth_migration.php` | PASS **21/0**, including real effective runtime/operator SQL roles, orphan recovery, retained business links and audit rollback |
| `session_retention.php`, `search_reconcile.php` | PASS **1/0 + 1/0**, both observed RED→GREEN |
| `round5_search_native.php`, `round4_index.php` final focused replay | PASS **15/0 + 2/0**; earlier repeat staging SQL timeout retained |
| Combined frozen native selection above | **87 tests, 0 failures** on native PHP/PostgreSQL/OpenSearch |
| Actual edge `r9_order_http.php` | PASS **1/0**, PHP HTTP → Go at 0/1/10/20/100 orders, all-four-byte maximum address fields |
| `python3 scripts/integration.py --url http://127.0.0.1:58452 --disposable` after runtime updates | PASS **93 HTTP assertions**, CARD/checkout races/screens/policies; earlier clean-catalog run had 94 |
| `go test -race ./internal/bff -run '^TestLiveYiiEightScreensAndActions$' -v -count=1` with real origins | Final PASS **65 steps**, eight screens/six commands, credentials/payment/logout. Earlier concurrent-build attempt failed AddToCart with 504 and remains recorded |
| `payments.php`, `payment-http.php`, `uuid_identity.php`, `uuid_recovery.php`, `recovery.php` | PASS **113/0 + 4/0 + 16/0 + 5/0 + 9/0**; payment migration **13/0** |
| `backend-sdui/scripts/check.sh` | PASS at dependency wave: format/tidy-diff/verify/vet/test/race/build/schema/goldens/semantic/import/lint/source+binary scan execution; Go application source subsequently unchanged |
| Final native image source/binary govulncheck | **BLOCKED by DB freshness** despite exit 0/no raw findings; see SECURITY_RESULTS |
| Locked Composer validate/audit/platform requirements | PASS; final image audit has no advisories/abandoned packages |
| Python discovery / runtime graph | Latest PASS **42 tests**; guard **11/0**, 62 exact frozen history files, no active findings |
| `go -C tools/ops test ./...`; protected-path Git comparisons | PASS: 32 SQL checksum entries; original SQL 001–014, client/SDUI contracts/routes/mobile sources and mobile CI unchanged |
| Fresh image scan + seven CycloneDX SBOMs | Completed; **six image policies PASS, OpenSearch FAIL with 19 HIGH instances** |

### Earlier failed gates retained for comparison

- Source-bound `scripts/verify.py` stopped at scheduler regression: **18 tests,
  4 failures**. In-budget health/recovery and TERM-ignoring-child timing assertions
  failed. An earlier attempt also found a real maintenance SQL timeout.
- A broader `composer test:all` passed its preceding auth/business/security/API
  suites but failed `500ms optional search deadline interrupts slow headers
  without blocking core readiness`: observed **7.131s** versus **<0.95s**, then
  fixture request-count 0 masked that primary timing failure. No full all.php
  GREEN is claimed; remaining suites were run independently as listed above.
- `recovery-scheduling.php`: **14 tests, 4 failures**. Multi-statement apply
  observed **13.872s** with SQLSTATE 57014 instead of the expected 4.5..8s
  transaction-termination scenario; local-expiry case observed 2.116s statement
  cancellation; real recovery/maintenance children returned failure on SQL timeout.
- Live trust replay passed real IPv4/IPv6, spoofing, two-client/per-user limits,
  Retry-After/no-store and webhook boundaries, then failed
  **“FPM denial log not observed after transport rejection”**. First attempt
  additionally hit Docker BuildKit SETTINGS/no-such-job failure; isolated retry
  used supported Compose serialized/non-Bake orchestration, same Dockerfiles.
- Final security gate: no successful complete functional receipt; Go DB older
  than 72h; **19 unresolved OpenSearch HIGH instances**. No policy was relaxed.

The shared Docker VM has many unrelated running stacks and exhibited scheduling,
DNS and SQL stalls. This is evidence of an environment contribution, **not proof
that every failure is environmental**. No deadlines, readiness checks, no-replay
rules, assertions or failure severities were weakened to obtain green results.

## Native CMP attempt

Xcode **26.2 / 17C52**, iOS **26.2**, original unmodified app and XCTest source.
Two owned fresh simulators were created and removed; existing devices/app data
were not reset. Raw XCTest activities/results stayed in private temporary paths
and were deleted; only fixed nonsecret outcomes were extracted.

Both builds launched the actual app. Full journey: **FAIL, xcodebuild 65, 1/1
test failed**. Registration/PIN setup and server PIN-login were successful; one
attempt reached the PIN-authenticated Home/profile identity milestone. The next
failure was `visible_home.title`; the same window contained BFF `user` timeouts
(4.287s/4.437s) and Home 504 (5.611s/6.948s). No JSON/token-decoding failure was
established. This is not a full native-client PASS. The frozen journey also has
an independent observer-token-after-global-logout assumption requiring review;
the server's global revocation was not weakened for that harness.

Android runtime: **NOT_RUN**, adb reported zero connected emulators. Physical
biometric hardware/attestation, production TLS deployment, linux/amd64 full images,
production restore/cutover, exhaustive large-catalog stress and packet capture of
all outbound DNS were **NOT_RUN**. Contract/native crypto checks are not substitutes.

## Resource budgets

Original Go upstream **2,097,152 bytes** and SDUI **1,048,576 bytes / 500 nodes /
depth 16** remain unchanged. Order read repository: **two SELECTs, zero
order_items references**, independent of item fan-out (authentication/rate-limit
SQL adds its own constant request overhead).

Every populated HTTP case has 200 items/order and 255 four-byte-code-point product
names, 2,000-code-point comments and maximum-length four-byte address segments.

| Orders | Summary bytes | SDUI bytes |
| ---: | ---: | ---: |
| 0 | 99 | 1,865 |
| 1 | 2,901 | 4,736 |
| 10 | 28,129 | 31,926 |
| 20 | **56,157** | **62,134** |
| 100 | **280,395** | **303,812** |

Full 20×200 control: **4,726,099 bytes**. Native assertions use 320 KiB summary /
352 KiB SDUI guardrails, leaving >86% upstream and >71% document headroom at 100.
Earlier task measurements used mixed-width address characters; they remain in
ORDER_RESULTS as intermediate evidence, not the final maximum-byte combination.

Search: strict bounded response parser; two data SELECTs for full/card hydration,
no catalog enumeration/writes on read. Maintenance defaults 100,000 documents /
900 seconds, explicit caps 1,000,000 / 1,800. Reconciliation now uses non-waiting
PUT/DELETE plus **one** final refresh; ordinary individual writers retain visibility
waits. Native SQL/protocol test covers this distinction. Search stage measured
four data SELECTs + ten control statements for one product, not a production
100k-document capacity claim.

Argon2id target **19,456 KiB/t=2/p=1**. Native Linux/aarch64 samples: hash
38.229..60.633ms, verify 47.920..68.908ms; two parallel worker samples 18.139..84.673ms.
Their total wall time was 13.476s including startup/scheduling stalls. Target
12-worker Argon memory is 233,472 KiB; accepted legacy verification can require
786,432 KiB/up to 48 threads, plus PHP/OS overhead. These measurements do not
establish production capacity. Account slots are bounded to 4,096×20 rows;
known-user lockouts are durable and temporary. Family cleanup cannot cascade
beyond bounded child-token slices.

## Source/image binding and publication

Latest native receipt is **`tested:true`**, after complete backend acceptance and
source/image comparison passed in the authorized isolated VM. Seven-image scans
bind immutable content to that source state. Security policy and full Safari
journey remain BLOCKED independently; this is not release approval. See the
continuation evidence index for current hashes, with older-wave receipts retained.
No risk disposition was approved by the agent.
