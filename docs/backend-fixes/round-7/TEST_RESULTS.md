# Round 7 — independent backend verification, 2026-11-01

**Local-commit authorization after verification:** the user subsequently requested
**"Make commit"**. This explicitly permits the reviewed task-only local commit;
all failed/unrun security/platform gates below remain unchanged, and push remains
blocked. Earlier "no commit" statements describe their original checkpoints.

## Continuation — current authoritative binding

The subsequent README-only continuation is recorded in
[CONTINUATION_RESULTS.md](CONTINUATION_RESULTS.md): fresh complete canonical build/
test **0/0**, **tested:true**, **503 inputs**, all **12** actual image bindings.
Current source-map SHA-256:
`c3db40d2b43556578453aa0ccd3f525c2aa92201852f2ec184e81540bb9e9242`.
The new exact-image security gate again executed and **FAILED** on 19 OpenSearch
HIGH instances and five authoritative-Go-DB freshness checks. Configured source-
built PHPStan and PHPUnit passed. The original records below remain historical.

[ANDROID_INSTALLED_RESULTS.md](ANDROID_INSTALLED_RESULTS.md) supersedes the older
Android installed **NOT RUN** status: the unchanged journey actually ran and failed
one post-logout verifier assertion (401), after real UI payment CAPTURED/PAID.
It is not a platform PASS. Its exact preceding source-stage binding and emulator
RAM deviation are retained. [SECURITY_FOLLOWUP.md](SECURITY_FOLLOWUP.md) records the
official vendor-release and no-cache advisory-DB rechecks.

## Final admission wave — superseded 503-input binding

**Current full canonical backend receipt: PASSED and independently revalidated.
Final exact-artifact security gate: FAILED. Final source-bound PHPStan/PHPUnit:
PASSED. Release remains BLOCKED.** The 498-input and initial failed stages below
are preserved as historical evidence, superseded for current backend binding.

```sh
T=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode
R="$T/round7-final-fixes/artifacts.json"
V="$T/final-admission-verification"
S="$T/final-admission-security"
P=shopkmp-round7-acceptance-e2e
```

### Current receipt, mandatory gates and independent revalidation

- Receipt **`$R`**, **`tested:true`**, `buildExitCode:0`, `testExitCode:0`, **503**
  bound source inputs, HEAD `160d2a5a535791c75c36c2f70a90515153339e87`.
- Exact sorted compact-JSON source-map SHA-256:
  **`023f7403f3dbc97165e3a553eef55ab43473d107a249b1bf815ecb8328769bf3`**.
- `FINAL_FIXES.md` records the coordinator's fresh full canonical build/test:
  build **06:57:37–06:58:16Z**, canonical success **07:06:32Z**. Its actual
  `canonical-test.log` was independently read: standard full verification,
  backend-fixes and runtime all passed, followed by **all four new mandatory F2
  gates**: `service-privileges`, `service-command-config`, `search-schema-protocol`
  and real `search-privileges-live`. The source map binds their runners and wiring.
- That source set includes cross-process PHP readiness admission: current policy
  **6/6**, native readiness **16/16**, demo identity **15/15** in canonical
  `php-all`; the retained rapid cancel/replace regression and results are in
  `FINAL_FIXES.md`. These supersede the earlier semaphore-only bounded-work claim.
- This final verification resumed only the existing owned containers using
  `compose start --wait`; **no accepted runtime tag was rebuilt or pulled**.
  The existing explicit 220–224/24 environment was reused privately.
- Before and after fresh security scanning, independent
  **`verify_receipt(..., require_tested=True)` passed** against current source
  bytes, all **12** actual required image/container bindings, healthy runtime
  services and successful retained initialization services. Evidence:
  `$V/{pre-security,security-start,post-security}-binding.json` and command receipts.
- The expensive complete functional suite was not repeated in this postcheck.
  The current success is the actual **503-input** receipt, not the superseded
  `97875b…` receipt or a merely mounted source image.

### Fresh final exact-image security

```sh
python3 scripts/backend_security.py --env .env.example --project "$P" \
  --platform linux/arm64 --output "$S" \
  --cache "$T/round7-verification/security-cache" --receipt "$R"
```

**Exit 1**, **07:18:40–07:19:48Z**. The final scanner's artifact/source/functional
prerequisite **PASSED**; `$S/build-test-receipt.json` holds the current successful
503-input receipt. All seven runtime service image scans, seven nonempty
CycloneDX SBOMs, both Go source scans, all three delivered Go binary scans,
production Composer audit and scanner DB stability checks actually executed.

| Current policy | Result |
| --- | --- |
| Production Composer audit | PASS |
| PHP, BFF, migrator, PostgreSQL, edge and internal Nginx image policies | **Six PASS** |
| OpenSearch 2.19.7 | **FAIL: 19 HIGH instances**, also 12 MEDIUM / 1 LOW; no dispositions |
| BFF and ops source govulncheck | **Both FAIL freshness**; zero raw findings |
| Delivered BFF, migrator and PostgreSQL `gosu` govulncheck | **All three FAIL freshness**; zero raw findings |
| Trivy vulnerability/Java DB versions, freshness and content stability | PASS |

All five govulncheck **v1.9.0** raw configurations independently report advisory
DB timestamp **2026-10-27T21:16:18Z**, older than the unchanged **72 h** policy.
BFF toolchain/SBOM is **go1.27.3**; ops/migrator/gosu **go1.26.10**. No advisory
freshness waiver or zero-finding-to-PASS conversion was applied.

Trivy **0.76.1**, pinned image
`sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c`.
Actual refreshed/checked DB metadata: vulnerability UpdatedAt
**2026-11-01T01:13:06.496727497Z**, Java UpdatedAt
**2026-11-01T00:58:32.801018394Z**. The complete current HIGH list and exact JAR
paths are in `$S/opensearch.policy.json`: lz4-java CVE-2026-106451; Jackson core
CVE-2026-89407/89425; databind CVE-2026-68497/91776/91777; Guava CVE-2026-102554;
snappy-java CVE-2026-108106/93451. Duplicate bundled instances total **19**.

Safe accepted image/index IDs, all **linux/arm64**:

| Service(s) | Current immutable ID |
| --- | --- |
| yii2, yii-migrate, reindex, maintenance, reconcile, payment-recovery | `sha256:bfc051b09f7811c49368e7481ff74ffc90eb7c1db23be8512a445ba9c0339778` |
| sdui | `sha256:9b164c78539bbd8be3b8368a8ae5c134ef560d71b3faa71f278238040f296b53` |
| migrate | `sha256:9ef8c2c4bdd074890aa2dc4a199ba060ca643b63dff6fb3d4157e78a52e61f9f` |
| postgres | `sha256:e5d9360ae1753d6451b92d18024afe3746235883edcfdf9ac52954783fa480dc` |
| opensearch | `sha256:9e8e4dbcce0c9899d421bf46ad9c0b33143765e04b57f514313e6bc2b316ea27` |
| edge, yii2-http | `sha256:dede8895648b461178c3bc604f7eed4423efe5220b2c4d839f5eb3d34ac3f8f7` |

Scanned current PHP config digest:
`sha256:60d2031dffaecb62932944edd3ca1f7f75d78e0e1f709127356ef45b2b1b8031`;
OpenSearch config digest:
`sha256:a908737e056e65730f0509b730885b5009b6739ead3b6d089f8125d0cd28a637`.
`$S/artifacts.json` records OCI IDs, config and archive hashes; `*.sbom.cdx.json`
and `$V/final-summary.json` record SBOM bytes/component counts/hashes. This is a
new final-admission scan set, independent of the earlier scan outputs below.

### Final source-bound tools and root script checks

Actual source-bound rebuild, preserving accepted runtime tags:

```sh
make php-tools-build \
  PHP_TOOLS_APPLICATION_IMAGE=shopkmp-round7-admission-tools-application \
  PHP_TOOLS_IMAGE=shopkmp-round7-admission-tools
docker run --rm --network none shopkmp-round7-admission-tools composer analyse
docker run --rm --network none shopkmp-round7-admission-tools composer test
```

All **exit 0**. Current source hashes matched the **503-input** receipt before
and after the real application/tools Docker builds and tests. The normal production
Dockerfile was built under a new tools-only tag and supplied as the named tools
application context; the real separate dev-tools lock was installed and validated.
The final `phpstan.neon` includes `ReadinessAdmission.php`.

- **PHPStan 2.3.4**, level **max**, PHP target **80300**: **zero errors** across
  the configured readiness/admission/SQL, payment and ID scope; no suppression.
- **PHPUnit 13.4.5 / PHP 8.5.12**: **19 tests, 24 assertions**, zero failures,
  warnings or skips, existing strict configuration and root script unchanged.
- On the independently source-built application tools base, actual root Composer
  validate `--strict`, `check-platform-reqs`, `composer test` (**74 tests**),
  `php tests/readiness_policy.php` (**6/6**) and `composer lint` (**143 files**)
  all exited **0**, without PostgreSQL/OpenSearch or full functional reruns.
- Built tools application image:
  `sha256:099e655c4dc7a9c4b5ce470bcb98c229f15d9ddb8f2ad9af8e1fcb03df42ed0d`;
  tools image:
  `sha256:2b3f2f2e8495d9b38177655996e815066eaedeb920e74ddc6b9942d1090c9ed4`.

Exact command exits/logs and source bindings:
`$V/tools-source-binding.json`, `tools-build.*`, `phpstan.*`, `phpunit.*`,
`root-{composer-validate,platform,unit,admission-policy,lint}.*`.

### Current task diff secrets, contracts and lock bytes

**Gitleaks v8.30.1**, default rules, `--redact=100`, immutable official scanner
`sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f`:
actual current changed tracked contents (**28 files**), new nonignored task
source/docs (**57 files**) and cumulative tracked diff against HEAD were scanned.
The two explicitly preexisting runtime JSON files were recorded as non-task inputs,
not copied as newly added source. Scanner rules/allowlists were unchanged.

Raw tracked/untracked/diff exits: **1 / 0 / 1**, findings **1 / 0 / 1**. Both raw
matches were individually reviewed and remain visible:

1. `scripts/validate_production.py:101–155`, `private-key`: two literal PEM-header
   validation strings and intervening Python validation code, no encoded key.
2. Removed Compose healthcheck in the diff, `curl-auth-user`: a credential-variable
   interpolation, no literal password value.

Context-review exit **0**, **zero unresolved findings**; raw nonzero scanner exits
are not called a scanner PASS. `$V/secrets/` holds immutable snapshot/diff hashes,
fully redacted raw reports, scanner identity and the exact review. The completed
report receives its own final document-only scan recorded in the same directory.

Contract/lock gate **exit 0**: **55** contract/schema/golden/migration inputs
byte-identical to HEAD; all **32** existing migration up/down checksums passed.
All six Composer/Go module lock hashes match the current receipt. Both modules'
`go.mod`/`go.sum` are byte-identical to HEAD. Root Composer dependency versions are
unchanged; its intentional platform changes are only `ext-pgsql` and `ext-sockets`
plus the associated content hash. The separate dev-tools lock is a new task input.

| Input | Current SHA-256 |
| --- | --- |
| Root Composer lock | `b37709145a61a8683f5712c4875972348ef58737e692d8594b81387c0afbc411` |
| Dev-tools Composer lock | `28e9b47d7ef0b3597d7a76ebc2048dff11aeda9e80b46bd2d945e72bfae79c85` |
| OpenAPI | `b119e88049a621e06ec331e4c040580d4ac74d8a92938fe1d3a0b4d3a9e2875c` |
| Route contract | `327614b3c634f01002a916e59cb30ac4eab886ce45a3d8efb8156958cf8e4f97` |
| SDUI screen schema | `936ec632f9da57c7174aad0a73c34ea318e04d9452d1a9daf9bb7c97c2c8353d` |
| Action request schema | `92ee9e46dfe429aaaa39b9ea8322e54aaa9be8f7b7dea4da19e2cccf2dd0baa9` |
| Action response schema | `8339640659c0a36d11dd866487a2c294c4ebec5c30f7602eba6d5fa84edcd88b` |

Complete per-file baseline/current and Go lock hashes:
`$V/contract-lock-hashes.json`, `$V/migration-checksums.log`.

### Native/platform evidence remains historical, not a new 503-input execution

The prior native Safari failure below remains an unresolved platform gate:
**exit 65**, **2026-11-01 06:32:25–06:37:31Z**, `safari_payment_completed`, two
demo GETs / zero POSTs, SQL CREATED/AUTHORIZED. It executed against the **498-input
`97875b…`** backend receipt, **not** the current 503-input source set. It was not
repeated in this postcheck. Earlier iOS common keychain, Android release URL and
Android installed-journey blockers remain in `PLATFORM_RESULTS.md`.

Hash-only integrity check **exit 0**: all **393** client/build files and both
F3 runtime inputs (`PaymentService.php`, `ApiKernel.php`) are unchanged from that
native attempt. Original test SHA-256:
`6e53a08f4d6e8674dd7b4a662689110efe5d78d8fd4d732e6c7b81d587d1668d`.
Historical native summary SHA-256:
`213549d1cd4e0475a105ee0a3f9a68d1c17d830aeb8455ca603098ac837b90ab`.
`$V/historical-native-binding.json` explicitly records
`currentWholeSourceNativeExecution:false`; unchanged bytes are not represented as
a current whole-source mobile functional proof.

### Final postcheck preservation and shutdown

Only this report and approved temporary helper/evidence files were edited by this
postcheck. The two existing evidence destinations were backed up again; no scan or
tools command overwrote them. Byte-exact comparisons passed with their original
hashes **0274421d…0fd3** and **0246e219…0ed** (full hashes in the preservation table
below and `$V/resources-final.json`). No source/test/timeout/policy edit, staging,
commit, push, subagent or foreign lifecycle operation occurred.

Owned-stack stop **exit 0**, **07:24:24–07:24:25Z**. All **12** required retained
service containers are stopped and still use their tested immutable images;
application/worker/SQL exits 0, OpenSearch normal SIGTERM 143. Both canonical
PostgreSQL/search volumes and all private evidence are retained. All **62**
initially running foreign containers remain running. Current source bytes still
match the successful 503-input receipt and the Git index remains empty.

**Final backend code/tooling blockers: none observed. Publication blockers:
fresh exact-image OpenSearch HIGH findings, stale authoritative Go advisory DB,
and the separately unresolved historical native/platform gates.**

---

## Prior 498-input follow-up — superseded backend binding; historical native run

**For this prior 498-input snapshot, source-bound backend acceptance: PASSED. F1 full-chain
deadline acceptance: PASSED.** The fresh receipt is `tested:true`, with build and
test exits **0 / 0**. **Exact-artifact security: FAILED. Unchanged installed Safari
journey: FAILED. Release remains BLOCKED**, also subject to the independent
platform blockers in `PLATFORM_RESULTS.md`.

The follow-up uses new private evidence, preserving every initial failure:

```sh
T=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode
F="$T/round7-verification-final"
P=shopkmp-round7-acceptance-e2e
```

### Final build/test source binding

- Receipt: **`$F/acceptance-artifacts.json`**, **498** source/lock/config files.
- Source snapshot SHA-256:
  **`97875b7771ed2338feb39302f1e48d1de20a78ac958f22fa038055bc6ca2e46c`**,
  using the same sorted compact-JSON hash definition as the initial stage.
- `buildExitCode:0`, `testExitCode:0`, **`tested:true`**.
- Independent `verify_receipt(..., require_tested=True)` revalidated current
  sources and **all 12** actual required service image/container bindings.
  Evidence: `$F/receipt-revalidation.*` / `summary-final.json`.
- Source HEAD remains `160d2a5a535791c75c36c2f70a90515153339e87`, with the
  coordinator's implementation/follow-up uncommitted. This verification task
  changed only this report and task-owned temporary helpers.
- The retained owned stack was rebuilt, not merely restarted with an old receipt.
  Existing PostgreSQL/search volumes and the same overlap-checked **220–224/24**
  networks were retained. Foreign stacks were not managed.

Exact final commands, run through `$T/round7_final_runner.py`'s private owned
network environment:

```sh
make integration-up E2E_PROJECT="$P" E2E_PORT=58207 \
  ARTIFACT_RECEIPT="$F/acceptance-artifacts.json"
make integration-check E2E_PROJECT="$P" E2E_PORT=58207 \
  ARTIFACT_RECEIPT="$F/acceptance-artifacts.json" \
  VERIFY_EVIDENCE_DIR="$F/acceptance"
```

Actual canonical tool chain:

```sh
python3 scripts/backend_artifacts.py --env .env.example --project "$P" \
  --receipt "$F/acceptance-artifacts.json" --phase build -- \
  python3 scripts/compose.py --env .env.example --project "$P" \
  up -d --build --wait --wait-timeout 2100
python3 scripts/backend_artifacts.py --env .env.example --project "$P" \
  --receipt "$F/acceptance-artifacts.json" --phase test -- \
  python3 scripts/verify.py --env .env.example --project "$P" \
  --url http://127.0.0.1:58207 --disposable --evidence-dir "$F/acceptance"
```

Build/seed/reconcile: **exit 0**, **06:23:26–06:24:08Z**. Complete canonical
acceptance: **exit 0**, **06:24:33–06:31:41Z**. **Every canonical phase executed:**
route matrix, runtime graph, packaged PHP runtime, Docker scheduler regression,
seed preservation, reconciliation, edge CARD journey, BFF race/live/vet,
round-3 live edge, ops test/vet, full PHP/search/native lane, max order HTTP,
focused auth/SQL-role/payment/recovery/scheduling, worker-ready restoration, and
runtime trusted-hop/private-port/history/shutdown/drain/restart verification.
No canonical stage was omitted or substituted with the earlier standalone checks.

### Final functional results

| Current final gate | Actual result |
| --- | --- |
| PHP runtime / scheduler container gates | **5/5 / 18/18**, default timing scale 1 |
| Shared edge CARD journey | PASS, **94 HTTP assertions**, eight screens, checkout races, 39+7 route policies, spoof/body/timeouts |
| BFF race/live/vet, explicit round-3 edge, ops test/vet | PASS |
| Full `composer test:all` | **PASS** with real PostgreSQL/OpenSearch and packaged PHP/vendor |
| New readiness / policy / demo identity | **15/15 / 4/4 / 15/15**, no guarded-suite skips |
| SQL-role matrix inside canonical verification | **10/10**, repeat lockout **3/3** |
| Focused auth lockout / concurrency / local recovery | **3/3 / 8/8 / 2/2** |
| Payment migration / payments / payment HTTP | **13/13 / 113/113 / 4/4** |
| UUID identity / UUID recovery | **16/16 / 5/5** |
| Recovery / recovery scheduling | **9/9 / 14/14** inside the complete canonical run |
| Eight original recovery schema faults | Each real committed fault gave **503**, each restoration **200** |
| Runtime/worker graceful stop and ready restart | PASS in canonical `runtime` phase, required application/worker stop codes all 0 |
| Explicit R9 native export/Go opt-ins | **exit 0**, both tests with race enabled, zero skips, fresh final-image/real-PG export and current OpenAPI conversion |

The broad Go invocation retains its existing opt-in skips. The round-3 test
executed in its own canonical phase; the two native R9 tests were subsequently
executed explicitly using fresh final-stage exports (`r9-native-opt-ins.*`).

### Final independent F1 retest

```sh
python3 "$T/round7_final_f1.py"
python3 "$T/round7_final_connect.py"
```

Both **exit 0**. They use the final canonical PHP image **4693bfd8…bb8851** and
current packaged BFF; no application-source mount or test-bound relaxation.
Evidence: `$F/f1-transport/{compose.json,commands.log,results.json,stats.json}`
and `$F/connect-diagnostic-results.json`. The original owned full-chain fixture
still starts edge through `yii2-http` and `sdui` healthy dependencies, with the
optional peer blackholed and actual PostgreSQL in retained `f1_chain_final`.

| Actual final observation | Result |
| --- | --- |
| Six exact formerly failing unused-IP kernel probes, proxy stopped | **602.866–603.570 ms**, all 503, every sample `<700 ms` |
| Required genuine pending TCP, direct PHP, five | p50 **606.527 ms**, max **610.473 ms**, all 503 |
| Required genuine pending TCP, BFF, five | p50 **606.206 ms**, max **607.414 ms**, all 503 |
| Pending TCP, 24 concurrent / eight callers | max **605.403 ms**, all 503, prompt overload retained |
| Accepted startup / TLS / authenticated read / real-PG established-query blackholes | all 503; respective maxima **612.235 / 606.883 / 605.909 / 605.219 ms** across PHP+BFF samples |
| Optional healthy / fast-fail / slow-header / TLS / established / unreachable-connect | **20×6**, every ready response 200, maximum **25.047 ms** |
| Optional fault, 40 requests / eight callers | Four 200, 36 bounded admission 503; max **13.970 ms** |
| Optional transport | **Zero accepts / zero application bytes**, configured `unknown`, disabled absent, capability preserved |
| Required-fault liveness | PHP and Go both 200, including pending TCP |
| Six client cancellations / restored healthy PG | Bounded cleanup, zero retained sessions; **20/20** subsequent ready responses 200 |

This independently supersedes the pending-connect failure below. The recovery
fixture correction also passed in the canonical invocation, without adding a
search-lane opt-in to the recovery command. `READINESS.md` contains the implementer's
root-cause/RED/GREEN record; the current successful receipt is bound to the repaired
source set rather than to that earlier standalone image.

### Final current images

All are **linux/arm64**; current local immutable image/index IDs:

| Service(s) | Image/index ID |
| --- | --- |
| yii2, yii-migrate, reindex, maintenance, reconcile, payment-recovery | `sha256:4693bfd8e097e51e0cc693a091584ce2ce263bcee2865ee6a51e08dc86bb8851` |
| sdui | `sha256:cf6b082a3cef9c29e2cdf396543e063a27402cb1c4dbc28f6fb19a23ffc401aa` |
| migrate | `sha256:c15ab4ceb708019422778051fe4f75f2a1e27ad5960dc3984b121b2bae7a3f9e` |
| postgres | `sha256:3d7557e2555a3624de5e5efb60f493a2cffcded6a7efb8e504c34b735b80bbb8` |
| opensearch | `sha256:178dce39f6a038bf3b490f8727d71bea36d3c3b39ba77d71b1ad660b4fd254cd` |
| edge, yii2-http | `sha256:dede8895648b461178c3bc604f7eed4423efe5220b2c4d839f5eb3d34ac3f8f7` |

### Final supplemental roles and trust

```sh
make test-database-roles
make test-trust-live ENV_FILE=.env.example E2E_PROJECT="$P"
```

Both **exit 0** on final sources/images. The explicit SQL-role Make target ran
**06:31:42–06:32:12Z**, fresh owned project
`shopkmp-round6-roles-6d223192-e2e`, **10/10** production-like TLS SQL-role tests,
lockout **3/3**, operator inventory, own cleanup and retained volume. The existing
runner enforces its round6 project-name prefix. Live trust ran
**06:32:12–06:32:47Z**: seven groups covering real IPv4/IPv6, transport spoofing,
budgets, webhook source, exact FastCGI peer and both BFF replicas. No historical
result was substituted. Evidence: `$F/database-roles-make.*`, `$F/trust-live.*`.

### Final exact-artifact security — FAILED

```sh
python3 scripts/backend_security.py --env .env.example --project "$P" \
  --platform linux/arm64 --output "$F/security" \
  --cache "$T/round7-verification/security-cache" \
  --receipt "$F/acceptance-artifacts.json"
```

**Exit 1**, **06:31:42–06:33:27Z**, executed **after** successful source-bound
functional acceptance. This is a new complete scan/report set. The scanner's
functional/source/image prerequisite **PASSED** (`artifact-observations.json`),
and `security/build-test-receipt.json` contains the final `tested:true` receipt.

- All seven actual runtime service images were saved/scanned; seven current
  CycloneDX SBOMs were emitted. Archive/config digests, full package inventories,
  production lock hashes, source scans and the three delivered-binary scans are
  preserved under `$F/security`; scans used the immutable accepted runtime images.
- Production Composer audit and **six image policies PASSED**.
- OpenSearch **2.19.7** freshly reproduced **19 HIGH instances**, **12 MEDIUM /
  1 LOW**, no dispositions. Exact IDs/packages/paths are in the final
  `opensearch.policy.json`; the same nine advisory IDs/package instance counts
  tabulated in the initial security section below were reproduced against the
  final OpenSearch artifact.
- **Five Go policy failures:** BFF and ops source, plus delivered BFF, migrator
  and PostgreSQL `gosu` binaries. Authoritative DB timestamp remains
  **2026-10-27T21:16:18Z**, exceeding the unchanged **72 h** policy. govulncheck is
  **v1.9.0**, BFF **go1.27.3**, ops/migrator/gosu **go1.26.10**. Raw streams and
  command exits are retained; all **five current raw scans have zero findings**,
  but freshness policy still fails. No freshness waiver is applied.
- The reusable owned Trivy cache was refreshed/checked by the actual scanner.
  Current DB UpdatedAt **2026-11-01T01:13:06.496727497Z**, Java DB UpdatedAt
  **2026-11-01T00:58:32.801018394Z**. DB versions/freshness/content stability passed;
  metadata retains the actual download timestamps.
- Scanned final PHP config digest:
  `sha256:f973d52c7eaf33d2a6cf2f8d3b233de45a623330775ecfb257498a9b63281688`.
  Final OpenSearch config digest:
  `sha256:a908737e056e65730f0509b730885b5009b6739ead3b6d089f8125d0cd28a637`.

Security release approval remains blocked even though canonical backend functional
acceptance is now successful.

### Historical installed native Safari journey — 498-input snapshot

**Actually executed; FAILED, exit 65**, **06:32:25–06:37:31Z**, **305.738 s** for
the build/test command. XCTest: **one test, one failure, zero skips**. Build
succeeded; zero Swift compiler errors. No general platform gate was rerun by this
task; only the explicitly requested unchanged
`InstalledJourneyTests/testRealCardJourney` was selected.

The runner first required the successful current receipt and revalidated all
source/image bindings. It then created and booted only:

- Simulator: `shopkmp-round7-native-safari-7c730fd7`.
- UDID: **`970E6333-71BE-45E1-AAFC-9A56C3D4EFB6`**.
- Device/runtime: **iPhone 17 Pro / iOS 26.2**.

Exact installed-gate command, using the existing Temurin 21 toolchain and SDK
settings recorded by the platform task:

```sh
export TEST_RUNNER_JOURNEY_BACKEND_URL=http://127.0.0.1:58207
xcodebuild test -project iosApp/iosApp.xcodeproj -scheme iosApp \
  -configuration Debug \
  -destination 'platform=iOS Simulator,id=970E6333-71BE-45E1-AAFC-9A56C3D4EFB6' \
  -derivedDataPath "$F/native-safari/ios-derived" \
  -resultBundlePath "$F/native-safari/Journey.xcresult" \
  -parallel-testing-enabled NO -collect-test-diagnostics never \
  -only-testing:iosAppUITests/InstalledJourneyTests/testRealCardJourney \
  BACKEND_SERVER_URL=http://127.0.0.1:58207 \
  CODE_SIGNING_ALLOWED=NO -hideShellScriptEnvironment
```

The built app's `Info.plist` independently confirmed
`BackendServerURL=http://127.0.0.1:58207`, matching the verifier and accepted owned
backend. No UI/source changes, hidden payment POST, capability replay, timeout
relaxation or replacement browser interaction was used.

Eight actual milestones passed: public product discovery, installed registration,
PIN/home identity, six-digit PIN login, password login, catalog/product/cart,
zero orders before confirmation, and canonical native checkout. Failure:
**`E2E-CHECK: safari_payment_completed`**, at
`iosApp/iosAppUITests/InstalledJourneyTests.swift:18` (the caught check; assertion
at the existing Safari completion boundary). Current browser diagnostics reported
the Complete payment control enabled/hittable with its frame inside the viewport;
the click-to-submit root cause remains unestablished.

Independent evidence from that exact native-run window:

- Edge: **2 demo GET / 0 demo POST**.
- Read-only SQL aggregate: **one order CREATED, payment AUTHORIZED**.
- No CAPTURED/PAID or return-to-app/full-journey milestone was observed.
- **393 client/build source files** were byte-identical before/after, with the
  same source-map SHA-256 as the platform task:
  `2a4e395e1869333d071eb9d4eedd156d38ad850bf804dda6c962f59f3e7c80d0`.
- Simulator shutdown: **exit 0**; final state **Shutdown**, device retained.

Safe summary and exact argv/times: `$F/native-safari/summary.json`. Raw XCTest
activity/results and build artifacts remain private under `$F/native-safari/`;
they may contain typed synthetic credentials and are not repository artifacts.

The independent platform report's reproducible iOS common keychain failure
(`-25291`, no keychain available), absent Android release
`SHOPKMP_RELEASE_SERVER_URL`, and unexecuted Android installed journey remain
separate release blockers. The successful backend receipt and the executed Safari
failure do not replace those platform results.

### Final evidence preservation and resource handoff

Before this follow-up, both existing evidence destinations were independently
backed up again. Canonical generated results are preserved privately as
`$F/generated-local-auth-runtime.json` and `$F/generated-integration.json`.
Restoration used dedicated `apply_patch` moves of byte-exact private copies.
The original SHA-256 values remain those in the preservation table below.
Final independent receipt/source revalidation after the native attempt **passed**
with `require_tested=True`. Source map and all 12 image/container bindings still
matched the successful receipt. Byte-exact evidence checks and `git diff --check`
exited **0**, and the Git index is empty. No commit or push was attempted.

Owned canonical stop command: **exit 0**, **06:38:19–06:38:20Z**:

```sh
python3 scripts/compose.py --env .env.example --project "$P" stop
```

`$F/resources-final.json` confirms **all owned canonical/F1 containers stopped**,
**all 62 initially running foreign containers still running**, and the newly
owned simulator Shutdown. Canonical/F1 containers and networks are retained;
the role/trust runners cleaned up only their own fixtures. All PostgreSQL/search
volumes, native journey business state and private evidence are retained. The
application/worker graceful-stop gate remains green; expected raw CLI fixture
force-stop 137 and final OpenSearch SIGTERM 143 codes are separately inventoried.

There are no remaining running resources from this verification task to stop.
Current backend F1/recovery findings are resolved and freshly verified; security,
native Safari and the independently reported platform gates are the remaining
publication blockers.

---

## Initial decision — historical, superseded by the final follow-up above

**Full canonical functional acceptance: FAILED. F1 connect-deadline acceptance:
FAILED. Artifact-security gate: FAILED.** No successful functional receipt or
release approval was issued at this initial stage. Two concrete integration/source
findings were reported to the coordinator during execution; both were unfixed at
that time. The final follow-up above supersedes those functional failures after
the coordinator's corrections and an actual complete source-bound rerun.

The complete native PHP/search lane, demo identity suite, live search privilege
matrix, SQL-role gates, payment suites before the recovery failure, separate
recovery-scheduling gate, trust gate, and separate runtime/shutdown gate passed.
Their individual successes do not change the failed canonical result.

Only this report was added to repository source. Helpers and raw evidence are
private, outside Git. No repository test, gate, timeout or application source was
edited, and no volume or index was deleted. No commit, push, child agent or
platform test was run.

## Evidence and source binding

In commands below:

```sh
T=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode
E="$T/round7-verification"
P=shopkmp-round7-acceptance-e2e
```

`$T/round7_verify_runner.py` injects only owned disposable network/test settings
and records each command, start/finish time, exit and private log. Resolved
credentials and full Compose configuration are not printed.

- HEAD: `160d2a5a535791c75c36c2f70a90515153339e87`; implementation was uncommitted.
- Receipt: `$E/acceptance-artifacts.json`, **497** source/lock/config files.
- Source-map SHA-256: **`150377fdc8c8bf3c52434683e8ff0d253d982da63961c38aeaf3f7fdba1cd177`**.
  Definition: SHA-256 of UTF-8 `json.dumps(receipt['sources'], sort_keys=True,
  separators=(',', ':'))`. The canonical tool's source scope excludes generated
  `deploy/evidence/` and this documentation directory.
- Read-only revalidation after testing matched current source hashes and **all
  12** required service image/container bindings to the build receipt. Evidence:
  `$E/summary.json`; successful revalidation used `require_tested=False` and did
  not promote the failed receipt.
- Receipt fields: `buildExitCode:0`, `testExitCode:1`, **`tested:false`**.
  Make's integration-check exit was **2**; the underlying canonical test exit
  was **1**.
- Root Composer lock SHA-256:
  `b37709145a61a8683f5712c4875972348ef58737e692d8594b81387c0afbc411`.
- Dev-tools Composer lock SHA-256:
  `28e9b47d7ef0b3597d7a76ebc2048dff11aeda9e80b46bd2d945e72bfae79c85`.
- Both Go module/lock hashes, all per-file hashes, exact commands and timestamps
  are in the private receipt, summary and security provenance.

Fresh canonical networks were overlap-checked against every existing Docker
network: ingress **10.252.220.0/24**, FPM **221/24**, application **222/24**, data
**223/24**, payment fixture **224/24**. Ingress used loopback port **58207**.
The independent F1 transport fixture used **225–227/24** and loopback ports
**58210–58212**. The TLS search action fixture used **228/24**. Foreign restart
stacks were inspected read-only and never stopped/reconfigured.

## Canonical build and acceptance commands

Executed through the private runner's environment wrapper:

```sh
make integration-up E2E_PROJECT="$P" E2E_PORT=58207 \
  ARTIFACT_RECEIPT="$E/acceptance-artifacts.json"
make integration-check E2E_PROJECT="$P" E2E_PORT=58207 \
  ARTIFACT_RECEIPT="$E/acceptance-artifacts.json" \
  VERIFY_EVIDENCE_DIR="$E/acceptance"
```

These actually invoked the unchanged source-binding tooling:

```sh
python3 scripts/backend_artifacts.py --env .env.example --project "$P" \
  --receipt "$E/acceptance-artifacts.json" --phase build -- \
  python3 scripts/compose.py --env .env.example --project "$P" \
  up -d --build --wait --wait-timeout 2100
python3 scripts/backend_artifacts.py --env .env.example --project "$P" \
  --receipt "$E/acceptance-artifacts.json" --phase test -- \
  python3 scripts/verify.py --env .env.example --project "$P" \
  --url http://127.0.0.1:58207 --disposable --evidence-dir "$E/acceptance"
```

Build/seed/reconcile: **exit 0**, **05:30:33–05:31:13Z**. Canonical acceptance:
**05:31:28–05:37:35Z**, failed at the recovery suite described below.

| Actual canonical phase | Result |
| --- | --- |
| Route matrix / runtime graph | PASS; 54 operations/policies, eight screens, six commands |
| Packaged PHP runtime | PASS, **5/5**, real PHP 8.5.12/extensions/FPM/runtime pruning |
| Docker scheduler regression | PASS, **18/18**, time scale **1**, assertions/deadlines unchanged |
| Seed preservation / initial SQL-to-search reconcile | PASS; existing development auto-bootstrap dependency worked |
| Shared edge CARD journey | PASS, **94 HTTP assertions**, eight screens, same/different-key checkout races, 39+7 policies, spoof/body/timeouts |
| BFF race/live/vet | PASS; actual edge origins supplied |
| Explicit round-3 live edge | PASS; actual PHP/Go/edge Unicode/body/projection/schema cases |
| Ops test/vet | PASS |
| Complete `composer test:all` / `tests/all.php` | **PASS**, real PostgreSQL + OpenSearch, packaged application/vendor, actual migrations/scheduler mounts |
| New round-7 native registration | **4/4 policy + 13/13 native readiness + 15/15 demo identity**, zero failures; dedicated `readiness_test` created/used |
| Max-Unicode order HTTP | PASS |
| Actual merged-production TLS SQL roles | **10/10**, repeat lockout **3/3**, operator inventory PASS |
| Focused auth lockout / concurrency / local recovery | **3/3 / 8/8 / 2/2** |
| Payment migration / payments / payment HTTP | **13/13 / 113/113 / 4/4** |
| UUID identity / UUID recovery | **16/16 / 5/5** |
| Recovery | **9 tests, 1 failure**, canonical fail-fast stop |
| Recovery scheduling / runtime after fail-fast | Run independently below; not canonical-phase successes |

`round7_native.php` was inspected before execution: the existing owned-project
guard is mandatory; it derives a separate database URL and invokes all three
suites. No suite was silently skipped. Native readiness reported 20 healthy
samples p50 **8.13 ms**, p95 **10.74 ms**, max **15.65 ms**, plus **8×5** concurrent
healthy kernel probes. Its accepted-socket blackhole tests passed, but did not
cover the genuine pending TCP-connect defect found below.

The broad Go command's explicit opt-in R9 native-export tests were initially
skipped by the existing runner; the round-3 live test was also skipped there
and then passed in its separate canonical phase. Fresh R9 export/contract
follow-through is recorded in the supplemental ledger below.

## Coordinator findings

### 1. Required TCP-connect blackhole exceeds F1 budget — source defect

Full-chain helper command:

```sh
python3 "$T/round7_readiness_chain.py"
python3 "$T/round7_connect_diagnostic.py"
python3 "$T/round7_connect_diagnostic.py" --trace
python3 "$T/round7_cancellation_remaining.py"
python3 "$T/round7_connect_live.py"
```

The first command's final meaningful run is `$E/readiness-chain-complete/`;
**exit 1** is preserved. The fixture uses exact canonical PHP/BFF/Nginx images,
the real FPM/Nginx configuration and owned real PostgreSQL, with a separate
`f1_chain_final` schema-copy database. It starts edge with both required
`depends_on: service_healthy` edges while optional search is blackholed. It
replaces no business response or application file. The task-owned PHP proxy
counts optional accepts/bytes and forwards real PostgreSQL startup/authentication
before selectively withholding established query traffic.

| BFF → Nginx/FPM → PHP → PG observation | p50 / p95 / max, ms |
| --- | --- |
| Optional healthy, 20 probes | **12.515 / 19.894 / 19.894**, all 200 |
| Optional fast-fail, 20 | **15.028 / 27.964 / 27.964**, all 200 |
| Optional slow headers, 20 | **14.842 / 26.541 / 26.541**, all 200 |
| Optional TLS blackhole, 20 | **11.502 / 17.361 / 17.361**, all 200 |
| Optional established/read blackhole, 20 | **11.789 / 15.638 / 15.638**, all 200 |
| Optional unreachable-connect target, 20 | **12.083 / 16.283 / 16.283**, all 200 |
| Optional fault, 40 concurrent requests / eight callers | **3.079 / 21.003 / 21.926**; four 200, 36 prompt overload 503 |
| Required PG fast-fail, five | **2.137 / 4.119 / 4.119**, all 503 |
| Required PG accepted startup blackhole, five | **603.266 / 604.376 / 604.376**, all 503 |
| Required PG TLS blackhole, five | **604.186 / 604.964 / 604.964**, all 503 |
| Required PG authenticated read blackhole, five | **603.238 / 603.771 / 603.771**, all 503 |
| Required real-PG established query blackhole, five | **604.577 / 605.077 / 605.077**, all 503 |
| Required established fault, 24 concurrent / eight callers | **2.801 / 605.028 / 605.315**, all 503 |

Every optional case had **zero optional TCP accepts / zero application bytes**.
Configured search remained `unknown`, disabled search was absent, and capability
`catalog-cards-v1` remained advertised. Native healthy concurrency was separately
all 200; HTTP overload 503 is the named two-in-flight BFF admission policy.
Required-fault liveness checks returned 200 at both Go and PHP boundaries.

**Failing case:** disconnect only the owned proxy's application interface so its
numeric PG address cannot complete TCP connect. Five BFF probes reached the
unchanged 2 s deadline; the strict `<2000 ms` fixture assertion failed (one sample
**2009.418 ms**). Nginx recorded 499 at about **2.001–2.002 s** and a direct PHP
503 at **4.075 s**. A separate repeated observation gave BFF 503 at **2016.720 /
2004.210 ms**, while Go/PHP live remained 200 at **1.542 / 3.561 ms**; the direct
PHP readiness diagnostic subsequently exceeded its **10 s observation timeout**
and that helper also exited **1**. No timeout was increased to turn either result
green.

Packaged-PHP kernel-only reproduction against two unused numeric addresses:
**six 503 responses in 3051.359–3101.280 ms**, without Go/FPM/queue overhead.
Real-builtin forwarding instrumentation localized **3066.433 ms** to the second
`pg_connect_poll()` call (`ReadinessSql.php` connect loop); `pg_connect()` was
**0.081 ms**, `pg_close()` **0.213 ms**. Trace helper exit **0** records the defect,
not a budget pass. See `$E/connect-diagnostic-results.json` and
`$E/connect-trace-results.json`. Coordinator needs to bound the genuinely pending
connect/poll path and add that native/full-chain regression.

Cancellation/recovery continued independently after the strict failure: **exit
0**, six requests closed by clients after 50 ms, maximum **two** proxy sessions,
all sessions closed by **601.099 ms**, zero retained readiness sessions in
`pg_stat_activity`; twenty subsequent ready responses were 200. FPM did not
observe those disconnects immediately: the hard SQL deadline bounded cleanup.
Evidence: `readiness-chain-complete/cancellation-remaining.json`. No whole-F1
acceptance is claimed.

### 2. Recovery readiness fixture is incompatible with the new session boundary

`backend-yii2/tests/recovery.php:64–78` failed at line **76**, expected 503, got
200; **eight** recovery tests passed. It alters tables inside its PDO
transaction and private recovery schema, then invokes readiness. The new probe
opens a separate native session with explicit startup options, so it observes the
healthy default schema rather than those private uncommitted faults. The new
dedicated readiness suite's committed/schema/role faults did pass.

Coordinator must preserve meaningful missing-schema assertions using the new
independent-session fixture boundary, then rerun affected checks and full canonical
acceptance. This task neither edited that test nor skipped it. Evidence:
`$E/acceptance/backend-fixes.log:354–388` and `canonical-acceptance.log`.

## Supplemental checks after the canonical failure

| Command / evidence label | Exit / result |
| --- | --- |
| `make test-database-roles` (`database-roles-make`) | **0**; fresh owned project `shopkmp-round6-roles-522240f3-e2e`, actual TLS PG role suite 10/10 + lockout 3/3; legacy round6 name is enforced by this existing tool |
| `make test-trust-live ENV_FILE=.env.example E2E_PROJECT=$P` | **0**; seven groups, real IPv4/IPv6, spoofing/budgets, webhook transport, FastCGI peer denial and both Go replicas |
| Verification-overlay `php tests/recovery-scheduling.php` with its existing provider fixtures and mounts | **0**, **14/14**; real scheduler and **5.019 / 5.006 s** transaction-timeout observations |
| `python3 scripts/runtime_checks.py --env .env.example --project $P --url http://127.0.0.1:58207 --shutdown` | **0**, real probes, trusted-hop/counter correlation, histories, private ports, worker readiness, every graceful exit 0 and successful ready restart |
| `python3 scripts/test_search_privileges_live.py --run --parent "$E" --subnet 10.252.228.0/24 --php-image $P-yii2 --postgres-image $P-postgres --opensearch-image $P-opensearch` | **0**, 05:34:30–05:35:30Z; fresh real TLS OpenSearch, exact current runtime images |
| `env -u BACKEND_DISPOSABLE_NETWORKS python3 -m unittest discover -s scripts -p 'test_*.py' -v` with `PHP_TEST_IMAGE=shopkmp-round7-final-php` | **0**, **56/56**, including 28 real TLS protocol scenarios |
| `scripts/test_infrastructure.py --production-env <owned-private-test.env>` | **0**, merged dev/prod boundaries and seven rejected unsafe inputs |
| `scripts/test_deployment_commands.py --production-env <owned-private-test.env>` | **0**, **5/5** actual wrapper/Make/config checks with intercepted lifecycle effects |
| `python3 "$T/round7_r9_native.py"` | **0**, both opt-in Go native tests with race enabled, zero skips; fresh current-image/real-PG export and current OpenAPI conversion; summary sizes 99–279,700 bytes, full 20×200 page 4,725,962 bytes |

The search action run used project `shopkmp-round7-search-a914451f-e2e`, private
PKI directory `$E/shopkmp-task4-pki-es9typig`. It verified fresh explicit bootstrap,
repeat upgrade without security-document rewrites, runtime/reconcile/indexer real
document writes/search/deletes, denied security/cluster/foreign/lifecycle actions,
legacy wildcard rejection, managed policy rollback, credential revocation/rollback,
independent operator mTLS, priority-1000 conflicting-template override, full-source
catalog writes, reconcile/stage, and unchanged policy after ordinary node restart.
Memory sample: **585.2 MiB / 768 MiB**. Its own containers/network were removed;
both volumes and private evidence were retained.

Execution corrections remain in private logs: the first Python discovery inherited
the acceptance-only network opt-in and failed three synthetic-project guards;
normal discovery above passed without changing tests. Initial infrastructure/
deployment commands used a nonexistent env filename; corrected `test.env` passed.
F1 helper setup initially hit a Docker nested-bind error, then a Desktop shared-file
atomic-rename visibility error, then sampled an unrelated periodic health probe as
an old active session. Only task-owned fixture plumbing was corrected; the final
strict connect-budget failure remains red. No application changes resulted.

## Current exact-artifact security gate

```sh
python3 scripts/backend_security.py --env .env.example --project "$P" \
  --platform linux/arm64 --output "$E/security" --cache "$E/security-cache" \
  --receipt "$E/acceptance-artifacts.json"
```

**Exit 1**, **05:37:36–05:41:59Z**. This run freshly scanned all seven exact runtime
images, emitted seven CycloneDX SBOMs, extracted/scanned the three actual delivered
Go binaries, scanned both source modules, audited production Composer dependencies,
and verified scanner DB content stability. It was allowed to continue independent
scans after rejecting the failed functional receipt; security-after-successful-
acceptance remains blocked by the functional failures above.

- Functional receipt prerequisite: **FAILED**, `tested:false`.
- Production Composer audit and **six** image policies: **PASSED**.
- OpenSearch **2.19.7**: **19 HIGH instances**, **12 MEDIUM / 1 LOW** also reported,
  no dispositions. Exact paths and identifiers are in `security/opensearch.policy.json`.
- Go source **2** and delivered-binary **3** raw scans: zero findings, but **all
  five policy checks FAILED** on advisory DB freshness. govulncheck **v1.9.0**
  returned DB timestamp **2026-10-27T21:16:18Z**, exceeding the unchanged **72 h**
  limit. Actual toolchains: BFF **go1.27.3**, ops/migrator/gosu **go1.26.10**.
- Trivy **0.76.1**, pinned digest in `security/provenance.json`; freshly downloaded
  vulnerability DB UpdatedAt **2026-11-01T01:13:06.496727497Z**, Java DB UpdatedAt
  **2026-11-01T00:58:32.801018394Z**. Both current and unchanged throughout scanning.

| Current HIGH advisory | Package/version | Instances |
| --- | --- | ---: |
| CVE-2026-106451 | `at.yawk.lz4:lz4-java` 1.11.1 | 1 |
| CVE-2026-89407 / CVE-2026-89425 | Jackson core 2.18.9 | 2 + 2 |
| CVE-2026-68497 / CVE-2026-91776 / CVE-2026-91777 | Jackson databind 2.18.9 | 3 + 3 + 3 |
| CVE-2026-102554 | Guava 32.1.3-jre / 33.4.0-jre | 3 |
| CVE-2026-108106 / CVE-2026-93451 | snappy-java 1.1.10.7 | 1 + 1 |

### Exact image IDs

All are **linux/arm64**. OCI index IDs below are distinct from per-platform image
config digests; security evidence records both, plus saved-archive and binary hashes.

| Service(s) | Immutable local image/index ID |
| --- | --- |
| yii2, yii-migrate, reindex, maintenance, reconcile, payment-recovery | `sha256:2bf9a23c225432d0e7ca13ed6157f682df0a2d2778d72130d4fcdbea7930c007` |
| sdui | `sha256:bfacbb15b1ec8a9c56e896073b508e7119eb8f13ee30bb5278b79c1e79babdb1` |
| migrate | `sha256:69ea146044d90bee221fd2a34d53c6c87f2a6439b9ffc349790071598330923a` |
| postgres | `sha256:c03b211abd4c95e5ae440c859ea6621ea6dfa9c7bde8d39fc5840f0b6f77ed6f` |
| opensearch | `sha256:e50ad6ec1ee2545028c2a5e1b03a02b1d854d977dff4065a5c36935b85b22521` |
| edge, yii2-http | `sha256:dede8895648b461178c3bc604f7eed4423efe5220b2c4d839f5eb3d34ac3f8f7` |

Scanned PHP config digest:
`sha256:ef883f56f7abe635c7a2eee699956a2d8b52e20d886878c55d98c33da776222d`.
Scanned OpenSearch config digest:
`sha256:a908737e056e65730f0509b730885b5009b6739ead3b6d089f8125d0cd28a637`.
Full artifacts: `$E/security/artifacts.json`, `artifact-observations.json`, raw
image reports, `*.sbom.cdx.json`, source/binary scanner streams and command receipts.

## Preservation and resource handoff

Before verification, exact bytes of the two existing canonical-output destinations
were saved privately. Generated results were captured as
`$E/generated-local-auth-runtime.json` / `generated-integration.json`. Originals
were restored using the dedicated `apply_patch` tool and compared byte-for-byte:

| Existing user file | Original/restored SHA-256 |
| --- | --- |
| `deploy/evidence/local-auth-runtime.json` | `0274421d08640ab47dc06cf40a64f1ba4fe1ce2072a359f49888a6be4a3a0fd3` |
| `deploy/evidence/integration.json` | `0246e219aa58b802f5531255c2c0efa25ecab5ef6c42044c57294489bb9380ed` |

The preexisting round-5 artifact receipt was untouched. No private runtime/config/
scanner blob is added to Git. Final owned-resource inventory and shutdown results
are recorded privately in `resources-final.json` / `stop-task-owned.*`. Final
owned canonical stop exited **0** at **05:48:48Z**; all owned canonical/F1
containers are stopped, with containers/networks retained. The seven application/
worker graceful-stop codes were all 0. Final OpenSearch SIGTERM exit was **143**;
the three CLI provider fixtures and task-owned fault peer had force-stop **137**
codes, separately from the passing application/worker drain gate.

Retained canonical volumes are `shopkmp-round7-acceptance-e2e_postgres_data` and
`shopkmp-round7-acceptance-e2e_search_data`; the former includes `readiness_test`,
`f1_chain` and `f1_chain_final`. Both SQL-role volumes and both TLS-search volumes
are retained. Role/search/trust fixtures removed only their own containers/
networks. Final read-only checks confirmed **all 62 initially running foreign
containers still running**, source snapshot still identical, original evidence
bytes restored, an empty Git index, and `git diff --check` exit **0**.

Coordinator follow-through: fix the pending-connect bound and recovery fixture,
rebuild/retest the changed source binding, obtain a genuine successful canonical
receipt, then rerun exact-artifact security against that receipt with current
advisory data. Platform verification belongs to the separate assigned task.
