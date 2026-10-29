# Round 2 — final review and publication status

**Source fixes implemented and locally regression-verified. Publication BLOCKED.**
The initial and runtime-verified base was
`ebea663fd22daf6b1e44e05aa6d2ac6bf933f077` on
`refactor/backend-production-hardening`. Configured upstream:
`origin/refactor/backend-production-hardening`, origin GitHub URL recorded in audit.
Real `git ls-remote --heads origin refactor/backend-production-hardening` returned
that exact same SHA after implementation, before the local checkpoint.
The user's subsequent explicit **“Make commit”** request authorizes a local
backend checkpoint despite the still-blocked publication gate. This report is
included in that checkpoint; its SHA is available from `git log -1`.
No vulnerability disposition or publication approval follows from that request.

## Fresh review and one correction pass

Read-only fresh reviewer session `ses_e786ea3aeffeA3P922GQ27I1SK` reviewed the
working diff/untracked additions against the initial HEAD, plan, ledger and native
evidence. It found no Critical defect and five Important items. That review did
**not** approve the final corrected snapshot; the author completed one TDD fix
pass and full verification afterwards, without a second reviewer run.

| Review finding | Correction | Evidence |
|---|---|---|
| Version-only recovery unblock strands a user after ordinary logout | Active password-job ownership predicate under user lock; retains newer fence | real logout during HTTP RED 5/2 → GREEN 5/0, both worker/console directions |
| READY dispatch can block after preparation releases locks | Nonblocking actual pre-HTTP dispatch claim; busy does not consume batch admission | real PDO/second-PG-connection barrier RED 55P03 → GREEN, B progresses then A after release |
| Workers/init jobs not all bound to scanned artifacts | Inventory all 14 services, require running/healthy or successful init, compare immutable IDs | missing/wrong-worker negative fixtures; final full inventory/receipt |
| Checkout source/lockfile not bound to tested image | Build before/after source receipt + successful test receipt, validated by scan | source-drift / untested-receipt negatives; final receipt matches all sources/images |
| Unbounded scanner could lose failure evidence | 900s overall + process deadlines, timeout exit 124, failed/incomplete gate, cleanup and CI budget | deliberately hung real subprocess negative fixture |

Two audit/reproducibility comments were treated as required evidence work:
explicit platform archive export/config/report checks and scanner DB content
hashes/drift detection. Wrong-architecture fixture was RED→GREEN. No deferred
minor remains from that review. No finding was waived to obtain green tests.

The reviewer declined to judge old client/backend history, vendor exploitability/
disposition eligibility and unexecuted remote/amd64 CI. Final ruling: preserve
history, keep all vendor findings blocked, and report those execution boundaries
as NOT RUN; cost is withheld publication rather than unsupported assurance.

## Requirement results

| ID | Original reproduction | Change | Native test / result | Limits |
|---|---|---|---|---|
| R1 | Native PG uppercase/mixed confirm/webhook/biometric failures; different advisory scope | Stored UUID identity, canonical validated operation scopes, job-owned fence | uuid_identity **16/0**, uuid_recovery **5/0**; EdDSA/ES256, ownership/linkage negatives, two connections/real HTTP, logout race | New-generation test forces a stale snapshot through an independent DB transition; it is not an unassisted production-bypass claim |
| R2 | CIDR native **46/31** RED; signed request accepted invalid list; rate-only source substitution RED | Strict whole-list parser, readiness/request 503, transport source | CIDR **46/0**, security **8/0**, live Nginx/FPM source/signature/dedup/forwarding negatives pass | Dev empty allowlist policy and exact trusted peers retained |
| R3 | **CONFIRMED**: two repeated scans × two actual CLI workers, A locked; B remained SENT, 55P03 exits | Bounded window and nonblocking preparation **and actual READY claim** | recovery scheduling **14/0**; A released then progresses, no SENT replay, deterministic READY gap contention, unexpected SQL propagates | Finite candidate window; no unlimited fairness or provider exactly-once promise |
| R4 | Existing CI lacked enforced backend audit/image policy | Local/CI artifact gate, receipt binding, both architectures, raw/SBOM/provenance and fail-closed negatives | Policy/process/provenance fixtures **10/0**; real arm64 scan complete, final exit **1** from vendor/package blockers | Source gate ready locally; publication **BLOCKED**, amd64/remote CI NOT RUN |

Final full native root: PHP shared **202/0**, focused **174/0**, scheduler **18/0**;
Go live/race/vet/schema and standalone tidy/gofmt/build checks pass. See
TEST_RESULTS.md for commands, versions, raw exits and evidence. Secret scan over
owned tracked/untracked changes: Gitleaks v8.30.1, exit 0, no detections.

## Preserved scope

- **API/SDUI unchanged:** actual 54 business operations, 8 screens, 6 commands;
  OpenAPI/JSON Schema and existing CMP models unchanged. No client validator bridge.
- **Mobile unchanged by this task:** no CMP/core/feature/composeApp/iosApp/Gradle
  or mobile CI job writes. Existing client commits remain their original work.
- PHP owns business data; Go continues to call the real PHP HTTP API.
- **Migrations:** zero new migrations; all **33** pre-existing migration files
  match base SHA-256. Fresh DB, populated upgrade/rollback guard suites pass.
- Payment intent/claim, UNKNOWN no-replay, 202 reversal rejection, webhook
  correlation/inbox/late compensation, stock restoration and concurrency tests
  retained. Session family/revoke/refresh/login and exact trust/quota tests retained.
- Existing offline access JWT expiry guarantee is unchanged; immediate offline
  JWT revocation is not asserted.
- Historical docs and generated goldens were not rewritten as new evidence.
  Only this task's generated integration/runtime JSON deltas were archived outside
  Git and restored to the original tracked bytes; unrelated files were not restored.

## Remaining blockers / handoff

1. Fresh High/UNKNOWN image findings detailed in SECURITY_MATRIX.md; no approved
   production exceptions. Keep A1/publication blocked pending supported fixes or
   concrete evidence plus owner approval.
2. Native amd64 and actual remote CI have not run. CI source is not execution proof.
3. Repository owner must separately configure the required `backend-security`
   check if desired; branch protection was not modified.
4. Supplied review/probe/log attachment files were not available in the checkout;
   the prompt descriptions and historical A1 report retain their original limits.
5. Production TLS rollout, capacity, data and provider payments were not operated.

Git disposition: **user-authorized local checkpoint / NOT PUSHED — publication
BLOCKED by required gate**. The verified remote feature ref above predates this
checkpoint and is not presented as publication of it. No reset/stash/global
staging, force push, hook bypass, remote or credential/identity change was performed.
