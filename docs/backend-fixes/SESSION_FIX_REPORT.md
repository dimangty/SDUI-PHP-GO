# B5–B6 review M1 correction

Date: 2026-10-26. Review: [SESSION_TRUST_REVIEW.md, M1](SESSION_TRUST_REVIEW.md#m1--service-account-authentication-failures-escape-logout-as-user-credential-failures).

## Finding verified and fixed

Without `KEYCLOAK_ADMIN_TOKEN`, `Keycloak::admin()` obtains a service-account token through `tokens()`. Its HTTP 400/401 user-grant classification previously escaped as `INVALID_CREDENTIALS` or `ACCOUNT_LOCKED`. Consequently, logout could report a caller-credential error after local revocation had already committed, even though no remote admin logout occurred.

The production correction is confined to **`backend-yii2/infrastructure/Keycloak.php`**, inside `admin()`: service-account acquisition preserves `IDENTITY_PROVIDER_UNAVAILABLE`, and converts other `ApiError` failures to a sanitized `IDENTITY_PROVIDER_ERROR`. This protects every admin caller without changing password/refresh user-grant parsing. The optional `HttpClient` constructor and its injection remain intact.

Additional files changed in this review follow-up:

- `backend-yii2/tests/SessionProviderFixture.php`
- `backend-yii2/tests/logout_regression.php`
- `docs/backend-fixes/SESSIONS.md`
- `docs/backend-fixes/SESSION_TEST_RESULTS.md`
- This report.

The test fixture can omit the admin-token override and record only numeric request counters (`grants`, `admin`, `logout`, `consent`) in test-owned temporary files. It never records request bodies, headers or tokens. Tests exercise the real HTTP adapter, AuthService and isolated PostgreSQL state.

## RED → GREEN commands and exit codes

Existing image: `shopkmp-php-tests` (PHP 8.3), current source bind-mounted at `/app`, image-supplied vendor initialized into an anonymous `/app/vendor` volume. Existing database/user/network are reused; all mutations are limited to `session_fixes`. No schema changes were needed.

The following exact command was run **before** changing production code and **again after** the correction:

```sh
PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH docker run --rm \
  --network shopkmp-backend-fixes-20261026 \
  -v /Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO/backend-yii2:/app \
  -v /app/vendor \
  -w /app \
  -e 'DATABASE_URL=postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/session_fixes?sslmode=disable' \
  shopkmp-php-tests php tests/logout_regression.php
```

| Stage | Observed result | Exit |
|---|---|---:|
| **RED**, tests added first | **41 tests, 8 failures**. Direct admin and authenticated logout tests received `INVALID_CREDENTIALS` for 400/401 `invalid_client`, and `ACCOUNT_LOCKED` for 400/401 account-disabled responses, instead of `IDENTITY_PROVIDER_ERROR` | **1** |
| **GREEN**, adapter correction applied | **41 tests, 0 failures** | **0** |

The RED logout cases checked local revocation, other-user isolation, request counters and secret handling **before** asserting the incorrect error code. They therefore established that M1 was the error-classification defect, rather than a missing local-cleanup side effect.

### Added regression coverage

Every service-account case explicitly unsets and checks the absence of `KEYCLOAK_ADMIN_TOKEN`:

| Token endpoint response | Expected admin/logout classification | Admin requests after rejected grant |
|---|---|---:|
| 400 / 401 `invalid_client` | `IDENTITY_PROVIDER_ERROR`, HTTP 502 | **0** |
| 400 / 401 with account-disabled text | `IDENTITY_PROVIDER_ERROR`, HTTP 502 | **0** |
| 503 with arbitrary provider body | `IDENTITY_PROVIDER_ERROR`, HTTP 502 | **0** |
| Timeout (200 ms injected HTTP deadline; fixture delays 700 ms) | `IDENTITY_PROVIDER_UNAVAILABLE`, HTTP 503 | **0** |
| 200 malformed JSON | `IDENTITY_PROVIDER_ERROR`, HTTP 502 | **0** |
| 200 invalid token field types | `IDENTITY_PROVIDER_ERROR`, HTTP 502 | **0** |
| Successful token response | Successful safe-user admin logout and consent cleanup | One logout and one consent call |

Each response is exercised directly through `Keycloak::admin()` and through `AuthService::logout()`. Successful requests require the expected client ID/secret in the grant and the returned service token in the admin Authorization header. Authenticated-user targeting is enforced by the HTTP fixture.

Logout integration assertions include:

- Both opaque access and refresh tokens from **two prior families** of the user are rejected, with both local token tables empty for that user.
- Another user's access token remains valid and their refresh token still rotates successfully.
- A failed service-account grant results in exactly one token-endpoint request and **no** admin logout/consent request.
- Client secret, admin override sentinel, service token, supplied provider refresh token, local opaque tokens and provider body sentinel are absent from surfaced error details/messages, captured application logs and the numeric fixture counters.
- Eight password/refresh-token cases preserve the existing `INVALID_CREDENTIALS`/`ACCOUNT_LOCKED` behavior for HTTP 400/401.

## Additional GREEN verification

Using the same Docker invocation above, run each PHP command tail below. Each invocation was freshly executed after the fix:

| Command tail | Observed result | Exit |
|---|---|---:|
| `php tests/session_revocation.php` | 7 tests, 0 failures | **0** |
| `php tests/session_password_recovery.php` | 10 tests, 0 failures | **0** |
| `php tests/session_concurrency.php` | 11 tests, 0 failures | **0** |
| `php tests/run.php` | 7 tests, 0 failures | **0** |
| `php tests/signed_oidc.php` | 2 tests, 0 failures | **0** |
| `php tests/durations.php` | 1 test, 0 failures | **0** |
| `php tests/lint.php` | PHP syntax: 76 files passed at verification time | **0** |
| `git diff --check` (repository root) | No whitespace errors | **0** |

Total freshly verified: **79 tests passed**, plus syntax checks. Shared full-stack verification remains with the main verifier. This follow-up did not edit shared recovery code, migrations, runners, Compose or controllers, and made no commits or pushes.
