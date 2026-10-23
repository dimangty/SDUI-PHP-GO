# Yii2 HTTP client

Production still calls real Yii HTTP endpoints. Public methods fix every
method/path; no caller-supplied URL/header map or business fallback exists.
Canonical upstream DTOs remain separate from SDUI props/builders.

## Transport ownership

Two client-owned transports, both without environment proxy, redirects or cookie
jar. TLS verifies hostname/chain with minimumTLS1.2. Operator-fixed HTTP(S) origin
has no credentials/path/query/fragment. Private service origins are intentional;
request input cannot override authority or endpoint prefixes.

* GET: bounded keepalive pool:64 total idle,16 idle/host,64 connections/host,
  90s idle timeout,30s TCP keepalive.
* Mutations: separate non-reusing transport with64-connections/host cap. One
  attempt, including empty POST and Idempotency-Key POST; GetBody cleared.
  Read-pool stale connections cannot replay writes.
* Dial/TLS/response-header timeout uses upstream budget (default3s); context
  bounds the entire operation including backoff and body decode.
* Header ceiling32KiB; decompressed body default2MiB, configurable1..8MiB.
  Bodies always closed; no unbounded drain just to retain connections.
* Runtime closes idle connections after server draining. The explicit custom
  transport retains HTTP/1.1; no forced HTTP/2 to private Nginx was required.

## Retries

GET only, at most two connection acquisitions per call, including net/http's
transparent stale-connection retries. One explicit cancellable backoff
(default50ms, maximum1s) for EOF/UnexpectedEOF, network timeout or valid Yii
502/503/504 envelope. No retry of401/403/409/422/429, malformed bodies, redirects,
mutations or canceled contexts. Deprecated unclassified Temporary is insufficient.

A request-scoped httptrace budget includes transparent retries; an explicit
retry does not receive a fresh budget. Context cancellation races with idle
connection delivery, so an over-budget assigned HTTP/1 connection is also closed
synchronously in GotConn before the write loop. Detached over-budget new dials
are rejected. A blocked third acquisition is not counted as a delivered retry.
Tests cover both one stale read followed by503 and three warmed idle connections
whose first two target reads lose responses; only two reads reach the server.

## Error/identity boundaries

TransportError exposes a safe Kind, optional unwrap Cause and known HTTP status,
retained even if body reading/decoding fails. UpstreamError retains the validated
canonical envelope/body/status/code. Business errors pass through unchanged;
messages/bodies are never logged. Malformed401/403 fail closed with sanitized
security errors. Existing BFF504 deadline and408 cancellation mapping remains.

Forward only validated Bearer, request ID, validated W3C traceparent, Accept,
Content-Type and the specific command's Idempotency-Key. No cookie, arbitrary
identity/role/forwarding header, baggage or client-configured credentials.
Yii verifies JWT/opaque sessions and authorization; Go does not replace it.

Raw wire correlation stays compatible; logs contain only process-keyed HMAC
fingerprints. A client cannot inject a syntactically valid secret/PII value into
log correlation fields. Fingerprints correlate within one process and change
after restart; no persistent pseudonymization key or external secret is required.

Tests cover exact methods/paths/query/DTOs, auth, redirects/TLS/cookies, size limits,
error envelopes/malformed401/403, EOF, retry ceiling/backoff, cancellation, pooling,
lost mutation response and correlation/metrics. Real PHP/Docker evidence:08;
latency/connection measurements:09. No production hardcoded business responses.
