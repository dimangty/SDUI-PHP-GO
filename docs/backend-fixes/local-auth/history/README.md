# Historical identity evidence — never a runtime input

The realm JSON and SHA256SUMS were moved without changing their bytes. They
record original policy, role names and backend service-account permissions, not
actual end-user role/disabled/password state. The old session and rollout guides
are retained byte-for-byte for audit, not runnable V6 procedures.

Current guidance: [deployment](../../../DEPLOYMENT.md),
[identity design](../../round-5-local-auth/ADR.md), and
[runtime execution](../../round-5-local-auth/RUNTIME_RESULTS.md).

The exact-path/content-hash manifest is `scripts/runtime_history_allowlist.json`.
Changes require review and a new explicit digest. Siblings are not automatically
exempt. Executable sources, active manifests/config and current guides cannot be
allowlisted; historical policies must never be mounted/copied into runtime images.
No archived file is permission to delete persisted volumes or infer user grants.
