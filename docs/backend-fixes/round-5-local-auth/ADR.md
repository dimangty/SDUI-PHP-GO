# V6 design: local identity and bounded SDUI reads

Date: 2026-10-30. Starting checkout: `6f002deb391146195cb63790e0b70e7f1001e8c4`.
The user's V6 is the controlling specification and explicitly requests execution
through implementation, verification and conditionally publication.

## Ownership and scope

HTTP → ApiKernel → PHP application services → read/command repositories → PDO,
payment HTTP and search adapters. PHP owns identity and business state. Go HTTP
adapters implement application-owned read/command ports; application models feed
typed SDUI builders. Go has neither SQL access nor token issuance. CMP and its CI
jobs are read-only. Keep 54 operations (15 public/32 authenticated/7 admin), eight
screens, six commands, error envelopes, decimal money and resource ownership.

## Identity decision

Extend the existing SessionService with explicit password families. Use native
Argon2id PHC strings in users.password_hash, retaining the bounded Secret verifier
only for the proven legacy format. Alternatives rejected: a parallel session
store would duplicate revocation truth; a new IdP/JWT server contradicts V6.
Registration writes user/cart metadata/session in one transaction, after hashing.
Login/rehash/password change verify outside SQL, then recheck hash, security
version and account state under the common user-first lock. Password change
updates the hash and revokes all families in the same transaction, returning 204.

Add a NEW SQL migration, never edit 001–014. Add account_disabled and
local_identity_ready independently of sessions_blocked. Existing accounts start
unreviewed and cannot authenticate through any credential until an operator
attests roles/disabled-state; new ordinary registrations are ready, never admin.
An empty password blocks password login, not reviewed PIN/biometric credentials.
Recovery-blocked accounts remain blocked until an individually audited local reset.
Role authority is users.is_admin, read from storage on authentication.

Tokens retain app_access_/app_refresh_ and independent 32-byte random material,
SHA256 verifiers only, single-use refresh and a bounded absolute family lifetime.
Duplicate/lost-response refresh requires sign-in; no raw-token grace cache, replay
retry or automatic family destruction on a harmless competing refresh.
IP/network rate limits remain at the trusted HTTP boundary. A bounded account
bucket budget applies before hashing, with durable user failed-attempt lockouts.
Unknown accounts use a precomputed dummy hash, not per-request hash generation.

## Existing accounts and recovery

Inventory is count-only and read-only. Apply is explicit, per UUID and ticket,
idempotently journaled. No export is available: realm-only admin and ban state are
NOT inferred. Operator must verify ownership outside the application and enter
new passwords through a no-echo TTY, never argv/logs. A local reset revokes old
families and can resolve a specifically selected historical recovery operation;
there is no bulk unblock or default password. Production cutover is NOT_EXECUTED.
New password changes need no saga. Payment recovery retains bounded scheduling,
durable intents and no-replay; historical identity jobs remain manual evidence.

## R9 reads

Orders: opt-in `sdui-order-summary-v1`, eight card/ownership fields, two SELECTs
and no order_items; default full/detail/create/replay remain unchanged. Go uses
OrderSummary, strict marked wire DTOs, original 2 MiB upstream cap. PHP first.

Search: strict semantic completeness (false timed_out, complete shards, exact
nonnegative total, no early termination), with existing sanitized 503 mapping.
Public reads never create/rebuild. Explicit maintenance creates a building physical
index, verifies SQL identities and marks ready. Missing/unready targets cannot be
adopted by readers or individual writers. Preserve staged physical-index config,
write-drain cutover, bounded jobs and fresh-target rollback after new SQL writes.
Search capability failure must not take login/payments offline.

## Verification and publication

RED→GREEN on native PHP/PostgreSQL and exact toolchains, real PHP→Go HTTP tests,
existing race/payment/security suites, migration upgrade tests, fresh Compose
without IdP, dependency graphs and remaining-image scans. No diagnostic shims.
Capture actual commands/results; historical PASS is not a current result. Any
mandatory failed/blocked gate blocks final push. No production mutation/deploy.
