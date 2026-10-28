# Task 2 M1 — scoped rereview, fix round 1

Date: 2026-10-27. Reviewed the current source and
`TASK2_REPORT.md:308–399` against M1 in `TASK2_REVIEW.md:29–59`.

## Verdict

| Finding | Rereview verdict |
|---|---|
| Task 2 M1 — Medium, hidden global-Koin account lookup | **Resolved.** The public two-callback entry no longer resolves global DI. Its documented compatibility contract is editor-lifetime-only; every located production editor entry explicitly supplies the real live account source. |
| New regressions introduced by this fix | **None found** in the inspected fix delta, actual caller chain and existing regression evidence. No new Critical/High/Medium finding. |

**Specification and quality: PASS for this scoped correction.** This closes the
M1 condition from the initial Task 2 review. This verdict does not extend to
unrelated Task 3 checkout/disclosure work or overall release readiness.

## M1 resolution and public contract

Source:
`feature/profile/profile-ui/src/commonMain/kotlin/com/shopkmp/profile/ui/EditProfileViewModel.kt:24–26,44–67,113–120`.

- The VM retains its repository-only constructor. BackendSession/KoinPlatform
  imports and the service-locator default are removed.
- `currentAccount: (() -> String)? = null` represents an absent source; it does
  not fabricate an account ID or catch a missing-Koin exception. The public
  `load(onBack, onSaved)` entry can load/edit/save with the repository and normal
  lifecycle dispatcher alone.
- KDoc at lines 44–47 expressly limits callers without a source to
  editor-lifetime guards and requires account-switching hosts to provide the
  lookup plus matching flow. The appended report clarifies that an accountKey
  alone is an owner label, not live-authentication proof
  (`TASK2_REPORT.md:327–342`). That appendix explicitly supersedes the historical
  global-Koin signature/explanation earlier in the report.
- `isCurrent` checks the revision **before** taking the no-source compatibility
  branch. With a supplied source, blank or mismatched live accounts still call
  `leave()` and reject the operation. Compatibility mode therefore does not
  disable editor-lifetime guards or weaken the live-source branch.

The documented legacy mode is acceptable for this fix: it is not the
account-switching production path, and its public contract does not claim
account-switch protection. No additional global DI fallback or fabricated
identity is needed to close M1.

## Actual production wiring and guard preservation

Searched Kotlin/Gradle/Swift sources for the VM, screen and editor callback
references, then read the relevant calls:

1. `feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt:67–75`
   captures `viewModel.stateOwner` and `state.profileEditorId`, keys the editor by
   both, passes the raw owner as `accountKey`, and invokes the explicit
   owner/editor-ID cancel/save overloads.
2. `feature/profile/profile-ui/src/commonMain/kotlin/com/shopkmp/profile/ui/EditProfileScreen.kt:36–45`
   resolves one BackendSession and **always** passes `{ session.userId }` and
   `session.accountIdFlow` to `load`, together with accountKey. Disposal still
   calls `leave()`. There is no production call relying on omitted/null source.
3. `core/network/src/commonMain/kotlin/com/shopkmp/di/Di.network.kt:49–50`
   registers BackendSession as a singleton.
   `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/ShopNativeSduiAdapter.kt:27–28`
   derives the host account and change stream from that session contract;
   `SduiViewModel.kt:101–102` exposes its raw account as stateOwner.
4. `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:189–208`
   still checks the latest account, editor-open flag and editor ID before save
   rebasing or cancellation. Those host guards were not changed by this fix.

For the VM itself, the inspected delta is limited to imports, nullable
source/owner representation, KDoc, ownership initialization/reset and
`isCurrent`. Revision invalidation, account-flow collection, load/result checks,
generated callback guards, synchronous single-flight save, submitted snapshots,
draft-preserving failures and distinct cancel/save callbacks remain in place
(`EditProfileViewModel.kt:55–95,98–110,123–185`).

This delta was checked against the prior review's SHA-256 source snapshot by
reconstructing the pre-fix text in memory. The reconstructed hash matched.
EditProfileScreen, SduiViewModel, its host test file, profile iOS UI tests and
profile module Gradle also match their prior-review hashes.

## Regression evidence

Reviewed with the existing `kmp-unit-tests` review criteria. The sole added case,
`legacyLoadWithoutGlobalKoinCanEditAndSaveTheRepositoryProfile`, is at
`feature/profile/profile-ui/src/commonTest/kotlin/com/shopkmp/profile/ui/EditProfileViewModelTest.kt:26–44`.
It directly constructs the real VM with the existing EditorRepository, calls
the two-callback `load` without Koin/account overrides, edits through the
generated input callback, saves through the generated action and asserts the
submitted payload plus saved-versus-back outputs.

Removing only that new 20-line block **in memory** reproduces the exact
prior-reviewed test-file hash. All 12 previous editor tests, the helper, fake,
dispatcher setup and cleanup are byte-identical. In particular, live-flow
account cancellation and queued/late-save ownership cases remain at lines
73–87 and 244–264. The unchanged host suite retains its stale-editor/account
guards; no assertion was replaced to accommodate the legacy branch.

`TASK2_REPORT.md:366–376` records the new regression's pre-fix RED:
14 JVM results / one failure, `KoinApplication has not been started`. That
historical failing run was not independently rerun during this rereview.

Current affected-module XML was independently inspected:

| Module | JVM results | iOS simulator results | Failures / errors / skips |
|---|---:|---:|---:|
| profile-ui | 14 | 19 | 0 / 0 / 0 |
| main | 43 | 44 | 0 / 0 / 0 |

XML timestamps are approximately 07:19Z on 2026-10-27. The new legacy test is
present and successful in both profile VM XML files. The main figures include
Task 3 chrome tests; they are affected-module evidence, not extra M1 scenarios.
The 26 host tests remain present and successful on both targets.

No test/build was rerun here. The successful affected-target compilation in
`TASK2_REPORT.md:378–393` remains author-reported evidence. iOS arm64 compilation
does not establish physical-device execution; no installed-app or device run is
claimed by this rereview. Scoped `git diff --check` returned exit 0.

## Write boundary

Only `docs/mobile-ui-fixes/TASK2_REREVIEW.md` was created by this rereview.
No source/test changes, subagents, full-suite runs, commits, pushes or device
operations were performed. Task 3 checkout/disclosure changes were not reviewed
or edited. Existing Task 2/A1 files were preserved. Inspection used the Python
fallback for the previously unavailable Bun-backed read/search tools.
