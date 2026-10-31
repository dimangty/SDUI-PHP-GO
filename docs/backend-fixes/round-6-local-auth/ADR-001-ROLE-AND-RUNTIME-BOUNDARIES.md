# ADR 001 — explicit SQL capabilities and separately gated PHP runtime upgrade

Status: SQL boundary and verified PHP 8.5.12 upgrade implemented; publication blocked.

Use seven fixed project SQL capabilities and explicit per-service secrets rather
than shared bootstrap ownership or a second auth service. A versioned object
manifest limits ACL/ownership changes; separately managed audit objects keep their
original authority. Unknown effective relation access, role membership, ownership
or exposed definer functions fail bounded preflight. Readiness checks identity
without repeating heavy catalog audits. App/session rules stay PHP-owned.

Recurring catalog reconciliation is SQL read-only. Explicit index initialization
has a distinct limited catalog login because PostgreSQL SHARE table locking needs
table UPDATE privilege. Maintenance and recovery have different financial/cleanup
rights; neither can issue sessions or read password verifiers. More deployment
secrets are the cost of preserving those boundaries.

Evaluate 8.5 separately before changing the default. That stage is now complete:
Composer/platform/Argon2id/legacy PIN, extensions/eager loading/TLS/FPM and complete
native suites passed on actual arm64 and amd64 images (amd64 under emulation).
Remove PHP 8 no-op cleanup APIs whose 8.5 deprecations mask primary exceptions;
preserve clean command stdout at entrypoint. Use client libpq headers and retain
verified runtime pruning. PHP 8.5.12 is now the pinned default; supported 8.3 was not
called EOL. No password/token or mobile flow change was bundled with the upgrade.

Final arm64 source-bound acceptance and live trust tests pass, including scheduler
and shutdown/restart with unchanged budgets. Exact-image security still rejects
OpenSearch HIGH findings and stale authoritative Go DB; native platform rollout
and native amd64 publication acceptance remain separate, mandatory boundaries.

The later official-stable OpenSearch 3.9.0 candidate is **not promoted**: actual
matched-pruning image scan has 32 HIGH versus current 19, including mandatory
security-plugin and shaded libraries. A major change must not be claimed as a
security fix merely from release notes. Upgrade/restore qualification stays a
prerequisite for a future acceptable candidate; no production data directory was
opened by 3.x, and no manual JAR/JDK replacement or VEX exception was introduced.

Native rollout now has actual evidence: the unchanged iOS test builds and reaches
checkout, then fails Safari completion with no payment POST. Backend acceptance
does not approve that platform boundary; the inherited assertion remains intact.

The former fixture-lifetime minor is fixed using the existing parent-control
pattern: explicit stop/EOF instead of a 400 ms inter-request idle cutoff. Two
reproducing failures became green, and full source-bound acceptance was repeated
for the resulting source snapshot. This changes fixture ownership, not production
HTTP, read, hash, transaction or scheduler deadlines.
