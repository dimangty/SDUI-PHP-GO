# Round 2 — implementation decisions

## UUID identity and recovery ownership

Payment linkage compares `orders.payment_id` with the stored `payments.id`;
biometric challenges compare with the stored credential's `id`. PostgreSQL owns
their canonical representation. SQL ownership predicates and linkage checks are
retained. No controller-wide body transformation is introduced, so already stored
idempotency fingerprints see exactly the same request bytes/representation.

`Ids::canonical()` rejects anything outside the existing UUID grammar. Only
validated password-recovery operation identifiers are canonicalized for advisory
lock scopes. Worker SQL `id::text`, console and application now share the same
scope. Reentrant acquisitions/unlocks remain balanced and no SQL transaction
crosses provider HTTP. Opaque tokens, nonce, signatures, passwords, device IDs,
idempotency keys and provider transaction IDs are untouched.

The final completion predicate uses **active recovery-job ownership**, under the
existing user row lock. A newer active password operation retains its fence.
Ordinary logout increments security_version without owning a new password fence;
it must not strand a successfully completed recovery. The initial version-only
predicate was rejected by review and a native logout-during-HTTP regression.

## CIDR configuration

`Network::cidrs()` validates the complete list before any match. Prefix grammar
is `0|[1-9][0-9]{0,2}`, then checked against IPv4 32 / IPv6 128. Bare IP is an
exact /32 or /128. Outer list-entry whitespace is trimmed; empty members and
invalid addresses/prefixes reject the entire nonempty list. Explicit valid /0
remains a real network, never the product of integer coercion. Existing stricter
production deployment policy still applies.

IPv4-mapped IPv6 remains IPv6 for CIDR source matching. Rate aggregation retains
its separate existing mapped-address normalization. Exact trusted BFF/edge peer
configuration is unchanged. Webhook verification now receives transport
`REMOTE_ADDR`, never the rate-only context. Invalid configured CIDRs return the
existing `WEBHOOK_NOT_CONFIGURED`/503 and readiness 503; a valid nonmatching list
still returns `WEBHOOK_SOURCE_DENIED`/403. HMAC uses original body bytes.

## Recovery liveness

The original busy-head risk is confirmed by real PostgreSQL workers. Selection
looks at `min(1000,max(25,limit))` due candidates and counts successful claims
against the original limit. Order→payment NOWAIT probes are in a local SQL
transaction; only SQLSTATE 55P03 rolls back/skips a busy preparation. The actual
bounded READY dispatch claim also uses nonblocking order/payment/operation locks.
Its contention result is handled only **before** HTTP. Unexpected SQL errors and
post-HTTP apply failures propagate; durable SENT/observations remain recoverable.

HTTP is outside row-lock transactions. Bounded scans retain the 30-second
candidate admission budget, one external effect per scheduled candidate and
existing session scheduling/backoff limits. Default application dispatch remains
synchronous. A fully busy finite window can still defer work beyond the window;
this is a deliberate bound, not an unbounded starvation-freedom claim.

## Security gate and exact artifacts

`make integration-up` records a before/after source snapshot and all 14 required
runtime/init container image identities. `make integration-check` verifies that
binding before/after the real suite and marks the receipt tested only on success.
`make security-backend ENV_FILE=.env.example E2E_PROJECT=<disposable>-e2e
SECURITY_PLATFORM=linux/arm64 SECURITY_OUTPUT=<new-directory>` requires that
receipt. Source/lockfile drift, missing/stale workers or image replacement fail.
Scanning never rebuilds or re-resolves runtime moving tags.

Trivy 0.76.1 is pinned by verified multi-platform index digest; govulncheck v1.9.0
was verified through the Go module proxy. Composer audits production locked
dependencies inside the bound image. Go source uses repository runtime toolchains;
four actual delivered binaries are extracted/scanned. Every runtime class has
unmodified raw image JSON and CycloneDX SBOM. Archives are exported explicitly for
the required platform, with image/config/archive digests; report architecture is
checked. DB version/timestamps/content hashes are retained and checked for drift.

Policy blocks High/Critical/UNKNOWN; Medium/Low remain visible inventory. Go raw
findings require review even when only module/package-level. No ignore-unfixed,
suppression or production exception is added. A disposition needs exact artifact,
platform, package/version/path, finding/target, evidence hash, owner, approval and
expiry. Approved dispositions produce `PASSED_WITH_DISPOSITIONS`, never raw zero.

The scanner has a 900s overall budget, bounded process groups and explicit timeout
receipts/failed gate output. Uniquely named scanner containers are cleaned up.
CI reserves upload/cleanup time and runs full-stack/security on amd64 and arm64;
the aggregate required-check candidate is `backend-security`. Repository settings
were not changed. Remote CI execution and owner approval remain external gates.
