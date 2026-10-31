# Fresh V6 runtime security results

**Latest continuation, 31 October 06:01:29Z:** full source-bound backend acceptance
now passes and its successful receipt is accepted. Fresh seven-image scan still
has six clean image policies and the same 19 OpenSearch HIGH instances. Go DB
freshness remains blocked. See CONTINUATION_RESULTS and continuation evidence
index; older missing-functional-receipt findings below describe the earlier wave.

**Final decision: BLOCKED.** Native platform **linux/arm64**, scan completed
`2026-10-31T02:01:40Z`, Trivy **0.76.1** pinned digest `790fd66d…eca6c06c`,
govulncheck **1.9.0**. All seven runtime image reports and CycloneDX SBOMs were
produced. Payment/maintenance/reconcile use the same PHP image.

| Runtime | First fresh image policy | Final image policy |
| --- | --- | --- |
| PHP local auth / PHP workers | PASS, no findings | **PASS** |
| Go SDUI | 4 UNKNOWN | **PASS** |
| SQL migrator | 4 UNKNOWN | **PASS** |
| PostgreSQL / gosu | 1 UNKNOWN | **PASS** |
| OpenSearch | 19 HIGH, 11 MEDIUM, 1 LOW | **FAIL: same 19 HIGH** |
| Edge Nginx | PASS | **PASS** |
| Internal PHP Nginx | PASS | **PASS** |

No ignore file, severity downgrade or agent-approved risk disposition was added.
`deploy/security/dispositions.json` is unchanged. The earlier wave lacked a complete
functional receipt; the continuation now provides a true tested receipt from the
full command. Focused passes were never used to set it true.

## Addressed runtime findings

**Static Go runtimes:** the publisher now lists only Debian 13 images as updated;
other tags are deprecated. Native registry inspection confirmed that the latest
Debian 12 nonroot tag still resolves to the existing `afa5c872…57f7ab` digest.
SDUI and migrator move to supported shell-free/nonroot
`static-debian13:nonroot@sha256:18f4cc26c9ffa7f20a359298be7deadac5c607c8e03a02b8119f1a5fc2f82c99`.
Applications remain CGO-disabled static Go, same toolchains and UID policy.
Native startup/migration, shared-edge CARD and final 65-step live BFF/race checks
passed. This is a runtime distribution update, not a lowered Go floor.

**gosu module:** retained the source release/commit/verified tarball and Go 1.26.10;
updated only x/sys **0.1.0 → 0.49.0** through normal module tooling in the actual
helper build. Build checks still demonstrate UID/GID 70. PostgreSQL 17.11 restarted
on retained disposable data. The extracted final binary reports x/sys 0.49.0.
The primary CNA identifies CVE-2026-39824 as **Windows NewNTUnicodeString**, fixed
in 0.44.0. This corrects the distributed module floor/inventory; it is **not a
claimed reachable Linux exploit fix**.

Initial findings were DLA-4845-1 on ca-certificates `20250419~deb12u1` and
DLA-4792-1/4854-1/4867-1 on tzdata `2026b-0+deb12u1`, repeated in two Debian 12
images. Final Debian 13 inventory contains ca-certificates `20250419+deb13u1` and
tzdata `2026e-0+deb13u1`; distribution-specific final policies are clean. A clean
scan does not establish universal reachability or production TLS acceptance.

Primary sources checked **31 October 2026**:

- [Distroless publisher/support and image list](https://github.com/GoogleContainerTools/distroless#what-images-are-available), native GCR manifests for Debian 12/13.
- Official Debian trackers: [DLA-4845-1](https://security-tracker.debian.org/tracker/DLA-4845-1),
  [DLA-4792-1](https://security-tracker.debian.org/tracker/DLA-4792-1),
  [DLA-4854-1](https://security-tracker.debian.org/tracker/DLA-4854-1),
  [DLA-4867-1](https://security-tracker.debian.org/tracker/DLA-4867-1).
  The linked advisory pages returned “I Challenge Thee”; their prose was not
  represented as successfully retrieved.
- [CVE-2026-39824 primary Go CNA](https://cveawg.mitre.org/api/cve/CVE-2026-39824),
  [GO-2026-5024](https://pkg.go.dev/vuln/GO-2026-5024).

## OpenSearch: remaining HIGH findings

**19 package/path instances, nine distinct CVEs, ten JAR paths.** Latest compatible
upstream **2.19.7** is already used. Its
[20 October release](https://github.com/opensearch-project/OpenSearch/releases/tag/2.19.7)
explicitly bundles Jackson 2.18.9. A library fix does not establish a tested
supported distribution. No manual JAR/JVM replacement or unverified 3.9 major
migration was performed.

| Family / instances | Primary CNA / publisher | Verified library fix and prerequisites |
| --- | --- | --- |
| yawk LZ4 1.11.1 / 1 | [CVE-2026-106451](https://cveawg.mitre.org/api/cve/CVE-2026-106451), [publisher](https://github.com/yawkat/lz4-java/security/advisories/GHSA-mcr4-qmvw-px4g) | 1.11.4; JNI extraction and local shared writable temporary-directory race. Exclusions not established |
| Jackson core 2.18.9 / 4 | [CVE-2026-89407](https://cveawg.mitre.org/api/cve/CVE-2026-89407), [89425](https://cveawg.mitre.org/api/cve/CVE-2026-89425), [publisher 1](https://github.com/FasterXML/jackson-core/security/advisories/GHSA-p6pp-m3f8-5c89), [2](https://github.com/FasterXML/jackson-core/security/advisories/GHSA-7hhh-6rmp-j9qf) | 2.18.11 (also 2.21.7/2.22.3); numeric coercion / DataInput parser paths, not proven by presence |
| Jackson databind 2.18.9 / 9 | [CVE-2026-68497](https://cveawg.mitre.org/api/cve/CVE-2026-68497), [91776](https://cveawg.mitre.org/api/cve/CVE-2026-91776), [91777](https://cveawg.mitre.org/api/cve/CVE-2026-91777), [publisher 1](https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-q4xh-88c3-wmh7), [2](https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-wv8q-qhhj-9h54), [3](https://github.com/FasterXML/jackson-databind/security/advisories/GHSA-cxp5-3px4-pw24) | Common 2.18.11 floor; temporal binding/polymorphism/identity-forward-reference configurations. OpenSearch paths not established |
| Guava 32.1.3/33.4.0-jre / 3 | [CVE-2026-102554](https://cveawg.mitre.org/api/cve/CVE-2026-102554), [publisher](https://github.com/google/guava/security/advisories/GHSA-xxph-c9ww-hj94) | 33.7.2; attacker-influenced native Java object deserialization, not ordinary JSON use |
| Snappy 1.1.10.7 / 2 | [CVE-2026-108106](https://cveawg.mitre.org/api/cve/CVE-2026-108106), [93451](https://cveawg.mitre.org/api/cve/CVE-2026-93451), [publisher 1](https://github.com/xerial/snappy-java/security/advisories/GHSA-q233-g92q-qxp6), [2](https://github.com/xerial/snappy-java/security/advisories/GHSA-gwq9-9ffj-mvrv) | 1.1.10.9; crafted decompression / typed-array JNI methods. Deployed paths not proven |

Copies occur in upgrade CLI, ingest, security, k-NN and job-scheduler paths.
Presence and private networking establish neither exploitability nor exemption.
Publisher/CNA severity differences are context; the recorded HIGH severities and
existing policy are not overridden. No owner-approved disposition exists.

## Independent freshness and acceptance blockers

The authoritative [Go DB index](https://vuln.go.dev/index/db.json) was fetched
again: **2026-10-27T21:16:18Z**, older than the unchanged **72-hour** policy on
31 October. Native source and extracted server/migrator/gosu binary scans exit
0/no raw findings, but **policy is BLOCKED**. Earlier 30 October dependency-wave
scans were inside the window; their earlier acceptance is not copied forward.

Initial Java DB downloads failed with GHCR HTTP/2 `PROTOCOL_ERROR`. The same
pinned tool/repository succeeded over HTTP/1 as download-only troubleshooting.
Subsequent unmodified security invocations checked the fresh cache/content hashes
and scanned every image. No application HTTP/TLS policy or scanner rule changed.

Final reports/SBOM and source/image/config/archive hashes are indexed in
`evidence/INDEX.md`. linux/amd64 full runtime execution/scanning is **NOT_RUN**.
Database freshness, unresolved HIGHs and remaining native acceptance must close before
the user's conditional commit/push/release instruction applies.
