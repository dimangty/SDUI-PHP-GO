# Execution ledger — plan: docs/backend-fixes/round-2/AUDIT_PLAN.md

Pre-flight: R1 and R3 share PaymentOperations; preserve stored-identity comparison
when changing bounded recovery selection. R2/R4 share readiness/CI verification;
security gate must not replace functional checks.

Ruling: use the clean existing feature checkout as requested; no overlapping index
or user hunks exist. The unrelated detached baseline worktree is left alone.
Cost if wrong: worktree isolation would need revisiting if new user edits arrive.

Ruling: user explicitly requests short audit/plan followed by implementation;
execute inline without extra design handoffs. No publication before gates.

Baseline PHP image build/unit/lint exit 0 (7 tests, 79 PHP files); BFF and ops native
race tests exit 0, actual auto-selected BFF toolchain Go 1.27.3, no fallback/bridge.
Route matrix exit 0: actual 54 operations / 8 screens / 6 commands.

Evidence root E: `/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/round2`.
Every backend_check result has command, cwd, UTC timestamps, raw exit and log.
Initial stack creation attempts failed due test network configuration/collision;
retained as failures, not suite results. Selected unused 10.239.210/211 networks
after inspecting all Docker network subnets; no existing network removed.

Task 1: complete — original `make integration-check` exit 0, 11:04–11:11 UTC;
original logs remain in deploy/evidence/*.log and original generated JSON delta
is retained in E/baseline-generated-evidence.patch. Do not treat older parity
logs in that directory as runs from this task.

R1: native RED 16/8 (payment/webhook/EdDSA/ES256) and recovery RED 3/3.
Stored-ID comparisons and validated recovery scope canonicalization give GREEN
16/0 and 5/0, including real worker↔console HTTP barriers, no open SQL transaction,
one logout call, balanced advisory locks. The generation fixture forces an
independent DB completion/next transition while the old HTTP response is pending;
it proves stale completion fencing, not an unassisted application exploit.

R2: RED 46/31 CIDR table and signed-kernel 503-versus-404; strict whole-list parser
gives GREEN 46/0 and 8/0 security tests. Additional native RED confirmed that a
trusted BFF's rate-only header was used as webhook source; the kernel now passes
REMOTE_ADDR to webhook verification. Live Nginx observed 204/401/403 and 409 dedup.

R3: confirmed on original immutable PHP image + current tests/native PDO:
SQLSTATE 55P03 lock timeout on sorted head A; two real CLI worker connections.
Bounded 25-candidate minimum window, order→payment NOWAIT probes, rollback on
55P03 only, unchanged default batch cap and external-effect admission. GREEN
native worker test; unexpected SQL errors propagate. No financial status is
invented on busy; SENT becomes UNKNOWN only after a successful local claim.

R4: pinned versions verified against Docker registry and Go module proxy:
Trivy 0.76.1 index 790fd66…; govulncheck v1.9.0. Fresh arm64 scan 12:06–12:07 UTC
has all required raw reports/SBOM and no scanner failures; policy rejects remaining
High/UNKNOWN vendor/package findings. No approved dispositions added.

Ruling: maintain zero new exceptions; preserve blockers instead of treating
historical mitigation prose as owner approval. Cost: publication remains blocked.

Ruling: use a fresh output directory for each security run and preserve all failed
attempts. A pre-existing E/security-arm64 directory belongs to earlier work and
was not overwritten or used as evidence; this task's scans are security-agent-*.

Final functional root verification exit 0, 11:50–12:04 UTC; dedicated
E/agent-final-root logs/captures. Live trust suite initially failed on asynchronous
FPM stderr delivery after the socket had already been rejected. A bounded poll
retains the original denial-log assertion; complete rerun exit 0 at 12:06 UTC.

## Final correction pass and verified snapshot

Fresh reviewer ses_e786ea3aeffeA3P922GQ27I1SK found five Important issues; no Critical.
All five were verified and corrected in one pass (FINAL_REVIEW.md). Review-driven
RED: logout during HTTP 5/2; READY claim 55P03; missing receipt/timeout 9-test run;
wrong-platform report accepted. Final GREEN: uuid recovery 5/0, recovery scheduling
14/0, policy/process/provenance 10/0. The real PDO statement observer does not
simulate SQL results: it acquires a second PostgreSQL connection's lock exactly
after the preparation commit, before the actual READY dispatch claim.

Ruling: fence ownership is the active password job, not security_version equality;
ordinary logout also advances that version. Newer active jobs remain fenced.
Cost if wrong: session availability or a newer fence could be lost; both HTTP
interleavings now have native coverage.

Ruling: platform export/validation and DB byte hashes are part of the requested
artifact reproducibility requirement, so the review's two audit comments were
included in the correction pass rather than left as optional polish.

Task 2: complete — UUID cases, authorization/linkage, crypto, worker/console and
ordinary-logout/new-job fencing, final native suites pass.
Task 3: complete — malformed CIDR fail-closed/readiness and live Nginx source,
signature, spoofing and FPM rejection pass.
Task 4: complete — original image repeated two scans/two workers leaves B SENT;
final image advances B, then A after release; READY gap and SQL error cases pass.
Task 5: source gate complete — tested-source receipts/all 14 containers, deadlines,
two-platform CI and 10 negative/positive fixtures verified. Publication still blocked.

Final fresh disposable project: shopkmp-round2-agent-final-e2e, ingress/FPM
10.239.209/208, loopback 58209. Build receipt and final full root verification
12:37–12:46 UTC exit 0; live trust exit 0. Evidence E/agent-verified-root and
E/verified-{fresh-stack,root,trust}.*. Previous owned project stopped; volumes kept.

Final exact-artifact scan E/security-agent-verified, 12:46–12:47 UTC: all scanners,
source/build/test bindings and DB consistency checks completed, exit 1 solely for
unresolved image policy findings. No exceptions. Additional current OpenSearch
JSSE mitigation/plugin probes exit 0; owned diff/additions Gitleaks scan exit 0.

Final review: author validation after one fresh-context review and one TDD fix
pass. Deferred minors: none. Reviewer-declined historical/mobile/vendor-exploit/
remote-CI judgments remain explicitly bounded in FINAL_REVIEW.md.

Publication ruling: no staging, commit or push while required gate fails, matching
the user's explicit condition. Existing local/remote feature HEAD is ebea663…;
the current backend diff is preserved. Cost: source improvements are not published.

Final source check at 13:03 UTC: policy/process/provenance 10/0, runner/model 6/0,
route matrix 54/8/6, diff whitespace, unchanged protected client/Go/contracts/old
migrations and empty index all exit 0. Final owned diff/additions Gitleaks exit 0.
Exact Make security target re-executed 13:06–13:13 with a new cache/output, 10/0
fixtures, complete scans, Make exit 2 / scanner exit 1 only for the same vendor/
UNKNOWN policy blockers. Evidence E/security-make-verified; no publication.

## Follow-up: explicit local checkpoint request

After the blocked-publication report, the user requested **“Make commit”**.
This supersedes the earlier local-commit restriction for a backend-only checkpoint;
it does not approve vulnerability exceptions or clear the push/publication gate.
Git was rechecked: same feature branch/base, empty index, only the task-owned
backend diff/additions, no client edits. Only publication-status documentation is
updated for this follow-up; the runtime verification results above remain the
original runs, not newly claimed results. The checkpoint SHA is in Git history.
Commit preflight repeats policy tests 10/0, runner/model tests 6/0 (3 focused
runner + 3 Compose model), route matrix and protected-path/whitespace checks,
exit 0; E/commit-preflight.{json,log}. This corrects the earlier summary's
runner-only count of 3 without changing the underlying execution evidence.
