# Local identity rollout and rollback

**Production cutover, production account review and production password setup:
NOT_EXECUTED.** Native disposable evidence is recorded in
[MIGRATION_RESULTS](../round-5-local-auth/MIGRATION_RESULTS.md). Whole-stack and
release evidence belongs to the coordinator's `TEST_RESULTS.md`.

## Cutover sequence

1. Bind deployment source/images to the coordinator's passing gates. Back up SQL
   and audit evidence; test restore in a separate environment. Arrange trusted
   operator access, private identity review and separated DB privileges.
2. Run `php yii auth/inventory` before the trust transition (schema 013/014 is
   supported) and resolve normalized-email conflicts privately. Record only counts
   in general evidence. No account is automatically merged or made ready.
3. Enter the write-drain maintenance window. Stop every old auth/profile/device
   writer, old identity recovery worker and old registration process. Schema 015
   defaults **future trusted inserts** to ready, so an old writer cannot coexist
   safely during this boundary.
4. Rebuild the **existing** `tools/ops/cmd/migrate` image so it embeds 015 and 016,
   check `tools/ops/migrations/SHA256SUMS`, and run the existing migration command.
   Its advisory lock/history/checksum verification applies the new migrations.
   Do not paste SQL into an alternate production migration runner. Yii's migration
   command is not a substitute for these embedded canonical SQL migrations.
5. Deploy the matching local-auth application/session runtime and runtime graph.
   Confirm old families fail, all unready/disabled/recovery-blocked credential
   kinds fail closed, and ordinary fresh registrations are non-admin. There is no
   provider/JWT fallback.
6. Run inventory again. Review **one UUID at a time** using
   [USER_MIGRATION](USER_MIGRATION.md). Attest role and state, separately set a
   password only after verified ownership, and resolve historical operations
   individually. Bring up a verified ordinary account and console-attest it for
   trusted admin bootstrap. No shared/default password is created.
7. Verify the account-state matrix and preserved business ownership, then reopen
   traffic under the coordinator's deployment procedure. Retain unreviewed users
   and unknown recovery jobs as unavailable/manual, not silently accepted.

## Account-state acceptance matrix

| Condition | Expected state after operator action |
|---|---|
| Old unready user; no attestation | No credential/session issuance |
| Ready, enabled, no fence, valid native/legacy password | Password flow allowed under core policy |
| Ready, enabled, no fence, empty/unknown password | Password login denied; valid reviewed device flow may work |
| Disabled user after attestation/reset | Still disabled; separate state-review action required |
| Recovery-blocked user after ordinary attestation/reset | Still blocked |
| Selected resolved operation with another unresolved operation | Only selected complete; current fence remains |
| Selected final operation resolved by confirmed local reset | Current fence cleared, but ban/readiness still enforced |
| Fence with no selected resolvable operation | Remains blocked pending investigation |
| Investigated orphan, owner-verified TTY reset with `--orphan=confirmed-investigated`, no unresolved operation | Fence clears atomically with new verifier/revocation/audit; ban/readiness remain |
| Same UUID/action/ticket/metadata again | Receipt/no new hash, revocation or journal row |
| Same ticket with different UUID/action/metadata | Rejected; no mutation |
| SQL/audit failure | No partial successful mutation; uncertain commit resolved with same-ticket fresh process |

## Rollback boundary

Migration 016's down SQL **always raises**: the operator journal is forward-only.
Deleting evidence is not a rollback of a password change, role decision, ban review,
family revocation or historical completion. Old family tokens must never be made
valid again. Schema 015's separate rollback restrictions also continue to apply.

Before any trust cutover, restoring a tested full pre-cutover backup is an
environment restore decision, with the associated write-loss boundary. After local
registrations, reviewed roles/bans or password writes have occurred, rolling an old
provider-dependent binary over the database cannot safely reconstruct that state.
Stop affected traffic and **roll forward** with a compatible repaired runtime.

Undo an incorrect role/disabled-state decision through a fresh audited selected-user
ticket. Correct a password with a new ownership-verified TTY reset ticket. These are
new reviewed security operations, not journal edits or token resurrection. A
previous reset ticket is rejected as superseded after a newer security mutation;
its durable receipt is retained and cannot reassign a password.

## Unresolved operator/release gates

- Actual production counts, normalized-email conflict review and trusted external
  ownership/admin/ban evidence are unavailable here.
- Production operator/runtime role provisioning, backup/restore rehearsal and
  drain/cutover scheduling require the deployment owner.
- Historical operation identities/outcomes and orphan fences require individual
  investigation. Only the explicit investigated-orphan reset can resolve a fence
  without an operation; no bulk completion/unblock is authorized.
- Coordinator whole-stack/native HTTP/security/dependency/publication gates must
  pass for the exact release source and images. Task-3 evidence alone is not a
  release approval.
