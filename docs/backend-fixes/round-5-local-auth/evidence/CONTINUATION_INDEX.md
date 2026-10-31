# Current continuation evidence — 31 October 2026

**Backend acceptance PASS; release BLOCKED.** Earlier INDEX.md hashes describe
the previous wave. Producer filenames can be reused; these are current hashes.
No raw token/password/header/UI activity was added to repository evidence.

## Bound source and result

- Branch/HEAD: `refactor/backend-production-hardening`,
  `6f002deb391146195cb63790e0b70e7f1001e8c4`; implementation remains dirty/uncommitted.
- Native platform: linux/arm64; same production PHP/Go/PostgreSQL/OpenSearch
  files/toolchains. Original SQL/client/SDUI contract paths are unchanged except
  explicitly requested PHP OpenAPI evolution.
- `deploy/evidence/shopkmp-round5-final-e2e-artifacts.json`: build/test exits 0,
  **`tested:true`**, source/image comparisons passed after the complete real command.
  SHA256 **`40d2ce1c4f758ea6d17de3948518a82ab740388f35d636c204ec21816179b591`**.
- Final scanner source manifest SHA256:
  **`1e578fb174b5a618f014938bbc0767ab443f20b5eaf28d3d65aa453f2246871e`**.
- Final scanner gate SHA256:
  **`cebd9c46fe9f0b8dbc7c459e3c1a9f127b9c8b4c715da4551d0c7e5ce6912b02`**.

Final PHP image index:
`sha256:49cbe417d3c3fa2c760d083c73a2f775156084e7c2b9b9c685767d4619bd9d5f`,
config `sha256:4057092943d73e080901a50b7b3f0065b4db06765ab6ae2f76c2f076d23bac7a`.
Other runtime config digests remain those in the prior image table; all final
IDs/config/archive hashes are recorded in current `artifacts.json`, not inferred
from a mutable tag. Documentation does not rebuild runtime bytes.

## Local private report root

`/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/`
(also `/private/var/...` on macOS). Preserve receipts under normal evidence
retention before OS temporary cleanup. Their presence on another machine/CI is
not asserted. Raw UI bundles were deleted after extracting fixed safe outcomes.

| Relative path | Result / SHA256 |
| --- | --- |
| `round5-continue-release-acceptance.log` | Complete canonical verify exit 0; `de2b8aabe9d6a4ac31b104725a40c8c8799f8169d1114e630e45b3ac526146a6` |
| `round5-continue-release-acceptance/` | Full phase logs: scheduler, PHP all, Go race/live/vet, migration, edge, payments, max HTTP order, runtime shutdown/recovery |
| `round5-continue-trust-fixed.log` | Seven real trust groups PASS; `f87a77a9970254fa041d16867e7a9acce91c2c8d777bf3add9b649a8a0a15848` |
| `round5-continue-scheduler-isolated.log` | 18/18, scale 1, 124.148s |
| `round5-continue-payments-isolated.log` | 179/0, including recovery scheduling 14/14; actual 5.017s transaction termination |
| `round5-continue-auth-dns.json` | Ten actual auth operations/no-store; only postgres DNS; `837a6a683dfb408ef37f5c01b9a5ba5deb0df2e601b05adc473ab8a457e0e0b7` |
| `round5-continue-auth-network.json` | SYN metadata only, PostgreSQL/FastCGI/local resolver; initial empty DNS decode explicitly not counted as proof |
| `round5-continue-ios-original.json`, `round5-continue-ios-browser.json`, `round5-continue-ios-hit-region.json` | Original source/UI: eight milestones then Safari completion failure; no payment POST |
| `round5-continue-ios-viewport.json` | Failed viewport hypothesis, reverted from source |
| `round5-continue-ios-tls-original.json` | Same original app/source under trusted test HTTPS, Safari still blocked |
| `round5-native-tls/` | Private test PKI/edge overlay based on actual TLS template; no verification bypass, default edge restored; private keys not staged |
| `round5_native_ios_contract_diagnostic.py` and diagnostic outputs | Unsuccessful generated-test experiments kept outside repository; no production/client change or PASS inferred |
| `round5-continue-release-security/` | Seven actual image reports/SBOM, six policies PASS, OpenSearch 19 HIGH; source/binary freshness blocked; gate exit 1, completed 06:01:29Z |
| `round5-authorized-isolation.json` | User-authorized exact 53 ID/image snapshot, active false/restored; `dda451408855e93e4ccba0fc23ff9b6ebc30dd63f5f7bd6fb0ffee1bb91f5cd9` |

Each scanner `*.command.json` preserves argv, cwd, UTC start/end and exit code.
Build/test receipt also records actual commands and timestamps. No wall time is
invented for an invocation that did not emit one. Failed original reports remain
separate from later successful phase logs; tests/deadlines were not weakened.

## Seven current CycloneDX SBOM hashes

Under `round5-continue-release-security/`:

| SBOM | SHA256 |
| --- | --- |
| `yii2.sbom.cdx.json` | `89374c24c84825fef8dcfac63fa6021443e7a90d2429db321ddcf9c6c65eb60c` |
| `sdui.sbom.cdx.json` | `ee287fa26054975aeeae6eb38a61ead3109324631ae5876a84c2a64755d48a9e` |
| `migrate.sbom.cdx.json` | `6cdbee86371680e1a194f8885545663bd17ba41f417b4f802bd304bb9356619c` |
| `postgres.sbom.cdx.json` | `a7a56b8e7a01e3daa41223e0eb8f88c3c788ceea579ba2a382ae7f2f9adadc73` |
| `opensearch.sbom.cdx.json` | `c4ec379f605f5dbb2a22469a6b240228dddcd70e6d9a3e26e3f5e6805b1cee61` |
| `edge.sbom.cdx.json` | `6aa63e1debe9410e450c28bef90d90434bf7f4c7f2f4bb359e826ca7f5c9252c` |
| `yii2-http.sbom.cdx.json` | `a55ca86598700351056283db851e3b59e2fb58af2a5f98834075133e8fa167e5` |

No approved dispositions, source/binary freshness exception or publication was
created. Production account migration/deployment remains NOT_EXECUTED.
