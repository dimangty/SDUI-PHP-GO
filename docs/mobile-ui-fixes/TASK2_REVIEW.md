# Task 2 — independent read-only review

Date: 2026-10-27. Diff baseline: `b9def93963ef08b1d19bedf957c0b3d3d003add7`.

## Verdict

- **Specification: conditional pass.** The owned editor, capability-aware photo
  control and biometric bridge meet the inspected presentation/state requirements.
  The unnecessary global account-source fallback needs the correction in M1.
- **Code quality: changes requested — one Medium finding.** No Critical or High
  finding was established in the Task 2 diff.
- This is a source/test-evidence review. Installed-app visual conformance,
  physical-device execution and completed Task 3 integration are not established.

Read `PLAN.md`, `TASK2_REPORT.md`, Task 1's exact API handoff in
`TASK1_REPORT.md:174–335`, the approved `docs/images/frontend-sketch.svg`
(especially lines 322–348), and `docs/backend-fixes/IOS_VISUAL_AUDIT.md`.
The later explicit authorization for mobile UI fixes is respected.

Reviewed the actual Task 2 hunks and complete relevant implementations: profile
editor screen/builder/VM/Gradle/common and iOS tests; EpsProfile, EpsAvatar/state,
EpsEditAvatar/EpsProfileFormFields in EpsShopLayout, EpsBiometricRow;
SduiViewModel, ShopNativeSduiAdapter and host tests. Existing session/auth/profile
dependencies were read to verify the real boundaries. Concurrent Task 3 work is
not attributed to Task 2.

## Findings

### M1 — Medium: the default account callback adds a hidden global Koin dependency

**Location:**
`feature/profile/profile-ui/src/commonMain/kotlin/com/shopkmp/profile/ui/EditProfileViewModel.kt:46–59`,
particularly line 50; subsequent account checks at lines 111–117.

The new default `currentAccount` performs
`KoinPlatform.getKoin().get<BackendSession>()` whenever invoked. Thus the retained
`EditProfileViewModel(repository).load(onBack, onSaved)` entry now requires a
process-global Koin application and BackendSession registration, although the VM
previously needed only its constructor repository. With the default empty key,
the lookup executes synchronously at line 55, before the load coroutine's error
handler. Missing global DI therefore throws instead of publishing a load error.
It also repeats service-location during input/save/result ownership checks.

This fallback is unnecessary for the real screen: it already supplies the
callback and flow from the same Koin-owned session at
`feature/profile/profile-ui/src/commonMain/kotlin/com/shopkmp/profile/ui/EditProfileScreen.kt:36–44`.
All new VM tests explicitly replace the callback, including the helper at
`feature/profile/profile-ui/src/commonTest/kotlin/com/shopkmp/profile/ui/EditProfileViewModelTest.kt:273–274`,
so their GREEN results do not validate the retained default entry.

**Action:** keep the live account lookup as an explicit, narrowly supplied
callback dependency, wired once at the existing composition/DI boundary. Back
any retained legacy entry with that supplied dependency, rather than resolving
global Koin inside its default argument. Retain the current callback/Flow source
identity and all token/live-account guards; substituting a constant account key
would defeat the protection. Verify direct VM construction with the supplied
account source and the existing stale-account/callback cases. This is a local
dependency correction, not a reason to introduce another settings/session or a
broad application test harness.

## Specification and boundary checks

| Requirement | Review result and source evidence |
|---|---|
| Fixed teal editor chrome and usable scrolling | `EditProfileScreen.kt:58–94`: shared EpsTopBar is outside LazyColumn; IME padding belongs to the scrollable body; save is full width. Title/back tags attach to the Task 1 app-bar API. The old body header is not rendered. |
| Honest photo capability and approved avatar | `EditProfileStateBuilder.kt:35–38` supplies no photo callback. `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/shop/EpsShopLayout.kt:157–180` enables the actual tagged button only for a non-null capability and shows the unavailable caption otherwise. `component/avatar/EpsAvatar.kt:27–43` uses the teal person placeholder and retains explicit initials rendering. No picker/upload or protected-avatar URL loading was added. |
| Readonly email and real selectors | `EditProfileStateBuilder.kt:52–57` keeps email enabled/readonly without an edit callback. `EpsShopLayout.kt:184–203` places tags on the real EpsTextField calls and supplies the non-actionable lock. Task 1's `component/textfield/defaulttextfield/EpsTextField.kt:55–76` forwards those tags/readOnly semantics to BasicTextField. UI tests at `EditProfileContentUiTest.kt:36–63` exercise the actual input/save/back nodes. |
| Duplicate-save and immutable payload guards | `EditProfileViewModel.kt:127–159`: saving is set synchronously before launching; submitted name/phone/address are copied; failures restore enabled controls and retain draft values. The repository command has no email parameter. |
| Editor/account lifetime and stale work | `EditProfileViewModel.kt:53–117,165–181`: each load invalidates the prior revision; leave cancels jobs and clears draft/callback state; generated callbacks capture the revision; queued work and returned results consult the live account. The real account flow promptly invalidates a changed account. M1 concerns the unnecessary default source, not removal of these guards. |
| Cancel is distinct from successful save | `EditProfileViewModel.kt:120–124,139–147`: cancel invokes only the captured back callback; accepted success invokes only saved after invalidation. `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:189–208` rebases profile drafts on save and preserves unsent values/errors on cancel. |
| Raw account ownership plus editor IDs | `SduiViewModel.kt:101–102,117,187–208,390`: stateOwner is the raw account ID; each opened editor gets a new sequence ID; explicit owner/ID callbacks reject obsolete editors and account changes. Task 3 must capture both values as shown in `TASK2_REPORT.md:119–130`; the no-argument save method is compatibility wiring, not an ownership-capturing callback. |
| Visible errors without losing draft | `EditProfileViewModel.kt:69–91,145–159` handles empty-profile/exception loads and preserves editable state on failed saves. `EditProfileScreen.kt:85–94` renders loading/error feedback. The existing structured address parser/repository contract is preserved. |
| Real biometric snapshots, no SavedHost persistence | `SduiViewModel.kt:61–63,84–88,131–141,431–434,468–472`: snapshots are nonserialized HostState values, derived from native state at start/fetch and cleared on account reset. `ShopNativeSduiAdapter.kt:27–31` delegates to the real session/AuthService. Hardware availability does not mask configured truth. |
| Configured but unavailable can revoke | `ShopNativeSduiAdapter.kt:46–50` gates only unavailable enrollment. The existing `feature/auth/auth-data/src/commonMain/kotlin/com/shopkmp/auth/data/AuthServiceImpl.kt:216–225` revokes through the authenticated endpoint and then deletes the key/clears configuration, with no biometric prompt. Failure leaves configuration intact. |
| Controlled switch, single flight and cancellation | `EpsBiometricRow.kt:26–33` has one toggle callback on the labeled Switch-role row and no local checked state. `SduiViewModel.kt:218–243,377–405` rejects repeated actions, refreshes after native completion/failure/cancellation, and guards late host results. Current-device revocation refreshes through commandApplied plus the subsequent fetch (`SduiViewModel.kt:358–360,431–434`; `ShopNativeSduiAdapter.kt:33–38`). |

Short editor/test names in this table refer to
`feature/profile/profile-ui/src/{commonMain,commonTest,iosTest}/kotlin/com/shopkmp/profile/ui/`;
short shared component names refer to
`core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/shop/`.

## KMP tests and preservation

Applied `kmp-unit-tests` in **review**, update-scoped mode. The profile VM is a
CMP UI-shared form/command with editor-lifetime races; the host adds native-state
and owner/ID transitions. Portable VM tests remain in commonTest.

- Existing commonTest/Android-hosted JVM/iOS targets were reused. Gradle changes
  add the existing coroutines-test alias, iOS Compose UI-test alias, and the
  direct production network dependency required by the session wiring
  (`feature/profile/profile-ui/build.gradle.kts:10–33`). No version/catalog or
  platform-only VM-test replacement was introduced.
- Dependency preflight: ProfileRepository is a constructor/common interface;
  EditorRepository is a fresh deterministic local fake. The plain lifecycle
  ViewModel requires test Main, which is installed before construction and reset
  in finally. Account lookup/change flow are common callback/Flow inputs; the
  real screen uses one existing BackendSession, not a parallel session. M1 is
  the remaining unnecessary global fallback.
- Host tests reuse FakeRepository/FakeNative and harness-owned backgroundScope.
  New native outcomes extend the existing fake rather than replace its identity.
  Revision/cancellation cases use deliberately suspended results, generated
  control callbacks and bounded scheduler execution; there is no terminal-poll
  shortcut or newly added broad Koin harness.
- Compared extracted test bodies against `git show b9def939:<path>`: **all 17
  original SduiViewModelTest bodies remain byte-identical**. The existing
  ProfileStateBuilderTest file is also byte-identical. No old assertion/test was
  deleted, ignored or weakened in the reviewed Task 2 diff.
- New coverage includes 12 editor VM scenarios, nine additional host scenarios,
  and five iOS UI scenarios. The biometric UI test proves controlled callback
  wiring; it does not substitute for executing a hardware enrollment/revocation.

## Verification evidence and its limits

No Gradle suite/build was rerun for this read-only review. No concrete finding
required a runtime experiment; M1 follows directly from the default callback and
its synchronous invocation. Targeted Task 2 `git diff --check` returned exit 0.

The first XML inspection reproduced the Task 2 report's table, with zero
failures/errors/skips in that evidence snapshot:

| Module | JVM results | iOS simulator results |
|---|---:|---:|
| profile-ui | 13 | 18 |
| main | 40 | 40 |
| profile data | 6 | — |
| auth-ui | 11 | 20 |
| sdui-model | 7 | — |
| sdui-actions | 4 | — |
| sdui-renderer | 2 | 8 |
| **Task 2 evidence total** | **83** | **86** |

Profile/main XML timestamps are approximately 06:43Z; model/actions and some
other results are older. These are **target result totals**, including retained
up-to-date task output and common scenarios executed on both platforms, not 169
freshly executed distinct scenarios.

During the concurrent Task 3 work, renderer XML was regenerated at approximately
06:54Z: the preserved 2 common + 6 UI results still showed zero failures, while
the new ShoppingPresentationUiTest had 2 results / 2 failures. That later
renderer snapshot is outside Task 2 and supersedes the combined-tree total; the
83/86 table above is not a claim that the current concurrent tree is GREEN.

`TASK2_REPORT.md:266–292` reports successful Android, simulator-arm64 and
physical-iOS-arm64 compilation for the affected UIKit/profile/main modules.
Those compile commands were not independently rerun here. **iOS arm64 compile
is not physical-device test execution.** Neither the Task 2 report nor this
review establishes an installed-app visual run or physical-device run.

The unavailable real photo upload is an honestly disclosed capability limit,
not a fabricated enabled feature or media-policy bypass. MainContainer chrome,
bottom-navigation exclusion, actual ownership-capturing integration, final
biometric-action enablement and installed journeys remain Task 3/coordinator
verification gates. The earlier cart-currency mismatch is not claimed fixed.

## Review write boundary

Only this review report was created. Task 2 production/tests, Task 1 files,
Task 3 sources and backend sources were not edited by this review. The existing
A1 Dockerfile/script work was preserved. No subagents, commits, pushes or device
operations were used. Dedicated read/search tools failed with `Bun is not
defined`; read-only inspection used Python fallback.
