# Local identity API compatibility

The operator tools add **zero HTTP endpoints**. They are registered only in
`backend-yii2/config/console.php`. The existing HTTP manifest remains 54 operations
(15 public, 32 authenticated, 7 admin). Existing client DTOs, ownership checks,
decimal money and error envelopes remain the controlling contract.

| Existing operation | Local identity contract |
|---|---|
| `POST /api/v1/auth/register` | Existing email/password/profile input and 201 token pair. New account is ordinary, enabled and locally ready; no client role claim is trusted. |
| `POST /api/v1/auth/login` | Existing email/password input and 200 token pair. Email is normalized; the password is not trimmed/case-folded. Local reviewed/enabled/unfenced account required. |
| `POST /api/v1/auth/refresh` | Existing refreshToken input and 200 pair. Refresh is single-use and bounded by family absolute expiry. A lost response/competing use requires sign-in. |
| `POST /api/v1/auth/logout` | Existing protected route and 204. Local user-wide revocation; no remote provider session is needed. |
| `POST /api/v1/users/me/change-password` | Existing oldPassword/newPassword input and 204, with no replacement pair. Atomic local verifier change and user-wide session revocation. |
| PIN/biometric routes | Existing IDs, proofs and DTOs. All issuance/authentication/refresh is subject to the same ready, disabled and recovery fences. |
| Admin routes | `users.is_admin` is read from current storage, not an old bearer claim. Role attestation revokes existing families. |

The pair remains `userId`, `accessToken`, `refreshToken`, `expiresIn`, with
`app_access_` / `app_refresh_` opaque values. There is no JWT/OIDC acceptance or
parallel provider fallback. Existing family/token verifier storage is retained.
Token-bearing responses remain no-store at the existing HTTP boundary.

## Deliberate cutover effects

- Pre-015 accounts require individual local review before **any** credential kind
  establishes a session; their old IDs and business data survive.
- A reviewed empty/unknown password cannot log in with a password. Existing
  reviewed devices can still be used if other account gates permit.
- A legacy password verifier may be upgraded by the core login flow after valid
  verification. Operator inventory never upgrades it.
- Prior opaque families are revoked by 015; operator attestation/reset/state
  operations also revoke all kinds. Users must sign in again.
- A disabled or pending-recovery user stays unavailable after password setup until
  the separate relevant review is completed.
- The previous historical session-recovery shortcut is insufficient. Use selected
  local reset evidence as described in [USER_MIGRATION](USER_MIGRATION.md).

Core HTTP/session behavior is owned and verified by the coordinator. Task 3's
native migration and console evidence is in
[MIGRATION_RESULTS](../round-5-local-auth/MIGRATION_RESULTS.md).
