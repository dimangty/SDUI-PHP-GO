# V7 review and completion boundary

**Backend functional acceptance: PASSED, source-bound `tested:true`, PHP 8.5.12.
Merge/publication/push: BLOCKED by actual security/platform gates. Local commit
explicitly requested by the user after these blockers were reported.**
Implementation baseline is `b33f15b`; the local commit is recorded in Git history
and does not constitute release approval or a verified remote upload.

## Independent review actually performed

A fresh-context general reviewer inspected the entire tracked/untracked task diff
against `b33f15b`, including ignored startup files. Its tool task ID was
`ses_e6efe8de9ffeJhRY6I3oinxQYN`; no unsupported model-selection claim is made.
It returned **not ready to merge**, five Important bugs, one Minor packaging issue
and two requirement gaps. This is independent code review, not independent execution
of the coordinator's native suite. Material fixes received coordinator self-review
and regressions.

A separate fresh-context reviewer inspected the later **runtime modernization**
phase, task `ses_e6e43a508ffee4Xf3oY9Mr2YXk`. It found no new Critical/Important
source defect and one Minor fixture-lifetime issue. Its verdict also holds release
for the actual outstanding gates; it did not independently rerun native acceptance.

| Finding | Before → after / concrete verification |
| --- | --- |
| Repeat lockout | Persistent expired marker caused 1,1… after first lock. Now explicit pure transition + DB clock read and one state write under existing user lock; native RED → 3/3 GREEN and concurrent failures |
| Shared production owner | Base+production used bootstrap superuser for PHP/workers. Now distinct explicit logins/credentials, actual role preflight, runtime journal/DDL/SET ROLE SQL denials; merged Compose + native role matrix |
| Missed constructor sites | Two fully qualified legacy test constructions threw ArgumentCountError. Converted to the same AuthComposition root; actual migration suite RED 19/21 → GREEN 21/21 |
| Owner-rights view bypass | Direct grants/function checks missed cross-schema PUBLIC journal views. Non-allowlisted effective relation/column rights now fail preflight; native view regression |
| Ineffective default ACL | Per-schema revoke could not subtract global PUBLIC EXECUTE. Use owner-global revoke; create a real new owner function and verify runtime has no EXECUTE |
| Blanket public ownership | Could move unrelated audit trigger functions to an owner lacking audit-schema rights. Only explicit versioned application manifest changes; native preexisting DBA-owned definer audit trigger keeps its owner and writes successfully after provisioning |
| Legacy-device operator regression | No suitable non-owner credential could complete revoke+audit. Operator receives DELETE, lock-only UPDATE(last_used_at), and audit INSERT; denial rolls back credential deletion; approved operation commits audit |
| Ignored entrypoint/preflight | Root bin/ ignore hid required source files. Narrow `.gitignore` exceptions make both visible in ordinary Git inventory |
| Worker overprivilege | Every worker had web identity DML. Dedicated search/indexer/maintenance/recovery logins now deny verifier reads/password rewrites/token issuance; actual index lock, local expiry and count-only recovery exercised |
| Existing-data upgrade evidence | Added pre-provisioning business row and separately owned audit trigger, not just one identity; verified state/decimal/stock/false and audit execution after repeat grants/migration |
| PHP 8.5 cleanup diagnostics | Native no-op cleanup deprecations broke success and masked the HTTP limit error under strict diagnostics. Object release fixes; 3/3 RED → GREEN, complete native suites on both architecture images |
| Machine stdout | Successful entrypoint preflight broke JSON/hash/package consumers. Quiet preflight restores machine output; runtime 5/5 and canonical Composer security audit pass, failure stderr remains enforced |
| Real-role external payment | New actual TLS dispatch found missing observation-sequence USAGE. Explicit runtime/recovery grant and managed sequence manifest; production-like role 10/10 passes both worker and runtime provider sends outside SQL transactions |

The reviewers' declined execution judgments stay coordinator-owned. Complete
source-bound PHP→Go/edge acceptance, native role tests and final exact arm64 scans
were executed by the coordinator; final security policy and the actual native
Safari journey fail. No independent production approval or native amd64 capacity proof.

**Resolved in continuation:** `SocketUpstream`'s 400 ms lifetime was reproduced by
two failing tests (request after 600 ms; explicit stop of an idle peer). Lifetime
is now parent-owned through stop/EOF, as in the existing search fixture; connection,
handshake/read/application budgets are unchanged. Runtime **5/5**, arm64/amd64
Composer and current complete source-bound acceptance pass. This fix has coordinator
TDD/verification, not a further independent approval.

## Remaining gates / next actions

1. Resolve **freshly reproduced OpenSearch 19 HIGH instances**. Official latest
   **3.9.0** was pulled/built with the same plugin pruning/truststore policy and
   scanned: **32 HIGH** versus 19. It fails upgrade qualification, is not the default,
   and its API/storage/restore tests were not run. DEPENDENCIES records affected/
   fixed versions. No unsupported library swap, policy exception or disposition.
2. Obtain a fresh authoritative Go vulnerability DB. Source and actual delivered
   binary scans remain rejected by the unchanged 72-hour policy despite zero raw
   findings. All seven arm64 scans/SBOMs and source/image receipt binding completed.
3. Native Safari is now **executed and FAILED**: unchanged test/client, newly owned
   simulator, eight milestones through checkout, then `safari_payment_completed`;
   edge 2 GET / 0 POST, SQL AUTHORIZED/CREATED. Owner: native platform/automation.
   No hidden payment POST, click replay, business response or UI source change.
   Android journey and native amd64 publication CI remain NOT RUN; emulated PHP
   checks do not substitute for them.
4. The later explicit **"Make commit"** instruction authorizes a local thematic
   commit despite the reported release blockers. Scope/staged diff/secrets are
   checked and existing unrelated receipts excluded. Push/publication remains
   gated: only after the mandatory conditions pass, verify the remote/upstream,
   explicitly push the feature ref and compare remote SHA.

No client or old migration rewrite, mass activation, guessed role/ban, password
reset by email, provider runtime, mutation retry or business mock was introduced.
The frozen 54/8/6 contract matrix and native preservation tests remain in place.
