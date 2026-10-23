# Verification results

Executed2026-10-23, darwin/arm64 Apple M4 Pro, Go1.27.3 and Docker Desktop.
Baselinead95258 used Go1.26.0. All results below are observed command output.

`sh scripts/check.sh` passed end-to-end after fixing two staticcheck findings.

| Check | Actual result |
|---|---|
| go mod tidy / go mod verify |pass; all modules verified|
| gofmt -l . with nonempty-output failure |pass; no unformatted files|
| go vet ./... |pass|
| go test ./... -count=1 |pass; opt-in live test skipped without env|
| go test -race ./... -count=1 |pass; no detected race; same explicit live skip|
| go build ./... |pass|
| independent golden/schema/contract tests |pass;13 fixtures,8 screens,6 command schemas|
| golangci-lintv2.15.0 |0 issues|
| govulncheckv1.9.0 ./... |No vulnerabilities found|
| strict command fuzz10s /4workers |pass;297949 executions|
| screen query fuzz10s /4workers |pass;550735 executions|

Pre-review offline coverage (`go test ./... -count=1 -cover`): bff90.8%, domain54.3%,
observe91.4%, runtime74.3%, sdui57.5%, yii54.6%, main0%. Package statement
coverage is not complete branch/executable coverage; moving validation changes
package denominators. HTTP integration tests use actual Go transports and mock
HTTP servers, not production mock business services.

## Real PHP + new Docker BFF

Unchanged disposable PHP edge:`http://127.0.0.1:58098`. New isolated Go test
container:`shopkmp-sdui-go-hardening-verify`,58101/58102; existing application
network, YII_ORIGIN=`http://yii2-http:8080`. No PHP container/config was recreated.

```sh
YII_INTEGRATION_ORIGIN=http://127.0.0.1:58098 \
BFF_INTEGRATION_ORIGIN=http://127.0.0.1:58101 \
go test -race ./internal/bff -run '^TestLiveYiiEightScreensAndActions$' -count=1 -v
```

**PASS**,64 real HTTP steps,8/8 screens,6/6 commands (both device kinds), all
independently schema-valid. Anonymous/JWT/opaque sessions, paging/real search,
idempotency/conflict, persisted writes, native credentials/checkout/payment,
stock reservation/restoration, cancellation, cross-account denial and logout.
Run finished2026-10-23 13:23UTC; test2.09s, race process4.24s. Existing live
evidence files were not overwritten; no auth body was saved.

## Docker/process

`docker build -t shopkmp-sdui-go-hardening:verify .`: pass (Go1.27.3 linux/arm64).
Pre-review image:`sha256:fff909a0afbf53bb3637509d89073be36378b3237ce3dc961ae845278f06010e`;
Docker local size3,806,940bytes (not a cross-registry compressed-size guarantee).
Nonroot:nonroot, read-only/no-capabilities container healthy; live200, ready200,
private metrics200, public metrics404. Graceful in-flight drain unit test passes;
Docker SIGTERM stop returned exit0 and JSON graceful=true.

First manual smoke origin mistakenly used port80 from exposed-port metadata;
readiness correctly returned503/network. Existing nginx config proved8080;
only the new owned Go test container was recreated with correct origin. No
source/PHP change was needed; the failed smoke is not counted as a pass.

Trivy0.76.1 image scan:0 HIGH/CRITICAL in Debian12.15/Go binary. Docker Scout
required login and did not scan; Trivy provided the gate.

Scope checks after stages show only backend-sdui changes. All schemas/fixtures,
PHP/CMP, root CI/Compose/Makefile and deploy sources remain unchanged. No device
UI or production load test ran; this proves HTTP/schema compatibility, not mobile
release certification. Final review/post-review evidence is in10-FINAL_REVIEW.

## Final post-review verification

Both Medium review findings have RED→GREEN regressions. Added actual multiple-idle
read exhaustion, blocked-dispatch retry-counter, idle cleanup/reconnect, bodyless
mutation lost-response and ordinary-error-before-sibling401/403 regressions.
Focused pool/budget/cleanup tests passed20 repetitions. `sh scripts/check.sh`
passed again: fresh tests/race/contracts/build/vet/format, lint0issues, clean
govulncheck. No production source changed after this final check run.

Integration-enabled final `go test -race ./... -count=1 -json`, using real PHP58098
and final Docker BFF58101: **77 top-level test/fuzz functions,226 passed test/fuzz
nodes,0 skipped,0 failures**; live PHP journey passed. These are JSON event counts,
including fuzz seeds/subtests, not a fabricated unit-test coverage percentage.

Final image rebuilt and rescanned:
`sha256:20db58ff493862e88c1547dae185fe32c66b1f406c1293dbf5bfa4b5942e1c62`;
Docker local size3,808,884bytes; nonroot:nonroot. Trivy0.76.1 again reports0
HIGH/CRITICAL in Debian12.15 and server Go binary. Final benchmark vectors in09
were rerun after HMAC/dispatch-guard changes, including telemetry overhead.

Final running-container smoke: live200, ready200, private metrics200, public
metrics404. A mock sensitive X-Request-Id was sent to the actual container;
captured JSON logs contained HMAC fields and no raw mock value. Final SIGTERM
returned exit0/graceful=true; only the owned verification container was removed.
The built image remains available locally; existing services were not stopped.
