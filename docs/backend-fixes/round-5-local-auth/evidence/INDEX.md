# V6 evidence index

**Previous-wave receipts:** producer paths and hashes below describe the initial
wave. Current complete acceptance (`tested:true`), final scan and restoration
hashes are in [CONTINUATION_INDEX](CONTINUATION_INDEX.md). Reused producer filenames
must not be mistaken for unchanged historical content.

These are **new 30–31 October execution receipts**. No prior round's PASS was
copied into this acceptance. Large raw archives, scanner databases, generated
binaries, raw UI activities and unrelated evidence are not source changes.

## Provenance

- Git source base / inspected remote: `6f002deb391146195cb63790e0b70e7f1001e8c4`.
- Branch: `refactor/backend-production-hardening`; dirty implementation, staged
  diff empty, no new commit/push/PR/deploy.
- Final disposable project: `shopkmp-round5-final-e2e`, native linux/arm64,
  edge `127.0.0.1:58452`; init migrations 001–016, existing runner.
- Full build receipt: `deploy/evidence/shopkmp-round5-final-e2e-artifacts.json`;
  **build exit 0, tested false**. The complete acceptance gate is not green.
- Receipt SHA256: `b53d3310f029f753822c6e0d477b70e5ec7d08d20290f20f0c3d4b57bd0db2a7`.
- Final scanner source manifest SHA256:
  `39d58751859d795bdc6e1b4ecaf2533dd88601203a1ed08062aedc6784ef915b`.
- Final gate SHA256:
  `41cf39de8596f46f5fcfe8e72b85017636e4a7d019fcf9d4bad62e2baa6603ef`.

Documentation additions do not rebuild runtime bytes; the existing producer
explicitly hashes backend/manifests/config/scripts and excludes generated
deploy/evidence data. The source manifest includes tracked and new source files;
the dirty diff hash alone is not the provenance for untracked implementations.

## Final image config digests

Full image indexes, immutable IDs, archive hashes, platform, captured source
hashes and build command/UTC timestamps are in the build/scanner receipts.

| Runtime | Config digest |
| --- | --- |
| PHP / workers | `sha256:5e67e18a7ed9831471d4c37ec685f905507f2e7cc63683148eece30835b4be6e` |
| SDUI | `sha256:7f6d587c0001efec42a374b0e22b7688abaa07d2a191538310c329a3105bd6b8` |
| Migrator | `sha256:323d7b4d78594e3e5692bcdae7594eea7939f7fb66cb2ca4643d985b9d47e963` |
| PostgreSQL / gosu | `sha256:481e8a2804c6bd7000cb51dfa1eca9811f11b5beb71464f3919627e8581d3812` |
| OpenSearch | `sha256:cef99d1134532b20f46712c8e870c5088a2b57f646ab324791a338eadfb34255` |
| Both Nginx roles | `sha256:59b2eea7f885f7a2401486e8c3574e9841cef033865b16fdd8ee75e2709b3e72` |

## Local report roots

Private scratch root used by the actual native checks:
`/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/`.
macOS also resolves this as `/private/var/...`. Preserve needed receipts under
the operator's retention policy before temporary-system cleanup; their presence
is not asserted on another machine or in GitHub CI.

| Relative report / command family | Exit / result |
| --- | --- |
| `round5-standard-build.log`, `round5-runtime-refresh-build.log` | Build/start 0; the latter includes supported Go bases and patched gosu module |
| `round5-frozen-native.log` | 0, 87 tests/0 failures |
| `round5-max-order-http.log` | 0, all maximum-four-byte 0/1/10/20/100 pages and ownership |
| `round5-bff-live-final.log` | 0, 65 live race-enabled BFF steps; previous AddToCart 504 attempt retained separately |
| `round5-verification-standard.log` and `round5-verification-standard/scheduler-regression.log` | 1, canonical gate stops at 4/18 scheduler failures; 417.572s recorded |
| `round5-php-all-final.log` | 1, complete PHP sequence stops at readiness deadline; exact timings retained |
| `round5-payment-final.log` | 1 at recovery-scheduling 4/14 after payment migration 13, payments 113, payment HTTP 4, UUID 16/5 and recovery 9 pass |
| `round5-native-tail.log` | Intermediate native migration/lifecycle/regressions pass, then repeated stage SQL timeout; superseded focused stage 2/0 retained in frozen-native |
| `round5-trust-final.log` | 1, five real network/trust groups pass, FPM denial-log assertion fails |
| `round5-orphan-red.log` / `round5-orphan-green.log` | RED 20/2 → GREEN 20/0; later effective-role test gives 21/0 |
| `round5-operator-role.log` | 0, 21 migration tests including least-privileged positive/negative path |
| `round5-security-arm64/` | First security attempt, missing Java DB and freshness/acceptance blockers |
| `round5-java-retry.log`, `round5-java-http1.log` | Official GHCR HTTP/2 failure, then successful download-only HTTP/1 retry, no application transport change |
| `round5-security-complete-arm64/` | First complete seven-image scan, 19 HIGH + 9 UNKNOWN instances |
| `round5-security-final-arm64/` | Final complete seven-image scan/SBOM, six image policies pass, OpenSearch 19 HIGH; overall exit 1 |

Each final scanner `*.command.json` records exact argv/cwd/UTC start/end/exit code;
`provenance.json`, `source-hashes.json`, `artifacts.json`, `*.raw.json`,
`*.policy.json` and `*.sbom.cdx.json` bind the content. Functional task reports
record their precise native source mounts and RED/intermediate failures. Where a
standalone command did not record wall time, that time is **NOT_RECORDED**, not
inferred from another run. Final timings and native-UI limitations are in TEST_RESULTS.

## Selected content hashes

| File | SHA256 |
| --- | --- |
| frozen native log | `6ddcc15b9d5ab4c1f8ccabb6a30d27d5d2c31f086fd5eae6ed95e5f19b9a3c17` |
| maximum-order HTTP log | `a02af65d717ad12734d38ee573cc2884ea32278652f8a410bee6f5a991e20d77` |
| final live BFF log | `cdb4881d49d91501429fb5b2bcd4d41f84d67b0c9f665a7716761d44ce69025e` |
| final trust log | `115e3530a2eea238c30b1ea562c0a49476b6c2413e0fea3fbcb814b6c796f7a3` |
| PHP SBOM | `5b868d0b7b168c9a3ab440f57e149d530519d28638e7a53d1b9ff197d5ba221c` |
| SDUI SBOM | `c512ee677072bd8ed3520a616f0d093c100fd5964d2b1c2690988d0ae908521b` |
| Migrator SBOM | `09c5540aaa3558e7bd563e18f0576fd64a605e2ee759b9325661bb803bf22556` |
| PostgreSQL SBOM | `b56d4b1a71a5ea7d6c9c5c16f0c97884cd302ef3300e6b267a2202bb84486fd5` |
| OpenSearch SBOM | `b2d423ecf920edf61daf4e40f0c10d3f9387d1ca571813c772cf7447633d939a` |
| Edge SBOM | `6ca6735af32905592711ab51b4eff93f9995c581bc7c3486b58d2bd077080e38` |
| Internal Nginx SBOM | `d8cd4bd5dd0d799be18101f5373d38e6e1b8e6e365880a4dc0aef35eb1993840` |

Before/after module/package/test/binary graphs and pinned-tool results are retained
under ignored `deploy/evidence/round5-dependencies/`, with curated hashes/versions
in DEPENDENCY_EVIDENCE.json and DEPENDENCIES.md. No compiled binary/archive or
scanner database is intended for staging. No raw authorization header/token,
password/credential export or real-user identity proof is included here.
