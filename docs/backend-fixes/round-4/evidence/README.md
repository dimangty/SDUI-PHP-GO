# Round-4 evidence

`round4-evidence.zip` and `INDEX.json` bind the retained command receipts, native
logs, synthetic HTTP/schema samples, resource observations, raw advisory/scanner
reports, SBOMs and artifact/source provenance by SHA256. RED, FAIL, timeout and
intermediate environment failures are retained. The index is an integrity record,
not an assertion that every check passed.

Host filesystem read/search tools failed (`Bun is not defined`), so read-only
shell/Python fallbacks were used. Host PHP/Composer/psql were absent; project-native
Docker PHP/PostgreSQL/OpenSearch and native Go 1.27.3 were used, without a version
downgrade or fake PDO replacing required integration tests. Deterministic PDO
fault doubles supplement the native timeout/termination/deferred-commit tests.

Reproduce using `PLAN.md`, the project Makefile and the isolated network variables
in `ROLLOUT.md`. Every top-level check receipt records argv/cwd/UTC/exit; the
existing verification runner's nested suites have raw logs under their own full
run directory. Scanner subcommands retain individual exits/stdout/stderr.

Packaging uses `scripts/backend_evidence.py --directory
docs/backend-fixes/round-4/evidence`; that existing round-3 utility writes a fixed
`round3-evidence.zip` name, which is renamed to `round4-evidence.zip` here. Its
archive verification is unchanged. Cache/tool directories, binary/image archives
and unreviewed private data are excluded. No production data is used.

Read TEST_RESULTS.md for semantic outcomes: a benchmark process exiting zero
while printing per-repetition FAIL is not accepted as passing. Timeout logs lacking
a completed command receipt are explicitly identified, not fabricated as exits.
