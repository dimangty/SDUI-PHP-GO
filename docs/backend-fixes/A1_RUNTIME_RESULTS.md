# A1 runtime remediation — 2026-10-28

**Release/push gate: BLOCKED.** Supported runtime changes are built and wired into
the root deployment. PHP and nginx now scan clean; PostgreSQL, BFF and migration
images have no raw High/Critical findings. Keycloak/OpenSearch still have
unresolved vendor High findings. An additional OpenSearch bundled-JDK Critical
outside Trivy's coverage has a verified official OpenJDK truststore mitigation,
detailed below; the remaining High findings still block publication.

This report supersedes the runtime inventories in the October 26–27 checkpoint
reports for the artifacts below. Earlier reports, failed attempts and raw scan
exits are retained. Detailed component investigations:
[PHP](PHP_RUNTIME_REMEDIATION.md), [Java](JAVA_RUNTIME_REMEDIATION.md).

## Scope and provenance

Private evidence directory, abbreviated **E**:
`/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/backend-fixes-security-tls-20261026`.
`a1-*.status.json` records exact command, UTC start/end, cwd and raw process exit;
logs, test credentials, logical backups and test PKI remain outside the repository.

* Trivy **0.76.0**, vulnerability scanner only, every severity, no ignore file,
  no `--ignore-unfixed`. `--exit-code=10` reports findings at **any** severity.
* Coordinated official-GHCR vulnerability DB refresh succeeded on October 28.
  DB v2 updated **2026-10-28 01:21:08 UTC**, downloaded **03:31:15 UTC**.
  Java DB v1 remains the current cached publication, updated **October 26 00:59:12
  UTC**, next update October 29; its explicit download/check exited 0.
  `a1-refresh-db`, `a1-refresh-java-db`, `a1-current-db-version` retain evidence.
* Builds/scans/live execution are **linux/arm64**. Upstream index digests are
  multi-platform; this does not establish an amd64 deployment pass.
* PHP remains the business/database owner, Go remains HTTP-only SDUI. No public
  schema/route/money/enum change or application dependency change is introduced
  by this runtime continuation. SQL 001–011 remain immutable.

## Current complete raw inventory

Counts are package/path/advisory occurrences, not unique CVEs or proof that every
affected path is reachable. Reports are `E/a1-current-<runtime>.json`.

| Runtime | Critical | High | Medium | Low | Unknown | Raw exit |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| PHP 8.3.36 / Alpine 3.24 | 0 | 0 | 0 | 0 | 0 | 0 |
| nginx 1.30.6 / Alpine 3.24 slim | 0 | 0 | 0 | 0 | 0 | 0 |
| PostgreSQL 17.11 / rebuilt gosu 1.20 | 0 | 0 | 0 | 0 | 1 | 10 |
| BFF, Go 1.27.3 / distroless Debian 12 | 0 | 0 | 0 | 0 | 3 | 10 |
| Migration, Go 1.26.10 / distroless Debian 12 | 0 | 0 | 0 | 0 | 3 | 10 |
| Keycloak 26.8.1 | 0 | 3 | 55 | 35 | 0 | 10 |
| OpenSearch 2.19.7, trimmed vendor distribution | 0 | 19 | 10 | 1 | 0 | 10 |

Local project image configuration IDs from `a1-current-images.json` (not pushed
registry digests):

| Runtime | Image ID |
| --- | --- |
| PHP | `sha256:f565a3f7560ecfa51333362527988bfee97f89a3fb604aa4d4e5b391335198a3` |
| BFF | `sha256:41e2aa0588ba0413fccd7a9463051ca1db639b955f6a23f194ce14352c73c1ed` |
| Migration, initial resolved builder | `sha256:7e0859dabb61d88baca2bea6c46d22db750a7a257b52d0ccf52b93d910c51766` |
| Keycloak | `sha256:45e85345127de5b54e2f8b0b4b47518e456f8f5008b3613bb97d050a9e5d9ba2` |
| OpenSearch | `sha256:9371f86e0f63f9bf5b26404cdb34eca473f3f00d32ad12148a99e1bc4495986a` |
| PostgreSQL | `sha256:dc7e67ac789b6ec5693bb01cfbe4b6d5e7f4f2b163e1c7968effad156c65b85e` |

The migration Dockerfile subsequently pins that exact Go 1.26.10 builder and the
already-resolved distroless index. Its separately built artifact is
`sha256:236b9c40823771d17819687d4b51c36b63c41cd7a0c5da60e9ce9d254a42c5bd`;
the complete ordered filesystem layers equal the scanned artifact above. The
configuration ID differs because of build provenance. The currently published
distroless `static-debian12:nonroot` index still resolves to the pinned
`sha256:afa5c872c891853ca7fcf1f12c3edb23f7eeef36189728842dd51042ff57f7ab`;
no newer same-tag CA/tzdata payload was available at inspection.

The later CA-only OpenSearch derivative is
`sha256:b0d4dfa6dca9e77af8625631fa7ab716b1595212a6ae6506a0ee37874c3dabae`.
Its separate final report `a1-current-opensearch-ca-fixed.json` retains exactly
**0 Critical / 19 High / 10 Medium / 1 Low**, raw exit **10**; default-JDK root
trust is assessed independently, not inferred from those scanner counts.

## PostgreSQL and nginx remediation

Both PostgreSQL services build `deploy/postgres.Dockerfile`, based on official
**17.11-alpine3.24**, index
`sha256:266d3c257594e195b309ae9d1805e54c788f1d7b375f56bf6c367aac6c917e7b`.
PostgreSQL major, data-directory layout, UID/GID 70, official entrypoint,
initialization and production TLS wrapper remain intact.

The refreshed official base has clean native packages but still ships gosu
**1.19**, built with Go **1.24.6**. Its raw scan has one Critical and 26 High
stdlib occurrences plus lower-severity findings. These metadata detections do
not prove gosu calls network/TLS parsers; the vendor's
[security policy](https://github.com/tianon/gosu/blob/1.20/SECURITY.md) explicitly
requires symbol applicability analysis.

The narrow derivative builds the actual released
[gosu 1.20](https://github.com/tianon/gosu/releases/tag/1.20), including upstream's
`moby/sys/user 0.4.1` fix for **CVE-2026-61801**, using pinned Go **1.26.10**.
No source, go.mod or go.sum is patched. Release commit
`29b939aa5bd4dc588af765b8248f6e39150d2565`; downloaded source archive SHA-256
`2b70da8a5480fafedc16bb1fc91b572f064d03df736cbd91a616af2f1b66ab51`
is enforced by BuildKit `ADD --checksum`; build uses `-mod=readonly`, static
CGO-disabled Linux output. Only `/usr/local/bin/gosu` is replaced in the runtime;
no compiler/source tree is copied into PostgreSQL.

Actual UID/GID name/numeric switching, argument preservation and exit propagation
passed (`a1-postgres-helper`). Actual final-binary govulncheck exits **0**,
`No vulnerabilities found` (`a1-gosu-govulncheck`). Remaining UNKNOWN
**CVE-2026-39824 / GO-2026-5024** concerns
`golang.org/x/sys/windows.NewNTUnicodeString`; the
[official Go record](https://vuln.go.dev/ID/GO-2026-5024.json) explicitly limits
it to **Windows**. This Linux executable cannot include that OS-specific package.
The occurrence remains in raw inventory; no suppression or unnecessary upstream
module substitution was used.

Both proxies pin official nginx **1.30.6-alpine3.24-slim**, index
`sha256:dede8895648b461178c3bc604f7eed4423efe5220b2c4d839f5eb3d34ac3f8f7`.
Real entrypoint/template rendering, proxy health and application startup passed;
TLS/search/trust verification is recorded separately below.

## Remaining Java blockers after the fresh DB

The October 26 DB results in `JAVA_RUNTIME_REMEDIATION.md` are historical;
the counts above include newly published October 27 advisories.

* **Keycloak:** raw High **CVE-2026-91403** has the vendor-backed default-CA-store
  fix already present, as documented in the Java report. **CVE-2026-91415**
  (vendor JDK 2D/font rendering) remains unresolved; latest inspected public UBI9
  package is affected. New **CVE-2026-102554**, Guava **33.6.0-jre** (fix
  **33.7.2**), concerns native Java deserialization with uncontrolled allocation:
  [vendor advisory](https://github.com/google/guava/security/advisories/GHSA-xxph-c9ww-hj94).
  No vendor-distribution fix or verified NotAffected disposition is established.
* **OpenSearch:** retain the prior **13 Jackson High occurrences** and the
  configuration-specific LZ4 private-temp NotAffected evidence. New High:
  **CVE-2026-102554** in job-scheduler/knn/security Guava (three paths), and
  **CVE-2026-108106**, **CVE-2026-93451** in security's Snappy **1.1.10.7**
  (fix **1.1.10.9**). Vendor advisories:
  [allocation](https://github.com/xerial/snappy-java/security/advisories/GHSA-q233-g92q-qxp6),
  [typed-array overflow](https://github.com/xerial/snappy-java/security/advisories/GHSA-gwq9-9ffj-mvrv).
  Their different entry-point preconditions require analysis; they are not waived
  because the service is private or uses TLS. Security/core libraries are retained.
* **Scanner coverage gap:** OpenSearch bundles Temurin **21.0.12.1+1**. Its
  **CVE-2026-91415 (High)** remains unresolved **outside the raw table**.
  **CVE-2026-91403 (Critical 9.1)** was also present in the original bundled
  default truststore; the separately verified official mitigation below removes
  its root trust path. The Keycloak/RHEL package disposition was not transferred
  to this different runtime.

Latest evaluated official OpenSearch **3.9.0** also retained affected core Jackson
and a pre-fix JDK; an automatic major upgrade does not close A1. No ad-hoc JAR/JDK
replacement or invented vendor waiver was applied.

BFF/migration retain three UNKNOWN Debian CA/tzdata advisories (exact versions
in `SECURITY_POSTCHECK.md`). The latest same-tag official distroless manifest is
unchanged. Raw nonzero exits are preserved, not reported as zero-finding scans.

### Additional supported OpenJDK CA mitigation

The advisory's [official advice](https://openjdk.org/emudhra) explicitly permits
removing four named roots when a patched standalone runtime is unavailable.
The pinned Temurin store contains **emSign ECC Root CA - G3** and **emSign Root
CA - G1**; **AAA Certificate Services** and **emSign Root CA - G2** are already
absent. `deploy/opensearch.Dockerfile` removes the two present roots through the
bundled vendor `keytool`, using their exact existing aliases and failing on any
unexpected error. The original public truststore is captured privately as
`a1-opensearch-original-cacerts.log` and remains recoverable from the immutable
upstream image. No JDK/library, private TLS CA, entrypoint or UID is replaced.

The official unmodified [CheckCerts.java](https://cr.openjdk.org/~mr/CheckCerts.java)
is retained with its redistribution license at `deploy/tests/CheckCerts.java`;
remote/local bytes match SHA-256
`0770e0c53677343cb8eb7bcb3dd4a78292017bc8f17fe821cd100c636ea9ce79`.
It initializes actual default JSSE trust managers and examines their accepted
issuer certificates, not just alias names. `probe_java_runtime.py --mode
opensearch-truststore` runs it in the actual image without network access:

* **RED exit 1:** both present roots reported `BAD ROOT`, before modification.
* **GREEN exit 0:** `No bad root certificates found`, after the supported change.
* Final raw scan remains exit **10** with unchanged Java-library findings.

Evidence: `a1-opensearch-truststore-{red,build,green}` and the final scan above.
The OpenJDK advice addresses the compromised-intermediate incident; Red Hat's
specific [CVE-2026-91403 statement](https://access.redhat.com/hydra/rest/securitydata/cve/CVE-2026-91403.json)
also confirms removal of the G3 root is sufficient to close that trust path.
This is a **default-truststore mitigation**, not a claim that the JDK version is
patched or that arbitrary operator-supplied stores/roots are safe. Recheck any
custom/effective stores and retain the private CA/hostname negatives. Final TLS
and real authenticated search passed after this CA-only change; the full-root
snapshot uses the immediately preceding image with otherwise identical layers.

## Deployment and executed checks

Only owned disposable project `shopkmp-backend-fixes-e2e` was stopped/upgraded;
unrelated stacks were not changed. Edge remains loopback **58196**, exact-peer
ingress **172.31.220.0/24**, isolated FPM **172.31.219.0/24**, two recovery
replicas. Existing business/identity volumes were preserved. Before replacing
Keycloak, all old application/identity nodes were stopped and actual `pg_dump -Fc`
business/identity backups captured privately. Upgraded Keycloak logs show model
migrations through **26.8.0**, existing realm import **skipped**, then server
**26.8.1** ready; this is a disposable populated-database upgrade, not production.

The initial **480s** startup wait exited **1** during legitimate reindex of
**754** accumulated fixture products (`refresh=wait_for` per document). Database,
Keycloak and search were healthy; the reindex process remained alive/progressing.
The root Makefile's unchanged **2100s** wait subsequently exited **0**; all
long-running services, including both recovery replicas, were healthy, and all
three initialization jobs exited **0**. No data cleanup, bulk-index rewrite,
security bypass or fake readiness was used to obtain the pass.

| Check | Actual result / evidence prefix in E |
| --- | --- |
| Root runtime image builds | exit 0, `a1-compose-build` |
| Production management-scheme regression | RED exit 1 on inherited development `http`; GREEN exit 0, `a1-infrastructure-{red,green}` |
| Live Keycloak management | exit 0, `a1-management-live`: HTTP with a TLS public listener; `inherited` HTTPS with trusted CA; opposite schemes and untrusted CA rejected |
| Development/production resolved boundaries, seven unsafe production inputs | exit 0, `a1-infrastructure-green` |
| Deployment command boundaries | 5/0, exit 0, `a1-deployment-command-boundaries` |
| Current actual PHP runtime | 5/0, exit 0, `a1-current-php-runtime`: extensions/codecs/ELF, real FPM ping, actual Database adapter libpq verify-full positive/negative CA/host tests |
| Actual scheduler regression, three worker types | 18/0, exit 0, timing scale **1**, `a1-current-php-scheduler` |
| Focused runner/Compose regressions; disposable PKI | 6/0 and 1/0, exit 0 |
| Current Composer audit and toolchain-matched BFF/ops govulncheck | exit 0 each, `a1-current-composer-audit`, `a1-current-govulncheck-{bff,ops}` |
| Actual logical-backup restore on new PostgreSQL | exit 0, `a1-backup-restore-check`: both backups restored into separate databases; original 2 realms / 14 clients (including secrets) / 46 users / 48 role links preserved in migrated identity DB |
| Full root regression on new runtimes, immediately before the CA-only change | **exit 0**, **2030.78s**, `a1-root-integration-check`: shared PHP **154/0**, focused **150/0**, real CARD, BFF live/race/schema/vet, ops tests/vet, internal trust/probes and graceful shutdown/restart |
| Final-image production-mode trusted-CA TLS stack | **exit 0** up/smoke/upstream/search/auth, `a1-tls-{up,smoke,upstream-probes,search-contract,live-auth}`; verified final CA-mitigated image, not the historical artifact |
| Final TLS Keycloak real realm | **4/0**, registration/password grant/refresh/audience, actual password reset/effective service permissions, online logout/configured-client offline revoke/revoked-token rejection |
| Final TLS OpenSearch real contract | authenticated indexing/search/deletion, name boost/descendants, exact prices, ascending/descending/rating/new sorts and pagination all pass |
| Real Keycloak JDBC sessions / payment effects | **4 TLS sessions (`4\|t`)** / **zero payments and operations (`0\|0`)**, `a1-tls-jdbc-session`, `a1-tls-payment-effects` |
| Final local origin restoration | **exit 0**, `a1-root-resume-after-tls`: scheduled reindex completed successfully; **12 runtime containers healthy**, including **two** recovery replicas |
| Fresh final working-variant check | **exit 0**, `a1-working-variant-final`: completed init jobs; actual mitigated OpenSearch image running; home/categories **200**, protected profile **401**, schemaVersion **1**; TLS project stopped |

Keycloak 26.8 accepts management scheme **`http` or `inherited`**, not `https`.
The production overlay explicitly overrides development's `http` with
`KC_HTTP_MANAGEMENT_SCHEME: inherited`; the healthcheck remains CA-verified
`https://keycloak:9000/health/ready`. The live H2 management probe ran after only
owned services were stopped to free memory; its earlier exit-2 capacity blocks
remain historical evidence. PostgreSQL-backed realm behavior is separately
covered by the root suite, not inferred from the H2 probe.

The full root run started **04:03:44 UTC** and finished **04:37:35 UTC**, exit 0.
Its fresh logs and captures were copied to `E/a1-root-evidence` before restoring
the repository's historical generated JSON, rather than committing volatile
fixture IDs as new goldens. Its ordinary runner normalizes recovery back to one
replica; the final local restoration explicitly selects **two** again.

The subsequent final-image TLS run used only owned project
`shopkmp-backend-fixes-tls-e2e`, loopback **58198**, the preserved private test CA
and existing business/identity volumes. Both pre-upgrade TLS databases also have
private logical backups. Unknown Host/SNI, operational-route hiding, spoofed
webhook source, verified PHP/JDBC database TLS, and both servers' rejection of
plaintext were exercised. PHP curl and PDO both rejected wrong host/untrusted CA.
Actual migrated realm tests exercised the effective service-account permissions,
password reset and online/offline cleanup; import declarations were not treated
as proof. The signed JWT correctly remains valid until expiry despite session
logout. No real provider was called.

As in the earlier resource-qualified TLS test, the private test override uses
**256 MiB OpenSearch heap / 2 GiB container ceiling** in the shared 8-GB VM. This
establishes functional TLS/search/auth compatibility, not default-resource
production capacity. The TLS stack was stopped afterwards; its volumes/backups
are retained. Development restoration completed at **04:54:14 UTC**, exit 0,
with the final CA-mitigated image consumed by the running JVM and successful
first scheduled reconciliation. The working local origin remains
`http://127.0.0.1:58196` (Android emulator `http://10.0.2.2:58196`).

## Separate source review

A read-only AI reviewer (`ses_e7f4c5a8fffee9bKw3sPFoeEBf`) inspected the complete
runtime source/Docker/Compose/test changes relative to `32675b249`, untracked
runtime additions, actual evidence and image metadata. **No additional concrete
Critical/Important/Minor source defect was identified.** The review confirmed the
then-known vendor Critical/High release blockers and explicitly withheld release
approval pending them and the still-running root/serial TLS verification.
This is a separate AI review, not a human or production certification. It did
not modify the checkout/index or operate services. Its recommendations are to
finish actual PostgreSQL-backed realm/root/TLS checks and retain platform scope.

The same reviewer separately reviewed the later OpenJDK mitigation, independently
verified the official checker bytes, RED/GREEN, unchanged finding identities and
ordered original image layers plus the one keytool layer. **No new
Critical/Important/Minor defect** was found. The targeted default-store mitigation
was accepted; its scope does not cover arbitrary custom stores, other JDK flaws
or running JVMs that have not consumed the new image. Release approval remains
withheld pending the unresolved High findings. The coordinator's subsequently
executed root and final-image TLS results are recorded above; the reviewer did
not execute those long suites.

## Publication status

### Final source/evidence checks

Gitleaks **v8.30.1**, default rules and full redaction: final staged/current source
snapshot **507 files / 23 detections / raw exit 1**; untracked additions
**zero files / zero detections / exit 0**; complete staged/working diff relative to
`32675b249` **zero detections / exit 0**. Evidence:
`a1-commit-gitleaks-{tracked,untracked,diff}` and private
`a1-commit-source-scan/manifest.json`. No ignore/allowlist was added.

All 23 tracked detections were context-reviewed: **14** disposable auth-fixture
passwords, **two** Compose variable/JDBC references, **three** security/coverage
prose or non-secret catalog-ID contexts, **three** documented Gradle task paths,
and **one** public test-product UUID in the installed report. No actual credential
leak was identified. Raw nonzero fixture detections remain visible.

`a1-mobile-evidence-integrity` exits **0**: all **26** accepted installed PNGs,
both contact sheets, exact byte counts/dimensions/SHA-256 and both current test
source hashes match the reviewed manifest; both receipts remain 1/0/0 with
13/13 exports. This does not rerun the installed journeys or expand their visual
acceptance scope. Temporary full-root JSON captures were retained privately and
restored/removed from the working tree; no unrelated edits were discarded.

Existing local checkpoints: backend `b9def939`, mobile `32675b249`. The user
explicitly requested a further local commit after completing the working variant;
its resulting SHA is available from `git log`. This report records that verified
snapshot and includes the subsequent installed evidence/privacy guard. No push,
remote CI, production cutover or production rollback has been performed.
Require supported vendor fixes/dispositions for the unresolved Java findings,
rebuild/rescan and affected functional/TLS checks before clearing A1 or publishing.
