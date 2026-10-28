# A1 PHP runtime remediation

**Coordinator follow-up:** the root-project PHP artifact was rebuilt and scanned
again with the **October 28 vulnerability DB**: **zero findings, exit 0**. The
actual final image also passes all five runtime cases and 18 scheduler cases at
timing scale 1. Root stack integration, final image identity and serial TLS status
are recorded in [A1_RUNTIME_RESULTS.md](A1_RUNTIME_RESULTS.md); the component
build results and October 26 DB provenance below remain historical evidence.

## Result (2026-10-28)

**The PHP leg reaches zero raw High/Critical findings with a supported fix,
without suppressions.** Final all-severity Trivy scan: **0 findings**, exit **0**,
using the shared DB updated **2026-10-26T01:20:01Z**. The same default image passes
all **five runtime cases**, **seven application unit tests**, **79-file syntax
lint**, and Composer validation/platform requirements. This is PHP-scoped evidence,
not closure of the other A1 images or a deployment of the disposable stack.

Selected runtime: pinned official **PHP 8.3.36 FPM / Alpine 3.24**, pinned
**Composer 2.10.3**, and the Alpine vendor fix **curl/libcurl 8.23.0-r0** for
**CVE-2026-92392**. No PHP framework, lockfile or application-source change.

| Actual raw report in `E` | Critical | High | Medium | Low | Unknown | Scan exit |
|---|---:|---:|---:|---:|---:|---:|
| `postcheck-20261027-trivy-php.json` (Bookworm baseline) | 7 | 291 | 1491 | 1118 | 108 | 10 |
| `a1-php-trivy.json` (interrupted Trixie candidate) | 1 | 54 | 92 | 149 | 1 | 10 |
| `a1-php-alpine-trivy.json` (first Alpine candidate) | 0 | 2 | 2 | 44 | 0 | 10 |
| `a1-php-alpine-curlfix-trivy.json` (targeted vendor fix) | 0 | 0 | 0 | 0 | 0 | 0 |
| `a1-php-final-trivy.json` (owned-source final build) | 0 | 0 | 0 | 0 | 0 | 0 |

Counts are vulnerability rows, not deduplicated CVEs. Raw nonzero reports and
their actual exit codes are retained. No blanket ignore-unfixed rule,
NotAffected claim, private-TLS exemption, or invented risk acceptance is used.

## Scope and checkpoint (2026-10-28)

Owned files: `backend-yii2/Dockerfile`, `scripts/test_php_runtime.py`, and this report.
Baseline HEAD: `32675b249b861f1e19bfb1149fdbd44ea7734dd2`.
The Composer lockfile and PHP application source are immutable for this work.
The default image must remain production FPM **and** the Composer/CLI test image.

Evidence directory (`E`):
`/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/backend-fixes-security-tls-20261026`.
Each new operation uses a distinct `a1-php-*` log/status/report name. Status JSON
records the exact argv, working directory, UTC timestamps and actual exit code.

## Established baseline

- Original Bookworm runtime: 298 raw High/Critical findings (prior audit).
- Interrupted Trixie candidate: official PHP 8.3.36 + Composer 2.10.3, development
  packages/headers/tools removed; `shopkmp-a1-php` index
  `sha256:1729698770c940614ec81c2c7ef71d0413dd8cf9ca53fe0bca61ae6e29e4d5cf`.
- `a1-php-runtime-red.log`: original image fails the real installed-package
  assertion; five cases run, one failure (36 development packages).
- `a1-php-runtime-green.log`: five cases pass on the Trixie candidate.
- `a1-php-make-test.log`: seven application tests, including integer money
  boundaries, and syntax lint of 79 files pass.
- `a1-php-trivy.json`: **1 Critical, 54 High, 92 Medium, 149 Low, 1 Unknown**;
  raw scan exit **10**, no ignore file or unfixed filter. Trixie is not A1 closure.
  Debian libxml2 is actually `2.12.7+dfsg+really2.9.14-2.1+deb13u3`;
  `a1-php-package-policy.log` confirms the installed affected packages were already
  the repository candidates. A tag change alone did not remediate them.

## Official publication and support verified before the candidate build

The official-images PHP publication file explicitly lists
`8.3.36-fpm-alpine3.24`, source commit
`f6048b5f6e3c2202ee082665a4ccc0d90de291a3`, directory
`8.3/alpine3.24/fpm`. It includes amd64 and arm64v8 (also 32-bit platforms;
our runtime contract must require 64-bit integers).

`a1-php-alpine-manifest.log` (exit 0) verifies the published OCI index:

- Index: `sha256:25e15cf35ed0f5b75d8cf14fc2c4ed7e0e6bee00d359e681b7fa3e91a7bee49f`
- linux/arm64/v8: `sha256:e7895641feafab60a1897a0a2ed846f23a09a5cb5de530ab37c763bad6f0108b`
- linux/amd64: `sha256:4bba7da77c6a8473122b7826df62763b5639d89b1eedfe6d38ffa10bb222f4fa`

Official support checked on 2026-10-28:

- PHP 8.3: security support through **2027-12-31**:
  <https://www.php.net/supported-versions.php>.
- Alpine 3.24: current stable branch, main/community supported now, main end of
  support **2028-06-01**; community support ends at the next stable release:
  <https://alpinelinux.org/releases/>.
- Publication: <https://github.com/docker-library/official-images/blob/master/library/php>.
- Build recipe: <https://github.com/docker-library/php/blob/f6048b5f6e3c2202ee082665a4ccc0d90de291a3/8.3/alpine3.24/fpm/Dockerfile>.

The publication and support responses are also retained locally as
`a1-php-official-library.txt`, `a1-php-supported-versions.html`, and
`a1-php-alpine-releases.html`, with fetch command/status/checksum receipts.
`a1-php-composer-manifest.log` independently verifies Composer 2.10.3's pinned
official index `sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc`.
Only its Composer executable is copied; its PHP/runtime packages are not imported.

### First actual Alpine measurement

`a1-php-alpine-build.status.json`: exit 0; candidate image
`shopkmp-a1-php-alpine-candidate`, index
`sha256:00e7dc041d577f413feb38d87cc77a509a8416a0caca1e1ec5095ef3a723028a`.
`a1-php-alpine-trivy.json`: **0 Critical, 2 High, 2 Medium, 44 Low**; raw exit
**10**. Both High rows are **CVE-2026-92392**, curl and libcurl `8.22.0-r0`,
fixed in Alpine `8.23.0-r0`. The official vendor advisory confirms affected
versions 8.12.0–8.22.0 and the supported fix 8.23.0:
<https://curl.se/docs/CVE-2026-92392.html>.
This is a measured reason for a targeted `apk upgrade curl libcurl`, not a broad
dependency refresh. No unfixed High/Critical remains to classify in this candidate.

`a1-php-alpine-runtime-portability-red.log`: five cases run, three failures;
confirmed Debian assumptions are dpkg-only inventory, multiarch-only libpq path,
and running musl's relocating `ldd` against PHP extension DSOs outside PHP.
PHP itself already loads the extensions and the real FPM ping passes. The updated
contract must test clean extension loading in the actual PHP host (all DSOs,
eager binding, no ini masking), not disregard loader failures. It also explicitly
requires 64-bit integer money support and keeps installed-package/header checks.

### Supported curl fix measured

`a1-php-alpine-curlfix-build.log` shows **only curl/libcurl** upgraded by the
targeted security operation: `8.22.0-r0` → `8.23.0-r0`. Extension build dependencies
remain a temporary virtual package. A brotli update pulled by build dependencies
is a normal stable-repository dependency resolution, not an application change.
The Alpine vendor SecDB is retained as `a1-php-alpine-secdb-main.json`; its curl
`8.23.0-r0` secfixes explicitly include CVE-2026-92392.

`a1-php-alpine-curlfix-trivy.json`: **0 Critical, 0 High, 0 Medium, 0 Low,
0 Unknown**; actual all-severity scan exit **0**. It scans 52 OS packages,
Composer vendor dependencies, and the detected node package metadata. There is
no ignore file, severity filter, ignore-unfixed flag, or NotAffected exception.
The source Dockerfile now selects this measured supported Alpine route.

The first portable test iteration exposed APK 3's missing-index warnings on a
no-cache/no-network runtime. Inventory now explicitly uses the installed database
with `apk --no-network --repositories-file /dev/null info -q`, verified against
all 52 actual installed packages; malformed/empty inventories still fail.

The private debug candidate's `apk policy` command populated APK indexes;
`a1-php-curlfix-runtime-tls.log` correctly rejects that cache (four cases pass,
one fails). The final source has no `apk policy` command. Its uncached build
passes the unchanged cache-absence assertion; no failure was hidden or relaxed.

## Final owned-source verification

Final image: `shopkmp-a1-php-alpine-final`, **linux/arm64**, 64-bit integers.

- Built OCI index: `sha256:22d22eebd705f692358faeaf0159d74bf299a181a1cf5e26cf70025236fcb4b0`
- Platform manifest: `sha256:daee23c1de71c24f52655b8915a40a10af853b629e19d29442f419122fde7297`
- Image config: `sha256:766ecd1e93918a5dc79eb54d632949b2a345de9d5de44510f8160e8cddfb29db`
- Make-built image: `shopkmp-a1-php-alpine-make`, index
  `sha256:4e70e9242ca9f6e11bace6e8787faee51eb5b6ba2aa0cc5b4dec2890c578adc2`;
  same platform manifest/config/layers (different build attestation).
- Both retain `docker-php-entrypoint` and default `CMD ["php-fpm"]`.
- Actual runtime: PHP **8.3.36**, Composer **2.10.3**, curl **8.23.0**, libxml2
  **2.13.9-r2**, libpq **18.6-r0**, OpenSSL **3.5.9-r0**. libpq is the client
  library; this change does not upgrade the PostgreSQL server.

### Exact commands and recorded exits

Commands run from the repository root with Docker's directory prepended to PATH:

```sh
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
docker build --progress=plain -t shopkmp-a1-php-alpine-final backend-yii2
python3 scripts/test_php_runtime.py --image shopkmp-a1-php-alpine-final
make test-php PHP_TEST_IMAGE=shopkmp-a1-php-alpine-make
docker run --rm --network none shopkmp-a1-php-alpine-final composer validate --no-check-publish
docker run --rm --network none shopkmp-a1-php-alpine-final composer check-platform-reqs --no-dev
```

The last two commands also run inside the five-case contract and the combined
version/inventory receipt. Full argv and UTC timestamps are in each status JSON.

| Status/log prefix in `E` | Actual exit | Evidence |
|---|---:|---|
| `a1-php-final-source-build` | 0 | Fresh extension compilation, install from immutable lockfile |
| `a1-php-final-runtime` | 0 | Five runtime cases, direct PDO/libpq TLS probe |
| `a1-php-final-runtime-app-tls` | 0 | Final five cases, real application Database adapter + libpq TLS |
| `a1-php-final-make-test` | 0 | Unchanged Make target: default-image build, 7 unit tests, lint 79 files |
| `a1-php-final-platform` | 0 | Composer 2.10.3, valid composer.json, all platform requirements, 52 installed OS packages |
| `a1-php-final-trivy` | 0 | Final image all-severity raw report, zero findings |
| `a1-php-db-version` | 0 | Trivy 0.76.0 and exact cached DB timestamps |
| `a1-php-final-image-identity` | 0 | Architecture and default FPM command/entrypoint of final and Make images |
| `a1-php-boundaries-verified` | 0 | Index, source and original running-container identity comparison |

The final runtime contract exercises these five independent concerns:

1. **Installed runtime surface:** real APK/dpkg inventory must be nonempty and
   well-formed; reject development packages, GCC/Clang/LLVM/build tools, headers,
   static archives, PHP source tarballs and phpize/php-config.
2. **ELF loading after pruning:** both CLI and FPM dependencies resolve; every
   installed shared extension loads in the real PHP host with `-n` and eager
   binding. Exact clean output catches startup/linker diagnostics. No missing
   library/symbol exception is ignored to accommodate musl.
3. **PHP 8.3 runtime behavior:** 64-bit integer boundary; PDO_pgsql, intl
   normalization, mbstring, sodium round-trip, OpenSSL digest, cURL HTTPS,
   OS CA bundle, GD PNG/JPEG/WebP round-trips and FreeType support, fork/wait,
   and scheduler `proc_open`. The **unchanged application Database adapter**
   consumes `sslmode=verify-full` plus `PGSSLROOTCERT`: real loopback TLS accepts
   a trusted hostname and rejects both wrong hostname and unknown CA.
   The TLS-only PostgreSQL-protocol peer deliberately returns an authentication
   error after a valid encrypted handshake; this proves transport verification,
   not SQL/database integration, and uses no external service or real credential.
4. **Default-image CLI contract:** lockfile SHA-256 equality, Composer validation
   and all platform requirements, autoloaded Yii/JWT, existing fixture/migration
   entrypoints, no package download caches, and upload limits.
5. **Production FPM contract:** actual deployed `deploy/php/fpm.conf` passes
   `php-fpm -tt`; a real FastCGI request receives `pong` inside an ephemeral
   network-none container. No host ports or application service writes.

The original real RED evidence is preserved. The portable contract also rejects
the original image's development packages (`a1-php-portable-contract-original-red`,
exit 1); the final five cases pass on the selected image. Money correctness is
additionally exercised by the existing application tests, including the NUMERIC
boundary, excess fractional digits and overflow; no floating-money substitute.

### Exact raw final scan

Here `E` is the evidence directory defined above:

```sh
docker run --rm \
  -v /var/run/docker.sock:/var/run/docker.sock:ro \
  -v backend-fixes-security-trivy-cache:/root/.cache/trivy \
  -v "$E:/out" aquasec/trivy:0.76.0 image \
  --image-src=docker --scanners=vuln \
  --skip-db-update --skip-java-db-update --no-progress --timeout=5m \
  --exit-code=10 --ignorefile= --format=json \
  --output=/out/a1-php-final-trivy.json shopkmp-a1-php-alpine-final
```

Actual exit **0**; scan timestamp **2026-10-28T03:09:33Z**. Coverage is 52 Alpine
OS packages, five Composer vendor packages and one detected node package.
All severities are retained; Trivy's compiled-PHP coverage is not represented as
a separate PHP security audit. Exact scanner/DB receipt: `a1-php-db-version.log`:

- Trivy **0.76.0**, vulnerability DB schema **2**.
- Updated **2026-10-26T01:20:01.38093772Z**.
- Downloaded **2026-10-26T07:06:32.291878173Z**.
- Advertised next update **2026-10-27T01:20:01.380937429Z**.

No DB refresh was performed by this PHP worker; it reused the coordinator's
snapshot for comparable measurements. A coordinator refresh/rescan, if performed,
must preserve these raw reports and use distinct filenames.

## Wiring and disposition

`docker-compose.yml`'s existing `x-yii` build remains `./backend-yii2`; FPM,
workers, fixtures and migrations therefore continue using the default image.
`Makefile`'s existing `test-php` target builds the same Dockerfile with no target
selection, then runs `composer test` and `composer lint` offline. Both routes are
preserved; no separate target model or shared Compose/Makefile edit was needed.
Production `DATABASE_URL` continues using `sslmode=verify-full`, with
`PGSSLROOTCERT=/run/tls/ca.crt` and the existing read-only CA/TLS ini mounts.

**Disposition:** original Debian findings are remediated by selecting the
supported official Alpine runtime and removing build-only packages/artifacts;
the first Alpine candidate's remaining curl findings are **fixed**, not ignored,
by the vendor's 8.23.0-r0 security package. Final raw High/Critical is **0/0**;
there is no unresolved PHP-leg vendor blocker or NotAffected/risk-acceptance list
against the recorded snapshot. Results apply to the tested **arm64** build;
amd64 publication/digest was verified, but an amd64 application build was not run.

`a1-php-before.json` and `a1-php-boundaries-verified.log` verify:

- Git index SHA-256 remains
  `781acaee53bbe7c44965a971639c38abc5cdf24fcfe64e24f5c7937cb52dde74`;
  the staged UI work is preserved.
- All **96** tracked PHP application/lock/config/fixture files outside the owned
  Dockerfile retain their pre-work content hashes.
- All original running-container IDs/images are preserved; the root/UI-verified
  stack remains on its original image, and TLS's separate project remains stopped.
- No A1 test containers remain. Only new `shopkmp-a1-php-*` image tags were added.

Changes are limited to the three owned source/report files. No commit, push,
subagent, production service, payment operation or real credential was used.
