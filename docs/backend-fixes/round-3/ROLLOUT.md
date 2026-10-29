# Round 3 — upgrade and rollback

**Publication remains blocked.** These are the required operational steps after
functional/security acceptance, not evidence of production deployment.

1. Back up/verify migration history. Deploy PHP first. Existing migration bytes
   remain immutable; run the additive Yii audit migration through the ordinary
   `yii-migrate` job. It adds only `credential_remediation_audit` and refuses a
   destructive down. Old application versions tolerate the unused table.
2. Verify internal PHP `/health/ready`: `status=ok`, capability
   `catalog-cards-v1`. Probe products/search/popular with
   `projection=sdui-card-v1`, including an empty page; require the exact envelope
   marker, real pagination and bounded bytes. Omitting the parameter must still
   return full public descriptions/images; a detail request is always full.
3. Deploy Go only after those probes pass. New Go against old PHP is unready and
   rejects unsupported projection responses; it does not invent empty content.
   Verify all eight screens/six commands, schema/CMP wire limits, private no-store,
   timeouts, cancellation, real-IP trust, read quota and mutation no-replay.
4. Tracing defaults off. Enable only with dedicated trusted `SDUI_TRACE_*` settings
   described in `backend-sdui/README.md`; no ambient `OTEL_*`, URL credentials or
   escaped paths. Start with local sampling 0.1 and bounded defaults. An unavailable
   collector must not change business responses; loss of telemetry is permitted.

## Legacy devices

New PIN/biometric enrollment rejects exactly `.` and `..`. Existing records remain
visible with explanatory text, without an incompatible CMP action. An owner may
revoke via `DELETE /api/v1/auth/pin?deviceId=.` (or biometric / `..`); path dot
segments are rejected. Ownership is checked by PHP under existing locks.

Operator dry run in the PHP container: `php yii maintenance/legacy-devices`.
Explicit one-record remediation:
`php yii maintenance/legacy-device-apply USER_UUID pin . OPS-123`.
It revokes the credential and linked session families atomically and inserts the
audit ticket; audit failure rolls the operation back. No automatic mass deletion
or opaque-ID rename. Dry run must precede addressed applies on existing data.

## Application rollback

Roll Go back **before** rolling PHP back. Old Go consumes unchanged full public
DTOs from new PHP. Retain the additive audit table/history; do not undo applied
SQL or erase remediation evidence. Disable export independently before an image
rollback if needed. Credential revocation is a security operation, not an
application-deployment rollback; never recreate revoked credentials/sessions.

No production migration, cleanup, load test, cutover or provider settlement was
performed. Local fixtures use explicitly opted-in disposable projects only.
