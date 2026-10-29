# Fresh security matrix — publication BLOCKED

This is a **new 2026-10-29 run**, not the historical A1 table. Evidence **S**:
`/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/round2/security-agent-verified`.
Raw counts are package/path/advisory occurrences, not proven exploits.

Trivy **0.76.1**, verified index
`sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c`;
govulncheck **v1.9.0**. Scan **linux/arm64**, 12:46:50–12:47:49 UTC, raw process
exit **1**, gate **FAILED**. All scanner commands completed successfully; the
nonzero final result is policy, not missing reports. Raw Trivy commands use exit 0
to preserve the full inventory; the separate mandatory policy determines the gate.
The exact local `make security-backend` target was also executed end-to-end at
13:06–13:13 UTC using a new output/default cache: Make exit 2, underlying scanner
exit 1, identical blocking occurrence counts. That independent invocation's full
reports are in `E/security-make-verified`, receipt E/verified-local-security-command.json.

DB v2 updated **07:17:11 UTC October 29**, next update October 30. Java DB v1
updated **01:08:14 UTC October 29**, next update November 1. Both official DB refresh
commands exited 0; max age/next-update checks passed. `*-database-content.json`
retains content hashes, verified unchanged after scans. Go DB last modified
**2026-10-27 21:16:18 UTC**, within the enforced 72h limit.

| Distinct runtime | C | H | M | L | Unknown | Policy |
|---|---:|---:|---:|---:|---:|---|
| PHP 8.3.36 / Alpine | 0 | 0 | 0 | 0 | 0 | PASSED |
| BFF / distroless | 0 | 0 | 0 | 0 | 3 | FAILED |
| ops migration / distroless | 0 | 0 | 0 | 0 | 3 | FAILED |
| PostgreSQL 17.11 / gosu | 0 | 0 | 0 | 0 | 1 | FAILED |
| Keycloak 26.8.1 | 0 | 3 | 56 | 35 | 0 | FAILED |
| OpenSearch 2.19.7 | 0 | 19 | 10 | 1 | 0 | FAILED |
| Nginx 1.30.6 | 0 | 0 | 0 | 0 | 0 | PASSED |

Nine service scan receipts cover duplicate Nginx and PostgreSQL roles. All **14**
required app/worker/init containers are bound to source/build/test receipt IDs,
including maintenance/reconcile/payment-recovery/yii-migrate/reindex. Full local
index/config/archive hashes and SBOMs are in `artifacts.json` / `*.sbom.cdx.json`.
Example actual tested local image index digests (not a claim of registry push):

| Runtime | Local immutable image digest |
|---|---|
| PHP | `78efc4bc4eac378b8a0c99f8f6f6babfb4525f92f1497f2ac6a66a9c77ce8c44` |
| BFF | `5cf18a3acbba337a9c445b268e3460fba781deb82c36bf08144b50689846b9db` |
| Migration | `063e38968c6760ac1cff0466c8a177b8cb07332cb2b97e10e44985ba9b4256ab` |
| PostgreSQL | `6e819f6e6206afb402a619423c82d821c9eafeb125e7404522f73399c360cd71` |
| Keycloak | `b65880566074c03723b5e882aa7d603aaf43b2ff6dc1cb5b4616b389fd215c81` |
| OpenSearch | `02778eeb817e06637ed29c3eed5d213c3a15c70f3ead8c43e8b541df62982478` |
| Nginx | `dede8895648b461178c3bc604f7eed4423efe5220b2c4d839f5eb3d34ac3f8f7` |

## Raw vs reachability vs dispositions

- Composer locked production audit: **0 findings**, exit 0. BFF/ops source and
  actual server/migrate/healthcheck/gosu binary govulncheck: **zero raw findings**,
  exit 0 each. These do not erase OS/JAR package findings from image inventories.
- Keycloak raw High: **CVE-2026-91403**, **CVE-2026-91415** (vendor JDK),
  **CVE-2026-102554** (Guava). OpenSearch retains 19 High occurrences across LZ4,
  Jackson, Guava and Snappy. Reachability/mitigation is not inferred from presence
  on an internal network, authentication or lack of an observed exploit.
- The final OpenSearch image's **actual default JSSE trust managers** passed the
  unchanged official CheckCerts probe. Existing CA mitigation is preserved and
  freshly verified; this is not a claim that all JDK/library CVEs are patched.
  The historical report's bundled-JDK scanner-coverage gap remains an additional
  unresolved item; no fresh full JDK vulnerability assessment is claimed.
- gosu's **CVE-2026-39824 / GO-2026-5024** has a fresh official record identifying
  only `golang.org/x/sys/windows.NewNTUnicodeString`, `goos=windows`:
  <https://vuln.go.dev/ID/GO-2026-5024.json>. Actual Linux binary govulncheck passes.
  This supports a NotAffected candidate; no owner-approved disposition was added,
  so the raw image finding continues to fail policy.
- BFF/migration UNKNOWN Debian CA/tzdata advisories remain visible blockers.
- **Approved production dispositions: none** (`deploy/security/dispositions.json`
  remains `[]`). Synthetic test-only approvals verify visible
  `PASSED_WITH_DISPOSITIONS`, expiry/evidence/owner checks, not production waivers.

## Vendor investigation / limits

Fresh official release API lookup identifies Keycloak **26.8.1** (the pinned
version) and OpenSearch **3.9.0** as latest marked releases. Additional OpenSearch
2.19 tag-inventory request returned **403**; a newer compatible 2.x fix was not
established. A major upgrade and arbitrary bundled JAR/JDK replacement are not
used as speculative remediation. Historical A1 analysis is retained separately,
not upgraded into a fresh scan of a different release.

Supported compatible remediation or a concrete evidenced owner-approved
disposition remains required. This task does not accept that risk on the owner's
behalf. amd64 has CI coverage configured, **no local/remote amd64 pass**. Set the
`backend-security` aggregate check as required in repository settings only through
the repository owner's authorized change; no setting was applied here.
