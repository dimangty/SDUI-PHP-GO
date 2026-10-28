# User-requested local UI checkpoint — 2026-10-27

The user explicitly requested a local commit and continuation. This checkpoint
records the shared reference-UI implementation, its task reviews/regressions and
the completed four-image installed journeys. It is **not a release/push approval**.
The earlier backend checkpoint is `b9def93963ef08b1d19bedf957c0b3d3d003add7`.

## Included work

- Teal/warm shared design tokens, compact controls, real vectors/navigation,
  auth layouts and explicit 4–6 digit PIN behavior.
- Account-safe native editor, readonly email, capability-aware photo control,
  real controlled biometric state and original-account/lifetime guards.
- Branded main chrome and reference shopping presentation; unchanged v1
  schemas, 54 PHP operations, eight screens and six commands; exact money data.
- Deliberate builder fixtures and meaningful VM/Compose/contract regressions.
- Installed runner portability, real viewport-aware scrolling and privacy-safe
  diagnostics. The iOS keyboard-clipping regression is fixed and was verified
  by the subsequent real installed journey.

## Executed evidence at the checkpoint

- Shared/task suites passed on Android-hosted JVM and iOS simulator; affected
  Android, iOS simulator-arm64 and iOS device-arm64 compiles passed. Individual
  commands, counts, reused results and RED/GREEN cases are in TASK1–3 reports.
- `make frontend-test` including Kover passed. Full lint initially found six
  wildcard imports; explicit-import correction made `make frontend-lint` pass.
  `make frontend-build` produced the Android Debug APK.
- BFF normal/race/vet and the route matrix passed: 54/8/6. The existing optional
  live Go test was skipped in that run and is not represented as executed.
- Completed pre-expansion iOS CARD journey: 1 passed / 0 failed, xcodebuild exit
  0, test 236.514s. Canonical exact order/stock/payment and logout were verified.
- Two fresh completed pre-expansion Android CARD journeys passed; final-source
  run 1 passed / 0 failed, 36.44s, runner exit 0. Its earlier unproven missed-click
  failure remains documented; no payment-action retry was added to mask it.

## Continuation, not yet a passing claim

The installed tests/runners now contain the expanded **13 named app screenshots**
covering ten reference states, six-digit PIN login and native-editor checks.
That extension had not completed an installed run at this checkpoint. The latest
four-image passing summaries do not certify the expanded source. Continue with
the two serial installed runners, then complete `INSTALLED_VISUAL_REPORT.md`,
before/after comparison and final integrated verification/review.

Photos remain an explicitly unavailable upload capability and notifications have
no API/action; neither is faked. Physical-device, all accessibility configurations
and production behavior are not certified by simulator/emulator evidence.

Unfinished A1 runtime-image work in `backend-yii2/Dockerfile` and
`scripts/test_php_runtime.py` is intentionally left outside this UI checkpoint.
A1 still blocks the overall release and push. No production data or real monetary
operation was used by these tests.

## Continuation evidence — 2026-10-28

The pending status above describes the committed checkpoint, not the later
execution result. Expanded journeys have now completed serially:

- iOS receipt `20261028T014901619564Z`: **1 passed / 0 failed / 0 skipped**,
  runner/xcodebuild exit **0**, 298.542s test, **13/13 accepted app images**.
- Final Android receipt `20261028T020941547858Z`: **1 passed / 0 failed /
  0 skipped**, runner exit **0**, 54.192s test, **13/13 accepted app images**.

Image review rejected two earlier Android PIN captures with transient key
feedback. A test-only rendered-release privacy guard now prevents export until
the actual key regions match their pre-entry released appearance; a failed-closed
attempt and all intermediate results remain recorded. Production UI is unchanged.

See [`INSTALLED_VISUAL_REPORT.md`](INSTALLED_VISUAL_REPORT.md) for exact commands,
complete attempt counts, actual ten-state contact sheets, 26-image checksum/source
manifest, privacy review and remaining capability/visual gaps. The continuation
does not erase the prior failed attempts, authorize a release/push, or resolve A1.
