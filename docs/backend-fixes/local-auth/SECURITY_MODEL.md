# Local identity security model

## Authority and proof

The canonical user UUID owns all business links. Normalized email is an address
lookup, not proof of ownership or grounds for merging users. Existing realm-only
roles/bans are unknown until explicitly attested. `local_identity_ready`,
`account_disabled`, and `sessions_blocked` are independent gates. A local password
reset is permitted even for a disabled/unready user, but cannot change those gates.
An operator flag records completion of outside identity proofing; the tool itself
cannot establish that proof. Tickets contain the private evidence and human review.

## Operator boundary

Mutations require CLI SAPI, one UUID, a structured ticket, explicit apply, and
SELECT/INSERT privileges on `local_identity_migration_journal`. HTTP routes cannot
invoke these methods. Console options require literal `yes`/`no` for role/state;
missing values are rejected. The normal application database role should not own
or have INSERT privileges on the operator journal. Inventory needs read-only
access to users, session families, historical operations and device credentials.

Provision a separately managed operator DB login/role with only the required
table rights: SELECT/UPDATE users and recovery operations, SELECT/UPDATE session
families, SELECT/DELETE access and refresh sessions, DELETE plus column-level
SELECT(user_id) on login_attempts, and SELECT/INSERT journal. PostgreSQL requires
that SELECT privilege for the DELETE predicate even when the table has no row.
It needs schema USAGE, but neither journal UPDATE/DELETE/TRUNCATE nor DDL, device
credential writes, or business-table writes. The journal requires no sequence
permission. A deployment administrator grants these rights outside the migration;
016 never guesses role names or grants PUBLIC access. Bind the DB login and ticket
to the actual operator. Development's shared schema-owner credential is not a
production privilege separation model.

V7 provides explicit production roles, secret injection, existing-volume upgrade
and bounded preflight in [round-6 rollout](../round-6-local-auth/ROLLOUT_ROLLBACK.md).
Runtime has no journal SELECT/INSERT or ownership; separately managed owner and
operator credentials are absent from web/recurring workers. The selected operator
also retains explicit legacy-device remediation: device DELETE and lock-only
UPDATE(last_used_at), plus INSERT into its remediation audit, never PIN/key writes.
The actual native role-test results and remaining publication gaps are recorded
in [V7 test results](../round-6-local-auth/TEST_RESULTS.md).

Database owners/superusers can change triggers/data by design; the journal is an
append-only application audit, not a defense against a compromised DBA. Retain
DB backups and external ticket/audit evidence under the deployment's controls.

## Secret handling

`AuthPasswordInput` requires both TTY stdin and a controlling `/dev/tty`. It
disables echo/newline echo, reads bounded input directly from that terminal, and
prompts for confirmation. Pipes are rejected. Cancellation/mismatch/errors and
handled termination signals restore terminal modes; a shutdown hook also restores
on ordinary fatal shutdown. The helper never shells out with a password: `stty`
receives only fixed terminal options/saved mode. CLI exceptions emit fixed error
codes, with exception argument rendering disabled, never SQL/driver details.

Native Argon2id is computed outside a SQL transaction using `PasswordHasher`'s
bounded policy. A `SensitiveParameter` annotation protects the migration method's
password argument in PHP traces. A password is not persisted in tickets, JSON,
journal keys, request digests, log output, argv or environment variables. Only the
current credential verifier belongs in `users.password_hash`. Existing PIN
encoding and public biometric credentials are preserved separately.

## Race and failure handling

The global user-first lock serializes against authentication, refresh and
revocation. After preparation, credential hash, security version, fence/ban/ready
state, email, role and password timestamp must match the locked snapshot. No
expensive hash is evaluated while that lock is held. A stale attempt fails closed.
Hash change, family revocation, selected recovery completion, fence check and audit
are one PostgreSQL transaction. A journal failure cannot leave a changed password
with live old sessions. Connection uncertainty is not treated as definite rollback;
a fresh same-ticket receipt check resolves the local commit outcome.

Tickets serialize even across different users and are globally unique. Identical
reviewed requests are no-ops; mismatched metadata is rejected. No reset-password
fingerprint is retained. A reset receipt checks the existing normal verifier
outside SQL, requires its original security version, and revalidates the snapshot
under the user lock. A mismatched secret is rejected, including when another
same-ticket reset wins during hash preparation. A superseded ticket is rejected
without changing state; it cannot undo a newer reset.

## Recovery boundary

A selected noncomplete historical operation must belong to the selected user and
not claim a future security version. Identity and historical reset outcome must
be explicitly confirmed. Completion is local supersession by a freshly verified
ownership reset, never a claim that an unknown old remote request succeeded. Only
the selected operation is marked complete; unresolved operations retain the fence.
No attestation or reset lifts a disabled flag. Orphan fences require investigation.
New local password changes need no historical remote saga. A separately
investigated orphan fence has the explicit owner-reset option described in
USER_MIGRATION: it requires no unresolved operation under the user lock and never
changes ban/readiness. A trusted reset clears only that user's temporary password
attempt counter; it does not clear network/account-slot budgets or device counters.

## Password, session and observation budgets

New passwords require at least eight Unicode code points, upper/lower/digit and
the existing maximum **128 UTF-8 bytes**, without trim/lowercase/truncation. The
character minimum preserves the old realm protection rather than accidentally
accepting five Cyrillic characters merely because they occupy eight bytes.
Email equality is rejected. Login verifies supported existing hashes without
silently assigning passwords to empty/unknown hashes.

Target native Argon2id is **19,456 KiB / t=2 / p=1**. Only explicit validated
19,456–65,536 KiB / t=2–4 / p=1 creation options are supported; there is no algorithm
fallback. Bounded preexisting verifiers can cost up to 65,536 KiB / t=4 / p=4.
At FPM's 12-worker ceiling, the target new-hash memory is 233,472 KiB; worst-case
accepted legacy verification is 786,432 KiB and up to 48 Argon threads, plus PHP
and OS overhead. Provision/measure that separate legacy budget before cutover.
The disposable target measurement is recorded in TEST_RESULTS; it is not a
production sizing benchmark. Admission and body bounds apply before hash work.

Access/refresh random parts are independent 32-byte CSPRNG values; only full-token
SHA256 verifiers are stored. TTL validation permits access 1..86,400 seconds,
refresh/family 1..7,776,000 seconds, ordered access ≤ refresh ≤ family. Defaults
are 300 / 2,592,000 / 2,592,000. Refresh consumes one verifier atomically; a replay
loses without destroying the winner's family. A lost successful response requires
sign-in; there is no raw-token grace cache or blind mutation retry. Expired families
are accepted by no credential and are removed only after bounded token slices
leave no children, preventing an unbounded cascade past the 1,000-row budgets.

AuthMetrics emits log-based count/latency observations with fixed operation,
outcome and hash-stage labels. No email, UUID, password, bearer value, verifier,
raw URL or exception text is a label or attribute. Storage errors, lockout,
rate limiting, refresh and revocation outcomes are distinguishable. The HTTP
composition root injects hash-work observation; tests cover the privacy boundary.
Protected storage failure is fail-closed. Cookie-based identity is disabled.
PIN and biometric proofs remain separate credential kinds, not an MFA claim.
