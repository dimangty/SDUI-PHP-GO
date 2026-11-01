# Round 7 — documentation-binding continuation, 2026-11-01

## Decision and current binding

**Fresh complete source-bound canonical backend acceptance: PASSED. Configured
PHPStan/PHPUnit: PASSED. Exact-image security: FAILED. Release remains BLOCKED.**
No new backend source defect was observed. Existing receipts and other reports
were preserved; this continuation changes only this report and new task-owned
temporary helpers/evidence.

```sh
T=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode
E="$T/round7-continuation-verification"
P=shopkmp-round7-acceptance-e2e
R="$E/artifacts.json"
```

Receipt: **`$R`**, **`tested:true`**, build/test exits **0/0**, **503** bound inputs.
Current compact sorted source-map SHA-256:

```text
c3db40d2b43556578453aa0ccd3f525c2aa92201852f2ec184e81540bb9e9242
```

The source-map comparison against
`$T/round7-final-fixes/artifacts.json` found **exactly one changed input**:
`backend-yii2/dev-tools/README.md`; **zero added/removed inputs**. Previous map:
`023f7403f3dbc97165e3a553eef55ab43473d107a249b1bf815ecb8328769bf3`.
README bytes changed from SHA-256
`f749c08108b4af19f46c35bf3bb45022fe8ba68196ef63b5db8cefcc12fb1eb1`
to `e6f59ad8c95f34b82a17802aca18c868e89077802ed8fb2d2bd80c05337a5eb1`.
Every other bound runtime, Go, deployment, test, gate and dependency input is
byte-identical to that previous receipt. HEAD remains
`160d2a5a535791c75c36c2f70a90515153339e87`; the work is uncommitted.

Fingerprint definition: SHA-256 of UTF-8 `json.dumps(sources, sort_keys=True,
separators=(',', ':'))`. Top-level reports are outside this canonical source scope.
`source-preflight.json` records the complete comparison and selected-source hashes.

## README scope and real source-bound tools

The README's numbered analysis list was parsed and compared with the actual
`phpstan.neon` `paths` block: **exactly seven distinct members, no extra/missing
or duplicate entries**. The configured level is **max**, PHP target **80300**.
Both lists contain:

1. `infrastructure/ReadinessBudget.php`
2. `infrastructure/ReadinessPolicy.php`
3. `infrastructure/CoreReadinessProbe.php`
4. `infrastructure/ReadinessSql.php`
5. `infrastructure/ReadinessAdmission.php`
6. `application/PaymentService.php`
7. `domain/Ids.php`

All seven actual source hashes were recorded, not just file existence. Actual
tools commands, from repository root:

```sh
make php-tools-build \
  PHP_TOOLS_APPLICATION_IMAGE=shopkmp-round7-continuation-tools-application \
  PHP_TOOLS_IMAGE=shopkmp-round7-continuation-tools
docker run --rm --network none shopkmp-round7-continuation-tools composer analyse
docker run --rm --network none shopkmp-round7-continuation-tools composer test
```

Every command **exit 0**. The normal application Dockerfile and named tools
contexts were actually rebuilt from this checkout. Current source bytes matched
the new fingerprint before and after the builds/tests.

- PHPStan **2.3.4**, configured seven-file scope, max/PHP 8.3 target: **zero errors**.
- PHPUnit **13.4.5 / PHP 8.5.12**: **19 tests, 24 assertions**, no failures,
  warnings or skips; existing strict configuration unchanged.
- Tools application image:
  `sha256:0f3a0a51b891d1fecb0af5ea346a35b999acc2680dfde47a30e633a182f7c0e5`.
- Tools image:
  `sha256:deb29859b104f542af27d8c6abdb8a17a9701ed91137a59245fd43b5500182e0`.

Evidence: `tools-build.*`, `phpstan.*`, `phpunit.*`, `tools-source-binding.json`.

## Fresh full canonical build and acceptance

Commands used the retained owned stack's saved private environment
`$T/round7-verification/environment.json`: explicit, previously overlap-checked
ingress/FPM/application/data/payment **10.252.220–224.0/24**, loopback edge **58207**.
Resolved credentials and full Compose configuration were not emitted.

```sh
make integration-up E2E_PROJECT="$P" E2E_PORT=58207 \
  ARTIFACT_RECEIPT="$R" VERIFY_EVIDENCE_DIR="$E/canonical"
make integration-check E2E_PROJECT="$P" E2E_PORT=58207 \
  ARTIFACT_RECEIPT="$R" VERIFY_EVIDENCE_DIR="$E/canonical"
```

These executed the real `backend_artifacts.py --phase build/test` wrappers and
complete `scripts/verify.py --disposable` with the new receipt/evidence paths.
Build/seed/reconcile **exit 0**, **08:08:07–08:08:50Z**. Full canonical test
**exit 0**, **08:08:50–08:17:02Z**. Independent post-canonical and post-security
`verify_receipt(..., require_tested=True)` checks matched current sources and
**all 12** actual required healthy-runtime/successful-init image/container bindings.

| Actually executed canonical phase | Result |
| --- | --- |
| Route matrix/runtime graph | PASS; unchanged 54 operations, eight screens, six commands |
| Packaged PHP runtime / Docker scheduler | PASS, **5/5 / 18/18**, timing scale 1 |
| Seed preservation / SQL-to-search reconcile / edge CARD | PASS |
| BFF race/live/vet / explicit round-3 live edge / ops test/vet | PASS |
| Full PHP/search/native `composer test:all` | PASS, real PostgreSQL/OpenSearch and packaged PHP/vendor |
| Policy / native readiness / demo identity | **6/6 / 16/16 / 15/15**, no guarded-suite skip |
| Max-order HTTP / production-like TLS SQL-role matrix | PASS; role matrix **10/10**, lockout **3/3** |
| Focused auth/payment/UUID/recovery suites | PASS; payments **113/113**, recovery **9/9**, scheduling **14/14** |
| Worker-ready restoration / runtime trusted-hop/private-port/history/drain/restart | PASS |
| Mandatory F2 `service-privileges` | **PASS** |
| Mandatory F2 `service-command-config` | **PASS** |
| Mandatory F2 `search-schema-protocol` | **PASS** |
| Mandatory F2 real TLS `search-privileges-live` | **PASS** |

All four mandatory F2 gates executed after runtime verification before the new
receipt became tested. Their invocation, immutable image arguments and actual
results are in `canonical-test.log` and `canonical/*.log`. The TLS search substack
was uniquely owned `shopkmp-round7-search-0debe823-e2e`; its containers/network were
removed by the unchanged runner, and both volumes retained. Existing opt-in test
conditions, application budgets and assertions were unchanged. No standard
canonical phase was skipped or replaced with an earlier PASS.

## Exact image comparison with the previous 503-input receipt

The new receipt compares **all 12** service bindings to the previous receipt:
**two identical OCI IDs** (both Nginx services), **ten new OCI IDs** after rebuilding.
Source/runtime dependency inputs are unchanged except the tooling README.

| Service(s) | Current immutable local image/index ID, linux/arm64 |
| --- | --- |
| yii2, yii-migrate, reindex, maintenance, reconcile, payment-recovery | `sha256:ebffa02858e1fc9a641025d00fe425da0c2ca9da39dbdf1dc219de71c87d4906` |
| sdui | `sha256:329c4355667400a86106f33a06f82dd9e396b1552f648aa446f19e7e78a6e351` |
| migrate | `sha256:3a95768a72a4904807f76130149a45ccc5be2352a60cb07e9a52b4b675cfd9a7` |
| postgres | `sha256:5337f02c36b6500c5338c202fc292bbd046e1e1827dac79513822378d2e3d62f` |
| opensearch | `sha256:a0c6a8a1ba5d151f9473750cd583cd91f329932dd63e3c019fb6b28dba51b346` |
| edge, yii2-http | `sha256:dede8895648b461178c3bc604f7eed4423efe5220b2c4d839f5eb3d34ac3f8f7` |

- Actual old/new scanner package inventories are identical for **all seven runtime
  service scans**; no runtime package version changed.
- Delivered BFF, migrator and PostgreSQL `gosu` binaries are **byte-identical** to
  the prior accepted stage; exact SHA-256 comparisons are in `summary.json`.
- Six of seven service config digests are identical. PHP's config digest changed
  from **60d2031d…8031** to
  **`893e81d4cd11397586d4616a002fc43c8f7eeb0bf52b43758cbec76c28acad45`**.
  Retained old/current Trivy config metadata proves that only image label
  **`com.docker.compose.service`** differs: architecture, OS, rootfs layer DiffIDs,
  creation time and all other runtime configuration fields are identical. This is
  build metadata variation, not a package or application-byte change.
- An extra attempt to inspect the old PHP OCI ID locally exited **1** because that
  untagged old index no longer resolves locally. It was not pulled or rebuilt.
  The retained actual old/new scan metadata comparison exited **0** and is the
  evidence used above (`php-config-metadata-comparison.json`).

Full old/current IDs, config/archive hashes, binary comparisons and package counts:
`post-security-binding.json`, `summary.json`, `security/artifacts.json`.

## Fresh exact-artifact security — still FAILED

```sh
python3 scripts/backend_security.py --env .env.example --project "$P" \
  --platform linux/arm64 --output "$E/security" \
  --cache "$T/round7-verification/security-cache" --receipt "$R"
```

**Exit 1**, **08:17:04–08:18:12Z**, after current successful canonical acceptance.
The artifact/source/functional prerequisite passed with the new `tested:true`
receipt. All seven runtime image scans and nonempty CycloneDX SBOMs, both Go
source scans, three delivered-binary scans, production Composer audit and scanner
DB stability checks actually executed. Current reports were written only under
`$E/security`; no historical scan was promoted.

| Current policy | Actual result |
| --- | --- |
| Production Composer audit | PASS |
| PHP/BFF/migrator/PostgreSQL/edge/internal-Nginx image policies | **Six PASS** |
| OpenSearch 2.19.7 | **FAIL: 19 HIGH instances**, also 12 MEDIUM / 1 LOW; no dispositions |
| BFF and ops source govulncheck | **Two freshness FAILs**, zero raw findings |
| BFF/migrator/gosu delivered-binary govulncheck | **Three freshness FAILs**, zero raw findings |
| Trivy vulnerability/Java DB versions, freshness/content stability | PASS |

All five current govulncheck **v1.9.0** configurations report authoritative DB
timestamp **2026-10-27T21:16:18Z**, older than the unchanged **72 h** policy.
Actual toolchains are BFF **go1.27.3**, ops/migrator/gosu **go1.26.10**. Zero raw
findings do not make stale-data checks PASS; no freshness waiver was applied.

Pinned Trivy **0.76.1** image:
`sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c`.
Actual refreshed/checked DB UpdatedAt values: vulnerability
**2026-11-01T01:13:06.496727497Z**, Java
**2026-11-01T00:58:32.801018394Z**. Both content hashes stayed stable while scanning.
The HIGH instances and exact JAR paths are in `security/opensearch.policy.json`:
lz4-java CVE-2026-106451; Jackson core CVE-2026-89407/89425; databind
CVE-2026-68497/91776/91777; Guava CVE-2026-102554; snappy-java CVE-2026-108106/93451.
Duplicate bundled instances total **19**.

OpenSearch config digest remains
`sha256:a908737e056e65730f0509b730885b5009b6739ead3b6d089f8125d0cd28a637`.
The scanner records all current OCI/config/archive digests, seven SBOM hashes and
raw policy inputs. [SECURITY_FOLLOWUP.md](SECURITY_FOLLOWUP.md) separately records
the earlier official 2.19.8 unavailability, unchanged/rejected 3.9 candidate and
authoritative stale Go DB retrieval. No vendor remediation, policy change or JAR
substitution was introduced by this continuation.

## Secret scan, completed document and conditional contract hashes

Gitleaks **v8.30.1**, default rules, full `--redact=100`, immutable scanner
`sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f`:
changed tracked contents **28 files**, untracked task source/docs **61 files**,
and cumulative tracked diff against HEAD scanned into new private snapshots.
Only the two explicitly preserved preexisting runtime JSON inputs were omitted
from the task-source snapshot; no allowlist/rule change was made.

Raw tracked/untracked/diff exits **1/0/1**, findings **1/0/1**. The two findings
retain their raw nonzero exits: literal PEM-header checks in
`scripts/validate_production.py:101–155`, and a removed Compose curl credential-
variable reference in the diff. The validator bytes, complete tracked diff and
finding identities were compared exactly with their previously reviewed contexts.
Context review **exit 0**, **zero unresolved findings**, no actual credential leak
identified. The completed version of this report receives a separate document-only
scan; its exact bytes/hash/exit are recorded under `secrets/`.

Conditional immutable-input check **exit 0**: all **55** contract/schema/golden/
migration inputs match the earlier independently checked manifest and HEAD;
all **six** Composer/Go module locks match that prior manifest and this current
receipt. The one changed-input preflight proves no code, contract, migration,
Go/toolchain requirement or dependency lock changed. Full per-file byte hashes:
`contract-lock-hashes.json`; prior checksums are not mislabeled as newly executed
migrations or functional tests.

## Existing platform results — no repeat execution or new mobile proof

No platform test was repeated. Relevant original stage bindings are preserved:

- **Android installed test**,
  [ANDROID_INSTALLED_RESULTS.md](ANDROID_INSTALLED_RESULTS.md): executed against
  source fingerprint **023f7403…9bf3**, **07:56:25–07:57:28Z**; one test, one failure,
  zero skips. The installed UI/Chrome payment issued **one GET and one POST, both
  200**, reaching **CAPTURED/PAID**. The full journey then failed verifier
  `GET /orders` after final logout: expected 200, actual **401**, consistent with
  the unchanged account-wide session revocation contract. This does not justify
  a backend change or a journey PASS. The report also discloses the emulator's
  effective 2048 MiB RAM exceeding its requested bound.
- **Native iOS Safari**, prior **97875b…46c** source stage,
  **06:32:25–06:37:31Z**, exit **65**, `safari_payment_completed`: two demo GETs,
  zero POSTs, CREATED/AUTHORIZED. Test SHA-256
  `6e53a08f4d6e8674dd7b4a662689110efe5d78d8fd4d732e6c7b81d587d1668d`;
  safe summary SHA-256
  `213549d1cd4e0475a105ee0a3f9a68d1c17d830aeb8455ca603098ac837b90ab`.
- Existing iOS common keychain failure and missing Android release URL remain
  separately recorded in [PLATFORM_RESULTS.md](PLATFORM_RESULTS.md).

`PaymentService.php`, `ApiKernel.php` and all other bound runtime inputs are
unchanged from the previous 503-input receipt. The new **c3db40…9242** README
binding is not represented as a new whole-source mobile execution. Client/UI/test
expectations were not changed to manufacture a PASS.

## Preservation, ownership and shutdown

Only `CONTINUATION_RESULTS.md` was edited in the repository by this task. Old
receipts, reports and private evidence were preserved. Generated canonical
`local-auth-runtime.json` / `integration.json` were saved privately as
`generated-*.json`, then the original repository destinations were restored with
dedicated `apply_patch` moves and compared **byte-for-byte**:

| Existing evidence | Restored original SHA-256 |
| --- | --- |
| `deploy/evidence/local-auth-runtime.json` | `0274421d08640ab47dc06cf40a64f1ba4fe1ce2072a359f49888a6be4a3a0fd3` |
| `deploy/evidence/integration.json` | `0246e219aa58b802f5531255c2c0efa25ecab5ef6c42044c57294489bb9380ed` |

Post-security current receipt/source/all-12-healthy-image revalidation **exit 0**.
Owned-stack stop **exit 0**, **08:21:47–08:21:48Z**. All owned canonical and live
subharness containers are stopped/removed as appropriate; all volumes and private
evidence retained. Application/worker/SQL/init exits 0, OpenSearch normal SIGTERM
143; raw CLI fixture force-stop 137 is separately inventoried.

At the original 08:21 checkpoint, the **62-container** initial inventory was still
running. A later coordinator audit corrected its ownership classification: that
inventory included **two older task-owned demo fixtures**, not 62 foreign services.
`shopkmp-round7-demo-php` had naturally completed its 14,400-second sleep at
**08:25:22Z**, exit **0**, `OOMKilled:false`. The coordinator verified both fixtures'
`shopkmp.owner=round7-demo` labels and stopped only the still-running owned
`shopkmp-round7-demo-postgres`, retaining its volume. Both now have exit **0**.

The refreshed `resources-final.json` confirms **60 actual foreign containers, all
60 still running**, all owned fixtures stopped, current source bytes matching the
new successful receipt, original evidence bytes intact and an empty index. No
foreign service was restarted to restore a misleading inventory count. The native
Android emulator is also stopped. No staging, commit, push, production operation,
foreign lifecycle command or volume/index deletion occurred.

**Current backend functional/tooling gates are green; publication remains blocked
by the freshly reproduced security policy failures and the unresolved, explicitly
stage-bound platform gates.**
