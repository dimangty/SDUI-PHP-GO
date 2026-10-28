# Task 2 — native profile editor and biometric bridge

Date: 2026-10-27. Baseline checkpoint: `b9def939`.

**Task 2 implementation, self-review, tests and affected target compiles pass.**
The installed application was not rebuilt/replaced. Full-app visual verification
and MainContainer/renderer integration belong to the coordinator's Task 3.

## Delivered

- Fixed shared teal `EpsTopBar`, title `Редактирование`, outside the scrolling
  form. The old two-line body header is no longer rendered. The body scrolls with
  IME padding; the app bar remains fixed.
- Horizontal 56dp teal person placeholder and an actual capability-aware photo
  button. With no capability, the button is disabled and the caption says
  `Изменение фото пока недоступно`.
- Shared filled name/phone/email/address fields. Email remains enabled,
  selectable and readonly, has no edit callback, uses `Email (через поддержку)`
  and a non-actionable Lock icon. The save command has no email parameter.
- Full-width Save uses Task 1's minimum 48dp/12dp shared button styling.
  Selectors attach to real inputs/actions, not duplicates or labels.
- Visible initial/save loading and load/save errors. Errors retain draft text.
  The repository's existing empty-profile-on-load-failure contract is rendered
  as an error rather than an editable blank customer.
- Synchronous single-flight save guard; immutable submitted field snapshot;
  separate cancel/success callbacks. Screen disposal cancels its editor lifetime.
  Every generated input/save/back callback captures that lifetime. Current-account
  checks reject queued work and late results; the real session account-change flow
  also cancels active load/save work promptly and clears the editor.
- Native biometric availability/configuration snapshots and a controlled switch
  row. Refresh occurs at start/re-entry, fetch/profile entry, native completion
  (including failure/cancellation) and command refresh/current-device revocation.
  Account reset clears snapshots, and subsequent start derives the new account's
  values. No snapshot is stored in `SavedHost`.
- Native host editor callbacks can capture account plus a monotonically increasing
  editor ID. Old callbacks cannot cancel/rebase a newer editor or another account.
  Existing no-argument save/back call sites still compile.

Address text is passed unchanged to the existing repository's structured
city/street/house/apartment parser. No new freeform wire address, fabricated
structured values, email mutation, upload subsystem, secret or persistence was
introduced.

## Exact Task 3 handoff APIs

### Editor (`com.shopkmp.profile.ui`)

```kotlin
@Composable
fun EditProfileScreen(
    onBack: () -> Unit,
    onSaved: () -> Unit,
    accountKey: String = "",
)

@Composable
fun EditProfileContent(
    state: EditProfileState,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
)

data class EditProfileState(
    val content: EpsEditProfileContentState? = null,
    val error: String? = null,
    val loading: Boolean = true,
    val onBack: () -> Unit = {},
)
```

`EditProfileContent` is stateless and does not start Koin. The real screen supplies
`state.onBack`, a lifetime-bound callback that also works during loading/error.

`EditProfileViewModel(ProfileRepository)` retains its existing constructor and
Koin binding. Its lifecycle entry now is:

```kotlin
fun load(
    onBack: () -> Unit,
    onSaved: () -> Unit,
    accountKey: String = "",
    currentAccount: () -> String = { KoinPlatform.getKoin().get<BackendSession>().userId },
    accountChanges: Flow<String> = emptyFlow(),
)
fun leave()
```

The screen passes the **same Koin-owned BackendSession instance** for the account
lookup and `accountIdFlow`. Empty `accountKey` resolves to the current account for
old callers. Explicit keys must match the live account. Direct VM consumers should
also supply the live change flow for immediate cancellation; synchronous lookup
guards still apply with the default empty flow. `leave()` is used by screen
disposal and invalidates all previous callbacks.

Actual selectors: `editProfile.title`, `.back`, `.photo`, `.name`, `.phone`,
`.email`, `.address`, `.save`, `.loading`, `.error`.

### Host (`com.shopkmp.main.sdui`)

```kotlin
interface NativeSduiAdapter {
    // Existing members remain.
    fun isBiometricAvailable(): Boolean = false
    fun hasConfiguredBiometric(): Boolean = false
}

// Added nonserialized HostState fields:
val biometricAvailable: Boolean = false
val biometricConfigured: Boolean = false
val profileEditorId: Int = 0

// SduiViewModel:
val stateOwner: String
fun nativeProfileSaved() // Existing callback compatibility.
fun nativeProfileSaved(accountKey: String, editorId: Int)
fun nativeProfileCancelled(accountKey: String, editorId: Int)
```

Capture ownership when composing the editor, for example:

```kotlin
val owner = viewModel.stateOwner
val editorId = state.profileEditorId
key(owner, editorId) {
    EditProfileScreen(
        onBack = { viewModel.nativeProfileCancelled(owner, editorId) },
        onSaved = { viewModel.nativeProfileSaved(owner, editorId) },
        accountKey = owner,
    )
}
```

Task 3 should place this outside bottom navigation/the normal host app bar. This
task deliberately did not edit MainContainerScreen. Successful native save still
invalidates legacy profile drafts; cancel retains unsent values/errors.

### Shared controls (`com.shopkmp.uikit.component.shop`)

```kotlin
@Composable
fun EpsBiometricRow(
    label: String,
    checked: Boolean,
    enabled: Boolean,
    onToggle: () -> Unit,
    modifier: Modifier = Modifier,
)

data class EpsEditAvatarState(
    val initials: String,
    val actionText: String,
    val onChangePhoto: (() -> Unit)? = null,
)
```

The biometric row's whole labeled row is the actual Switch-role toggle target;
the visual Switch has no duplicate callback. The caller's modifier/tag belongs
to that real control. There is no optimistic/local checked state.

Use `checked = state.biometricConfigured`. Enable only when the existing typed
ManageBiometric action is present, no action/confirmation is pending, and
`state.biometricAvailable || state.biometricConfigured`. Invoke
`viewModel.event(SduiEvent.Activate(existingManageBiometricAction.id))` once.
Never mask configured truth with hardware availability or invent an action ID.

`EpsProfileFormFields` retains old calls and adds final defaulted
`testTagPrefix: String = ""`; editor passes `editProfile`. Readonly email gets
the lock slot. `EpsAvatarState` retains initials/background arguments and adds
`showPersonPlaceholder: Boolean = true`; its default background is
`ShopTokens.PrimaryContainer`. Explicit `false` retains initials rendering.

## Actual capability behavior

- `ShopNativeSduiAdapter` delegates both snapshot methods to existing AuthService.
- Unconfigured + unavailable cannot enroll. Configured + unavailable stays checked
  and **can revoke**: `AuthServiceImpl.disableBiometric()` uses the authenticated
  delete endpoint, then deletes the key/clears local configuration; it does not
  require a biometric prompt or available hardware. The adapter's old blanket
  availability gate was narrowed accordingly. Failed revocation remains checked.
- Native success rereads actual configuration. Returned native failures retain
  actual configuration and show the existing failure notice. Coroutine
  cancellation releases the flight without manufacturing success/check state.
- There is currently **no photo picker/native upload adapter**. Real editor state
  therefore leaves `onChangePhoto = null`. The tested non-null capability branch
  only proves callback/control wiring; it is not a claim that upload exists.
  Protected `/uploads/users` URLs were not loaded through public media policy.

## KMP bootstrap and preservation audit

- Profile mode: bootstrap, compact, CMP UI-shared form/command + lifecycle races.
  Existing `commonTest` and Android-hosted JVM/iOS executable targets were reused.
  Added only existing catalog aliases `kotlinx.coroutines.test` to commonTest and
  `compose.ui.test` to iosTest. No new versions/libraries/catalog changes.
- Dependency matrix: ProfileRepository = constructor/common interface, local
  deterministic fake; ViewModel = lifecycle/Main, install/reset test Main;
  account lookup + change stream = supplied common callback/Flow. The screen
  uses the real session, so profile-ui adds its direct `:core:network` project
  dependency. This account/lifecycle boundary is production behavior, not a
  test-only branch.
- **No BaseViewModel seam was required:** EditProfileViewModel already extends
  plain ViewModel. No base dependency, Koin binding or DI constructor was changed.
- JVM source-set configuration and the first real load test executed successfully
  before the remaining suite was added. Each VM fixture is fresh; no broad Koin
  application, platform device/resources or parallel settings/session is created.
- New tests use public `load`/`leave` and production-generated control callbacks;
  observe State, repository submissions and cancel/saved outputs. Suspended
  fakes deliberately model late responses. `runCurrent` executes queued Main
  work; there is no polling/terminal-result shortcut. The one live-account-flow
  fixture cancels its collector on the tested transition; other fixtures use a
  finite empty flow. Main is reset in finally.
- Host mode: update-scoped; reused FakeRepository/FakeNative and backgroundScope.
  Added only biometric outcomes and related editor guards. Existing fake identity,
  scheduler style and cleanup policy remain. Background work is harness-owned.
- Byte-level extraction against `b9def939` confirmed **all 17 original host test
  bodies unchanged**. The existing profile test is also byte-identical. Existing
  pending-add recovery, stale drafts, logout and account isolation tests remain.

## RED evidence

Read first: PLAN, Task 1 exact API report, installed visual audit and reference
SVG. The visual audit documents the old two-line header, missing teal app bar,
disabled email/no lock, static photo caption and blue initials placeholder.

All commands below use:

```sh
export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home
```

Initial semantic RED:

```sh
./gradlew :feature:profile:profile-ui:testDebugUnitTest :feature:main:testDebugUnitTest --continue --no-daemon --console=plain -q
```

Exit 1, 15s: **profile 6 tests / 4 failures; main 37 / 5 failures; zero skips**.
Failures proved duplicate saves, stale editor controls/load, disabled email,
missing biometric snapshots and unchecked late native completion/account change.
The snapshot API shapes were scaffolded with false defaults before testing their
behavior. Existing tests passed.

Further focused RED runs, before the corresponding guard implementations:

- Main 38 / 1 failure: leaving editor still accepted a late save (11s combined run).
- Main 39 / 1: save callback did not check the latest live account (22s combined).
- `./gradlew :feature:main:testDebugUnitTest --no-daemon --console=plain -q`:
  main 40 / 2 failures, including an old editor callback closing a newer editor
  (25s; overload/ID API scaffolding existed before guard behavior).
- Profile 11 / 1 JVM failure: repository's empty failure profile exposed a blank
  editor. Corresponding iOS run had 16 / 2 failures: that regression plus a UI
  fixture's incorrect lookup of a deliberately semantics-cleared visual label.
- `./gradlew :feature:profile:profile-ui:testDebugUnitTest --no-daemon --console=plain -q`:
  profile 12 / 1 failure (21s): session-flow switch did not immediately clear the
  suspended editor. Then profile 13 / 1 failure (12s): loading Back callback was
  not yet wired to its captured lifetime. Both preceded their implementations.

All these runs had zero skips. Additional success/cancel/payload preservation
cases passed when introduced and are not misrepresented as failing baselines.

The first native UI run also exposed a fixture assumption: iOS initial selection
inserts at the start, not the end. The test now replaces the input with a known
value rather than asserting a cursor policy. Email support copy is asserted on
the actual field's content description, as Task 1 intentionally clears external
label semantics. No production field implementation or assertion was weakened.

## Final GREEN verification

Final command, **exit 0; 36.03 seconds**:

```sh
./gradlew :feature:auth:auth-ui:testDebugUnitTest :feature:profile:profile-ui:testDebugUnitTest :feature:profile:data:testDebugUnitTest :feature:main:testDebugUnitTest :core:sdui-model:testDebugUnitTest :core:sdui-actions:testDebugUnitTest :core:sdui-renderer:testDebugUnitTest :feature:auth:auth-ui:iosSimulatorArm64Test :feature:profile:profile-ui:iosSimulatorArm64Test :feature:main:iosSimulatorArm64Test :core:sdui-renderer:iosSimulatorArm64Test :core:uikit:compileDebugKotlinAndroid :core:uikit:compileKotlinIosSimulatorArm64 :core:uikit:compileKotlinIosArm64 :feature:profile:profile-ui:compileDebugKotlinAndroid :feature:profile:profile-ui:compileKotlinIosSimulatorArm64 :feature:profile:profile-ui:compileKotlinIosArm64 :feature:main:compileDebugKotlinAndroid :feature:main:compileKotlinIosSimulatorArm64 :feature:main:compileKotlinIosArm64 --no-daemon --console=plain -q
```

XML results (all failures/errors/skips **zero**):

| Module | JVM | iOS simulator |
|---|---:|---:|
| profile-ui | 13 (12 editor + 1 preserved) | 18 (13 common + 5 UI) |
| main | 40 (26 host + 14 preserved other tests) | 40 |
| profile data | 6 | — |
| auth-ui | 11 | 20 |
| sdui-model | 7 | — |
| sdui-actions | 4 | — |
| sdui-renderer | 2 | 8 |
| **Total target results** | **83** | **86** |

Unchanged/up-to-date tasks retain valid XML results; totals are not represented as
169 freshly re-executed distinct scenarios. All affected UIKit/profile/main
Android, simulator-arm64 and physical-iOS-arm64 compile tasks passed. Existing
MainContainer BackHandler/common platform warnings and the host's redundant-else
warning remain. The known Task 1 cart currency mismatch was not suppressed or
represented as fixed by this task.

## Final scope/self-review

Checked Task 2 production and new test files, full targeted diff, callback/token
ownership, session fixture identity, cancellation, real-node semantics, readonly
email/address boundaries, native snapshot persistence exclusions and Task 3
compatibility. `git diff --check` passes. Dedicated file tools failed with
`Bun is not defined`; inspection used Python fallback and edits used apply_patch.

Only the owned editor/Gradle/tests, profile/avatar/layout controls, new biometric
row, native adapter and host VM/existing test were edited for Task 2. No
MainContainer, renderer, Task 1 control/theme/nav/topbar, Go/PHP/A1 runtime changes,
subagents, commits, pushes or installed-app replacements were made. The concurrent
Task 1 review is separate; this is Task 2's self-review and handoff report.

## Fix round 1 — review M1: remove hidden VM account-source DI

Date: 2026-10-27. Read `TASK2_REVIEW.md` and verified M1 against the current
VM/screen and complete existing editor test suite. **M1 is fixed.** This section
supersedes the earlier VM `load` default/API explanation; earlier verification
totals are historical snapshots preceding the completed Task 3 work.

### Local API correction

```kotlin
fun load(
    onBack: () -> Unit,
    onSaved: () -> Unit,
    accountKey: String = "",
    currentAccount: (() -> String)? = null,
    accountChanges: Flow<String> = emptyFlow(),
)
```

The VM no longer imports BackendSession/KoinPlatform or resolves process-global
DI. An omitted source is explicitly absent (`null`), not a fabricated account ID
or a caught Koin failure. The legacy `load(onBack, onSaved)` entry now works with
only its constructor repository and lifecycle dispatcher.

**Fallback contract:** without a live source, ownership is editor-lifetime-only.
`accountKey`, when provided alone, is an owner label, not proof of the current
authenticated account. Account-switching hosts must supply the real lookup and
matching change flow. This limitation is documented on `load`.

With a supplied source, blank/mismatched live accounts still invalidate the editor;
revision/stale callback checks, flow-driven cancellation, submitted snapshots and
single-flight save guards remain. The real EditProfileScreen still explicitly
passes `{ session.userId }` and `session.accountIdFlow` from the **same existing
Koin-owned BackendSession**. Its source and the completed Task 3 integration were
not edited in this round.

### Regression and RED

Mode: update-scoped; existing commonTest/Main harness and EditorRepository reused.
Added exactly one test:
`legacyLoadWithoutGlobalKoinCanEditAndSaveTheRepositoryProfile`. It constructs
the real VM with the existing fake, calls the public two-callback `load` without
Koin/account overrides, then edits and saves via generated controls, asserting the
submitted values and saved-versus-back callback. All existing tests, factories,
fakes and scheduler/cleanup patterns remain intact.

All commands use:

```sh
export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home
```

Pre-edit baseline, exit 0, **14.61s**:

```sh
./gradlew :feature:profile:profile-ui:testDebugUnitTest :feature:main:testDebugUnitTest --no-daemon --console=plain -q
```

After adding only the regression, before the production fix:

```sh
./gradlew :feature:profile:profile-ui:testDebugUnitTest --no-daemon --console=plain -q
```

**RED: exit 1, 19.25s wall time (Gradle 18s), 14 tests / 1 failure / zero
errors or skips.** The new test failed with
`IllegalStateException: KoinApplication has not been started`, tracing through
`GlobalContext.get` → `KoinPlatform.getKoin` → the default load callback → the
synchronous owner lookup. The other 13 profile tests passed.

### GREEN and target compilation

After the local production correction, **exit 0, 46.75s**:

```sh
./gradlew :feature:profile:profile-ui:testDebugUnitTest :feature:main:testDebugUnitTest :feature:profile:profile-ui:iosSimulatorArm64Test :feature:main:iosSimulatorArm64Test :feature:profile:profile-ui:compileDebugKotlinAndroid :feature:profile:profile-ui:compileKotlinIosSimulatorArm64 :feature:profile:profile-ui:compileKotlinIosArm64 :feature:main:compileDebugKotlinAndroid :feature:main:compileKotlinIosSimulatorArm64 :feature:main:compileKotlinIosArm64 --no-daemon --console=plain -q
```

| Affected module | JVM | iOS simulator | Failures / errors / skips |
|---|---:|---:|---:|
| profile-ui | 14 | 19 | 0 / 0 / 0 |
| main, including completed Task 3 chrome tests | 43 | 44 | 0 / 0 / 0 |

Both affected modules compiled for Android, simulator arm64 and physical-device
arm64. Compilation is not a physical-device execution claim. Existing
MainContainer BackHandler and host redundant-else warnings remain.

Self-review checked the nullable-source branch against all existing supplied-source
account switches, obsolete controls/results, cancel/save and single-flight cases.
Only EditProfileViewModel, the one appended commonTest case and this report were
edited in fix round 1. `git diff --check` passes. No Task 1/Task 3 screen/renderer,
backend/A1, dependency, app-installation, subagent, commit or push changes were made.
