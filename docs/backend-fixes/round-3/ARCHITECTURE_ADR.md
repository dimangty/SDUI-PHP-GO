# ADR: bounded PHP reads and consumer-owned SDUI application

## Decision and alternatives

Use an opt-in, fixed `projection=sdui-card-v1` on the existing catalog/search/
popular read operations. Unprojected public lists and details retain full data.
Only absence of the parameter selects full data; a supplied empty/unknown value
is explicitly rejected before database/search I/O.
Increasing the global body budget would conceal overfetch; per-product HTTP
fan-out would amplify it. Neither is selected.

The projection is a data model, not UI markup: ordinary product card fields,
one ordered image (`imageUrl`), `descriptionSnippet` (at most **257 Unicode code
points**) and `descriptionComplete`. PHP does not add ellipsis or choose SDUI
components. Go alone produces the existing at-most-256-code-point preview,
including its ellipsis. A non-complete snippet must contain 257 code points.
Projected pages carry `projection: "sdui-card-v1"`; popular uses an envelope with
the same marker and `items`. A missing/wrong marker is a protocol error, never an
empty fallback. PHP readiness advertises `catalog-cards-v1`; Go requires it.

Batch read repositories load product images/order items once per page. Mapping
functions receive all data and perform no I/O. Parent pagination happens before
child loading. Existing snapshots, money formatting, owner predicates and ordering
are retained. No speculative index or migration is needed without EXPLAIN evidence.

New device IDs accept `[A-Za-z0-9._-]{1,255}` except exactly `.` and `..`.
Opaque values retain case. Legacy address validation allows those two values only
for owner-scoped revocation using query semantics, existing user/credential locks
and family revocation. SDUI shows legacy records with an explanatory text and no
incompatible RevokeDevice action. An operator dry-run/count and explicit addressed
remediation reuse that locked application operation and record an audit trail;
there is no automatic bulk cleanup or rename.

## Go dependency boundaries

`transport HTTP -> application -> consumer-owned ports/models + typed SDUI/domain`.
The Yii adapter implements ports and converts wire responses explicitly. The
composition root constructs the real adapter. Application errors are neutral;
HTTP status/body/header mapping stays at transport. Pure builders do not perform
I/O. Import-boundary tests prevent adapter/HTTP dependencies from returning.
Compatibility aliases at the old boundary may preserve callers/tests but cannot
make the application depend on the adapter.

Split SDUI definitions, encoding/revision, structural/semantic checks and limits
without changing schemaVersion, local JSON Schema, CMP capabilities or limits.
Keep typed validation at runtime and independent embedded-schema contract tests;
no new runtime schema compiler or network loaders.

## Deployment and verification

Deploy PHP first, verify capability and compact reads, then Go. Roll Go back before
PHP; the old Go still works with unprojected PHP. A new Go with old PHP is unready
and fails closed rather than silently losing data. See ROLLOUT for operational
commands and final verification results.

Use the task's detailed requirements as the approved design brief and execute
inline/independent bounded work as requested, without pausing at a plan-only handoff.
Keep publication blocked until the existing mandatory security gate passes.
