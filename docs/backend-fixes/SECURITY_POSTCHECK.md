# Final backend security postcheck — 2026-10-27

**Functional checks: PASS within the full-root/affected-suite scopes in [TEST_RESULTS.md](TEST_RESULTS.md). Overall task/release: A1 BLOCKED by unresolved runtime-image security findings. No commit or push.**

This final pass changes documentation only. It closes the superseded ops dependency finding, checks the latest existing artifacts, and scans final intended backend/docs source. No service versions/dependencies/application source are changed in this pass. The coordinator-stopped TLS project remains stopped; TLS is not rerun against the final source. [SECURITY_TLS_RESULTS.md](SECURITY_TLS_RESULTS.md) preserves the dated 2026-10-26 TLS and original seven-image evidence.

`$EVIDENCE` below is `/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/backend-fixes-security-tls-20261026`. New evidence uses the `postcheck-20261027-` prefix and does not overwrite earlier reports. Status JSON records exact arguments, working directory, UTC timestamps and raw process exit; logs and snapshots remain private. Credentials, token values and private keys are not reproduced here.

## Current verified checks

### Coordinator final-wire artifact follow-up

After this postcheck, the coordinator rebuilt the PHP image with the string-only
recovery error detail. The current immutable image is
`sha256:4af2de608eb1c0ca046bf08ded17e1b6238959261816ef1d79bb5e2aa1beddd7`.
An actual Trivy 0.76.0 all-severity, no-ignore cached-DB scan on **2026-10-27
03:55 UTC** returned **exit 10**. The exact current report is
`$EVIDENCE/trivy-yii2-final-wire.json`: **7 CRITICAL, 291 HIGH, 1491 MEDIUM,
1118 LOW, 108 UNKNOWN**. Thus the earlier pre-string image qualification is now
superseded for the PHP vulnerability inventory, and **A1 remains BLOCKED**.
The scan did not rebuild dependencies, suppress findings or restart TLS.

| Check | Raw exit / actual result | Evidence in `$EVIDENCE` |
|---|---|---|
| Ops source, explicitly Go 1.26.10 | **0**, no vulnerabilities found | `postcheck-20261027-govulncheck-ops.{log,status.json}` |
| Rebuilt `/migrate` binary | **0**, no vulnerabilities found | `postcheck-20261027-govulncheck-migrate-binary.{log,status.json}` |
| BFF source, explicitly Go 1.27.3 | **0**, no vulnerabilities found | `postcheck-20261027-govulncheck-sdui-matched-toolchain.{log,status.json}` |
| Current BFF `/server` binary | **0**, no vulnerabilities found; metadata Go 1.27.3 | `postcheck-20261027-govulncheck-sdui-binary.{log,status.json}` |
| Composer 2.10.3 audit, latest existing root PHP image | **0**; empty `advisories`, `abandoned`, `filter` | `postcheck-20261027-composer-audit.{log,status.json}` |
| Latest existing root PHP image, Trivy 0.76.0 | **10**; 7 CRITICAL, 291 HIGH, 1491 MEDIUM, 1118 LOW, 108 UNKNOWN | `postcheck-20261027-trivy-php.{json,log,status.json}` |
| Remediated root migrate image, Trivy 0.76.0 | **10**; only 3 UNKNOWN, **zero HIGH/CRITICAL** | `postcheck-20261027-trivy-migrate.{json,log,status.json}` |
| PKI isolation/trust/dynamic FPM range regression | **0**; 1 test, 0 failures | `postcheck-20261027-pki-regression.{log,status.json}` |
| Intended tracked backend/docs secret snapshot | **1**, 12 reviewed non-secret detections across 399 files | `postcheck-20261027-gitleaks-tracked.{log,status.json}` |
| Intended untracked backend/docs secret snapshot | **1**, 7 reviewed synthetic-fixture detections across 62 files | `postcheck-20261027-gitleaks-untracked.{log,status.json}` |
| Backend/docs tracked diff relative to HEAD | **1**, 5 synthetic auth-fixture detections | `postcheck-20261027-gitleaks-diff.{log,status.json}` |
| Existing PHP image/current source equality | **Not equal: 40/41 files match**; inspection PHP CLI exits 0, comparison assertion exits 1 | `postcheck-20261027-php-source-hashes.{json,log,status.json}` |

Trivy uses `--exit-code=10` for **any severity**, so the remediated migration's raw 10 is due to UNKNOWN distro inventory; it is not a remaining Go vulnerability. The source and actual-binary Go scans above both return 0. PHP's nonzero result represents unresolved findings, not an unavailable scanner/database.

An initial BFF source invocation without an explicit toolchain used scanner/host Go **1.27.2**, returned **3**, and reported `GO-2026-6809`. That failed invocation is retained as `postcheck-20261027-govulncheck-sdui.{log,status.json}`. BFF `go.mod` and the actual built binary require/use **1.27.3**; the corrected toolchain-matched source scan and actual-binary scan each return 0. This ambient-host result is not attributed to the current BFF runtime. govulncheck version is **v1.9.0**; current source scans leave no remaining Go finding.

## Chronological supersession of ops findings

1. **2026-10-26:** ops source/binary and original migration-image scans found `GO-2026-6629` / `CVE-2026-56851` and `GO-2026-5970` / `CVE-2026-56852` in `golang.org/x/text v0.29.0`. Original reports/digests and raw failure exits remain in `SECURITY_TLS_RESULTS.md` and the private evidence.
2. **Targeted coordinator remediation:** `tools/ops` locks now select **x/text v0.41.0** and its required **x/sync v0.22.0**; the module still declares Go **1.26.0**. Official module metadata fetched from [proxy.golang.org x/text v0.41.0](https://proxy.golang.org/golang.org/x/text/@v/v0.41.0.mod) and [x/sync v0.22.0](https://proxy.golang.org/golang.org/x/sync/@v/v0.22.0.mod) declares Go **1.25.0**; x/text explicitly requires x/sync v0.22.0. No ops Go upgrade or downgrade is necessary. The official Go advisory fixes are v0.41.0 and v0.39.0 respectively, so this targeted version addresses both findings.
3. **2026-10-27 artifact confirmation:** `/migrate` metadata shows **Go 1.26.10**, x/text **v0.41.0** and x/sync **v0.22.0**. Source and actual-binary scans exit **0**. The rebuilt migration inventory no longer contains either HIGH finding.

Current migration image:

`shopkmp-backend-fixes-e2e-migrate@sha256:5fa5f60da41542d106d6b02813060a77be401c381721b1728658f95825c0a7ea`

| Report | SHA-256 of exact JSON bytes |
|---|---|
| Coordinator's `trivy-migrate-remediated.json`, created 2026-10-27 03:28:01 UTC | `3b75d3ef29681c25fc0ad20a87e46365e30f493c681c3a90800ba304b882627d` |
| Fresh `postcheck-20261027-trivy-migrate.json` | `f990d1e7b0e7ae5e05fb07751ec806295f26e091119fa41f88ca1e5bca22be88` |

Both reports identify the same immutable image and only these UNKNOWN occurrences:

- `DLA-4845-1`: `ca-certificates 20250419~deb12u1`, fix `20250419~deb12u2`.
- `DLA-4792-1`: `tzdata 2026b-0+deb12u1`, fix `2026c-0+deb12u1`.
- `DLA-4854-1`: same installed tzdata, fix `2026e-0+deb12u1`.

Current ops file hashes: `go.mod` = `1cc8e928a4aa27bbafbc95cafd17dd3d98b4e366bd0c862bf14b0ca54aac9346`; `go.sum` = `b90c1ce39e44b933baf4b2d6c8674e41f9d66d8b71b1d94e93923d3441c5f469`.

## Latest PHP artifact and unchanged image blockers

The latest existing root PHP image was scanned without rebuilding or starting a stack:

`shopkmp-backend-fixes-e2e-yii2@sha256:3de5a2092cfa0a3ebc7a6c6bd09a2bdee6fd4899ea1eab801f0e463d1c06d277`

Its exact package/version inventory and advisory/package/version/fix/severity inventory match the previously scanned PHP artifact. Trivy report SHA-256: `da8888c328649959cf08cb44e6f885ef4498df51fb975665701a03eb4b3844eb`. Its Composer lock matches the working tree (`635397897f4c832360b9ea606df628e74a3812ccf869c53ade8e4d8362e0c040`). Composer remains clean, but the PHP image's **3015** package/advisory occurrences remain unresolved. These are inventory occurrences, not a unique-CVE count or a claim that all paths are exploitable.

Snapshot qualification: the metadata-only source-hash comparison checked **41 files** (39 production PHP files plus Composer manifest/lock). **40 match; `application/PasswordSessionRecovery.php` differs** because the final string-only error-detail correction follows the last root image build. A separate network-disabled source-shape inspection confirms that this image still has the Boolean flag, while current source has the corrected string. No fresh all-source image match is claimed. The full-root F1–F3 pass and narrow wire-fix regressions are distinguished in `TEST_RESULTS.md`; no full root rerun or image rebuild was performed for this documentation pass. Current source uses `recoveryRequired: "true"`, preserving the existing OpenAPI/CMP string-only details map. The dependency/image scan remains valid for the explicitly identified existing artifact, not an unpublished future build.

No broad seven-image rescan was needed: nginx/PostgreSQL/OpenSearch still resolve to the exact historical immutable digests; BFF/Keycloak locally rebuilt tags have identical filesystem layers to the scanned TLS images, with differences only in image labels. `postcheck-20261027-images.json` and `postcheck-20261027-payload-comparison.json` record those comparisons. This carries forward the existing inventory only; it does not clear any finding or attest signatures.

| Runtime | Historical CRITICAL / HIGH occurrences carried forward | Current disposition |
|---|---:|---|
| PHP, latest artifact freshly scanned | 7 / 291 | **A1 BLOCKED** |
| Keycloak 26.3.3 | 8 / 81 | **A1 BLOCKED** |
| nginx 1.27-alpine | 2 / 58 | **A1 BLOCKED** |
| PostgreSQL 17-alpine | 1 / 34 | **A1 BLOCKED** |
| OpenSearch 2.19.3 | 19 / 431 | **A1 BLOCKED** |
| Ops migration, rebuilt and freshly scanned | 0 / 0; 3 UNKNOWN | Original two Go findings resolved |
| BFF | 0 / 0; 3 UNKNOWN | Source/binary scans clean; original distro inventory retained |

The earlier architecture/path/package-source applicability qualifications remain documented, without blanket exclusions. No service is upgraded and no HIGH/CRITICAL inventory is falsely cleared by this postcheck. No publication/deployment gate is passed.

Database provenance: Trivy uses the existing cached vulnerability DB v2 updated **2026-10-26 01:20:01 UTC**, downloaded 07:06:32 UTC; Java DB v1 updated 2026-10-26 00:59:12 UTC, downloaded 07:40:21 UTC. These are **cached-DB scans**, not a claim of a fresh 2026-10-27 vulnerability DB download. The carried-forward five service inventories retain their original scan date. No network or DB failure is misreported as a clean scan.

## Final source secret scan

Final snapshot scope: `backend-yii2`, `backend-sdui`, `tools/ops`, `deploy`, `scripts`, **all `docs/`** (including backend final reports and `docs/DEPLOYMENT.md`), root Compose/env examples, `Makefile` and `.github/workflows/ci.yml`. Tracked current contents and untracked non-ignored additions are scanned separately using `git ls-files`; tracked staged/unstaged diff is scanned relative to `HEAD`. Original tracked generated JSON captures were restored by the coordinator; initial enumeration for this pass found **zero untracked JSON source captures**. No generated capture is silently substituted for intended source.

Gitleaks **v8.30.1**, default rules, `--redact=100`; no new allowlist, baseline or blanket path exclusion. Actual snapshot: **399 tracked files, 62 untracked additions**. Raw tracked/untracked/diff exits are **1/1/1**, with **12/7/5** detections. Every detection's secret field is fully redacted. These are scanner detections including overlap, not 24 distinct leaked secrets. Context review identified **no actual credential leak**; the raw nonzero exits are preserved.

| Scan | Location(s) | Count | Context classification |
|---|---|---:|---|
| Tracked | `backend-sdui/docs/refactoring/07-SECURITY.md:43` | 1 | Security prose, not a key value. |
| Tracked | `backend-yii2/tests/auth.php:11,31,32,39,44,48`; `tests/api.php:109` | 7 | Synthetic passwords for disposable test identities and test-only assertions. |
| Tracked | `docker-compose.prod.yml:96`; `docker-compose.yml:95` | 2 | Runtime variable/JDBC context, not literal runtime credentials. |
| Tracked | `docs/DEPLOYMENT.md:67` | 1 | Access-policy/body/header coverage prose; no credential. |
| Tracked | `docs/SDUI_IMPLEMENTATION_REPORT.md:179–180` | 1 | Non-secret catalog product UUID after the words “catalog API”; not an API key. |
| Untracked | `backend-yii2/tests/session_concurrency.php:20`, `session_revocation.php:54`, `session_password_recovery.php:19,28,45,57,71` | 7 | Synthetic old/new-password test fixtures. |
| Tracked diff | Five matches in `backend-yii2/tests/auth.php` changes | 5 | The same synthetic password/old-password literals, not additional exposed secrets. |

The original final-pass snapshot/reports are under `$EVIDENCE/postcheck-20261027-secret-scan/`. Its manifest SHA-256 is `04f8991d650260103275a3adb77cc552d51c8d286f5943313b34d5a70a90fa3d`; original redacted report SHA-256 values: tracked `510bf6bdd3cb81d067e798687b0cb66f63f41af67411686e68999aea7133785f`, untracked `02fdda731e8bbad57b50e270e1bee54aabf49e10443c50154cc85fbef18b17d3`, diff `01b6fd4f73497997f48bd73770529080c59672052881b9aa307c33957c087b33`. Report updates and any repeat scan use separate filenames; no earlier evidence is overwritten. New final reports were included in the untracked scan; a final document-only check covers the completed report text.

The second complete snapshot at `$EVIDENCE/postcheck-20261027-final-secret-scan/` verified the same **399/62** files, **12/7/5** detections and **1/1/1** raw exits (`postcheck-20261027-final-gitleaks-*.status.json`). Every rule/location/redacted-value tuple matches the reviewed set. Only this report changed between the two snapshots; backend source stayed unchanged, and the final selected source still contains zero untracked JSON captures. This result paragraph follows that snapshot; the completed reports receive an additional Gitleaks document-only scan recorded as `postcheck-20261027-completed-docs.{json,log,status.json}`.

The private helper uses the original snapshot implementation with its docs scope widened from `docs/backend-fixes` to all `docs/`; it copies only git-selected regular files and records per-file SHA-256. Ignored local credentials, private PKI, vendor/cache/build directories and git history are outside this source audit, not represented as scanned. No production credential source was copied into tracked reports. Original SQL 001–011 was independently rechecked: **22 files byte-identical to HEAD and matching the original checksum manifest**.

## TLS and publication disposition

- On 2026-10-27 the task's TLS containers are **all stopped**, confirmed before the postcheck. They remain stopped. No service lifecycle command, real payment, Keycloak account operation, or TLS smoke is performed in this final pass.
- The 2026-10-26 trusted-CA smoke and negative CA/hostname/PDO/JWKS checks remain genuine historical passes with explicit test resource settings; default-resource production capacity was never established. They do not certify later F1–F3 or error-detail application edits.
- PKI helper now also generates `YII_FPM_DYNAMIC_RANGE=172.31.223.128/25` for the requested `172.31.223.0/24` test subnet, excluding the fixed nginx `.3` peer. Coordinator RED/GREEN evidence is retained in `TEST_RESULTS.md`; this pass reran the actual generation/CA/mode/config regression: **1/0, exit 0**.
- Overall stop state is **A1 BLOCKED**, consistent with `TEST_RESULTS.md` / `FINAL_REVIEW.md`. Index was verified empty. No runtime source change, subagent, commit, push or publication is performed by this postcheck.

## Exact postcheck commands

```sh
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
GOTOOLCHAIN=go1.26.10 "$EVIDENCE/govulncheck" -C tools/ops ./...
GOTOOLCHAIN=go1.27.3 "$EVIDENCE/govulncheck" -C backend-sdui ./...
"$EVIDENCE/govulncheck" -mode=binary "$EVIDENCE/postcheck-20261027-migrate-artifact"
"$EVIDENCE/govulncheck" -mode=binary "$EVIDENCE/postcheck-20261027-sdui-artifact"
docker run --rm shopkmp-backend-fixes-e2e-yii2 composer audit --locked --format=json
TMPDIR=/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode \
  python3 -m unittest discover -s scripts -p test_test_pki.py

# Run separately for yii2 and migrate; IMAGE and OUTPUT select the reports above.
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock:ro \
  -v backend-fixes-security-trivy-cache:/root/.cache/trivy \
  -v "$EVIDENCE:/out" aquasec/trivy:0.76.0 image \
  --image-src=docker --scanners=vuln --skip-db-update --skip-java-db-update \
  --no-progress --timeout=3m --exit-code=10 --ignorefile= \
  --format=json --output="/out/$OUTPUT" "$IMAGE"

# Snapshot scan, run separately for secret-tracked and secret-untracked.
docker run --rm -v "$SNAPSHOT:/scan:ro" -v "$SECRET_REPORTS:/out" \
  ghcr.io/gitleaks/gitleaks:v8.30.1 dir --redact=100 --no-banner --no-color \
  --report-format=json --report-path="/out/$SECRET_OUTPUT" /scan
git diff --no-ext-diff --no-color HEAD -- backend-yii2 backend-sdui tools/ops \
  deploy scripts docs docker-compose.yml docker-compose.prod.yml .env.example \
  .env.prod.example Makefile .github/workflows/ci.yml | \
  docker run --rm -i -v "$SECRET_REPORTS:/out" \
  ghcr.io/gitleaks/gitleaks:v8.30.1 stdin --redact=100 --no-banner --no-color \
  --report-format=json --report-path=/out/gitleaks-diff.json
```

Artifact extraction used uniquely named audit containers created but **never started**, copied `/migrate`, `/server` or `composer.lock`, then removed those audit containers. PHP source hashes were read by a network-disabled CLI process; neither operation restarted the stopped TLS stack or executed application/payment flows.
