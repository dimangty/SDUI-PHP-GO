# Execution ledger — plan: 04-IMPLEMENTATION_PLAN.md

Base: ad95258. Spec: 03-ARCHITECTURE.md. Feature branch:
refactor/backend-production-hardening. Initial working tree/index clean.

Ruling: continue in the supplied feature checkout, as explicitly requested;
all artifacts and ledger stay under backend-sdui/docs/refactoring instead of
root-level skill workspaces. Cost: no separate worktree isolation; mitigate by
checking status/diff at each stage. Only final commit after validation.

Pre-flight: Tasks 1→2 share New/CommandResponse and immutable JSON: preserve APIs.
Tasks 2→3 share backend port methods and Yii DTO aliases: preserve signatures.
Tasks 3→4 share client lifetime/observations: recorder is optional and immutable.
Tasks 4→5 share runtime wiring and Go version: verify tool compatibility first.

Baseline fresh Go1.26.0 tests pass; coverage bff88.7%, runtime76.7%, sdui57.5%,
yii56.6%, main0%. First auto-toolchain coverage run failed only for main with
mixed host1.25.3/toolchain1.26.0 compiler; explicit GOTOOLCHAIN=go1.26.0 fixes it.
Real PHP baseline at edge58098 passes64 steps, all8 screens/6 commands, native
credentials/checkout/payment/logout, idempotency and cross-account rejection.
Existing evidence fixtures were not regenerated.

Task 1 complete: characterization matrix/all-six independent command schemas pass
before implementation. Original Go1.26.0 benchmarks (three2s runs): ClientRead
208217/208468/212846ns/op,167alloc/op,1connection/1upstream-call per op; HomeHTTP
770528/437344/433094ns/op,569–571alloc/op,2connections/2calls per op. First home
run was noisy during concurrent compilation. Same-Go1.27.3 original-client
baseline:207311/208180/208365ns/op,160alloc/op,1connection/1call per op.

Task 2 complete: direct application tests initially RED (missing NewApplication),
domain tests RED (missing domain types); extracted independent value/query types,
consumer-owned ports and HTTP-independent Screen/Execute. Full tests and race pass;
all13 goldens unchanged. Raw query length is checked before parsing.

Task 3 complete: reuse test RED (5reads→5connections)→GREEN (1connection).
Pooled GET plus dedicated non-reusing mutations; stale pooled retry and explicit
retry share two-acquisition budget. EOF/lost-write, malformed401/403, TLS/cookie,
limit/deadline/cancellation tests pass. Full suite/race passed before telemetry.

Task 4 complete: recorder/wiring tests RED (missing API/config/Operations)→GREEN.
Bounded-cardinality metrics on admin listener, slog JSON with validated request
and W3C trace correlation, read retry/reuse/upstream/encode/http/probe observations;
no raw paths/query/body/credential logging. Full tests/race/vet pass.

Ruling: keep catalog/devices sequential in this change — first-error precedence
is part of existing behavior; read pooling already removes measured connection
overhead, while home retains proven bounded concurrency. Cost: those screens'
independent read latencies still add; no claim of parallelization speedup.

Task 5 in progress: Go1.27.3/jsonschema6.0.3/x-text0.43.0 verified; Docker build
passed. Fuzz smoke:297949 command/550735 query executions, no failures. Initial
lint found deprecated net.Error.Temporary and one uppercase internal error.
Temporary-only retry regression RED (2calls)→restrict retry to EOF/timeout.
govulncheck1.9.0: no vulnerabilities. Docker Scout could not scan without login;
do not count its zero exit status as a successful image security scan.

Task 5 verification: sh scripts/check.sh fully GREEN (format/tidy/verify/vet,
fresh unit/integration/contract/race/build, lint0issues, govulncheck clean).
Fuzz sessions and before/after benchmark vectors documented in08/09. Trivy0.76.1
image gate completed with0 HIGH/CRITICAL for Debian12.15 and server Go binary.
Only a new owned Go test container was created. First origin port80 was a manual
setup error (readiness503/network), diagnosed from unchanged nginx.conf's8080.
Recreated only owned Go container with8080; live/ready/private metrics200, public
metrics404. Real PHP/new Docker BFF/race journey passes64steps,8screens/6commands.
Docker user nonroot, read-only/cap-drop ALL/no-new-privileges, health healthy.
SIGTERM stop previously returned exit0 with graceful=true. No PHP/CMP/infra edits.

Task 6 independent review: no Critical/High; Medium M1 accepted-sensitive IDs in
logs, Medium M2 GetConn cancellation dispatch race, Low L1 prior status retained
after retry network failure. M1 end-to-end RED exposed mock token/raw trace;
HMAC log fingerprints GREEN, wire unchanged. M2 callback RED demonstrated bytes
written after cancellation; synchronous GotConn close+detached-dial guard+
explicit HTTP/1.1 GREEN. Actual multi-idle HTTP regression GREEN; blocked third
dispatch counter RED2→GREEN1. Added idle cleanup/bodyless write/home security
precedence cases. Focused pool tests20runs, full script all GREEN.

Final: fixed M1/M2 — targeted RED→GREEN and full fresh/race suite. Final
integration-enabled race JSON counts77top-level/226passed nodes/0skip/0fail,
real PHP/current Docker BFF journey64steps. Final image20db58ff4938… rebuilt and
Trivy-scanned0HIGH/CRITICAL. Final benchmarks rerun, supersede pre-review vectors.
Final: minor (deferred): L1 last-known upstream response status can remain in
telemetry after retry network failure; HTTP error contract itself is unaffected.
Final: Ruling: PHP/CMP internals excluded; certify only unchanged tested HTTP/
schema boundary — scope binding — external bugs need owners, not Go workarounds.
Final: Ruling: edge rate policy/admin network excluded; ignore untrusted forwarded
identity and retain existing status contracts — deployment owns quotas/privacy —
cost if wrong: exposed operational listener or inadequate edge rate protection.

Final container smoke/redaction confirmed live/ready/private metrics200 and public
metrics404; no raw mock sensitive request ID in actual JSON logs. Final SIGTERM
exit0/graceful=true, owned verification container removed. Existing services kept.
Only documentation changed after final full checks; production source is frozen
for backend-only staging and the explicitly requested commit/push/SHA comparison.
