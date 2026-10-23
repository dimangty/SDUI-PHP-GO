# Security hardening and review

* Authorization remains request-scoped, verified by Yii for JWT and native opaque
  sessions. Duplicate Authorization, role/user/cookie headers and command identity
  fields do not grant privileges. Commands reauthenticate; ownership is checked.
* SSRF boundary: fixed operator origin and finite methods/paths/queries. UUID/
  device validation rejects path/query injection and dot segments. No redirects,
  environment proxy, cookie jar or arbitrary HTTP actions. TLS rejects untrusted
  certificates and verifies hostname/chain.
* net/http owns framing/request-smuggling checks; BFF is not a raw reverse proxy
  and does not copy hop-by-hop headers. Header/read/write/idle timeouts remain.
  Command32KiB, exact field tags, UTF-8, duplicate-key rejection, depth16. Query
  4KiB is now checked before parsing. Upstream decompressed bodies are bounded.
* Read retries are bounded/cancellable; mutations remain single-attempt on a
  separate non-reusing transport. Malformed upstream401/403 stays a security
  failure, never a personalized successful document.
* SDUI is a finite allowlist with same-origin approved media, node/body/depth
  budgets, no arbitrary execution/HTTP, no generic credential/payment commands.
* JSON slog accepts finite labels/status/error kinds/timings and validated
  correlation fingerprints only (process-keyed HMAC, no raw caller IDs). No body,
  raw URL/query, token/PIN/password/payment secret or
  personal data logging. Labels cannot grow per attacker-controlled URL. Metrics
  are admin-only, without per-request/account/token labels.
* Nonroot distroless, CA roots, pinned digests, reproducible Go build, SIGTERM drain.
  Test container runs read-only, cap-drop ALL and no-new-privileges.

## Actual scans

go vet and golangci-lintv2.15.0: clean. govulncheckv1.9.0 (database2026-10-21):
**No vulnerabilities found**. Trivyv0.76.1 image gate with
`--scanners vuln --severity HIGH,CRITICAL --exit-code 1`: **0 HIGH/CRITICAL** in
Debian12.15 and Go binary. Lower-severity image findings were not part of that
gate. Docker Scout required login and did not scan; Trivy supplied the gate.
No credentials were requested or changed. Scans do not prove absence of unknown
vulnerabilities or PHP issues.

## External boundaries

Per-IP limiting needs trusted edge/proxy configuration. Trusting arbitrary
X-Forwarded-For permits spoofing; a new global quota would change existing status
contracts. Existing edge quotas/network policy remain deployment responsibilities.
Keep9090 private; remote scraping must use the protected operational network.
Traceparent is correlation, never identity. No JWT signing key, Keycloak/SQL
credential, collector or new infrastructure stack was added.

Exact validated Yii error bodies still reach clients. Go cannot alter a trusted
upstream's business-message policy without changing the error contract, so it
does not log those bodies. No PHP/CMP/edge/root-CI/configuration source was changed.
See REFACTORING_LIMITATIONS.md for scope decisions and remaining risks.

Independent review found accepted-but-sensitive correlation values and the
GetConn cancellation race. Both Medium issues were reproduced with targeted RED
tests and fixed: HMAC log correlation; synchronous close/reject dispatch guard.
Read pooling now explicitly selects HTTP/1.1 so connection ownership is exclusive.
Full checks, real-PHP/race journey and image scan passed again after these fixes.
