# Core modules

- `common`: platform helpers and centralized user-facing error mapping.
- `network`: Ktor clients, DTOs, token refresh, request IDs, and timing.
- `settings`: encrypted Android session settings and platform storage on iOS.
- `remoteresult`: exhaustive transport result model.
- `mvvm` and `navigation`: shared presentation and navigation primitives.
- `uikit`: stateless Compose components, image placeholders, and previews.

Public APIs have KDoc; implementation details should remain `internal`.
