# V6 controlled rollout / rollback

**Procedure only. Production NOT_EXECUTED. Release currently BLOCKED by
TEST_RESULTS and SECURITY_RESULTS.**

1. Preserve business and retired identity backups/volumes, immutable source/image
   references and migration history. Rehearse restore on a disposable copy.
2. Before schema 015, run count-only `php yii auth/inventory`. Resolve normalized
   email conflicts by reviewed identity decisions; never auto-merge users. Obtain
   independently verified roles/disabled-state and investigate every recovery fence.
3. Drain ingress, every old/new business writer, reconciliation, maintenance and
   payment recovery. Independently reconcile in-flight financial outcomes without
   replaying uncertain provider effects. There must be one password writer.
4. Apply **new 015 and 016 through the existing checksum runner**, then the
   separate existing Yii migration history job. Never alter old SQL/checksums.
   Existing users become unready; old opaque families are revoked. External tokens
   are not accepted by the new runtime. Preserve all original UUID/business rows.
5. Use the console-only, per-UUID/ticket procedures in
   [USER_MIGRATION](../local-auth/USER_MIGRATION.md): explicit role/ban attestation,
   owner-verified no-echo password setup, selected historical operation resolution
   or separately investigated orphan reset. No default password, batch unblock or
   guessed realm privilege is authorized. Provision actual least-privilege runtime
   and operator DB principals first; disposable effective-role tests do not provision them.
6. Rehearse PHP/Go/PIN/biometric/payment flows without any external provider.
   Never re-enable retired JWT/remote-refresh trust for apparent compatibility.
   Real users needing password/role proof remain an explicit unfinished cutover gate.
7. Search is a separate capability: on a fresh catalog use explicit
   `maintenance/search-bootstrap`. Existing unmarked/partial targets are refused.
   Under the documented write-drain, `maintenance/search-stage FRESH_TARGET`
   builds and verifies a ready physical index, then every PHP role switches its
   `OPENSEARCH_PRODUCTS_INDEX` together. Do not mechanically replace concrete
   indices with aliases. Keep old indices/backups; do not delete them automatically.
8. Deploy the compatible PHP summary extension **before** Go OrderSummaries.
   Old requests retain full DTOs. New Go against old PHP fails explicitly on a
   missing/wrong marker. Post-seed `make reindex` is reconciliation, not bootstrap.
9. Require source-bound full functional acceptance, trusted-network checks,
   restored workers, native-platform scans/SBOM and valid advisory databases.
   UNKNOWN/HIGH findings and incomplete acceptance continue to block publication.
   Commit/push only after these gates, then inspect exact-SHA CI separately.

## Rollback boundaries

After 015/trust cutover, use a **compatible local-identity repair release**.
Never return to an old provider-dependent PHP runtime that can accept superseded
passwords/JWTs or ignore local bans, readiness and revocations. Down migrations
deliberately refuse that unsafe reversal. A different identity mechanism requires
an approved remigration preserving every newer credential/role/revocation decision.

Before search writes resume, a failed coordinated switch can retain/restore the
previous verified compatible target. **After any new writes**, drain again and
build a fresh verified target from current SQL; never switch blindly to a stale
retained index. Interrupted/failed builds remain unpublished for inspection;
choose a new fresh target. Worker restarts and smoke writes cross this boundary.

Docker `down` without volume deletion is not a database rollback. No production
migration, restore, deploy, password setting or risk exception is implied by this
document or by a future Git push.
