# Continuation — 31 October 2026

**Complete backend functional acceptance: PASS, source-bound `tested:true`.
Overall release/publication: BLOCKED by security policy and full Safari journey.**
This supersedes earlier backend timing/readiness/trust failures; their logs remain.
Branch/HEAD remain `refactor/backend-production-hardening` / `6f002de`, dirty.

## Confirmed causes and fixes

**Shared VM pressure.** Before isolation: Docker 8 GiB, available 469,616 KiB,
swap free 80 KiB, memory PSI **37.41% full**, **116,899 major faults / five seconds**.
The user explicitly authorized temporarily stopping five named old e2e projects.
Exact running IDs/images were saved first; only the selected 53 containers were
stopped, with no volume deletion. Available memory reached 6,102,172 KiB; later
PSI was 0.00%. Unchanged checks then passed:

- Scheduler **18/18, TIME_SCALE=1, 124.148s** versus prior 4 failures/417.572s.
- Recovery scheduling **14/14**: actual transaction termination **5.017s** and
  local expiry **5.013s**. All nine payment-runner suites: **179/0**.
- Deadline suite **4/4**; optional search readiness **0.517s**, retained <0.95s.

**FastCGI target bug.** Both main and fixture PHP register `yii2` on the external
DB network. The adversarial probe could reach main PHP; its denial was in main
logs while the test searched fixture logs. `check_fpm()` now inspects the exact
project-scoped fixture container and validates its literal shared-network IPv4,
without DNS fallback. Zero response/no timeout, no bucket writes and fixture denial
log remain required. Unit RED→GREEN **2 tests**; native trust **all seven groups
PASS**, including IPv4/IPv6, spoofing, both replicas, webhook and FastCGI.

**Worker-start race.** Runtime verification asserted readiness immediately after
starting a first reconcile job. Starting is correctly not ready. Restoration now
uses existing healthchecks and `--wait --wait-timeout 2100`, the established
startup allowance; application/job/freshness deadlines are unchanged. Independent
review found cleanup could mask the primary PHP exit. Both cleanup operations now
run, secondary failures are reported, primary exit is preserved, and cleanup-only
failure still fails verification. Lifecycle unit RED→GREEN **5 tests**; Python
discovery **42/42**.

## Complete actual verification

Existing `backend_artifacts.py --phase build`, then **`--phase test -- python3
scripts/verify.py ... --disposable`** completed with exits 0. `tested:true` was
written only after that complete command and source/image comparison passed.

All canonical phases passed: route/graph guards, scheduler, seed preservation,
reconciliation, shared-edge CARD, full Go race/live test/vet, round-3 live edge,
ops tests/vet, complete `composer test:all`, maximum order HTTP, all nine focused
payment/recovery suites, real worker readiness and runtime shutdown/drain/restart.
An earlier retry had inconsistent fixture network variables and Docker rejected
it; that failure remains recorded.

Disposable settings: ingress `172.30.213.0/24` (.2 edge/.3 PHP HTTP/.4 BFF), FPM
`172.30.212.0/24` (.3 Nginx, dynamic .128/25), payment `10.250.227.0/24`
(.2 provider/.3 HTTP/.4 CLI), edge port 58452. Keep these settings consistent across
build/test; never target production. Exact command/UTC receipts are indexed.

Latest maximum-four-byte order HTTP payloads: 20×200 full **4,726,098**, summary
**56,156**, SDUI **62,133** bytes; 100 orders summary **280,455**, SDUI **303,872**.
Original 2 MiB/1 MiB limits remain unchanged.

## Auth network capture

A removed temporary tcpdump sidecar shared only actual PHP's network namespace.
No HTTP payload/headers were captured. Ten real public HTTP operations passed
with no-store: register/login/refresh/session/profile, password change, revoked
profile 401, new-password login, logout and revoked profile 401. SYN metadata
showed local resolver/PostgreSQL/FastCGI traffic. Docker resolver NAT ports require
explicit DNS decode; that separate successful capture observed only **`postgres.`**,
no retired provider names. Empty initial decoder results were not counted as proof.
Application image/capability/logging policy was not changed for capture.

## Remaining native boundary

Original CMP/iosApp sources stayed byte-identical. Isolated original iOS journey
consistently passed eight milestones through PIN/password login, catalog/cart and
canonical checkout. Home timeout is no longer the reproduced boundary.

Still **BLOCKED at `safari_payment_completed`**: the single Safari action produces
no payment POST; state remains AUTHORIZED/CREATED. Original consent allowlist finds
no visible prompt. Viewport, single-coordinate and simulator-locale experiments
did not solve this. Viewport production changes were reverted; unsuccessful test
consumer diagnostics were moved out of repository source. No assertion was removed,
payment tap replayed, app edited or hidden payment API fallback retained.

Trusted HTTPS also used the real production TLS server template on an edge-only
disposable overlay, with test CA installed only into the owned simulator and full
certificate validation. Auth/checkout passed; Safari still failed. Default edge
was restored. Separate actual macOS WebKit submission through the same backend
reached CAPTURED, ruling out a general server capability/form/CSP failure; it does
not establish iOS automation success. The frozen observer-after-global-logout
assumption requires explicit observer re-login in a future complete flow, not
weaker global revocation.

## Security and restoration

Fresh seven-image scan/SBOM at **2026-10-31T06:01:29Z**: six image policies PASS;
OpenSearch 2.19.7 **19 HIGH instances**, no dispositions. Functional receipt is now
accepted. Remaining source/binary failures: authoritative Go DB timestamp
**2026-10-27T21:16:18Z**, outside 72h. Compatible 2.19.8 lookup returned 404. No
unsupported JAR/JVM swap, major jump or policy exception was made.

The original **53/53 containers** were restored by exact IDs/images; all five old
Keycloak containers were healthy. Their archived/deleted preexisting bind source
required temporary materialization of exact historical bytes, SHA256
`a8a8bbbbb8ae8cbcec8e7a20ac1e718bd9f86aa93b7d4fee6bf1d37c5f8456ee`.
The temporary path was then removed; no default provider/import edge was restored.
Snapshot: `activeIsolation:false`, `currentlyRunning:53`; no production data/volume
change. Shared-host pressure can return; isolated acceptance is not a capacity claim.

Production account proof/migration/cutover remain NOT_EXECUTED. No commit/push:
mandatory security/native acceptance is still blocked.
