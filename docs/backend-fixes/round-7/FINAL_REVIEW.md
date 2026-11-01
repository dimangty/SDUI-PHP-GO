# Backend V8 — final review and publication boundary

**Backend functional acceptance: PASSED. Source-review material findings: CLOSED.
Artifact security: FAILED. Platform: FAILED / partly NOT RUN.
Local commit explicitly authorized by the user's subsequent "Make commit".
Push/publication: NOT RUN, blocked by the mandatory gates.**

## What changed

### F1 — core readiness

The dispatcher now delegates to an explicit readiness policy, budget and required
SQL probe. Configured optional search is truthfully **unknown**, never measured on
this path. Liveness stays I/O-free; required DB/schema/identity/unsafe-role/runtime
configuration failures remain 503; `catalog-cards-v1` negotiation is preserved.

The two-second BFF deadline is retained. PHP has a 700ms allocation, including
100ms cleanup, and a shared 600ms active deadline across bounded resolver,
connect/TLS/send/read work. PDO remains the business SQL implementation. Native
pgsql/sockets are used only for readiness; READINESS contains the ADR and real
blocking-API regressions, including the pending-connect defect discovered by
full-chain verification and corrected before final acceptance.

Two precreated, read-only local lock files bound actual work across FPM processes.
Their leases outlive cancelled HTTP callers until native cleanup finishes. During
80 rapid 50ms cancel/replacements, peak active connections/real SQL sessions were
**2/2**, retained sessions **0**, liveness approximately **2.6–2.8ms**, healthy
recovery **20/20**. Saturation returns 503 without initiating dependency I/O.

### F2 — external service privileges

Both base and production Compose now inject credentials by capability. Runtime
has exact selected-index metadata/search/document write/delete rights; reconcile
has existing-index reconciliation; explicit indexer has product-namespace
create/mapping/refresh/scroll rights. None has security/cluster administration or
foreign-index access. Runtime catalog writes remain functional.

Recovery receives provider credentials without search/webhook secrets. Migration,
maintenance and identity operator receive no external-service credentials. Go
receives no database/search/payment credentials. The independent operator key is
absent from ordinary application mounts and is not required for ordinary lifecycle
validation. Fresh security initialization and targeted existing-index upgrade are
explicit operator processes. Existing security data is never reset on node start.

Real TLS OpenSearch tests exercised catalog create/update/delete/search,
bootstrap/reconcile/stage, effective-role denials, conflicting template handling,
repeat provisioning, wildcard-mapping rejection, managed-policy rollback,
credential revocation/rollback and independent operator access. Security/cluster/
foreign-index and runtime lifecycle operations returned **403**; revoked credentials
returned **401**. Semantic acknowledgements and readback are required; unknown
mutation outcomes do not trigger retries. All four privilege runners are mandatory
in canonical acceptance, with immutable tested image IDs.

### F3 — canonical demo payment identity

Confirm uses the verified repository payment UUID for the response, demo path and
HMAC. `Ids::canonical()` is reused only for UUIDs; token/signature/provider bytes
are not normalized. Ownership and durable/idempotent workflow are preserved.
Native DB and real Yii HTTP regressions cover lower/upper/mixed cases, repeat
confirm with no extra operation, explicit GET/POST completion, tampering, ownership
and production/provider-configured demo denial: **15/15**.

## Compatibility and dependency decisions

- Recounted registry/policies: **54 API operations / 8 SDUI screens / 6 commands**.
  Route matrix, OpenAPI, schema/golden contracts and 32 historical migration
  checksums were verified unchanged. No CMP/platform source was edited.
- PHP retains business/PostgreSQL authority and local Argon2id/opaque-session
  authorization. Go retains application-owned models, ports and typed SDUI.
- Existing Go 1.27.3, PHP 8.5.12, Composer 2.11.1, Yii2 2.0.55, gateway 2.33.0,
  pgx 5.12.0, OTel 1.47.0 and JSON Schema 6.0.3 were rechecked and retained.
  No existing version is claimed as this task's upgrade.
- Added native `ext-pgsql` and `ext-sockets`; regenerated the root Composer lock
  platform/content metadata without changing its package versions.
- Separate dev-tools Composer lock pins PHPStan **2.3.4** and PHPUnit **13.4.5**.
  Runtime PHP minimum remains **>=8.3**, tools minimum **>=8.4.1**. Max-level
  analysis targets PHP 8.3, with no new-error baseline or suppressed rules.
- Small layer and forbidden-configuration guards are required in backend checks.

## Reviews actually performed

| Review | Actual result and disposition |
| --- | --- |
| F2 fresh-context source review, task `ses_e6aa3bb65ffe4mYApX4V7Eb60T` | Three Important: ordinary lifecycle required offline admin key; competing template defeated writer schema; HTTP 200 discarded semantic acknowledgements. Two Minor: unsafe diagnostic categorization and historical test-image dependency. All corrected with RED→GREEN and real TLS tests; scoped re-review approved. |
| Whole-task source review, task `ses_e6a28c234ffeMArGkfeARjPsk3` | Two Important: HTTP cancellation freed admission before FPM work ended; privilege regressions were not required by canonical/CI. One historical-handoff documentation minor. One focused fix wave added actual cross-process admission and mandatory deterministic/live gates. |
| Scoped final fix review, same review task | Both Important and original documentation finding ADDRESSED; no new Critical/Important fix-wave issue. Read-only reviewer independently verified all 503 source inputs and 12 image bindings, not broad test execution. Scoped source approval granted; release/push approval withheld. |

**Documentation minor closed in continuation:** the dev-tools README now lists
all seven configured PHPStan paths, including ReadinessSql and ReadinessAdmission.
The lists were compared exactly; the fresh complete canonical run and source-built
tools passed against the new documentation-bound receipt. No runtime/dependency
input changed. See CONTINUATION_RESULTS.md for the exact old/new binding.

Reviewer declined-to-judge items were adjudicated as follows: native Safari and
keychain root causes and Android installed/configuration behavior are outside the
backend edit scope; their failed/unrun gates remain binding. No causal link from
the demo UUID defect to Safari is established. Production rollout/rotation/recovery
were not authorized or executed. Unexecuted runtime/architecture combinations are
not certified by static compatibility or emulation.

## Current evidence and gate status

Current functional receipt:
`round7-continuation-verification/artifacts.json`, **tested:true**, build/test exits **0/0**,
**503** source inputs and **12** matching actual service image bindings.

```text
c3db40d2b43556578453aa0ccd3f525c2aa92201852f2ec184e81540bb9e9242
```

Private evidence parent:
`/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/`.
Detailed commands/exits/current image digests are in CONTINUATION_RESULTS;
TEST_RESULTS and FINAL_FIXES retain the earlier execution/review records.

| Gate | Status |
| --- | --- |
| Complete source-bound backend / real Go→Nginx/FPM→PHP→PG/search / worker shutdown | **PASS**; includes final mandatory privilege checks, readiness 16/16, policy 6/6, demo 15/15, payments 113/113, recovery 9/9 and scheduling 14/14 |
| Auth / repeat lockout / concurrent sessions / real SQL role upgrade and denials | **PASS**, required native suites retained |
| Go full test/race/vet/build/lint; contracts; PHP validate/platform/custom/lint | **PASS**, exact commands in TEST_RESULTS/tooling evidence |
| Final source-built PHPStan / PHPUnit | **PASS**, zero errors / 19 tests, 24 assertions |
| Final secret review | **No unresolved secret findings**; raw scanner matches and nonsecret adjudications retained, not relabelled raw PASS |
| Final seven images, SBOM, source and delivered-binary security | **FAIL**, fresh exact artifacts: **19 OpenSearch HIGH** and **five Go advisory freshness failures**; six other image policies and Composer audit pass |
| Authoritative Go advisory DB | **FAIL**, timestamp `2026-10-27T21:16:18Z`, unchanged 72h limit; zero raw findings is not freshness PASS |
| Unchanged native Safari/CARD | **FAIL**, exit65, one failure/no skips, eight milestones through checkout; 2 demo GET/0 POST, CREATED/AUTHORIZED. Executed against the earlier 498-input stage; not asserted as new 503-input platform proof. |
| Unchanged client unit/coverage/lint/debug build and iOS auth/cart/link/harness compile | **PASS** as recorded in PLATFORM_RESULTS |
| iOS common native gate | **FAIL**, keychain expected -25300, actual -25291 |
| Android release / installed journey | **FAIL** missing release URL / **FAIL**, actually executed one test, one failure, zero skips: UI payment CAPTURED/PAID, then post-logout verifier GET /orders returned 401 under preserved account-wide revocation |
| Native amd64 publication/security CI | **NOT RUN** in this local session; arm64 results are not a substitute |
| Production rollout / real credential rotation | **NOT RUN**, not authorized |
| Local commit | Explicitly authorized by the user's later **"Make commit"**; the enclosing Git commit records the result, without release approval |
| Push/publication | **NOT RUN**, blocked by the same security/platform gates |

The Android installed run used the preceding `023f7403…9bf3` source stage. All
393 client files and runtime inputs are unchanged; only the tooling README differs
in the current receipt. It is not relabelled a new whole-source platform execution.
The emulator raised requested 1536 MiB RAM to 2048 MiB; it is stopped, and no
memory-bound acceptance is claimed. ANDROID_INSTALLED_RESULTS records the deviation.

Security follow-up found no available supported 2.19.8 artifact; latest 3.x still
resolves to the previously rejected candidate. Official advisory DB retrieval and
the fresh current-artifact scans still fail the unchanged security policy.
No unsupported JAR swap, weakened account-wide logout or platform test change was
used to manufacture a passing gate.

## Git and safe handoff

Branch `refactor/backend-production-hardening`; remote `origin` is the existing
GitHub repository `https://github.com/dimangty/SDUI-PHP-GO.git`.
At baseline and final target-ref lookup, both local HEAD and
`refs/heads/refactor/backend-production-hardening` were:
`160d2a5a535791c75c36c2f70a90515153339e87`.

Verification originally ended with task changes **unstaged and uncommitted** and
an empty index. The subsequent explicit **"Make commit"** authorizes staging and
committing only the reviewed task sources/tests/locks/docs locally. Two
preexisting untracked receipts were preserved byte-for-byte and remain excluded;
no vendor, binaries,
private PKI, environment files, dumps or credential-bearing evidence were added.
Only owned fixtures were managed and stopped; named volumes/evidence retained.
Final ownership audit corrected the earlier 62-container inventory: two entries
were this task's older demo fixtures. The PHP fixture naturally exited after its
four-hour sleep; the remaining owned demo PostgreSQL was explicitly stopped. All
**60 actual foreign containers** remain running. See CONTINUATION_RESULTS.
No reset/clean/stash/rebase, foreign-stack stop, production operation, merge/tag/
release or force push occurred.

The local-commit request does not authorize a push. To continue publication:
resolve the vendor-image/advisory freshness and required platform/native-architecture
gates through their owners, then revalidate intended source/artifacts and the remote
ref before an explicit nonforce feature-branch push. No production-readiness claim
follows from functional PASS or from a local commit.

## Local-commit preflight

Under the subsequent explicit **"Make commit"** instruction, the complete intended
diff and new-file inventory were reviewed and **89 task files** selected explicitly.
The two preexisting untracked receipts remain outside the index. Contract/layer/
runtime-graph guards and whitespace checks pass; the 503-input accepted source map
is unchanged. No client/migration, vendor, binary, private PKI or populated env file
is staged.

Pinned Gitleaks v8.30.1 scanned the actual cumulative staged patch with full
redaction. Its raw result is **one finding**, `curl-auth-user`, on the **removed**
production healthcheck's credential-variable interpolation, not a literal password.
That exact removed line was inspected; there are **zero unresolved secret findings**.
The raw scanner finding is retained in private precommit evidence rather than
mislabelled a raw scanner PASS. All failed/unrun release gates above remain binding.
