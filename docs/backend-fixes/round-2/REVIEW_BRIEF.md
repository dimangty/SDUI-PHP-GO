# Final source review brief

Review the **working diff plus untracked new files** against initial HEAD
`ebea663fd22daf6b1e44e05aa6d2ac6bf933f077`. There are no task commits yet because
the user's publication/security gate remains blocked. Do not review or attribute
pre-existing client/backend commits as this task's changes.

Read AUDIT_PLAN.md and PROGRESS.md in this directory. Requirements:

- UUID equivalence across case without losing authorization/linkage; no body or
  opaque token normalization; canonical application/console/worker advisory locks;
  balanced nesting, no transaction over HTTP, generation-fenced user unblock.
- Strict whole CIDR-list parsing, invalid configuration 503/readiness non-200;
  bare exact IP, mapped IPv6 stays IPv6 for source matching; exact BFF/edge peers;
  transport webhook source never a rate-only header; HMAC/timestamp/dedup intact.
- Native reproduced busy-head payment queue; bounded selection/claim with
  order→payment→operation ordering; no busy financial failure, no replay of SENT,
  unexpected SQL errors propagate, existing scheduling/time budget preserved.
- Composer production audit, pinned real govulncheck source/delivered binaries,
  exact tested runtime image archives + raw JSON/SBOM/digests/platform provenance.
  Required DB freshness and missing/scanner/error negatives must fail closed.
  CI uses both amd64/arm64, existing mobile jobs remain byte-identical. No waiver
  without exact evidence, owner, approval and expiry; production exceptions empty.

Review focus (verbatim from plan):
1. Case-insensitive identity must retain authorization and resource linkage.
2. Recovery advisory locks must serialize worker/console and balance nesting;
   old generation completion must not clear a newer fence.
3. Invalid CIDR anywhere in a list must fail closed before a matching entry wins.
4. Busy rows must not become financial failures or hide unexpected SQL errors.
5. Missing/stale scanner DB, reports or platform artifacts must fail the gate.

Evidence E `/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/round2`:
`final-root-integration.json`, `agent-final-root/*.log`,
`final-live-trust-wait.json`, `final-go-checks.json`,
`security-agent-final-20261029T1206/{gate,artifacts,provenance}.json` and raw/policy
reports. Those are new runs from this task. No remote CI/amd64 or publication pass.

Return concrete defects with severity/path/line, missing mandatory checks, and
scope/compatibility concerns. Read-only: do not edit, operate services, commit or
push. A known failing vulnerability policy is an intentional publication blocker,
not something to bypass to make the code review green.
