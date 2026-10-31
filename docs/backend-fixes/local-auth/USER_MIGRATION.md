# Existing-user operator procedure

**Production: NOT_EXECUTED.** These commands are a runbook for a trusted operator.
All UUID/ticket examples below are placeholders. Supply `DATABASE_URL` using the
approved private runtime environment; never paste credentials into commands or
evidence. Run from `backend-yii2` in the deployed, verified PHP image.

## 1. Inventory / dry run

```sh
php yii auth/inventory
```

This is count-only and uses a repeatable-read, read-only transaction. It emits
integer counts for users, local admin/disabled/unready/blocked users, normalized
email conflict groups and involved users, native/legacy/empty/unknown password
encodings, unresolved historical password operations, total/unrevoked session
families, and PIN/biometric credentials. Family counts are evidence counts, not a
claim that those sessions remain usable. No identifiers, addresses, verifier
samples, tokens or passwords are emitted. No hashing/rehashing or journal writes
occur. Inventory works on schema 013/014 as well, before 015's normalized email
unique index. Before local state columns exist, every user counts as unready;
`disabled=0` means there is no locally stored disabled flag, **not** an external
ban attestation. External state is unknown until reviewed.

Conflicting normalized addresses block 015. Investigate the individually selected
accounts and their ownership evidence; no merge/rename/deduplication is automated.
Do not infer a role or ban from count output. Inventory itself grants no readiness.

## 2. Review one existing identity

Select the original user UUID from the approved private account investigation,
preserving all business relationships. Verify historical admin and disabled state
independently. Record the evidence and reviewer in the external ticket system.
Use a distinct ticket reference for each action (format `MIG-123`, uppercase
project prefix and positive numeric issue ID).

```sh
php yii auth/attest USER_UUID MIG-123 --admin=no --disabled=yes --apply=yes
```

Both flags are mandatory literal `yes`/`no`. This example records an ordinary,
disabled account as reviewed. Attestation revokes existing families, sets local
readiness, and records the reviewed role and state. An existing ban cannot be
lifted here. Reviewed PIN/biometric credentials remain enrolled; they can establish
new sessions only if the account is enabled, ready and not recovery-blocked.
An empty/unknown password still cannot be used for password login.

## 3. Set/reset a password after ownership verification

Verify ownership **outside this application**, tie it to the selected UUID and
record the method/reviewer in the ticket. This command does not perform identity
proofing. Use an unrecorded, trusted, interactive terminal:

```sh
php yii auth/reset-password USER_UUID MIG-124 --ownership=verified-out-of-app --apply=yes
```

Enter and confirm the password at the two no-echo prompts. The command rejects a
pipe/non-TTY, cancellation, mismatch and excessive/control-character input. It
does not accept a password option, environment password or stdin-pipe password.
Do not use shell redirection, command substitution, terminal recording, CI secret
injection or an argv-based password wrapper. Password policy is the same bounded
upper/lower/digit, minimum eight Unicode characters / maximum 128 UTF-8 bytes
policy as local registration, and cannot equal email.
Password computation occurs before the locked SQL transaction.

The reset replaces the native verifier and revokes every current session family
atomically with the audit. It does not issue a session, mark an unreviewed account
ready, lift a ban or silently clear a recovery fence. The user signs in again only
after the relevant gates are satisfied. The selected user's temporary password
attempt counter is cleared as part of this reviewed reset. Network/account-slot
budgets, PIN/biometric counters, enrollments, bans and readiness are preserved.

## 4. Resolve exactly one historical operation

Privately investigate the historical operation and its original identity. Verify
the UUID belongs to this user, confirm identity independently, and record whether
the remote reset was confirmed or its result remains unknown. A fresh local
password replaces that uncertainty; no old remote operation is replayed.

```sh
php yii auth/reset-password USER_UUID MIG-125 --ownership=verified-out-of-app --operation=OPERATION_UUID --identity=confirmed --outcome=unknown-reset --apply=yes
```

- `RESET_PENDING`: use `unknown-reset` only if that is the reviewed historical
  outcome, or `confirmed-reset` if independently confirmed.
- `REVOKE_PENDING`: requires `confirmed-reset`; a guessed/unknown outcome is rejected.
- A foreign, missing, completed or future-security-version operation is rejected.
- Only the selected operation receives `COMPLETE`, this ticket and confirmation
  time. Other users and other unresolved operations remain untouched.
- Only after that completion, if **no** unresolved operations remain for this
  user, is the current `sessions_blocked` cleared. A disabled user stays disabled.
- A blocked account without a resolvable selected operation remains fenced until
  the separate orphan procedure below; no SQL/bulk-unblock shortcut is authorized.

### Investigated orphan fence

If investigation proves that the fence has **no unresolved operation**, select
one UUID and a fresh ticket, verify ownership outside the application, then use:

```sh
php yii auth/reset-password USER_UUID MIG-127 --ownership=verified-out-of-app --orphan=confirmed-investigated --apply=yes
```

The secret still comes only from the no-echo controlling TTY. This option refuses
an unblocked account, any unresolved operation, or simultaneous selected-operation
flags. Hash replacement, all-session revocation, fence clearance and the explicit
orphan decision commit with the audit. It never marks an unknown remote operation
complete, activates an unreviewed identity or lifts a ban. A stale snapshot fails.

## 5. Separate state review / trusted admin bootstrap

After a separately authorized ban review, use a fresh action ticket:

```sh
php yii auth/set-disabled USER_UUID MIG-126 --disabled=no --apply=yes
```

`--disabled=yes` bans the account. Both directions revoke all current families.
This command does not attest roles/readiness or complete recovery operations.

For a new trusted administrator, have the verified person register an ordinary
local account with their privately chosen password through the existing register
flow. Independently verify the owner and first perform a fresh no-echo console
password reset with its own ticket. Then, **only in this console**, attest that
selected UUID with a reviewed
`--admin=yes --disabled=no` ticket. There is no default/bootstrap password,
automatic first-user promotion, environment admin list or HTTP promotion route.
The old registration session is revoked; the administrator signs in again.

## Results, retries and unknown commit outcome

Successful writes emit `{"status":"applied"}`; exact metadata replays emit
`{"status":"already_applied"}`. A ticket cannot be reused for another UUID,
action, role/state or recovery outcome. Passwords have no journal fingerprint.
An already-applied reset verifies the typed secret against the normal current
credential outside SQL and rechecks its snapshot under the user lock. A different
secret is `MIGRATION_TICKET_CONFLICT`; a later security mutation is
`MIGRATION_TICKET_SUPERSEDED` and requires reviewing the durable receipt. Neither
case reassigns a password. Use a **new ticket** to intentionally set a different
password.

On `MIGRATION_STALE_SNAPSHOT`, review the concurrently changed account and retry
only after confirming the new situation. A failed action leaves no success entry.
After `MIGRATION_FAILED_REVIEW_TICKET`/lost connection, use a fresh process with the
same action, UUID, ticket and reviewed metadata to establish whether it committed.
Never automatically replay under a new ticket or assume failure means rollback.
Keep output/evidence free of passwords, hashes and tokens.
