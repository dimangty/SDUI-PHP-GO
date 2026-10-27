# B7 trust/rate-limit test results

Date: 2026-10-26. Baseline: `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`.

## Scope and integration status

Implemented Go trusted-edge runtime configuration, HTTP original-client validation, typed Yii client context, exact PHP BFF peer trust, finite Nginx/FastCGI forwarding, webhook context separation, and dedicated regressions/live harness. The exact coordinator-owned Compose/FPM/env/runner integration is in [TRUST_AND_RATE_LIMITS.md](TRUST_AND_RATE_LIMITS.md#required-coordinator-integration).

**Deployment integration remains required:** production/shared Compose must set exact BFF/edge peers, ensure BFF egress uses its allocated ingress IP, and restrict FPM admission to the dedicated internal Nginx peer. The isolated harness applies all these settings and tests their effect. This report does not claim the coordinator's full payment/session/deployment integration is already verified.

## Environment

- Host Go 1.27.2; `backend-sdui/go.mod` auto-selects Go 1.27.3 without modification. Docker build uses the existing pinned Go 1.27.3/distroless Dockerfile.
- Docker works with `/Applications/Docker.app/Contents/Resources/bin` prepended to PATH; Nginx image is the existing `nginx:1.27-alpine` (reports 1.27.5).
- PHP image: existing `shopkmp-php-tests`, PHP 8.3.36 with image-local vendor. Source-only mounts retain its correct dependency platform. Host vendor currently requires PHP >=8.5.11, so binding the whole host PHP tree fails before tests; no dependency/runtime files were changed.
- PostgreSQL: existing disposable `shopkmp-backend-fixes-pg` on `shopkmp-backend-fixes-20261026`; **only `trust_fixes`** was used for this task. It was cloned from supplied `backend_fixes` with migrations 001–011. Session/payment changes belong to other agents; the B7 harness uses the baseline PHP image plus current Network/Nginx/Go code and its FPM admission configuration.
- Dedicated read tool failed with `Bun is not defined`; reads used Python. Changes used `apply_patch` (Go formatting used `gofmt`).

## RED evidence

| Command / regression | Exit | Observed failure before implementation |
| --- | --- | --- |
| `go -C backend-sdui test ./internal/bff -run TestClientContext -count=1` | 1 | Actual Yii HTTP requests carried no original IP for IPv4, IPv6 or mapped clients, including authentication and all three home reads. |
| `go -C backend-sdui test ./internal/bff -run TestClientContextOnly -count=1` | 1 | Trusted edge still forwarded its own peer IP; missing/duplicate/chain/port/zone claims reached business calls instead of returning 400. |
| `go -C backend-sdui test ./internal/runtime -run TestClientContext -count=1` | 1 | Unsafe CIDRs, unspecified addresses, hostnames and partially invalid edge allowlists were accepted. |
| `php tests/trusted_clients.php` in PHP 8.3 image, source-only mounts | 1 | Three failures: BFF originals were discarded; legacy shared-subnet XFF trust accepted forged client IP; invalid BFF allowlists were accepted. |
| `php tests/trusted_rate_limits.php` against `trust_fixes` | 1 | Three failures: separate external networks/IPv6/multiplier checks collided in the BFF network bucket. |
| Live Compose harness after Go/PHP changes, before Nginx forwarding | 1 | Two catalog compositions used BFF-network budget; the same real client's direct `/categories` wrongly returned 200 instead of 429. Edge logs confirmed both replicas had served requests. |
| New raw FastCGI negative probe before FPM admission restriction | 1 | Untrusted database-network container submitted forged `REMOTE_ADDR`/rate context directly to FPM and received **336 response bytes**; the application executed. |
| Mapped unspecified and mapped-zone Go boundary regressions | 1 | `::ffff:0.0.0.0` and zoned mapped addresses revealed validation/canonicalization ordering; fixed while retaining zone rejection. |

Initial setup-only failures (not counted as RED): host vendor/PHP mismatch (fatal before harness); unquoted zsh `?` in a DATABASE_URL argument; reserved test subnet overlapped the existing Docker 172.29/16 network; initial IPv6 fixture used a third-hextet address outside its /48. Corrected quoting and test subnets/addresses; no production trust was relaxed.

## GREEN evidence

The commands below were executed from the repo root unless otherwise stated. Final results and any later checks are recorded below.

### Go and contracts

| Command | Exit | Result |
| --- | --- | --- |
| `go -C backend-sdui test ./internal/bff ./internal/runtime -run TestClientContext -count=1` | 0 | Exact trusted/untrusted peer, IPv4/IPv6/mapped client, multi-upstream and malformed context checks passed. |
| `go -C backend-sdui test ./... -count=1` | 0 | All existing packages passed, including UUID/list/Int32, upstream-status, schema and golden regressions. |
| `go -C backend-sdui test -race ./... -count=1` | 0 | All packages passed under the race detector. |
| `go -C backend-sdui vet ./...` | 0 | Passed. |
| `go run github.com/golangci/golangci-lint/v2/cmd/golangci-lint@v2.15.0 run --config .golangci.yml` from `backend-sdui` | 0 | **0 issues.** |
| `go -C backend-sdui build ./...` | 0 | Passed. |
| `go -C backend-sdui test ./internal/bff -run 'Test(Published\|Baseline\|CommandSchema\|IndependentSchema)' -count=1` | 0 | Published/baseline/golden/independent schema checks passed. |
| `gofmt -l` on all changed Go source/test files | 0 | No unformatted paths. |
| `python3 scripts/route_matrix.py --check` | 0 | **54 exact operations = 15 public + 32 protected + 7 admin; eight screens; six commands.** Contract matrix matches canonical source/OpenAPI; no route count changed. |

The old optional `TestLiveYiiEightScreensAndActions` is skipped by the regular Go suite because `YII_INTEGRATION_ORIGIN` is unset. Its full auth/payment journey remains coordinator verification. The dedicated B7 live chain below was executed separately and passed; schema/golden checks ran normally.

### PHP source-only test invocation

The tested shell wrapper (single-quoted inner command varies by suite) was:

```sh
PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH docker run --rm \
  --network shopkmp-backend-fixes-20261026 \
  -e 'DATABASE_URL=postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/trust_fixes?sslmode=disable' \
  -v "$PWD/backend-yii2/tests:/app/tests:ro" \
  -v "$PWD/backend-yii2/infrastructure:/app/infrastructure:ro" \
  shopkmp-php-tests sh -c 'php tests/trusted_clients.php && php tests/trusted_rate_limits.php && php tests/security.php && php tests/throttling.php && php tests/cors.php && php tests/lint.php'
```

| Suite | Exit | Result |
| --- | --- | --- |
| `trusted_clients.php` | 0 | 4 tests, 0 failures. |
| `trusted_rate_limits.php` | 0 | 5 tests, 0 failures; exact BFF replicas, independent networks, persistent user/network ceilings, verified-auth multiplier, untrusted-peer spoofing, Retry-After/no-store. |
| `security.php` | 0 | 6 tests, 0 failures, including original-body webhook signatures/source allowlist. |
| `throttling.php` | 0 | 7 tests, 0 failures; existing canonical rate policy retained. |
| `cors.php` | 0 | 7 tests, 0 failures. |
| `lint.php` | 0 | PHP syntax passed for **70** mounted/image PHP source/test files in the final sequenced run. |

### Live chain

```sh
PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH \
TRUST_DATABASE_URL='postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/trust_fixes?sslmode=disable' \
python3 backend-yii2/tests/trust-live/run.py
```

Exit **0**, with real Compose services, production Go Dockerfile and Nginx proxy/routes/FastCGI template:

1. Real IPv4 source `/24` networks: independent limits; same network shared between direct API and composed screens; spoofed forwarding/identity headers ineffective; 429 Retry-After/no-store retained.
2. Real IPv6 source `/64` networks: independent limits and same-network neighbor sharing; database bucket keys matched the actual source networks.
3. Untrusted same-ingress-subnet container: rotating XFF/XReal/internal rate/user/admin headers failed against internal Nginx and **both** BFF replicas; all hits stayed in the actual untrusted peer network.
4. Per-user ceiling persisted across real client networks; invalid bearer stayed at the anonymous network ceiling; only independently verified users earned the configured network multiplier.
5. Webhook signatures from allowed actual client reached payment lookup (404 for a fresh nonexistent payment); different real source, untrusted container and even the genuine BFF TCP source with spoofed original context all received `WEBHOOK_SOURCE_DENIED` 403.
6. Raw FastCGI attempt from an untrusted database-network peer: **zero response bytes**, no timeout, no rate hits, and FPM `Connection disallowed` log. No application execution.
7. Edge logs contained both exact BFF upstream endpoints (`10.247.249.4:8080`, `10.247.249.5:8080`).

Final Go tests/race/vet/build/schema/matrix commands were rerun after the mapped-address validation fixes, all exit 0. The complete PHP wrapper above then ran sequentially before the final live chain (29 PHP tests, no failures), avoiding shared-test-database reset races.

Cleanup verification found six `Created` containers left by Compose 5.3.1 after the initial failed IPv6 endpoint setup. Their exact project labels were inspected; the harness now also removes leftover containers/networks by its unique **exact project label** in a cleanup `finally`. That fallback was exercised against the six stale test containers. The disposable database and reusable test image remain available for coordinator reruns. No filter was disabled.

The final live rerun after adding the cleanup fallback and dropping `NET_RAW` from all harness services exited **0**, with all seven live checks passing. Subsequent `docker ps -a --filter name=shopkmp-b7-trust` and `docker network ls --filter name=shopkmp-b7-trust` returned no resources. Scoped `git diff --check` and Python AST syntax validation also exited 0.

## Remaining coordinator verification

Apply the documented shared deployment/FPM/env/test-runner wiring, then rerun against the coordinator's rebuilt current PHP image and migrated final schema. The B7 harness does not stand in for the full merged payment/session tests, production trusted-CA TLS run, or independent final review. No subagents, commits or pushes were used for this task.
