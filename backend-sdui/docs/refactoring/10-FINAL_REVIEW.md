# Final independent review and fix evidence

Scope: all working-tree and untracked changes under backend-sdui against
ad9525847982c75d12cb987e81d7b480d5e98cc2. One independent fresh-context reviewer;
read-only review, no code/index/HEAD changes by reviewer. Author then performed
one fixes-and-verification pass. No claim of a second independent re-review.

## Assessment by category

Architecture/readability/idiomatic Go: pragmatic application/HTTP separation,
consumer-owned ports, independent SDUI values, no gratuitous layer migration.
Errors/context/concurrency: preserved ownership/security precedence, bounded
fan-out/limits, cancellation and graceful drain. HTTP: fixed endpoints, real Yii,
TLS/redirect/cookie isolation, protected mutations and bounded read pooling.
SDUI: eight screens/six commands/13 goldens/three schemas unchanged. Dependencies:
verified stable Go/test/tool pins, no added runtime library. Docker: reproducible
nonroot pinned images. Performance: measured loopback gains with limitations.
Documentation: actual results/risks/rollback, no root edits.

Reviewer independently ran fresh full offline tests and race tests for all six
internal packages; all passed. Live test explicitly skipped in reviewer session
without environment variables. Author separately ran actual Docker/PHP journeys,
lint/scans/fuzz/benchmarks; these are not misrepresented as reviewer-run checks.

## Findings and resolution

| Severity | Finding | Resolution |
|---|---|---|
| Critical | none | 0 open |
| High | none | 0 open |
| Medium M1 | syntactically allowed caller correlation can contain tokens/PII and was logged raw | **fixed**: process-keyed HMAC fingerprints for request and trace correlation; wire behavior unchanged |
| Medium M2 | GetConn context cancellation can race with idle connection delivery, allowing over-budget write | **fixed**: explicit HTTP/1.1, synchronous close in GotConn before write, detached new-dial budget guard |
| Low L1 | prior response status retained if retry then fails before headers; final-operation status/timeout metrics can be misattributed | deferred; client-visible error/status mapping unaffected |

M1 RED: `TestClientCorrelationCannotInjectSensitiveValuesIntoLogs` observed literal
mock access-token/header and raw trace ID in log. GREEN: no raw values, HMAC
correlation fields present; existing correlation tests prove stable within-process
fingerprints. No persistent key, new secret configuration or API status change.

M2 was source-traced by reviewer in Go1.27.3 transport. Author RED callback-level
`TestReadBudgetClosesThirdAssignedConnectionBeforeAnyWrite` demonstrated that
canceling context alone still allowed assigned connection writes. GREEN after
synchronous closure. Actual three-idle-connection HTTP regression reaches Yii
only twice and returns retry_budget. Blocked third dispatch initially inflated
retry counter to2 (RED); now excluded, producing1 (GREEN). Focused pool/budget/
cleanup tests passed20 repetitions. This is not a claim that a stochastic third
wire request was observed in the original implementation.

Additional requested coverage: idle cleanup causes reconnect; bodyless CancelOrder
lost response remains one write after warmed read pool; ordinary home failure
cannot hide a later sibling's malformed401/403. All existing tests retained.

## Rulings on scope and deferred items

* PHP/CMP implementation internals remain outside the allowed scope. Their HTTP
  boundary is tested; do not certify or alter external business/native logic.
  Cost: external implementation bugs require owners' follow-up.
* Trusted edge quotas and admin-network protection remain deployment ownership.
  Go ignores untrusted forwarding identity and does not add status-changing global
  quotas. Cost: deployment must keep operational9090 private and manage rate policy.
* Keep catalog/devices sequential to preserve baseline first-error behavior.
  Cost: independent read latencies still add; no parallel speedup claimed.
* Deferred minor L1 remains explicitly documented; fixing telemetry event semantics
  is a follow-up, not a hidden required business/security correction.

## Final verification

After fixes: `sh scripts/check.sh` passed format/tidy/module verify/vet/fresh tests/
race/build/contracts, golangci-lint0issues and govulncheck clean. Integration-enabled
full race suite: **77 top-level test/fuzz functions,226 passed nodes,0 skipped,
0 failed**, including actual real-PHP/new-Docker-BFF journey with64 HTTP steps.
Rebuilt final Docker image and repeated Trivy gate:0 HIGH/CRITICAL. Benchmarks
rerun after fixes; final vectors in09. All schemas/fixtures/evidence unchanged.
Remaining findings: **Critical0, High0, Medium0, Low1**.

Ready for requested backend-only commit/push with the documented Low limitation.
Delivery SHA/remote equality is verified at delivery time, not invented here.
