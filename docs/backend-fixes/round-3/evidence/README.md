# Portable round-3 evidence

`round3-evidence.zip` contains raw command receipts/logs, schema-validated HTTP
bodies, SQL samples/plans, fresh scanner output/SBOMs, and artifact observations.
`INDEX.json` records every member's size and SHA256. It deliberately retains RED,
intermediate FAIL and environment failures; filenames containing “green” are not
an authority over their actual exit codes.

Extract in this directory to resolve the relative evidence paths in the reports:

```sh
python3 -m zipfile -e round3-evidence.zip .
```

Scanner caches, downloaded tools, extracted binaries and full image tar archives
are excluded. Database metadata/content hashes, binary hashes, image config IDs,
raw scanner reports and command stderr/exit receipts are retained. No production
data or credentials are intended as evidence. Test users/identifiers are synthetic.

Regenerate with `python3 scripts/backend_evidence.py --directory
docs/backend-fixes/round-3/evidence` from the repository root. The archive proves
preservation of observations, not passing acceptance or publication approval.
