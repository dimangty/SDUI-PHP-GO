# ADR: preserve native transaction diagnosis and stage catalog index replacement

Date: 2026-10-30. Scope: backend round 4.

## Decisions

1. Preserve the **same primary Throwable**, not a wrapper replacing its SQLSTATE.
   Track transaction ownership and commit phase, protect cleanup, and quarantine
   an uncertain PDO session without implicit reconnect. Subsequent use rethrows
   that original cause. Definite PostgreSQL commit rejection is distinguished from
   missing acknowledgement: known server constraint/rollback/RAISE SQLSTATEs may
   retain a cleaned-up session. Unknown completion (including 40003) cannot.
   HTTP error classification is unchanged; logs retain only fixed event kinds and
   syntactically bounded SQLSTATE, never driver details or caller correlation IDs.
   Cost: callers need a fresh Database after uncertain failure; no automatic
   financial recovery/replay is inferred from connection handling.
2. Rebuild legacy catalog IDs into a new physical index from SQL under an explicit
   all-writers drain, verify counts/identities and switch a common backend index
   setting. Preserve the old index. Avoid deleting the concrete legacy index to
   turn its name into an alias, and avoid an asynchronous dual-write framework.
   Cost: an operator-maintained write-drain window and coordinated PHP role
   recreation; post-write rollback requires a fresh SQL-derived target.
3. Move the existing pure product/order conversions out of ShopRepository into
   stateless DTO mappers. Read repositories now require only Database; no read
   repository constructs another or depends on the write-oriented repository.
   Existing batch loads, snapshot values, ownership predicates and wire fields
   remain guarded by the native 0/1/20/100-row read suite. No new repository
   interface hierarchy, ORM or generic conversion framework is needed.

The round-3 Go application-owned ports/models, independent Yii wire mapping,
contract split, compact cards, batching and OTel SDK are pre-existing work and are
preserved, not introduced by this ADR.
