# A1 Java runtime remediation

**Coordinator follow-up, 2026-10-28:** the management live probe now passes, the
root Compose/production scheme changes are integrated, and the owned disposable
populated identity database has been upgraded. A coordinated October 28 DB
refresh adds Guava/Snappy findings: current raw Keycloak **3 High**, OpenSearch
**19 High**. The coordinator then applied OpenJDK's official root-removal fallback
and ran its unmodified checker RED/GREEN, mitigating the bundled-JDK certificate
Critical in the default store; the JDK font-rendering High remains. See
[A1_RUNTIME_RESULTS.md](A1_RUNTIME_RESULTS.md) for exact current artifacts,
superseding counts and functional/TLS status. The component handoff below retains
its original build/October 26 DB/capacity-blocked snapshot, not current live state.

## Result — 2026-10-28: images built, security/rollout gate blocked

Supported patch upgrades and optional-plugin removal are implemented, built and
scanned. **Zero applicable High/Critical has not been achieved.** Remaining
vendor JDK and bundled Jackson findings are detailed below, including a bundled
OpenSearch JDK Critical advisory that the raw image scan does not enumerate.

Runtime-only scope: `deploy/keycloak/Dockerfile`, a justified minimal OpenSearch
derivative, focused probes, and this report. Shared Compose changes belong to the
coordinator. Evidence directory (private, outside the repository):
`/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/backend-fixes-security-tls-20261026`
(abbreviated **E** below). `a1-java-*.status.json` records commands, times and exits;
matching `.log` files contain output. No live deployment upgrade has occurred.

### Verified upstream candidates

* Keycloak **26.8.1**, official `quay.io/keycloak/keycloak`, pulled digest
  `sha256:a78d6b79572168cb25886b80a8b5b8d4ed4390743e3eecb810538683e209dd6b`.
  Official availability: <https://www.keycloak.org/downloads>.
  Initial raw candidate scan: `E/a1-java-keycloak-26.8.1.json`, exit **10**;
  **0 Critical, 2 High, 55 Medium, 35 Low**. All Java-library findings from
  26.3.3 are absent; remaining High findings are the vendor JDK package
  `java-21-openjdk-headless 1:21.0.12.1.1-1.2.el9`: CVE-2026-91403 and
  CVE-2026-91415, with no fixed RPM reported. The first is addressed by the
  vendor CA package already present; the second remains blocked upstream.
* OpenSearch **2.19.7**, latest published 2.19 patch in the official release list,
  released 2026-10-20: <https://github.com/opensearch-project/OpenSearch/releases/tag/2.19.7>.
  Retried pull succeeded (exit **0**), official image digest
  `sha256:3bfa1ec74d56928c2e018033d45c460ce2c0ea65a5cedd577de3102326c31b89`.
  Release notes include Netty 4.1.139.Final, Jackson 2.18.9, bc-fips 2.1.3,
  and bundled JDK 21.0.12.1+1. Raw candidate scan completed, exit **10**.
  The derivative removes 12 optional plugins using the official plugin manager.
  The [maintenance policy](https://opensearch.org/releases/#maintenance-policy)
  lists major 2 as in maintenance until GA of 4.0; its patch-version table lags
  the official 2.19.7 release and version-history pages.
* Healthcheck builder verified by registry manifest inspection:
  `golang:1.26.10-alpine3.24@sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308`.
  Preserve `tools/ops/go.mod`'s Go 1.26 floor and x/text v0.41.0.

### Critical coordinator handoff: management scheme

26.8.1's exact-tag `ManagementOptions.java` permits **`http` or `inherited`**;
`https` is not a valid value:
<https://github.com/keycloak/keycloak/blob/26.8.1/quarkus/config-api/src/main/java/org/keycloak/config/ManagementOptions.java>.
See also <https://www.keycloak.org/server/management-interface>.

Development: retain `KC_HTTP_MANAGEMENT_SCHEME: http` and
`HEALTHCHECK_URL: http://127.0.0.1:9000/health/ready`.
Production overlay must explicitly override the inherited development value with
`KC_HTTP_MANAGEMENT_SCHEME: inherited`, alongside the existing
`HEALTHCHECK_URL: https://keycloak:9000/health/ready` and CA file.
Do **not** set `KC_HTTP_MANAGEMENT_SCHEME=https`. The actual built image's
`start --help-all` confirms these values; starting with `https` fails with exit
**2** and `Expected values are: http, inherited` before starting a server.
The live management probe is capacity-blocked, as recorded below.

### Compatibility constraints

Preserve password grants with `openid offline_access`, the explicit backend
audience mapper, service-account user management and existing logout/consent
revocation calls. Exact-tag API/permission validation is recorded below.
Importing a realm at startup skips an existing realm; changing the
import file would not migrate a populated database. Rehearse the 26.3.3 database
snapshot upgrade on an isolated restored copy before rollout, retaining a
pre-upgrade database backup. Do not start 26.3.3 against a migrated database;
rollback requires restoring the matching database backup (and accounting for
post-upgrade writes), or a supported roll-forward fix.

### Security gate

Keep complete all-severity raw reports and exit **10** for findings. No ignore
file, `--ignore-unfixed`, ad-hoc JAR substitution, or blanket private-network/TLS
waiver is used. Shared Trivy cache was read with DB updates disabled; no refresh
was raced. Recorded DB snapshot: vulnerability DB updated **2026-10-26 01:20 UTC**,
Java DB **2026-10-26 00:59 UTC**, scanner **0.76.0**. A coordinated refresh and
rescan is still required; these are dated results, not a claim of current zero
findings. Scanner image digest:
`sha256:2416d1f74045b57274df1a340eee6b510cacd73c66a6ccb8da092b117eb7d222`.

## Build artifacts and raw counts

Local builds and scans are **linux/arm64**; the pinned upstream index digests are
multi-platform. An amd64 deployment requires its own platform build/scan.

| Artifact / report in E | Critical | High | Medium | Low | Exit |
| --- | ---: | ---: | ---: | ---: | ---: |
| Original Keycloak 26.3.3, `trivy-keycloak.json` | 8 | 81 | 216 | 65 | 10 (retry) |
| Original OpenSearch 2.19.3, `trivy-opensearch.json` | 19 | 431 | 340 | 18 | 10 |
| Official Keycloak 26.8.1, `a1-java-keycloak-26.8.1.json` | 0 | 2 | 55 | 35 | 10 |
| Built `shopkmp-a1-keycloak`, `a1-java-keycloak-final.json` | 0 | 2 | 55 | 35 | 10 |
| Official OpenSearch 2.19.7, `a1-java-opensearch-2.19.7.json` | 2 | 53 | 35 | 1 | 10 |
| Built `shopkmp-a1-opensearch`, `a1-java-opensearch-final.json` | 0 | 14 | 9 | 1 | 10 |
| Evaluated official OpenSearch 3.9.0, `a1-java-opensearch-3.9.0.json` | 0 | 71 | 3 | 1 | 10 |

Counts are package/path occurrences, not unique CVEs. Unknown severity: 0 in all
listed reports. No finding has been removed from a raw report.

Built local image index digests (not pushed):

* `shopkmp-a1-keycloak@sha256:ecc7f3db8928c1337ec8fb6f459f77a4d5428e72892ae3348fcbaf151208520e`
* `shopkmp-a1-opensearch@sha256:b63b55419110a19382f23ee0f6eb46ea91546475c258bb1ec51b2354b5823eb8`

Both retain upstream UID **1000** and entrypoint. The Keycloak healthcheck remains
a `CGO_ENABLED=0` static binary built with Go **1.26.10**. Its final Go-binary
scan has no findings. No module or business-code changes were required.

Reproduction commands, from repository root:

```sh
docker build --progress plain -t shopkmp-a1-keycloak -f deploy/keycloak/Dockerfile tools/ops
docker build --progress plain -t shopkmp-a1-opensearch -f deploy/opensearch.Dockerfile .

# E is the private evidence directory defined above. Run scans sequentially.
docker run --rm --name shopkmp-a1-java-trivy \
  -v /var/run/docker.sock:/var/run/docker.sock:ro \
  -v backend-fixes-security-trivy-cache:/root/.cache/trivy \
  -v "$E:/evidence" aquasec/trivy:0.76.0 image \
  --image-src docker --skip-db-update --skip-java-db-update \
  --scanners vuln --ignorefile= --format json --timeout 10m --exit-code 10 \
  --output /evidence/a1-java-keycloak-final.json shopkmp-a1-keycloak
# Repeat for shopkmp-a1-opensearch, output a1-java-opensearch-final.json.
```

## Remaining findings: precise dispositions

### Keycloak vendor JDK

1. **CVE-2026-91403 — vendor fix already present in default truststore.**
   Red Hat's [CVE statement](https://access.redhat.com/hydra/rest/securitydata/cve/CVE-2026-91403.json)
   explicitly says non-portable OpenJDK RPMs consume the system `ca-certificates`
   fix, and removal of the affected roots is sufficient even while the JDK RPM
   itself remains `Fix deferred`. Its linked
   [CVE-2026-114406 record](https://access.redhat.com/hydra/rest/securitydata/cve/CVE-2026-114406.json)
   identifies RHEL9 fixed package
   `ca-certificates-2026.2.92_v9.0.319-90.3.el9_8` (RHSA-2026:88459).
   That exact package is present in the final scan inventory. The image's Java
   `cacerts` resolves to `/etc/pki/ca-trust/extracted/java/cacerts`; actual
   `keytool -list -cacerts` succeeds and the affected **emSign ECC Root CA G3**
   is absent. One different alias, `emsignrootca-g1`, remains: this is not a claim
   that every emSign root was removed. Evidence: `a1-java-keycloak-ca-store.log`,
   `a1-java-keycloak-rpm-audit.log`, `a1-java-source-redhat-91403.txt` and
   `a1-java-source-redhat-114406.txt`. Disposition applies to this vendor default
   store, not arbitrary custom truststores supplied at deployment.
2. **CVE-2026-91415 — unresolved High / upstream package blocker.**
   [Red Hat](https://access.redhat.com/hydra/rest/securitydata/cve/CVE-2026-91415.json)
   marks RHEL9 `java-21-openjdk` affected. This is a 2D/font-rendering flaw;
   headless mode is not evidence that the affected library is absent. The
   [OpenJDK October advisory](https://openjdk.org/groups/vulnerability/advisories/2026-10-20)
   lists 21.0.12.1 and earlier as affected. The official public **UBI9 aarch64
   appstream metadata**, queried on 2026-10-28, still offers only
   `1:21.0.12.1.1-1.2.el9`, matching the image. See
   `a1-java-source-ubi9-packages.json` and the saved repomd files. No newer
   vendor RPM was available to apply through the official UBI installroot
   method. Wait for a patched vendor RPM / rebuilt official Keycloak image;
   then pin, rebuild, scan and retest. No NotAffected claim is made.

The official container deliberately has no rpm/dnf/microdnf. The supported
[installroot method](https://www.keycloak.org/server/containers#_installing_additional_rpm_packages)
was considered; adding another OS layer would not fix an unavailable JDK patch.

### OpenSearch raw High findings

All paths below are relative to `/usr/share/opensearch/`.

| Finding | Installed / fixed upstream | Exact remaining paths | Disposition |
| --- | --- | --- | --- |
| CVE-2026-106451, `at.yawk.lz4:lz4-java` | 1.11.1 / 1.11.4 | `plugins/opensearch-security/lz4-java-1.11.1.jar` | Not affected **under verified private-temp default**, below |
| CVE-2026-89407 and CVE-2026-89425, `jackson-core` | 2.18.9 / 2.18.11 | `lib/jackson-core-2.18.9.jar`, `lib/tools/upgrade-cli/jackson-core-2.18.9.jar` | 4 unresolved High occurrences |
| CVE-2026-68497, `jackson-databind` | 2.18.9 / 2.18.10 | `lib/tools/upgrade-cli/jackson-databind-2.18.9.jar`, `modules/ingest-geoip/jackson-databind-2.18.9.jar`, `plugins/opensearch-security/jackson-databind-2.18.9.jar` | 3 unresolved High occurrences |
| CVE-2026-91776 and CVE-2026-91777, `jackson-databind` | 2.18.9 / 2.18.11 | Same three databind paths | 6 unresolved High occurrences |

FasterXML vendor advisories describe different preconditions; they do not justify
a general network/TLS waiver:

* [GHSA-p6pp-m3f8-5c89](https://github.com/FasterXML/jackson-core/security/advisories/GHSA-p6pp-m3f8-5c89): string-to-number coercion / regex backtracking.
* [GHSA-7hhh-6rmp-j9qf](https://github.com/FasterXML/jackson-core/security/advisories/GHSA-7hhh-6rmp-j9qf): malformed tokens on `DataInput` parser path.
* [GHSA-q4xh-88c3-wmh7](https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-q4xh-88c3-wmh7): XML duration/calendar deserialization without numeric bounds.
* [GHSA-wv8q-qhhj-9h54](https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-wv8q-qhhj-9h54): long-lived polymorphic fallback deserializer cache.
* [GHSA-cxp5-3px4-pw24](https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-cxp5-3px4-pw24): identity-enabled forward-reference completion.

No authoritative OpenSearch NotAffected statement was established for those
specific bundled paths; retain them as unresolved. Upstream library fixes exist,
but neither evaluated official OpenSearch distribution bundles all of them.
Applying `dnf` updates cannot patch bundled JARs. Core transport/security/GeoIP
modules and the upgrade CLI were retained; no ad-hoc JAR replacement was used.

**LZ4 classification evidence:** the
[upstream advisory](https://github.com/yawkat/lz4-java/security/advisories/GHSA-mcr4-qmvw-px4g)
explicitly excludes private `java.io.tmpdir`. The shipped `bin/opensearch` calls
the official `TempDirectory` launcher when `OPENSEARCH_TMPDIR` is unset, and
`config/jvm.options` binds `java.io.tmpdir` to that directory. The exact-tag
[launcher source](https://github.com/opensearch-project/OpenSearch/blob/2.19.7/distribution/tools/launchers/src/main/java/org/opensearch/tools/launchers/TempDirectory.java)
documents this privacy. Executing that launcher from the built image produced
**mode 0700, UID/GID 1000/1000**, verified by `stat` (exit 0). The current Compose
files do not override `OPENSEARCH_TMPDIR` or `java.io.tmpdir`. This is a
configuration-specific NotAffected finding, conditional on preserving those
defaults; it is still retained in the raw scan. Evidence:
`a1-java-opensearch-private-tmp-retry.log` and
`a1-java-opensearch-tmpdir-source.log`.

### OpenSearch bundled JDK: scanner coverage gap

The final image's `jdk/release` and `bin/opensearch --version` identify
**Eclipse Temurin 21.0.12.1+1**, with a private bundled `jdk/lib/security/cacerts`,
not the patched Amazon/RHEL system truststore. Trivy's report has OS and JAR
results but does **not** enumerate this tar-distributed JDK's advisories.

* **CVE-2026-91415 (High, CVSS 7.0)** also covers this JDK version, according to
  the OpenJDK October 20 matrix. No applicability waiver is established.
* **CVE-2026-91403 (Critical, CVSS 9.1)** is listed by the
  [OpenJDK October 21 advisory](https://openjdk.org/groups/vulnerability/advisories/2026-10-21).
  Actual `keytool -list -cacerts` from the image still contains the affected
  **emSign ECC Root CA G3** (and C3/C1/G1). Its complete
  `jdk.security.caDistrustPolicies` value is
  `SYMANTEC_TLS,ENTRUST_TLS,CAMERFIRMA_TLS,CHUNGHWA_TLS`, with no eMudhra policy.
  The Keycloak/RHEL CA-package
  disposition cannot be transferred to this bundled Temurin store. This is an
  additional unresolved Critical blocker, outside the raw scan counts.

Evidence: `a1-java-opensearch-jdk-metadata.log`,
`a1-java-opensearch-version.log`, `a1-java-opensearch-ca-store.log`,
`a1-java-opensearch-distrust-policy-complete.log`.
Require a vendor-supported distribution refresh with corrected JDK/truststore.
No manual root-store surgery or alternate JDK/JAR distribution was introduced.

### Why major 3 was not selected

Official [3.9.0](https://github.com/opensearch-project/OpenSearch/releases/tag/3.9.0)
was actually pulled and scanned, digest
`sha256:3b55f6b6249cfdb92b52ee59adc8a266203ba9ef9f5d82544fad6f1af2f3609e`.
It retains affected Jackson **2.22.2** in the security plugin and other modules,
and Jackson **3.2.2** in core (`lib/jackson-core-3.2.2.jar`, CVE-2026-89425;
fixed 3.2.3). Its release notes specify JDK 25.0.4.1+1, also preceding the
October fixes. It therefore does not clear the security gate and is not a
justified automatic major upgrade. No 3.x derivative or index migration was
performed.

## Optional-plugin removal and application contract

`deploy/opensearch.Dockerfile` uses only the pinned official 2.19.7 base and
`bin/opensearch-plugin remove --purge`, in dependency order. Removed plugins:
performance-analyzer, skills, flow-framework, neural-search, ml,
security-analytics, anomaly-detection, observability, reports-scheduler,
notifications, notifications-core and sql (all `opensearch-` prefixed).

These account for unused Spark/Jetty, older performance-analyzer Bouncy Castle,
ML/JLine and duplicated Jackson libraries. The official removal command succeeds
for all twelve; it refuses removal of extended dependencies still in use.
The stock entrypoint explicitly handles an absent performance-analyzer plugin.
No vendor OS update was added because the 2.19.7 Amazon Linux package scan already
has **zero findings at every severity**.

`backend-yii2/infrastructure/OpenSearch.php` uses only core REST APIs: HEAD/PUT
index, PUT/DELETE document, and POST `_search`. Its analyzer is `standard`; queries
use bool/filter, multi_match, terms/range and sort. SQL remains the canonical
database and search results are rehydrated through the repository. The
OpenSearch **SQL plugin is unrelated to that SQL database**. The `id` text field
with `id.keyword` subfield and ascending tie-break sort are preserved exactly.
There is no use of removed plugin endpoints, processors, mappings or models in
the application adapter. Official CLI inventory verifies security/TLS,
`transport-netty4` and `analysis-common` are retained. Real TLS indexing/search
remains a coordinator integration check; source/inventory verification is not
represented as a live search pass.

## Exact Keycloak 26.8.1 API and permission review

Exact-tag sources saved in E as `a1-java-source-keycloak-*.txt`:

* [UserResource.java](https://github.com/keycloak/keycloak/blob/26.8.1/services/src/main/java/org/keycloak/services/resources/admin/UserResource.java):
  `POST /users/{id}/logout` requires `auth.users().requireManage(user)`, sets user
  not-before and logs out online sessions; successful result is empty **204**.
* `DELETE /users/{id}/consents/{client}` uses the **configured clientId**, not a
  client UUID, and requires the same manage permission. It resolves with
  `realm.getClientByClientId(clientId)`. Missing client, or neither a consent nor
  offline token to revoke, returns **404**; successful revocation is empty **204**.
* [UserConsentManager.java](https://github.com/keycloak/keycloak/blob/26.8.1/services/src/main/java/org/keycloak/services/managers/UserConsentManager.java):
  revokes both explicit consent and offline tokens; returns true if either was
  revoked. Therefore deleting the configured client's consent remains necessary
  after user logout for the existing offline-token revocation contract.
* [UserPermissions.java](https://github.com/keycloak/keycloak/blob/26.8.1/services/src/main/java/org/keycloak/services/resources/admin/fgap/UserPermissions.java)
  and [UserPermissionsV2.java](https://github.com/keycloak/keycloak/blob/26.8.1/services/src/main/java/org/keycloak/services/resources/admin/fgap/UserPermissionsV2.java):
  existing `realm-management/manage-users` authorizes manage operations in both
  evaluators. It also permits password reset. The imported service account already
  has `manage-users` and `view-users`; no new role grant was invented. Effective
  assignments/client scope in an existing database still need a real service-token
  check on the restored database; import declarations alone are not that proof.
* [LogoutEndpoint.java](https://github.com/keycloak/keycloak/blob/26.8.1/services/src/main/java/org/keycloak/protocol/oidc/endpoints/LogoutEndpoint.java):
  form POST with client credentials and refresh token still returns empty **204**
  after verified-token logout, including an absent matching session. Missing
  refresh token is **400 invalid_request**; invalid token errors remain **400**,
  and certificate-bound-token verification failures can be **401**. These do not
  become successful logout responses.

The realm still explicitly enables password grants, confidential client service
accounts, `offline_access`, and the backend audience mapper. According to the
[26.8.1 migration guide](https://www.keycloak.org/docs/26.8.1/upgrading/index.html),
26.8 changes offline tokens to include `exp`, and `refresh_expires_in` reflects
the session idle limit; do not require the old zero/no-exp representation.
The backend treats refresh tokens as opaque and returns the access-token
`expires_in`. The same guide says disabled clients are omitted from audience;
the configured backend client must remain enabled. Client credentials, password
grant, refresh, audience, reset-password, user logout and consent revocation must
all be verified with actual existing effective permissions after migration.

## Upgrade / roll-forward constraints

The exact-version guide requires **all old nodes to be stopped** for a minor
upgrade such as 26.3.3 → 26.8.1. Patch rolling-update support within a minor stream
does not authorize mixed 26.3/26.8 operation against one database.

On an isolated PostgreSQL restore of the 26.3.3 snapshot, preserve realm IDs,
users, client secret, role assignments, online/offline sessions and configured
clientId. Start 26.8.1 once to migrate schema; inspect migration logs/index
completion and database resource use. 26.8 changes offline-session indexes and
persists brute-force login failures in the database. Validate old and newly
issued refresh tokens, audience/issuer, login, password reset, user-admin logout,
configured-client offline-consent revoke, and revoked-token rejection. Startup
`--import-realm` skips a realm already present; it cannot repair database drift.

Keep the pre-upgrade backup and old image together. The migrated schema is not
compatible with the old server. Rollback is a coordinated database restore, with
explicit handling of writes made after upgrade; otherwise use a supported
roll-forward release/fix. No production snapshot or migration was run here.

For OpenSearch 2.19.3 → 2.19.7, rehearse on restored snapshots or a fresh derived
index rebuilt from canonical SQL, using the existing real security/TLS config and
`deploy/search-start.sh`. Preserve mappings and role/auth configuration, test
authenticated indexing, filtering, pagination and each sort including
`id.keyword`. Do not downgrade a modified data directory in place. References:
[rolling upgrade](https://docs.opensearch.org/latest/migrate-or-upgrade/rolling-upgrade/),
[snapshot/restore](https://docs.opensearch.org/latest/migrate-or-upgrade/snapshot-restore/),
[security configuration](https://docs.opensearch.org/latest/security/configuration/security-admin/).

## Focused verification and coordinator handoff

| Evidence prefix `E/a1-java-…` | Actual check | Exit/result |
| --- | --- | --- |
| `build-keycloak`, `build-opensearch` | Dockerfiles above, actual image builds | 0 / 0 |
| `healthcheck-tests` | Pinned Go 1.26.10 container, `go test -p 2 ./cmd/healthcheck` | 0 |
| `keycloak-version-retry` | Built image `--version` | 0; Keycloak 26.8.1, Red Hat JDK 21.0.12.1 |
| `keycloak-management-help` | Built image `start --help-all` | 0; `http,inherited` |
| `keycloak-reject-https-scheme` | Invalid scheme negative probe | 2 as expected |
| `opensearch-inventory-red` | Official untrimmed image against optional-plugin check | 1 as expected |
| `opensearch-inventory-final` | Trimmed image, official plugin CLI / required core files | 0 |
| `opensearch-version` | Built image CLI | 0; 2.19.7, JDK 21.0.12.1 |
| `opensearch-private-tmp-retry` | Official TempDirectory launcher plus mode/owner assertions | 0; 0700 / UID 1000 |
| `keycloak-ca-store`, `opensearch-ca-store` | Actual vendor JDK truststore inventories | 0 / 0 |
| `scan-keycloak-final`, `scan-opensearch-final`, `scan-opensearch-3.9.0` | Complete raw image reports | 10 / 10 / 10 |
| `management-probe` | Memory-gated isolated live probe | **2, blocked**, 395 MiB available; no server started |
| `opensearch-inventory-verified` | Final script after hostname/timeout/cleanup review | 0 |
| `management-probe-verified` | Final memory preflight | **2, blocked**, 332 MiB available; no server started |

Early diagnostic failures remain in E: selecting SerialGC alongside Keycloak's
default G1GC was rejected, then corrected for the version probe; directly
sourcing `opensearch-env` under bash gave the wrong script-relative classpath,
then the actual launcher was invoked with its explicit `lib/*` classpath.
The final inventory probe adds a local hostname mapping under `--network none`
to avoid an irrelevant Log4j hostname-resolution error. These are not image
runtime failures or security passes.

`scripts/probe_java_runtime.py` provides:

```sh
python3 scripts/probe_java_runtime.py --mode opensearch-inventory
python3 scripts/probe_java_runtime.py --mode keycloak-management --parent "$TEST_TEMP_PARENT"
```

The live mode requires ≥1 GiB **Docker VM** available memory, runs one new
768 MiB-capped isolated H2 test container at a time, publishes no ports and uses
one-day test-only TLS material. It tests HTTP management despite a TLS main
listener, inherited HTTPS management with CA verification, rejection of the
opposite scheme and rejection of an untrusted certificate. Containers and TLS
files are cleaned up. **Its live assertions have not run in this session**
because the memory preflight blocked them. Coordinator should rerun after
capacity is made available, before the full PostgreSQL/realm/TLS integration.

Recommended shared Compose changes for the coordinator, **subject to the open
security and integration gates**, not edited here:

```yaml
# docker-compose.yml
services:
  keycloak:
    # existing tools/ops build context + ../../deploy/keycloak/Dockerfile
    image: shopkmp-a1-keycloak
    environment:
      KC_HTTP_MANAGEMENT_SCHEME: http
      HEALTHCHECK_URL: http://127.0.0.1:9000/health/ready
  opensearch:
    build:
      context: .
      dockerfile: deploy/opensearch.Dockerfile
    image: shopkmp-a1-opensearch

# docker-compose.prod.yml overlay
services:
  keycloak:
    environment:
      KC_HTTP_MANAGEMENT_SCHEME: inherited
      HEALTHCHECK_URL: https://keycloak:9000/health/ready
      SSL_CERT_FILE: /opt/keycloak/conf/ca.crt
```

Keep the existing production OpenSearch security plugin, real certificate mounts,
hostname verification, password bootstrap script and authenticated healthcheck.
Build locally with those refs; production registry digests must be obtained from
the coordinator's separately published artifacts. No full stacks or existing
containers were started, stopped or mutated by this work. The existing staged
22 UI-evidence files remain staged; no index operation, commit or push was run.
