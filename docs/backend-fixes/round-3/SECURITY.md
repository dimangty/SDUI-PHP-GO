# Round 3 — fresh security result: BLOCKED

Run: **2026-10-29 22:56:38–23:04:42 UTC**, `linux/arm64`, exit **1**.
Command receipt: `evidence/security-final-arm64.json`; complete raw reports,
stderr, SBOMs, image config/archive hashes and command exits:
`evidence/security-final/`. These are new observations, not copied round-2 status.

| Artifact/check | Fresh result |
|---|---|
| Composer locked production audit | PASS, zero findings |
| BFF/ops source govulncheck | PASS, zero raw findings |
| Actual server/migrate/healthcheck/gosu binaries | PASS, zero raw findings; binary hashes retained |
| PHP / both Nginx roles | Image policy PASS |
| BFF and migration distroless | Each **4 UNKNOWN**: ca-certificates DLA-4845-1; tzdata DLA-4792-1/4854-1/4867-1 |
| PostgreSQL / Keycloak PostgreSQL | Each **1 UNKNOWN**, gosu Linux image advisory; no approved disposition |
| Keycloak 26.8.1 | **3 HIGH**, 56 medium, 35 low: vendor JDK CVE-2026-91403/91415 and Guava CVE-2026-102554 |
| OpenSearch 2.19.7 | **19 HIGH**, 11 medium, 1 low: LZ4/Jackson/Guava/Snappy package-path occurrences |
| Complete build/test/worker acceptance | FAIL: reconcile readiness; no successful final source-bound full-stack receipt |
| amd64 / remote CI | NOT_RUN |

Exact package/version/path/platform/config digest is retained in each `*.policy.json`
and `*.raw.json`. Counts are occurrences, not proven exploit paths. High JAR/JDK
reachability has not been established or dismissed. Network isolation does not
justify a waiver. UNKNOWN does not mean false-positive.

Trivy **0.76.1** is digest-pinned. Official DB downloads completed; v2 DB updated
**2026-10-29 19:18:50 UTC**, Java DB **01:08:14 UTC**. Both content hashes remained
stable during scanning. Go DB last modified **2026-10-27 21:16:18 UTC**, within the
enforced 72-hour bound. No scanner execution error was disguised as a clean report.

The current distroless nonroot tag resolves to the already-pinned index
`afa5c872…` (`vendor-runtime-metadata` receipt); no newer compatible fixed image
was established. Official OpenSearch latest metadata identifies **3.9.0**, a major
upgrade needing migration/rollback/security verification. Keycloak metadata lookup
returned HTTP 403; a compatible fix was not established. Arbitrary JAR/JDK swaps
were not performed. These remain external release blockers, not accepted risk.

The Linux-only gosu binary result supports further NotAffected investigation for
the Windows-specific advisory; it does not approve a disposition. Production
`deploy/security/dispositions.json` remains unchanged and empty.

## Fail-closed evidence behavior

The existing gate, severities, scanner freshness/deadlines, source/test receipts,
architecture and immutable-ID checks remain enforced. A TDD improvement retains
verified image/container observations when subsequent health acceptance fails, so
independent raw scans can still finish. It still fails the gate; observations are
not a successful receipt. Wrong-image observations are rejected. All **11** policy,
timeout, source-drift, missing-worker/report and wrong-architecture fixtures pass.

This run scanned nine runtime roles using verified local immutable IDs despite
the unhealthy reconcile worker. It does **not** establish complete 14-service
acceptance or source/build/test equivalence. Later evidence-packaging/reporting
tools are also newer than that rejected build receipt; a future release must
rebuild and rerun the unmodified mandatory acceptance workflow.

No owner-approved waiver was created, and no automatic push/deploy is permitted.

## Evidence secret review

Gitleaks v8.30.1 scanned the staged source: after replacing an inert test-fixture
credential with an unmistakable `fixture` placeholder, the source scan exited 0.
Archive scanning (115.56 MB uncompressed) reported **233 raw findings**, all reviewed
against their exact original lines: SHA256 source provenance, public Go/moby commit
references, a public Maven package name and official PHP public GPG fingerprints.
`evidence-secret-classification-complete.json` accounts for every finding with
**zero unresolved**. Raw findings/report/exit 1 are preserved, not rewritten into
a scanner PASS. The narrow classification utility rejects credential shapes and
does not modify Gitleaks configuration or the vulnerability gate.
The final staged scan also flags five literal public references added by that
classification utility itself (Maven name, moby commit and three public GPG
fingerprints). They were checked line-by-line; `source-secret-classification.json`
retains all five and the raw exit 1. No real credential finding was waived.
The final rebuilt package scan has **234** raw findings: the same 233 plus the
public Maven name repeated in that triage note. The extra exact line was reviewed
in `final-evidence-secret-review.json`; zero unresolved credential findings after
manual review. Gitleaks still reports exit 1, transparently retained.
