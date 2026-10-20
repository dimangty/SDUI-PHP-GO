# ADR 0003: Deliberate deviations from the project stack examples

- Status: accepted
- Date: 2026-07-26

## Context

The assignment names several libraries as examples of the intended stack.
ShopKMP implements the same capabilities, but in four places a smaller or more
operationally consistent mechanism fits the project better. Leaving those
choices implicit makes an intentional trade-off look like an unfinished
dependency migration.

## Decision

| Assignment example | ShopKMP decision | Rationale and guardrail |
| --- | --- | --- |
| `golang-migrate/migrate` | Embedded one-shot `cmd/migrate` | Forward migrations and their SHA-256 checksums ship in the same immutable backend image. Tests reject changed, already-applied SQL. |
| `testcontainers-go` | Docker Compose plus `cmd/e2e` | Local and CI E2E use one infrastructure definition for PostgreSQL, Keycloak and OpenSearch instead of maintaining a second topology in Go. |
| `opensearch-go` | Narrow HTTP adapter | Only health, index and search are needed. The adapter bounds time and response size and has wire-contract tests. |
| `swaggo/swag` | Hand-maintained OpenAPI 3.1 contract | The contract drives both backend and KMP DTO checks. CI validates it with contract tests and Vacuum lint. |
| SQL generated exclusively by `sqlc` | `sqlc` for stable queries plus explicit `pgx` transactions | Multi-row checkout/payment flows need visible lock ordering and transaction boundaries; focused persistence tests protect them. |

## Consequences

The project accepts ownership of these small adapters and runners. Every
deviation must retain an automated contract or integration test, and changes to
the hand-written OpenAPI document must pass both Vacuum and the Go/Kotlin
contract tests. Adding a replacement library later requires a new ADR if it
changes these operational guarantees.
