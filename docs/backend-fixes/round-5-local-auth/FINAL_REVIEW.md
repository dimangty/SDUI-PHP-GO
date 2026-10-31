# V6 final review

**Decision: BLOCKED for release/publication.** Functional local-auth/R9 changes are
implemented and native-tested; mandatory failed gates remain visible.

**Continuation:** complete canonical backend acceptance now passes, with a true
tested receipt after the complete source/image-bound command. Scheduler/recovery/
deadline failures cleared under user-authorized VM isolation. New independent
review confirmed the exact-fixture FastCGI correction and strict readiness wait;
its cleanup-primary-exit finding was fixed RED→GREEN in five lifecycle tests and
the complete native acceptance passed again. Python discovery is 42/42. All 53
authorized old containers were restored by original IDs/images. Security and
native Safari completion remain BLOCKED; see CONTINUATION_RESULTS.

## Independent review actually performed

A fresh-context general reviewer inspected the full tracked and untracked diff
against `6f002de`, including threat model, migration, contracts and R9 boundaries.
It found one High and four Medium issues. A follow-up inspected later sensitive
changes and identified the operator DELETE-predicate permission omission.
These are real tool reviews, not a claimed external approval or unsupported model selection.

| Finding | Resolution / evidence |
| --- | --- |
| High: promotion left PIN usable for admin | Deny under the user lock in CredentialService and SessionService; native core RED→GREEN, both promotion/issuance queue orders in 27-case session suite |
| Medium: exact-total short upstream hit page accepted | Check expected raw hit count before SQL hydration; protocol RED→GREEN. Valid empty/past-end and SQL stale-row filtering preserved |
| Medium: seed followed by bootstrap inventory | `make reindex`/integration post-seed path performs actual reconciliation; Python model RED→GREEN and actual seed/reconcile/edge checks |
| Medium: focused-suite list drift | Exact intended nine-suite assertion updated; Python model 9/0 |
| Medium: main rollback guide permitted old identity trust | Main and local runbooks require roll-forward/approved identity remigration after cutover |
| Follow-up Medium: operator DELETE lacks predicate SELECT | Require `SELECT(user_id)` on login_attempts; native non-owner runtime/operator role test proves 42501 rollback without it and atomic success with it |

Additional verified corrections: native Unicode minimum preserves the prior
realm's eight-character rule; expiry cleanup cannot cascade past token batch
budgets; orphan fences have an explicit investigated owner-reset path; current
audit prose cannot be repurposed as live runtime configuration; reconciliation
uses one visibility barrier rather than a wait on every maintenance document.
TDD/focused native evidence is retained. Post-review packaging/data-boundary
changes received coordinator self-review and real image/native checks; no
independent approval of a production deployment is claimed.

## Requirement checks and remaining gates

- PHP owns passwords/users/roles/sessions and PostgreSQL; Go remains typed HTTP
  aggregation/SDUI, with no SQL/payment/token issuer. No replacement IdP was added.
- Password login/rehash/change, all session kinds, security versions, single-use
  refresh, global logout, revocation and disabled/ready/fence gates were exercised
  on native PostgreSQL with process-level races and failure injection.
- Admin ordinary registration cannot elevate. Existing unknown roles/bans remain
  unready; migration uses selected UUIDs/tickets, retains business links and cannot
  resurrect an older credential through a repeated reset receipt.
- Active provider components/Composer consumer/runtime/build/inventory edges were
  removed; historical policy/evidence is exact-hash guarded and never executable.
- Old full/detail order contracts and money/pagination/revision/component limits
  remain; explicit summaries avoid items/comments and pass actual max-byte HTTP.
- Search completeness is validated independently of HTTP 200. Reads never create
  or rebuild, building indices are unpublished, normal writers cannot adopt them,
  and staged physical-index/write-drain rollback remains explicit.
- Original SQL 001–014, client/mobile source, SDUI fixtures/schema and mobile CI
  sections match the baseline. PHP OpenAPI changes are the explicitly requested
  auth trust description and opt-in order extension.
- Failed scheduler/recovery/deadline/trust-log/native-iOS checks are **not waived**.
  Shared-runtime stalls contribute evidence, not an established universal cause.
- Final native seven-image scan/SBOM completed. Six image policies pass; latest
  compatible OpenSearch 2.19.7 retains 19 HIGH instances. Go DB is older than the
  fixed 72-hour policy. No owner-approved disposition exists.
- Production roles/disabled-state/password proofing, backup/restore rehearsal,
  actual production migration and complete platform/deployment acceptance remain
  operational gates. No real user password was recovered or automatically reset.

## Rulings carried into implementation

1. Continue the inspected existing feature branch in place, as V6 requests; no
   design-only stop or premature documentation commit. Cost: large local dirty
   diff until mandatory release gates pass.
2. Existing identities start unreviewed, independent of a ban or missing password.
   Cost: legitimate existing users require reviewed activation; guessing state
   would be an authorization regression.
3. Refresh reuse loses without destroying the winning family; lost response needs
   sign-in. Cost: no transparent recovery/grace cache for lost refresh responses.
4. Physical-index metadata plus drained verified switch, not aliases/dual write.
   Cost: maintenance window and explicit recovery of interrupted builds.
5. Current reports may describe removed trust, but no report/history file may
   become live configuration. Cost: new executable/config consumers must be reviewed.
6. Do not mark the build receipt tested from a focused pass. Do not commit/push
   with failed mandatory gates. Cost: completed functional code remains local.

No unresolved in-scope Critical/High/Medium code-review finding is asserted closed
by silence; release failures above remain blockers. No deferred cosmetic/minor
change was implemented merely to broaden the diff.
