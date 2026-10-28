# Backend fixes — verification summary

**October 28 continuation:** [A1_RUNTIME_RESULTS.md](A1_RUNTIME_RESULTS.md)
contains the current runtime upgrades, seven-image fresh-DB inventory and new
root/TLS verification status. The tables below preserve the October 27 functional
checkpoint. Subsequent user-authorized mobile changes and installed journeys are
documented in [mobile results](../mobile-ui-fixes/RESULTS.md). Local checkpoints
`b9def939` and `32675b249` exist; a further user-requested local working-variant
commit records the runtime continuation. Push remains blocked by A1.

**Verification checkpoint: 2026-10-27 UTC. Functional checks passed; release gate BLOCKED by unresolved runtime-image security findings (A1). A later explicit user request authorizes a local checkpoint commit; push remains blocked.**

The detailed workstream reports preserve individual commands, failed attempts,
RED/GREEN evidence, fixture isolation and artifact hashes. Counts below overlap
across reruns; they must not be added into an invented unique-test total.

## Environment and baseline

| Item | Observed value |
|---|---|
| Branch / baseline HEAD | `refactor/backend-production-hardening` / `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9` |
| Remote | `origin`, `https://github.com/dimangty/SDUI-PHP-GO.git` |
| Initial index/worktree/untracked | Clean; no inherited changes were staged or overwritten |
| PHP / Composer | Docker PHP **8.3.36**, Composer **2.10.3**; neither installed on host |
| Go | Host **1.27.2**; BFF auto-selects required **1.27.3**; ops runtime binary **1.26.10** |
| PostgreSQL / Keycloak | **17.10** / **26.3.3** |
| Docker / Compose / Python | **29.6.2** / **5.3.1** / **3.14.7** |
| Supplied review archive evidence | `ALL_BACKEND_REVIEW.md` and its attachments absent; task text and actual code were investigated |

Baseline `route_matrix.py --check`, both Go test commands and Compose config
exited **0**. First `make test-php` exited **2** because the installed Docker
credential helper was absent from PATH; prepending
`/Applications/Docker.app/Contents/Resources/bin` allowed the unchanged PHP 8.3
build, seven baseline cases and lint to pass. Real baseline PostgreSQL integration
ran **11/0**. No Go downgrade, alternate schema validator or mock database was
substituted for required checks.

## Final functional results

| Command/check | Actual result | Evidence |
|---|---|---|
| `make integration-up E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196` | **exit 0**, actual build/migration/FPM/Nginx/Keycloak/search readiness | `LIVE_TEST_RESULTS.md` |
| `make integration-check` with the same project/port | **exit 0**, **1,867.87 seconds**, final F1–F3 snapshot | `deploy/evidence/backend-live-final-wave-root.status.log` and `.log` |
| Shared PHP `composer test:all` | **154 cases / 0 failures**, syntax **79 files**, exit 0 | `php-all.log`, live report |
| Focused payment/migration/HTTP/recovery/scheduling | **150/0**: 113 + 13 + 4 + 9 + 11, exits 0 | `backend-fixes.log`, final fix report |
| Actual scheduler tests, maintenance/search/recovery | **18/0**, exit 0; explicitly documented fixture timing scale **3** | `scheduler-regression.log` |
| Go `test -race ./... -count=1 -v`, live origins supplied | **exit 0**; optional live test actually executed | `bff-race.log`; eight screens/six commands |
| Both Go modules: test/race, vet, build, `go mod tidy -diff`; gofmt | **exit 0**, no formatting output | Live report plus final coordinator commands |
| Complete runtime check with shutdown/restart | **exit 0**; real health probes and all three worker types | `runtime.log` |
| Two payment-recovery replicas, restart one | Both healthy with a successful fresh scan | Final-wave proof/live report |
| Live trust harness with two Go replicas | **7 checks passed**, exit 0; actual IPv4/IPv6 sources and raw FastCGI negative probe | `TRUST_TEST_RESULTS.md`, final live trust log |
| Live Keycloak online/offline logout/password policy | **4/0** inside shared suite and independently | `tests/auth.php`, live report |
| Real clean/populated upgrade, two normal migrators concurrently | **exit 0 for all six CLI invocations**; 14 history rows, original history unchanged | `COMPATIBILITY_MIGRATION_RESULTS.md` |
| Real constraints/indexes | **58 expected SQL rejections**, positive writes/cascades; 92 validated constraints and 62 ready/valid indexes per DB | Same migration report |
| `python3 scripts/route_matrix.py --check` | **exit 0**, actual 54 operations, eight screens, six commands | Final coordinator run |
| `python3 -B scripts/test_backend_integration.py` | **6/0**, exit 0 | Final coordinator run |
| Disposable PKI regression | **1/0**, exit 0 | `scripts/test_test_pki.py` |
| Trusted-CA production-mode smoke and negative CA/host tests | **exit 0** with resource qualification below | `SECURITY_TLS_RESULTS.md` |

After the full-stack F1–F3 pass, a final reader check found that the new
`recoveryRequired: true` error detail violated the existing **string-only**
OpenAPI/CMP `details` map. Regression: **10 tests / 5 failures**, exit **1**.
The backend now emits `recoveryRequired: "true"`; the client is unchanged.
Affected password-recovery **10/0**, revocation **7/0**, logout **41/0**, PHP lint
and route matrix each exited **0**. The complete root pass predates this narrow
wire correction; its affected suites were rerun, rather than representing the
old root snapshot as a fresh complete run. OpenAPI now explicitly documents
logout/password-change 502/503 and local/remote semantics.

The PHP image was subsequently rebuilt with that correction (build exit **0**).
Real PHP readiness returned **200** and the running file hash matched the source:
`b8ece73383278948663af6c5052b8129b0f3d4e5ff1ce66580b0903fdd8e313b`.
An additional aggregate deployment wait of **180 seconds** exited **1** while
the search scheduler was still in its first running reindex (its configured job
budget is **1,800 seconds**). PHP, maintenance and both recovery replicas were
healthy. This short follow-up wait is not represented as another full root pass;
the earlier actual root exit 0 and the narrow affected tests remain distinct.
The unchanged first reindex subsequently completed: a bounded readiness
observation returned **exit 0**, healthy after **80.32 seconds** of that final
observation. PHP, maintenance, both recovery replicas and search reconciliation
are now healthy on the rebuilt image; no search timeout/assertion was changed.

The final PKI helper also generates a dynamic FPM allocation range excluding
the fixed Nginx peer: new regression **1 failure → 1 pass**. Config checks retain
exact peer admission; no production trust/filter was weakened.

## Payment scenario index

| Required scenario | Executed coverage |
|---|---|
| Authorize AUTHORIZED/PENDING; CAPTURED/FAILED | `payments.php`, both correlated callback outcomes |
| CARD/SBP/ON_DELIVERY cancel/expiry and terminal exclusion | Method/state matrix; on-delivery never creates a remote transaction |
| 204 terminal reversal; 202/unexpected 2xx/invalid JSON/4xx/5xx | Adapter and lifecycle matrix; uncertain outcome never becomes REFUNDED |
| Provider executed, response lost | Real journaled provider, 10-second timeout and killed sender; no sent replay |
| Intent/claim/apply commit failure | Real PostgreSQL deferred-constraint COMMIT failures; durable recovery |
| Restart before send/after send/before apply | Fresh services/processes and SIGKILL; READY recovery, SENT/UNKNOWN fencing |
| Two workers and cancel/capture/refund/expiry races | Real independent processes/transactions, provider attempts and stock assertions |
| Signed HTTP callbacks, early/duplicate/foreign callbacks | Actual PHP HTTP, HMAC/source/timestamp checks, durable quarantine; duplicate remains 409 |
| HTTP outside transaction; exact amounts; permissions | Provider-visible committed intent, SQL activity probes, integer cents and owner/admin HTTP checks |
| Once-only stock; completed-order refund | Persistent guard rollback/retry; completed purchase remains consumed |
| Legacy lost authorize response during upgrade | Frozen predecessor performs real POST/rollback, real migration, no repeat POST |
| Capture before void send / blocked refund / late observations | R2–R4 regressions retain financial facts and desired intent |
| Verified final no-effect reversal | F1 console evidence, original transaction, atomic owed-refund queue; no-effect refund stays manual, no replay |
| Busy session head / payment backlog / retention backlog | F2/F3 actual schedulers and PG locks; bounded admission and cleanup progress |

Provider journal and idempotency checks are test-only and durable in disposable
PostgreSQL. They are not evidence of undocumented production replay guarantees.
No real monetary operation was performed.

## Security, resources and non-passing gates

- Composer audit: **exit 0**, no advisories/abandoned packages.
- BFF govulncheck: **exit 0**, Go 1.27.3.
- Ops govulncheck initially found `GO-2026-6629`/`GO-2026-5970`. Targeted
  `x/text 0.29.0 → 0.41.0` (and its required `x/sync 0.22.0`) is locked and
  compatible with Go 1.26; official module metadata requires Go 1.25. Final
  Go 1.26.10 source scan: **exit 0**. Rebuilt migrate image no longer contains
  the two HIGH findings; its inventory retains three UNKNOWN distro advisories.
- Seven runtime images were scanned with Trivy 0.76.0. **A1 remains NOT PASSED**:
  unresolved HIGH/CRITICAL inventory in PHP, Keycloak, nginx, PostgreSQL and
  OpenSearch. Applicability qualifications and exact artifacts are in the
  security report; neither a private network nor TLS is used to dismiss them.
- Gitleaks v8.30.1 raw nonzero fixture/prose findings were reviewed; no actual
  credential leak was identified. Values are redacted; no blanket ignore added.
- The Docker VM has **8 GB**, with pre-existing unrelated stacks. Earlier SQL,
  strict timing and OpenSearch OOM/readiness failures are retained in the live
  report. Stopping only this task's TLS stack allowed the unchanged strict root
  checks to pass. Unrelated services were not stopped.
- TLS smoke used an explicit disposable OpenSearch resource override (256 MiB
  heap, 2 GiB container ceiling), with trusted CA and hostname verification.
  This proves that TLS test, **not default-resource production capacity**.
- Remote CI, production deployment/cutover, production rollback, and a live
  five-minute JWT-expiry wait: **NOT RUN**. Offline JWT expiry tests and real
  pre-expiry behavior were tested separately.

## Reproduction and release gate

Use the exact isolated IP/port environment in `LIVE_TEST_RESULTS.md`; do not run
these commands against production. Required top-level commands are:

```sh
make integration-up E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
make integration-check E2E_PROJECT=shopkmp-backend-fixes-e2e E2E_PORT=58196
go -C backend-sdui test -race ./...
go -C tools/ops test -race ./...
python3 scripts/route_matrix.py --check
python3 scripts/test_backend_integration.py
```

Generated historical tracked `deploy/evidence/*.json` captures were compared and
restored; newly generated capture pages were removed. Ignored execution logs and
the detailed reports retain actual exit evidence. CMP and old SQL were not
modified. The index was empty at verification. The user's later explicit request
authorizes a local checkpoint of this reviewed snapshot, excluding unfinished A1
runtime work. **Do not push or claim release readiness while A1 is uncleared.**
