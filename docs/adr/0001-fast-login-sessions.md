# ADR 0001: backend-managed sessions for fast login

- Status: accepted
- Date: 2026-07-26

## Context

The assignment proposes Keycloak token exchange after PIN or biometric
verification. Password authentication already returns Keycloak JWT and refresh
tokens. Fast login, however, proves possession of an application-managed
credential and must not grant the backend a subject-impersonation role in
Keycloak.

## Decision

PIN and biometric login issue opaque application access and refresh tokens.
Only SHA-256 token hashes, the user identifier and expiry are stored in
PostgreSQL. Application tokens use reserved `app_access_` and `app_refresh_`
prefixes. The composite verifier dispatches by prefix and never falls back
between the application and OIDC trust domains.

Refresh rotates the opaque refresh token transactionally. Logout always asks
both the Keycloak client and the application session repository to revoke the
current user's session; device revocation additionally removes its fast-login
credential. Password changes revoke every application access and refresh token
for the user.

## Consequences

This avoids Keycloak preview token exchange and elevated service-account
impersonation. The cost is a second, deliberately small session lifecycle.
Parity is protected by identity behavior tests and PostgreSQL repository tests
covering refresh rotation, logout, revocation and cross-user isolation.

The decision must be revisited if standard token exchange can preserve the
original subject without an elevated impersonation grant and provides the same
revocation guarantees.
