# Backend security and trusted-CA TLS verification

**Final disposition, 2026-10-27: functional checks passed within the snapshot scopes recorded in `TEST_RESULTS.md`; overall task/release remains A1 BLOCKED by unresolved runtime-image security findings. No commit or push.**

The two concrete ops Go dependency findings below are **superseded and resolved**: current `x/text v0.41.0` / `x/sync v0.22.0`, Go 1.26.10 source and rebuilt migration-binary scans exit **0**. Current BFF Go 1.27.3 source/binary scans and Composer audit also exit **0**. The rebuilt migration image retains only three UNKNOWN distro advisories, with no HIGH/CRITICAL findings. This does **not** clear PHP, Keycloak, nginx, PostgreSQL or OpenSearch image findings.

The TLS project is now **STOPPED by the coordinator**, confirmed on 2026-10-27, and was not restarted for the final postcheck. The successful TLS run remains the **2026-10-26 resource-qualified test**, not a fresh TLS verification of subsequent application changes or a default-capacity claim. The PKI helper's subsequent dynamic upper-half FPM allocation regression passes **1/0**, exit **0**.

See [SECURITY_POSTCHECK.md](SECURITY_POSTCHECK.md) for current commands/exits, ops remediation/report hashes, latest existing PHP image inventory, final backend/docs secret-scan classification, and snapshot limitations. [TEST_RESULTS.md](TEST_RESULTS.md) and [FINAL_REVIEW.md](FINAL_REVIEW.md) retain the overall **A1 BLOCKED** disposition. Original inventories, failed attempts, image identities and dates below are preserved as historical evidence.

## Historical checkpoint — 2026-10-26

Original audit disposition: security gate failed on findings. Trusted-CA TLS smoke passed with the isolated test resource override; default resource readiness remained blocked in the shared Docker VM. That day's last smoke completed at **08:19:17 UTC**, after rebuilding the then-current PHP source and refreshing only the isolated TLS stack.

## Scope and isolation

- Current backend working tree, including backend deployment configuration and both Go modules. Existing concurrent changes are preserved.
- Disposable production-mode project: `shopkmp-backend-fixes-tls-e2e`; published ingress `127.0.0.1:58198`; ingress `172.31.222.0/24` (edge `.2`, Yii HTTP `.3`, SDUI/trusted BFF `.4`); FPM `172.31.223.0/24` (nginx `.3`). The sibling project on port 58196 and existing stacks are untouched.
- Short-lived test CA, service certificates, keys and random test credentials are outside the repository, under `/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/`. No production credentials/accounts or real payments are used.
- Private evidence directory, abbreviated `$EVIDENCE` below: `/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/backend-fixes-security-tls-20261026`. Command status JSON files record command, working directory, UTC start/end and process exit; full logs stay there. Secrets are not reproduced in this report.
- `$TLS_ENV` means the **path**, not contents, printed by the PKI helper: `/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/shopkmp-task4-pki-uq25_og2/test.env`. Docker CLI is supplied through `/Applications/Docker.app/Contents/Resources/bin` on `PATH`.

## Historical results — 2026-10-26

| Check | Actual exit/result | Interpretation |
|---|---|---|
| Disposable PKI regression, before fix | unittest exit 1; helper rejected the four requested CLI options with exit 2 | Reproduced missing isolation options before editing helper. |
| Same regression after fix | exit 0; one real generation/chain/mode test | Generated trust/IP settings match requested isolated topology; env mode 0600, directory 0700. |
| Production wrapper `config --quiet` | 0 | Production certificate/secret validation and Compose resolution passed. |
| Production wrapper `build` | 0 | Current PHP 8.3, SDUI, ops migration and Keycloak images built. |
| First production `up -d --wait --wait-timeout 240` | 1 | `reindex` exited 1, `Configured upstream unavailable`; no healthy-stack claim. OpenSearch subsequently restarted repeatedly. |
| OpenSearch Docker events | `oom` followed by `die`, exitCode 137 | Confirmed resource failure, not a certificate-validation bypass. Investigation continues. |
| Keycloak 26.3.3 built-in probe, production HTTPS URL + CA | 0 | Live current image management interface uses HTTPS on port 9000. |
| Same probe with `HEALTHCHECK_URL=http://keycloak:9000/health/ready` | 1 | Plain HTTP does not serve this listener. Existing HTTPS health configuration retained. |
| `composer audit --locked --format=json` | 0 | `advisories`, `abandoned`, `filter` all empty. |
| SDUI `govulncheck` text mode | 0 | No vulnerabilities found; analyzer uses Go 1.27.3, scanner v1.9.0. |
| Ops `govulncheck` text mode through `go run` | 1 (tool reports inner exit 3) | Findings, not a scanner outage; details below. Initial JSON mode returns 0 even with findings, so text-mode gate was also run. |
| Trivy first DB mirror | 1 | `mirror.gcr.io/aquasec/trivy-db:2` layer fetch HTTP 404. |
| Trivy explicit official GHCR DB retry | 0 | Download from `ghcr.io/aquasecurity/trivy-db:2` succeeded using verified TLS; scans can proceed. |
| Gitleaks tracked backend snapshot, initial / final | 1 / 1; 9 / 10 detections | Reviewed non-secret prose, variable references and synthetic test fixtures; full secret text is redacted. |
| Gitleaks untracked backend additions snapshot, initial / final | 1 / 1; 7 / 7 detections | Reviewed synthetic test fixtures; full secret text is redacted. |
| Gitleaks backend `git diff HEAD`, initial / final | 0 / 1; 0 / 5 detections | Final diff adds synthetic auth-test passwords; no actual credential leak identified. Untracked additions are separately scanned above. |
| Resolved deployment boundaries and seven invalid production configurations | 0 | `scripts/test_infrastructure.py`; production/dev isolation, exact BFF/FPM trust allocation and fail-closed configuration validation passed. |
| Production up with isolated test resource override | 0 | All 11 long-running services healthy; migrate, yii-migrate and reindex exit 0. |
| Real production TLS smoke | 0 | Fifteen expected HTTP responses plus unknown host/SNI rejection; PDO TLS and both DB plaintext rejections passed. |
| PHP upstream positive/negative CA probes, final corrected probe | 0 | Discovery, exact issuer, JWKS, Keycloak management and authenticated OpenSearch over verified HTTPS; curl verification result 0; wrong CA/hostname rejected by curl and PDO. |
| Keycloak probe with public-system roots only / wrong hostname | 1 / 1 | Both expected failures; the private test root is required and hostname checks are active. |
| Live Keycloak JDBC PostgreSQL sessions | 0; `1\|t` | One TCP session observed and its TLS flag true, with production JDBC `sslmode=verify-full`. |
| Payment-effect query | 0; `0\|0` | No payments or payment operations exist in this isolated database. Readiness checks gateway configuration without calling it; no provider stub was needed. |
| Migrations 001–011 | 0; 22 SQL files | Bytes match `HEAD`, SHA-256 matches the original `HEAD` manifest. |
| Final current-source PHP rebuild / up / smoke / upstream probes | 0 / 0 / 0 / 0 | Both changed production PHP files incorporated; all 39 production PHP source hashes match the current working tree. |
| Final refreshed PHP image audit / Composer audit | 10 / 0 | Same image findings as initial PHP build; no Composer advisories/abandoned packages. |

### Historical Go ops findings — superseded by 2026-10-27 remediation

Source analysis of `tools/ops` used host Go 1.27.2 (distinct from the production image builder):

- `GO-2026-6809`: `crypto/x509` compromised-intermediate distrust update, fixed in Go 1.27.3; source trace reaches `cmd/healthcheck` via TLS. Actual built binary Go version still to be checked; do not infer it from host analysis.
- `GO-2026-6629`: `golang.org/x/text v0.29.0`, fixed in v0.41.0; panic in `secure/precis`, source trace through pgx SCRAM authentication into migrations.
- `GO-2026-5970`: same dependency, fixed in v0.39.0; invalid UTF-8 normalization loop, source trace through pgx SCRAM authentication into migrations.

Both ops production binaries actually use **Go 1.26.10**. Repeating source analysis with `GOTOOLCHAIN=go1.26.10` and directly invoking govulncheck exits **3**, finding only the two `x/text` advisories above. The built `/migrate` binary independently exits **3** for the same two advisories. The built Keycloak healthcheck and SDUI server binaries each exit **0**, no vulnerabilities found. Thus the host Go 1.27.2 distrust advisory is **not** attributed to these production binaries.

The SCRAM path processes operator-controlled DB credentials, not anonymous HTTP input; symbol reachability alone does not prove either exploit precondition (invalid UTF-8 / Nickname short-buffer handling). These remain actionable dependency findings, not suppressed. The narrow candidate remedy is an ops-only `x/text` update to a compatible version at least v0.41.0, followed by ops tests/build and rescans. Official Go advisory sources confirm the versions: [GO-2026-6629](https://vuln.go.dev/ID/GO-2026-6629.json), [GO-2026-5970](https://vuln.go.dev/ID/GO-2026-5970.json). No dependency/runtime updates have been made by this audit; remediation is for coordinator approval.

## Resource failure and TLS evidence

The first OpenSearch failure included a live Docker `oom` event and `die` exit 137. Increasing this test container's limit from 1 GiB to 2 GiB briefly allowed verified requests but **did not provide sustained readiness**. The shared VM reports 8,321,515,520 bytes total memory and had only 102,044 KiB available during diagnosis, with 57 concurrent containers. At that point the OpenSearch container cgroup reported `oom_kill=0`, so continued restarts cannot honestly be attributed solely to its own limit. Historical Docker events later aged out of the event buffer; the earlier tool observation is retained in this report, not replaced by a fabricated event log.

To finish functional TLS verification without altering other stacks, an off-repo **test-only** override at `$EVIDENCE/compose.tls-test-resources.yml` sets OpenSearch heap to `-Xms256m -Xmx256m` and its limit to 2 GiB. No image, security plugin, TLS option, trust root, route, network or data volume is changed by this override. Full up/health and smoke pass with it. This establishes TLS correctness for that test run; it is not a production sizing/capacity sign-off. The original 512 MiB heap / 1 GiB container setup remains unproven under this shared-host load. [OpenSearch 2.19 Docker documentation](https://docs.opensearch.org/docs/2.19/install-and-configure/install-opensearch/docker/) documents heap controls and Docker memory requirements; it does not establish an adequate limit for this deployment's workload.

Live Keycloak **26.3.3**, unchanged, logs public HTTPS on 8443 and management HTTPS on 9000. Its version-specific [management property mapper](https://github.com/keycloak/keycloak/blob/26.3.3/quarkus/runtime/src/main/java/org/keycloak/quarkus/runtime/configuration/mappers/ManagementPropertyMappers.java) maps management certificates from public HTTPS certificates. No newer management-scheme semantics were substituted. The configured CA-verified HTTPS healthcheck passes; HTTP, wrong-host and wrong-CA checks fail.

`scripts/production_smoke.py` uses `ssl.create_default_context(cafile=...)`, verifies the DNS identity `api.shopkmp.test`, and does not disable hostname or peer verification. The CA is a disposable private test CA explicitly trusted by the clients; this is **not a public WebPKI certificate or an actual production-environment test**. No `-k`, `--insecure`, verify-peer disabling or fake readiness was used.

Successful smoke coverage:

- Seven public operational/documentation routes return 404: `/health`, `/health/live`, `/health/ready`, `/metrics`, `/swagger/`, `/swagger/openapi.yaml`, `/fpm-ping`.
- Unknown HTTP Host is disconnected; unknown SNI handshake is rejected.
- Categories and anonymous home return 200.
- Forged forwarding/user/role headers cannot bypass webhook source policy: 403 `WEBHOOK_SOURCE_DENIED`.
- A newly generated test account registers (201), logs in (200), loads authenticated home/profile (200/200), and logs out (204). Tokens/passwords are not logged.
- PHP PDO's verified connection reports `pg_stat_ssl.ssl=true`; both PostgreSQL servers reject `sslmode=disable` TCP connections with `pg_hba.conf ... no encryption`.
- Separate PHP probes reject an untrusted CA and wrong DNS identity in both curl and PDO. An early probe exited 255 because PHP 8.3 does not expose the newer `CURLE_PEER_FAILED_VERIFICATION` constant name; the test-only probe uses libcurl error 60 and was rerun successfully. Another intermediate probe failed while OpenSearch was restarting. Neither failure is counted as a pass.

## Historical seven-image vulnerability inventory — 2026-10-26

Trivy **0.76.0**, vulnerabilities only, all severities, no ignore file and no unfixed-package suppression. Explicit `--exit-code=10` distinguishes detected findings from tool failure (1). Counts below are **package/advisory occurrences**, including repeated advisories in multiple artifacts/packages; not unique CVEs or proven exploitable paths.

| Actual runtime image | Exit | Critical | High | Medium | Low | Unknown | Total |
|---|---:|---:|---:|---:|---:|---:|---:|
| PHP Yii2 (final refreshed artifact) | 10 | 7 | 291 | 1491 | 1118 | 108 | 3015 |
| SDUI | 10 | 0 | 0 | 0 | 0 | 3 | 3 |
| Ops migrate | 10 | 0 | 2 | 0 | 0 | 3 | 5 |
| Keycloak 26.3.3 (successful retry) | 10 | 8 | 81 | 216 | 65 | 0 | 370 |
| nginx 1.27-alpine (edge + Yii HTTP) | 10 | 2 | 58 | 100 | 46 | 6 | 212 |
| PostgreSQL 17-alpine (both DBs) | 10 | 1 | 34 | 38 | 15 | 3 | 91 |
| OpenSearch 2.19.3 | 10 | 19 | 431 | 340 | 18 | 0 | 808 |

DB download initially failed with a mirror HTTP 404, then succeeded from official GHCR. Keycloak's first Java DB download failed with `unexpected EOF` (exit 1); OpenSearch's subsequent Java DB download succeeded, and Keycloak was rescanned successfully with the cached DB (exit 10). **No final image is left unscanned because of these download errors.** Both original failures remain in private status/log files.

### Findings, applicability and bounded remediation candidates

- **Ops migration:** two HIGH `x/text` advisories above, confirmed by source and actual binary analysis. This is a specific dependency remediation candidate, not a reason to update unrelated runtimes.
- **SDUI:** only `DLA-4845-1` (`ca-certificates 20250419~deb12u1`, fixed `20250419~deb12u2`) and `DLA-4792-1` / `DLA-4854-1` (`tzdata 2026b-0+deb12u1`, fixed `2026c` / `2026e`). Scanner classifies these UNKNOWN. Consider a separately validated distroless digest refresh for the trust-store update; no HIGH/CRITICAL or Go-symbol finding is present in this artifact.
- **nginx:** critical `CVE-2026-31789` against `libcrypto3` and `libssl3 3.3.3-r0` (scanner fix `3.3.7-r0`) describes a **32-bit** X.509 overflow; scanned runtime is arm64, so that architecture precondition is absent here. Other HIGH findings include curl/libcurl `8.12.1-r1` (scanner fix `8.14.1-r3`), OpenSSL and XML/image libraries. nginx terminates public TLS but uses HTTP for its private upstreams. Inventory presence is not proof those library code paths are reachable. Evaluate an official supported nginx/base-image refresh and retest routing/TLS; do not silently roll the stack to a different runtime.
- **PHP image:** distro findings include runtime `libxml2 2.9.14+dfsg-1.3~deb12u6`, `libsqlite3-0 3.40.1-2+deb12u2`, curl/libcurl `7.88.1-10+deb12u15`, plus build-only development packages. `CVE-2026-6653` is CRITICAL in the scanner, but Debian marks the bookworm libxml2 issue postponed/minor; untrusted XML use was not demonstrated by this audit. No bookworm fixed version is supplied for that or SQLite `CVE-2025-7458`. Kernel CVEs against `linux-libc-dev 6.1.190-1` are header/source-package detections, not evidence that a container ships a running vulnerable kernel. `CVE-2023-45853` against `zlib1g`/`zlib1g-dev` is [explicitly ignored by Debian for bookworm because the affected contrib/minizip code is not built](https://security-tracker.debian.org/tracker/CVE-2023-45853). These classifications do not suppress the remaining findings. Targeted OS patching/removal of unnecessary build packages deserves its own validated change; PHP remains 8.3.
- **PostgreSQL image:** 61 of 91 findings are in the bundled `usr/local/bin/gosu` built with Go 1.24.6, including CRITICAL `CVE-2025-68121` and many HTTP/TLS issues. gosu is a privilege-drop helper, not a network listener; static build-version inventory does not show those paths are called. OS HIGH findings include `libcrypto3`/`libssl3 3.5.7-r0` (fix `3.5.8-r0`) and `libuuid 2.42.1-r0` (fixes `2.42.3-r0/r1`). DBs are private and require TLS, but those controls do not remove image findings. Evaluate an official PostgreSQL-17 image refresh and DB compatibility/TLS checks.
- **Keycloak 26.3.3:** CRITICAL findings include `org.keycloak:keycloak-services 26.3.3`, `CVE-2026-18963` (scanner fixes `26.4.15, 26.6.6, 26.7.2`), `io.netty:netty-handler 4.1.124.Final`, `CVE-2026-75595` (fix `4.2.17.Final, 4.1.137.Final`), FreeMarker `2.3.32` / `CVE-2026-84939` (fix `2.3.35`), and Bouncy Castle `1.81` / `CVE-2025-14813`, `CVE-2026-8763` (fixes include `1.84`, `1.85`). The Keycloak reset-flow account-takeover advisory requires coordinator/vendor review even though the issuer/admin interface is private; registration being disabled is not proof it is mitigated. CA-package distrust advisory `CVE-2026-114406` also appears. No exploit or reset-flow test against production identities was attempted. Keep version-specific health semantics while separately planning a security-supported Keycloak update.
- **OpenSearch 2.19.3:** CRITICAL occurrences include Netty handler `4.1.118.Final` / `CVE-2026-75595`, Bouncy Castle `bc-fips 2.0.0`, `bcprov-jdk15to18 1.78.1`, `bcprov-jdk18on 1.78` / `CVE-2026-8763`, and provider occurrences of `CVE-2025-14813`. Relevant HIGH findings also include OS curl `8.11.1-4.amzn2023.0.1` and many Java artifacts. It is private, authenticated and TLS-protected, but actual vulnerability reachability has not been disproved. Use an official supported service update with index/security compatibility testing, not ad-hoc replacement of jars.

The exact complete package/advisory/fixed-version inventories are in `$EVIDENCE/trivy-{yii2,sdui,migrate,keycloak,nginx,postgres,opensearch}.json`; the latest PHP rescan is `trivy-yii2-current.json` (earlier refresh `trivy-yii2-final.json` also retained). Scanner-suggested fixes above are audit data, **not approved upgrades**. Security sign-off remains failed while these findings await review/remediation.

## Historical secret scan classification — 2026-10-26

Gitleaks **v8.30.1**, default rules, full `--redact=100`; no added allowlist/baseline. Backend snapshots include tracked current file contents and separate untracked additions; `.git` history/vendor/ignored local credentials are not silently presented as scanned. Initial scope: 343 tracked files and 49 untracked backend/deployment/test/report files; diff includes staged and unstaged tracked changes relative to `HEAD`.

All 16 initial detections were inspected in context; **no actual credential leak identified**:

| Snapshot | Location(s) | Detections | Classification |
|---|---|---:|---|
| Tracked | `backend-sdui/docs/refactoring/07-SECURITY.md:43` | 1 | Prose containing “signing key”/“credential”, not a key value. |
| Tracked | `backend-yii2/tests/auth.php:11,31–34`, `tests/api.php:109` | 6 | Test-only synthetic passwords and surrounding fixture code; generated test identities. |
| Tracked | `docker-compose.prod.yml:96` | 1 | Runtime variable reference for curl basic auth; no literal password. |
| Tracked | `docker-compose.yml:95` | 1 | JDBC URL/variable context; no credential embedded in URL. |
| Untracked | `backend-yii2/tests/session_concurrency.php:20`, `session_password_recovery.php:19,28,45,57,71`, `session_revocation.php:54` | 7 | Test-only old/new-password fixtures, not runtime/provider credentials. |

Initial raw scanner exits were 1/1 for the two snapshots and 0 for the diff; a reviewed non-secret classification does not change those raw exits to zero. No matched secret values are reproduced here.

**Final rescan after concurrent changes:** 343 tracked files and 54 untracked additions; raw exits tracked/untracked/diff **1/1/1**, with **10/7/5** detections. Tracked `auth.php` fixture changes shift/add detections to lines 11, 31, 32, 39, 44, 48 (plus `api.php:109`); other tracked locations above are unchanged. All five final diff matches are synthetic password/old-password literals in that auth-test change. The seven untracked findings remain the same test fixtures. All final detections were reviewed, no real credential identified. The final report was also included in the untracked snapshot. Original and final logs/reports are kept separately; final redacted reports/manifests are under `$EVIDENCE/final-secret-scan/`, with `final-gitleaks-*.status.json` in `$EVIDENCE`.

After the last concurrent recovery-source edit, a further current-source scan under `$EVIDENCE/current-secret-scan/` reproduced the same 343/54 file counts, 10/7/5 detections and 1/1/1 exits; detected rules, locations and redacted values match the reviewed set exactly. `current-gitleaks-*.status.json` preserves this run. A separate scan of the completed report exited 0; a direct comparison against generated test credential values also found none in the report.

## Historical runtime identities and source provenance — 2026-10-26

Repository base: `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9` plus current uncommitted backend fixes. No commit/push performed. Go module/lock files and Composer manifest/lock are unchanged versus that base. Initial image refresh predated concurrent `RecoveryService.php`/`PaymentOperations.php` edits; their mismatch was detected, PHP rebuilt, its image rescanned, and the full TLS smoke repeated. One further `RecoveryService.php` change was caught by the final freshness check; the PHP build, up, Composer/image/secret audits, upstream probes and smoke were repeated again. Latest `runtime-php-source-hashes-current.json` verifies **39/39 current production PHP files** against the running container. Earlier `*-final.*` evidence is preserved, not overwritten. The tracked Go module source snapshot has no changes since its initial audit/build.

All runtime/scanner images below are **linux/arm64**. These are locally resolved immutable image IDs/repository digests, not a claim of registry signature validation. Full inspect metadata: `images.json` (initial set), `image-yii2-current.json` and `runtime-state-current.json` (latest actual running/exited containers); intermediate `*-final.json` records are also retained.

| Image / role | Resolved runtime digest (`sha256:`) |
|---|---|
| Yii2, migrations, reindex, schedulers — current PHP 8.3.36 | `bc444d84cf44ba941e9bfe1d97941c05a1f42db82d15d4a257b4a21eb4ffbf9b` |
| Yii2 — intermediate refreshed image, retained in old logs | `4dd7d52f32f09e42ddddd21f51c1d48f397605759a0bcfabe27b09c4e0c3d512` |
| Yii2 — initial audited image, retained in old logs | `51405fd9947a09854c2dcc12c9ac87a61d8c9fbd27ac021ec067d227663c6f1c` |
| SDUI — Go 1.27.3 | `ac425f3a0d2429268111fac56c61e325d66e6140b88e0d71fab1913fcedd10ae` |
| Ops migrate — Go 1.26.10 | `4020aea85c95352810991a9e973578dcb42db1c25677ad76269ec34a3cc65f92` |
| Keycloak 26.3.3 + Go 1.26.10 healthcheck | `90e186f388706dc5d3171bd30c4d869686b8e45ae84f10052251632e721e21e4` |
| nginx 1.27.5 (`nginx:1.27-alpine`) | `65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10` |
| PostgreSQL 17.10 (`postgres:17-alpine`) | `742f40ea20b9ff2ff31db5458d127452988a2164df9e17441e191f3b72252193` |
| OpenSearch 2.19.3 | `e96cc6ae1500a073d973c0906f30f7cf4d9c461f32f855f9242a2da933660cdd` |
| Trivy 0.76.0 | `2416d1f74045b57274df1a340eee6b510cacd73c66a6ccb8da092b117eb7d222` |
| Gitleaks v8.30.1 | `c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f` |

Resolved source/base digests from the actual build log:

| Base / build tool | Resolved digest (`sha256:`) |
|---|---|
| `php:8.3-fpm-bookworm` | `6b4641a875dd3e0f7551b2751e1f5909084856d18166af7fbb522ac230feb702` |
| `composer:2` (Composer 2.10.3) | `b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc` |
| `golang:1.27.3-alpine` | `b8c9b82affbbb82a020bbcf998dad2ba9516944ac7e883524642a481a9505e9c` |
| Ops `golang:1.26-alpine` / `1.26-alpine3.24` | `27f2d3b83cfc5803c8078f8e730af0f7c431bf067afaec8f60a36fb763187cba` |
| `gcr.io/distroless/static-debian12:nonroot` | `afa5c872c891853ca7fcf1f12c3edb23f7eeef36189728842dd51042ff57f7ab` |
| `quay.io/keycloak/keycloak:26.3.3` | `6a7217a100bd3e5de4063a27a538ef999a3c5a88c4b4ec0ffc0a642aee7b2597` |

Trivy vulnerability DB v2 updated `2026-10-26 01:20:01 UTC`, downloaded `07:06:32 UTC`; Java DB v1 updated `2026-10-26 00:59:12 UTC`, downloaded `07:40:21 UTC`. Go DB last-modified `2026-10-21T00:27:29Z`. PHP curl is 7.88.1 with Debian security patch `7.88.1-10+deb12u15`, OpenSSL 3.0.22. No audit failed finally because a DB, dependency, scanner, or network was unavailable; the transient download failures above recovered. The outstanding blockers are findings and default resource readiness, not a falsely clean unavailable scan.

## Historical handoff — 2026-10-26, superseded above

- Current-source functional TLS checks pass with explicit CA and DNS validation. Observed ingress negotiation: **TLS 1.3 / TLS_AES_256_GCM_SHA384**, `CERT_REQUIRED`, hostname checking enabled. Certificate SAN `api.shopkmp.test`; disposable CA; valid 2026-10-26 07:03:13 to 2026-10-29 07:03:13 UTC. Public-system roots alone reject it (verification error 20), as expected for a private test CA.
- At this checkpoint the security release gate failed for the ops dependency findings and unresolved runtime-image inventory. The ops findings were subsequently resolved; other image findings still block A1 as of 2026-10-27.
- At this checkpoint the isolated TLS stack was left running: 11 healthy services, zero restarts after the resource-override recreation, and three successful one-shot jobs. The coordinator subsequently stopped it to free memory; the final postcheck preserves that stopped state. Recreating OpenSearch without the override restores the original resource settings and invalidates the resource-qualified readiness result.
- Test-only repository changes from this audit: `scripts/test_pki.py` isolation/trust options and `scripts/test_test_pki.py` regression. No core payment/session/trust logic, dependency lockfiles, service runtime versions or production health semantics were changed by this audit.

## Historical reproduction commands used — 2026-10-26

```sh
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
TMPDIR=/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode \
  python3 -m unittest discover -s scripts -p test_test_pki.py
python3 scripts/test_pki.py \
  --parent /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode \
  --project shopkmp-backend-fixes-tls-e2e --port 58198 \
  --edge-subnet 172.31.222.0/24 --fpm-subnet 172.31.223.0/24
# Set TLS_ENV to the returned env-file path; never print its contents.
python3 scripts/compose.py --env "$TLS_ENV" --prod config --quiet
python3 scripts/compose.py --env "$TLS_ENV" --prod build
python3 scripts/compose.py --env "$TLS_ENV" --prod up -d --wait --wait-timeout 240
docker exec shopkmp-backend-fixes-tls-e2e-keycloak-1 /opt/keycloak/bin/shopkmp-healthcheck
docker exec -e HEALTHCHECK_URL=http://keycloak:9000/health/ready \
  shopkmp-backend-fixes-tls-e2e-keycloak-1 /opt/keycloak/bin/shopkmp-healthcheck
docker run --rm shopkmp-backend-fixes-tls-e2e-yii2 composer audit --locked --format=json
go -C backend-sdui run golang.org/x/vuln/cmd/govulncheck@v1.9.0 ./...
go -C tools/ops run golang.org/x/vuln/cmd/govulncheck@v1.9.0 ./...
docker run --rm -v backend-fixes-security-trivy-cache:/root/.cache/trivy \
  aquasec/trivy:0.76.0 image --download-db-only \
  --db-repository ghcr.io/aquasecurity/trivy-db:2 --no-progress

# Isolated resource-qualified run (the private override is documented above).
# Production validate(load_env(TLS_ENV)) was called before these Compose commands.
docker build --tag shopkmp-backend-fixes-tls-e2e-yii2 backend-yii2
docker compose --env-file "$TLS_ENV" \
  -f docker-compose.yml -f docker-compose.prod.yml \
  -f "$EVIDENCE/compose.tls-test-resources.yml" \
  up -d --wait --wait-timeout 180
python3 scripts/production_smoke.py --env "$TLS_ENV" --disposable
# Private, read-only upstream probe; reports only check names/booleans.
docker exec -i shopkmp-backend-fixes-tls-e2e-yii2-1 php \
  < /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/security_tls_probes.php

# Direct scanner exits, matching the real ops image's build toolchain.
GOTOOLCHAIN=go1.26.10 "$EVIDENCE/govulncheck" -C tools/ops ./...
"$EVIDENCE/govulncheck" -mode=binary "$EVIDENCE/migrate"
"$EVIDENCE/govulncheck" -mode=binary "$EVIDENCE/keycloak-healthcheck"
"$EVIDENCE/govulncheck" -mode=binary "$EVIDENCE/server"

# Initial command template for each of the seven distinct runtime images.
# IMAGE is one of the exact names in images.json; OUTPUT selects its report.
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock:ro \
  -v backend-fixes-security-trivy-cache:/root/.cache/trivy \
  -v "$EVIDENCE:/out" aquasec/trivy:0.76.0 image \
  --image-src=docker --scanners=vuln --skip-db-update \
  --java-db-repository=ghcr.io/aquasecurity/trivy-java-db:1 \
  --no-progress --timeout=10m --exit-code=10 --ignorefile= \
  --format=json --output="/out/$OUTPUT" "$IMAGE"
# Successful Keycloak retry and final PHP rescan replaced the Java repository
# option with --skip-java-db-update and used --timeout=3m (DB already cached).

# Final secret snapshots contain CURRENT tracked files and untracked additions
# selected by git ls-files --cached / --others --exclude-standard in these scopes:
# backend-yii2 backend-sdui tools/ops deploy scripts docker-compose.yml
# docker-compose.prod.yml .env.example .env.prod.example Makefile
# .github/workflows/ci.yml docs/backend-fixes
# Snapshot creation uses the private security_tls_audits.py helper, which copies
# only selected regular files and records per-file SHA-256 (never file values).
docker run --rm -v "$EVIDENCE/final-secret-scan/secret-tracked:/scan:ro" \
  -v "$EVIDENCE/final-secret-scan:/out" ghcr.io/gitleaks/gitleaks:v8.30.1 \
  dir --redact=100 --no-banner --no-color --report-format=json \
  --report-path=/out/gitleaks-tracked.json /scan
# Same command for secret-untracked / gitleaks-untracked.json.
git diff --no-ext-diff --no-color HEAD -- backend-yii2 backend-sdui tools/ops \
  deploy scripts docker-compose.yml docker-compose.prod.yml .env.example \
  .env.prod.example Makefile .github/workflows/ci.yml docs/backend-fixes | \
  docker run --rm -i -v "$EVIDENCE/final-secret-scan:/out" \
  ghcr.io/gitleaks/gitleaks:v8.30.1 stdin --redact=100 --no-banner --no-color \
  --report-format=json --report-path=/out/gitleaks-diff.json
```

Tool versions observed: Docker client/engine 29.6.2, Docker Desktop 4.83.0, Compose v5.3.1, Trivy 0.76.0, Gitleaks v8.30.1, govulncheck v1.9.0. Go vulnerability DB reports last modification `2026-10-21T00:27:29Z`.
