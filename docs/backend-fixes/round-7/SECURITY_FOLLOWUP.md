# Backend V8 — security follow-up, 2026-11-01 UTC

## Decision

**Neither remaining security blocker can be closed from today's verified evidence.**
The accepted F1–F3 implementation and current **503-input** functional receipt are
preserved. No newly published official OpenSearch remediation was found that
justifies changing that source/image binding.

Newly established in this follow-up:

- **OpenSearch 2.19.8 is not available:** exact Docker Hub manifest lookup returned
  `not found`; the official release page and tagged consolidated release notes
  returned 404. The vendor's schedule places its release window on **December
  8–15, 2026**, not November 1. Major 2 remains in maintenance.
- **2.19.7 and latest 3.x have unchanged registry content.** `latest` and `3.9.0`
  resolve to the same previously rejected upstream digest. The scheduled 3.10.0
  window is **November 3–17**. A schedule is neither an available artifact nor a
  promise that a release clears the findings.
- Joining **all 19 current HIGH instances** to the retained raw report establishes
  their exact JAR paths and fixed-library versions below. Further optional-plugin
  removal can target **at most two** of these instances; **17** are outside those
  optional plugins. This is inventory arithmetic, not a newly scanned 17-HIGH image.
- A fresh official Go DB fetch, explicitly disabling curl configuration and
  requiring certificate verification, still returns
  **`modified: 2026-10-27T21:16:18Z`**. At **07:41:39.333970Z** its age is
  **106.422593 hours**. The unchanged **72-hour** gate fails.

**Unchanged:** the last actual exact-image policy is **19 OpenSearch HIGH**, and
all **five** Go checks fail freshness despite zero raw findings. No PASS,
disposition, exclusion, VEX exception or freshness waiver is issued. Existing
platform/native-architecture blockers in FINAL_REVIEW remain independently binding.

## Scope, accepted binding and evidence

Read the current admission section of `TEST_RESULTS.md`, `DEPENDENCIES.md` and
`FINAL_REVIEW.md`, the actual receipt, current security reports, deployment
Dockerfile, security policies and application search adapter. Earlier reports were
used only as explicitly historical evidence.

```sh
T=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode
E="$T/round7-security-followup"
S="$T/final-admission-security"
R="$T/round7-final-fixes/artifacts.json"
```

`/private/var/...` paths in command receipts are the resolved macOS aliases of
`/var/...`. Private retrieval bodies, headers, command argv/start/end/actual child
exit codes and stdout hashes are retained under `$E`. Only this document is added
to repository content; helper/evidence files are outside the workspace. Credentials,
resolved runtime configuration and raw scanner configurations are not copied here.

At **07:37:59Z**, read-only source hashing matched all **503** receipt inputs,
`tested:true`, build/test exits **0/0**, and this exact compact sorted source-map hash:

```text
023f7403f3dbc97165e3a553eef55ab43473d107a249b1bf815ecb8328769bf3
```

The receipt's immutable local images were present (`docker image inspect`, exit
**0**). This is a preservation check, not a new functional execution or a claim
that stopped services are healthy. The final preservation checks are recorded at
the end of this document.

No runtime build/pull/retag, scanner installation, image rescan, source scan,
binary rescan or full functional rerun was warranted. Registry manifest inspection
downloads metadata only. No application/Compose/policy/lock source was edited;
no container was started, stopped or removed; no index or volume was changed or
deleted; no production action, staging, commit, push or subagent was used.

## 1. Authoritative Go advisory DB — still stale

The final executed retrieval was:

```sh
curl --disable --no-insecure --proto '=https' --proto-redir '=https' --tlsv1.2 \
  --location --fail-with-body --silent --show-error \
  --connect-timeout 15 --max-time 60 \
  -H 'Cache-Control: no-cache' -H 'Pragma: no-cache' \
  -D "$E/go-index-final.headers" -o "$E/go-index-final.body" \
  -w '%{json}' https://vuln.go.dev/index/db.json
```

**07:41:38.460355–07:41:39.333970Z, curl exit 0, HTTP 200,
`ssl_verify_result:0`, effective URL unchanged**, remote IP `34.117.213.18`.
`--disable` is the first curl option and prevents a user curlrc from changing the
verification policy; `--no-insecure` explicitly requires verification. The body is:

```json
{"modified":"2026-10-27T21:16:18Z"}
```

Primary HTTP/cache metadata, inspected rather than substituted for advisory age:

| Field | Observed value |
| --- | --- |
| `Date` | `Sun, 01 Nov 2026 07:36:45 GMT` |
| `Cache-Control` | `public, max-age=3600` |
| `Age` | **294** seconds on the final request; **75** on the recorded 07:38:00 request |
| `Last-Modified` | `Sun, 01 Nov 2026 04:17:05 GMT` |
| `ETag` | `"96684fc0218141bcbc570f858ac67364"` |
| `x-goog-generation` / metageneration | `1793139327444471` / `1` |

The initial direct no-cache probe at **07:36:45Z** also returned HTTP 200,
certificate verification result 0 and **Age: 0**, with the same payload. The
subsequent cache ages are disclosed: request `no-cache` is not asserted to prove
an origin bypass. Even the observed Age-0 response already contained the stale
authoritative timestamp. Neither the newer HTTP modification date, download time,
cache expiry nor a cleared local cache can turn that payload into fresh advisory data.

UTC arithmetic executed at **07:41:51Z**, exit **0**, gives:

```text
checked_at 2026-11-01T07:41:39.333970+00:00
age_hours 106.42259276944444
deadline_72h 2026-10-30T21:16:18+00:00
fresh_72h False
```

The two retained source streams and three delivered-binary streams were parsed
again, without rerunning govulncheck. All five name `https://vuln.go.dev`, scanner
**v1.9.0**, timestamp **2026-10-27T21:16:18Z**, and zero raw finding messages.
Thus the authoritative response agrees with the scanner metadata; a local-cache
refresh is not an evidenced remedy. `scripts/backend_security.py:44–59` still
requires age **<=72h** and rejects excessively future-dated timestamps.

**Resolution owner / trigger:** the Go advisory publisher must expose an
authoritatively fresh DB timestamp. When it does, rerun the **two toolchain-matched
source checks and three exact delivered-binary checks** with that DB and unchanged
policy. A newly fresh DB justifies those scans; the unchanged stale payload does
not justify repeating them now. Do not rewrite DB metadata or use HTTP dates as a
substitute.

## 2. Official OpenSearch availability and immutable manifests

Executed, with complete argv/exits in `$E/manifest-*.command.json`:

```sh
docker buildx imagetools inspect --raw opensearchproject/opensearch:2.19.8
docker buildx imagetools inspect --raw opensearchproject/opensearch:2.19.7
docker buildx imagetools inspect --raw opensearchproject/opensearch:3.9.0
docker buildx imagetools inspect --raw opensearchproject/opensearch:latest
```

All times below are **2026-11-01 UTC**. Index digests are SHA-256 of the actual
returned raw registry bytes, distinct from platform manifests and image configs.

| Tag | Retrieval interval | Actual exit / result |
| --- | --- | --- |
| `2.19.8` | 07:37:59–07:38:03 | **1**, `docker.io/opensearchproject/opensearch:2.19.8: not found` |
| `2.19.7` | 07:37:59–07:38:04 | **0**, index `sha256:3bfa1ec74d56928c2e018033d45c460ce2c0ea65a5cedd577de3102326c31b89` |
| `3.9.0` | 07:37:59–07:38:04 | **0**, index `sha256:3b55f6b6249cfdb92b52ee59adc8a266203ba9ef9f5d82544fad6f1af2f3609e` |
| `latest` | 07:38:33–07:38:37 | **0**, exactly the same raw index bytes/digest as `3.9.0` |

| Published version | linux/arm64 manifest | linux/amd64 manifest |
| --- | --- | --- |
| 2.19.7 | `sha256:ea01768b2c3ba38b54c61117a8c9e36f9db9e666b3a84d2122230fc3ce779448` | `sha256:19615b2c40f322830527fbd4a15bf87d53677a438c1159fdbba8e3fb916f5c51` |
| 3.9.0 | `sha256:bbdf65cb3d35c61a8ba495f22c1a42d7d7f0df23412e3419bf1e6cfca3ba4774` | `sha256:5c20af6b5cb472a74ba99f663bee3dd03afbeced14b3897b220a87fc75278445` |

The official core release API returned stable, non-draft **2.19.7**, published
**October 20, 22:16:56Z**, and highest published 3.x **3.9.0**, published
**September 29, 21:54:18Z**. Consolidated build-repository release publication is a
different event; it is not substituted for the core publication date.

The [maintenance/schedule page](https://opensearch.org/releases/) still lists
major 2 as **Maintenance**, ending at GA of 4.0. Its small maintenance table's
`2.19.6`/`3.7.0` version cells lag the actual releases; the fresh release API,
exact tagged notes and registry supply version availability. Its future windows
are **3.10.0: November 3–17** and **2.19.8: December 8–15**, explicitly subject
to change.

The exact-tag 2.19.7 core notes still specify **Jackson 2.18.9**, Netty
4.1.139.Final and bundled JDK 21.0.12.1+1. The tagged build manifest selects core
2.19.7 and plugin tags **2.19.7.0**. The 3.9.0 consolidated changelog includes
Guava **33.7.1-jre** and lz4-java **1.11.2** in security: neither reaches the
fixed versions **33.7.2** / **1.11.4** required below.

Historical candidate evidence was inspected, not rerun:
`$T/round6-native/opensearch39-candidate.raw.json` has **32 HIGH / 4 MEDIUM /
1 LOW**, image ID
`sha256:9ff9935a7a072370267d8a96208ce6c17f975b668ddcdc870c4c1d4a6c35e06e`.
Its retained upstream pull receipt binds the same `3b55f6…` index now returned by
the registry. The candidate report SHA-256 is
`ecbe9c83e3f7415e2d8ecf5278ad93b8289f9df0ded3e01348b21f4dd0039a98`.
This remains historical rejected evidence, not a current-DB candidate qualification.
There is no newly published digest or vendor remediation to justify another costly
3.9.0 build/scan today.

## 3. All 19 current HIGH instances: paths and actual fixed versions

Read `$S/opensearch.policy.json` and joined each blocker to exactly one raw finding
by advisory ID, package, installed version and JAR path in
`$S/opensearch.raw.json`. The join/assertions exited **0** at **07:41:02Z**;
`$E/joined-findings.json` retains all 19 rows and upstream references.

- Current accepted OpenSearch local image/index:
  `sha256:9e8e4dbcce0c9899d421bf46ad9c0b33143765e04b57f514313e6bc2b316ea27`.
- Scanned platform/config: **linux/arm64** /
  `sha256:a908737e056e65730f0509b730885b5009b6739ead3b6d089f8125d0cd28a637`.
- Raw report SHA-256:
  `4a6a2bfd0cfd9944a7c07f34a9e6ab6aa0c213eca04e3d8cae8b93ffdaf2c882`.
- Policy SHA-256:
  `8a008ce065fd5104d9e12e8f1805c93a216b224c9581dbd94dec24cc8968cd88`.
- Policy/raw counts remain **19 HIGH / 12 MEDIUM / 1 LOW**, no dispositions.

All path labels below are relative to **`/usr/share/opensearch/`**:

| Label | Exact JAR path |
| --- | --- |
| A | `plugins/opensearch-security/lz4-java-1.11.1.jar` |
| B | `lib/jackson-core-2.18.9.jar` |
| C | `lib/tools/upgrade-cli/jackson-core-2.18.9.jar` |
| D | `lib/tools/upgrade-cli/jackson-databind-2.18.9.jar` |
| E | `modules/ingest-geoip/jackson-databind-2.18.9.jar` |
| F | `plugins/opensearch-security/jackson-databind-2.18.9.jar` |
| G | `plugins/opensearch-job-scheduler/guava-32.1.3-jre.jar` |
| H | `plugins/opensearch-knn/guava-32.1.3-jre.jar` |
| I | `plugins/opensearch-security/guava-33.4.0-jre.jar` |
| J | `plugins/opensearch-security/snappy-java-1.1.10.7.jar` |

Fixed-version alternatives below are the actual raw report's `FixedVersion`
values, normalized only for ordering. They are **library versions, not available
OpenSearch distribution versions or instructions to replace JARs**.

| Advisory | Installed package/version | Paths | Instances | Fixed library versions |
| --- | --- | --- | ---: | --- |
| CVE-2026-106451 | `at.yawk.lz4:lz4-java` 1.11.1 | A | 1 | 1.11.4 |
| CVE-2026-89407 | `com.fasterxml.jackson.core:jackson-core` 2.18.9 | B, C | 2 | 2.18.11, 2.21.7, 2.22.3 |
| CVE-2026-89425 | Same core package/version | B, C | 2 | 2.18.11, 2.21.7, 2.22.3 |
| CVE-2026-68497 | `com.fasterxml.jackson.core:jackson-databind` 2.18.9 | D, E, F | 3 | 2.18.10, 2.21.6, 2.22.2 |
| CVE-2026-91776 | Same databind package/version | D, E, F | 3 | 2.18.11, 2.21.7, 2.22.3 |
| CVE-2026-91777 | Same databind package/version | D, E, F | 3 | 2.18.11, 2.21.7, 2.22.3 |
| CVE-2026-102554 | `com.google.guava:guava` 32.1.3-jre / 33.4.0-jre | G, H / I | 3 | 33.7.2 |
| CVE-2026-108106 | `org.xerial.snappy:snappy-java` 1.1.10.7 | J | 1 | 1.1.10.9 |
| CVE-2026-93451 | Same Snappy package/version | J | 1 | 1.1.10.9 |

### Supported removal/configuration paths considered

1. **Further optional-plugin removal:** vendor documentation supports removal
   using `bin/opensearch-plugin remove`, modifying a Docker image rather than a
   running container. Current Dockerfile already removes twelve unused plugins.
   Remaining k-NN and job-scheduler contain **G/H: two HIGH instances total**.
   The application adapter uses core REST document/index/search/mapping/scroll
   operations and standard analysis, not these plugin APIs. Job-scheduler removal
   requires handling its remaining dependents first: exact-tag geospatial and
   index-management builds both declare `extendedPlugins =
   ['opensearch-job-scheduler']`. The vendor removal CLI checks every installed
   plugin and refuses a still-extended dependency (exit 11); do not bypass it.
   A full dependency inventory and functional/storage qualification would still
   be needed for a new derivative. Even successful removal of both affected
   optional plugin copies leaves **17** of the current HIGH instances. No image
   was built merely to demonstrate this insufficient reduction.
2. **Required security plugin:** A/F/I/J contain **seven HIGH instances**. Removing
   or disabling this plugin defeats the accepted authentication, TLS and F2
   privilege boundary. Disabling one security feature does not remove its bundled
   dependencies. This path cannot be used.
3. **Core, upgrade CLI and built-in GeoIP:** B contains **two**, C/D **five**, E
   **three** HIGH instances. `RemovePluginCommand` operates on the plugins
   directory, not `lib`, upgrade tooling or built-in `modules`. The exact-tag
   GeoIP build explicitly bundles databind. No vendor-supported removal procedure
   for these specific delivered core/module/tool JARs was established. Disabling
   processors, avoiding the upgrade command or deleting individual JARs is not
   an artifact remediation. Even an official minimal distribution with the
   matching security plugin retains the core/security dependency problem; it is
   not evidence of a clean alternative.
4. **Vendor configuration mitigations:** the LZ4 vendor advisory documents a
   private `java.io.tmpdir` workaround, already considered in the historical
   remediation report. It leaves the affected JAR/version installed and does not
   satisfy this no-disposition artifact gate. FasterXML's DataInput advisory
   explicitly states that parser constraints cannot mitigate that specific
   invalid-token path. Other Jackson advisories have different model/coercion
   prerequisites; no supported OpenSearch setting was established that fixes all
   bundled copies. Snappy workarounds require checks at individual decompression
   call sites; a compressed chunk-size limit alone does not fix the allocation
   flaw, and the typed-array overflow has a separate alignment prerequisite.
   Guava's advisory concerns native deserialization. Network isolation, smaller
   heaps, TLS, generic body limits, unused APIs or serialization assumptions do
   not remove these raw findings or establish a permitted complete remediation.
5. **Severity/source disagreements:** today's LZ4 vendor page labels its advisory
   Low and Guava's labels its advisory Moderate, while the accepted Trivy report
   classifies their instances HIGH. The two Snappy vendor pages show the matching
   flaw/fix but currently say “No known CVE”; the CVE associations above come from
   the retained scanner references. None of these differences is silently used
   to downgrade, exclude or waive a current blocker.
6. **Other distribution/update routes:** an OS package update cannot replace
   these bundled Java libraries. A new supported vendor distribution/rebuild
   remains the viable complete-remediation route; unsupported source backports,
   arbitrary JAR substitution, JDK swaps and security-disabled images are not
   qualified candidates. Today's exact official patch and latest major artifacts
   provide no such new distribution.

The shared Trivy cache metadata was inspected read-only, not refreshed again:

| DB | Version | UpdatedAt | NextUpdate | DownloadedAt |
| --- | ---: | --- | --- | --- |
| Vulnerability | 2 | 2026-11-01T01:13:06.496727497Z | 2026-11-02T01:13:06.496727277Z | 2026-11-01T05:38:12.83160126Z |
| Java | 1 | 2026-11-01T00:58:32.801018394Z | 2026-11-04T00:58:32.801018003Z | 2026-11-01T05:40:52.213046458Z |

These are the cache's actual metadata and still fall inside the existing 48h/96h
windows at inspection; they do not prove that no newer publisher DB exists. The
last admission scan already used this day's DBs and pinned Trivy **0.76.1**, image
`sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c`.
No same-image/same-DB repeat or synthetic rescan PASS is presented.

## 4. Retrieval ledger and command exits

For recorded HTTP sources below, the executed curl form was:

```sh
curl --proto '=https' --proto-redir '=https' --tlsv1.2 --location \
  --fail-with-body --silent --show-error --connect-timeout 15 --max-time 60 \
  -H 'Cache-Control: no-cache' -H 'Pragma: no-cache' \
  -D "$E/LABEL.headers" -o "$E/LABEL.body" -w '%{json}' URL
```

The final Go request additionally used the explicit `--disable --no-insecure`
options shown above. All recorded HTTP requests report **TLS verification result
0**; HTTP failures are retained as failures. The helper's diagnostic orchestration
exit is separate from the actual child-command exits tabulated here.

All finish times are **2026-11-01 UTC**, shown without fractional seconds here;
exact start/end times and full argv are in `$E/LABEL.command.json`.

| Label / actual source | Retrieved at | HTTP / curl exit |
| --- | --- | --- |
| `go-index`: <https://vuln.go.dev/index/db.json> | 07:38:00 | 200 / 0 |
| `go-index-final`: same exact URL, hardened verification options | 07:41:39 | 200 / 0 |
| `core-releases`: <https://api.github.com/repos/opensearch-project/OpenSearch/releases?per_page=10> | 07:38:37 | 200 / 0 |
| `schedule`: <https://opensearch.org/releases/> | 07:38:35 | 200 / 0 |
| `notes-2197`: <https://raw.githubusercontent.com/opensearch-project/opensearch-build/2.19.7/release-notes/opensearch-release-notes-2.19.7.md> | 07:38:34 | 200 / 0 |
| `notes-390`: <https://raw.githubusercontent.com/opensearch-project/opensearch-build/3.9.0/release-notes/opensearch-release-notes-3.9.0.md> | 07:38:35 | 200 / 0 |
| `release-2198`: <https://api.github.com/repos/opensearch-project/OpenSearch/releases/tags/2.19.8> | 07:38:36 | **403 / 22**, rate-limited; not absence evidence |
| `release-2198-html`: <https://github.com/opensearch-project/OpenSearch/releases/tag/2.19.8> | 07:39:14 | **404 / 22** |
| `notes-2198`: <https://raw.githubusercontent.com/opensearch-project/opensearch-build/2.19.8/release-notes/opensearch-release-notes-2.19.8.md> | 07:39:13 | **404 / 22** |
| `core-notes-2197`: <https://raw.githubusercontent.com/opensearch-project/OpenSearch/2.19.7/release-notes/opensearch.release-notes-2.19.7.md> | 07:39:13 | 200 / 0 |
| `vendor-manifest-2197`: <https://raw.githubusercontent.com/opensearch-project/opensearch-build/2.19.7/manifests/2.19.7/opensearch-2.19.7.yml> | 07:39:13 | 200 / 0 |
| `plugin-docs`: <https://docs.opensearch.org/docs/2.19/install-and-configure/plugins/> → `/2.19/install-and-configure/plugins/` | 07:39:15 | 200 / 0 |
| `remove-cli`: <https://raw.githubusercontent.com/opensearch-project/OpenSearch/2.19.7/distribution/tools/plugin-cli/src/main/java/org/opensearch/plugins/RemovePluginCommand.java> | 07:39:13 | 200 / 0 |
| `module-build`: <https://raw.githubusercontent.com/opensearch-project/OpenSearch/2.19.7/modules/ingest-geoip/build.gradle> | 07:39:13 | 200 / 0 |
| `geo-build`: <https://raw.githubusercontent.com/opensearch-project/geospatial/2.19.7.0/build.gradle> | 07:39:55 | 200 / 0 |
| `index-management-build`: <https://raw.githubusercontent.com/opensearch-project/index-management/2.19.7.0/build.gradle> | 07:39:55 | 200 / 0 |
| `advisory-lz4`: <https://github.com/yawkat/lz4-java/security/advisories/GHSA-mcr4-qmvw-px4g> | 07:39:55 | 200 / 0 |
| `advisory-jackson-number`: <https://github.com/FasterXML/jackson-core/security/advisories/GHSA-p6pp-m3f8-5c89> | 07:40:24 | 200 / 0 |
| `advisory-jackson-core`: <https://github.com/FasterXML/jackson-core/security/advisories/GHSA-7hhh-6rmp-j9qf> | 07:39:55 | 200 / 0 |
| `advisory-databind-number`: <https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-q4xh-88c3-wmh7> | 07:40:24 | 200 / 0 |
| `advisory-databind-cache`: <https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-wv8q-qhhj-9h54> | 07:40:24 | 200 / 0 |
| `advisory-databind-reference`: <https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-cxp5-3px4-pw24> | 07:40:24 | 200 / 0 |
| `advisory-guava`: <https://github.com/google/guava/security/advisories/GHSA-xxph-c9ww-hj94> | 07:39:55 | 200 / 0 |
| `advisory-snappy-memory`: <https://github.com/xerial/snappy-java/security/advisories/GHSA-q233-g92q-qxp6> | 07:39:55 | 200 / 0 |
| `advisory-snappy-buffer`: <https://github.com/xerial/snappy-java/security/advisories/GHSA-gwq9-9ffj-mvrv> | 07:39:56 | 200 / 0 |
| `upgrade-guide`: <https://docs.opensearch.org/latest/migrate-or-upgrade/rolling-upgrade/> | 07:40:22 | 200 / 0 |
| `restore-guide`: <https://docs.opensearch.org/latest/migrate-or-upgrade/snapshot-restore/> | 07:40:23 | 200 / 0 |

`gh api -H 'Cache-Control: no-cache'
'repos/opensearch-project/OpenSearch/releases?per_page=20' --jq
'map({tag_name,draft,prerelease,published_at,html_url})'` could not execute:
`gh` is absent. Initial Python launcher exit **1** at 07:37:59Z; the recorded
unavailable-command receipt at **07:38:33Z** records child availability exit
**127**. No tool was installed; official HTTPS retrieval above supplied the data.
An initial exploratory web-tool request to `https://opensearch.org/versions/`
returned **404** and was replaced by the actual releases/schedule URL; it was not
used as release-absence evidence.

Other executed local diagnostic commands:

```sh
PYTHONDONTWRITEBYTECODE=1 python3 "$E/research.py" binding source-before
python3 -B "$E/research.py" analyze
docker ps -a --filter label=com.docker.compose.project=shopkmp-round7-acceptance-e2e \
  --format '{{.Names}} {{.State}} {{.Image}}'
```

All **exit 0**: initial binding **07:37:59Z**, final recorded report/metadata
analysis **07:41:01–07:41:02Z**, retained-container query **07:41:01Z**. Every
returned canonical container was **exited**, including all 12 required services
and retained provider/fixture containers. No lifecycle operation followed.

## 5. Concrete next steps and qualification boundary

1. **Go DB publisher:** obtain fresh authoritative `modified` metadata, then run
   the five exact current Go checks. Keep 72h unchanged. Do not rebuild accepted
   runtime images merely to refresh advisory evidence.
2. **OpenSearch distribution/security maintainers:** provide a published supported
   2.19.x patch or official rebuilt distribution that incorporates the required
   library fixes in **every bundled location** above. Track 2.19.8's actual
   publication or an earlier supported security release; its planned December
   window is not a reason to claim a fix exists today. A future supported 3.x
   artifact is a separate candidate, not an automatic migration.
3. **Only when a genuinely new candidate exists:** resolve official index and
   platform digests first; qualify a separate owned image/tag with the pinned
   scanner, freshly fetched/validated DBs, exact image/config/archive hashes and
   a nonempty CycloneDX SBOM. Retain complete unmodified findings and enforce
   zero CRITICAL/HIGH/UNKNOWN without exceptions. Include the bundled JDK in
   vendor-advisory review rather than assuming the JAR/OS scanner covers it.
4. **Coordinate subsequent API/storage qualification:** on an isolated restored
   copy, verify real TLS/authentication, F2 role denials/operator boundaries,
   document create/update/delete, mappings, filtering, all sorts including
   `id.keyword`, pagination/scroll, staging/reconciliation and persisted restart.
   Preserve PostgreSQL authority and document identities. Back up data and the
   security configuration using supported procedures, then prove restore and a
   rollback rehearsal with the matching old image/backup. Vendor upgrade docs
   explicitly prohibit in-place downgrade: rollback is a fresh compatible
   installation plus restore, not pointing an old binary at upgraded storage.
   These gates are **not executed or approved by this research**.
5. **Coordinator admission:** a later source/image change requires a new bound
   canonical functional receipt and exact-artifact security qualification. The
   current 503-input PASS cannot certify a replacement image. Preserve existing
   accepted tags and volumes throughout candidate work; resolve independent
   platform/native-architecture gates before publication.

**Current resolution:** blocked on upstream advisory publication and an actually
available clean supported OpenSearch distribution. Additional optional-plugin
trimming is a limited reduction path, not a complete security resolution.

## Final preservation verification

Executed **07:44:53–07:44:54Z**:

| Actual command/check | Exit / observation |
| --- | --- |
| `python3 -B "$E/research.py" binding source-after` | **0**; all **503** source hashes still match `023f7403…9bf3`; receipt remains `tested:true`, build/test 0/0; immutable images present and all **12** receipt tag references still resolve to their accepted IDs |
| `git diff --check` | **0**, no diagnostics |
| `git diff --cached --quiet` | **0**, index still empty |
| `git diff --no-index --check /dev/null docs/backend-fixes/round-7/SECURITY_FOLLOWUP.md` | **1**, zero stdout/stderr diagnostics; `--no-index` reports the new-file difference from `/dev/null`, not a clean-worktree result |

Receipts: `$E/preservation-final.command.json`, `source-after.json`,
`whitespace-worktree.command.json`, `empty-index.command.json` and
`whitespace-document.command.json`. The completed document receives the same
no-index check in `whitespace-document-final.command.json`. No runtime or test
execution result was promoted by these document/preservation checks.
