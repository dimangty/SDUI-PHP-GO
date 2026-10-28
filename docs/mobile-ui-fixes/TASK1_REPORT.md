# Task 1 — shared design system, authentication and PIN

Date: 2026-10-27. Base: `b9def93963ef08b1d19bedf957c0b3d3d003add7`.

**Implementation complete; Task 1 tests and platform compiles pass.** One
additional cart UI test has an existing source-baseline currency-expectation
mismatch, detailed below for Task 3. No installed-app visual-match claim is made.

## Delivered

- `ShopTokens` and explicit Material color roles, including all surface-container
  roles, use the teal/warm palette. EpsColor, screen/scaffold backgrounds and text
  defaults also use the reference palette.
- Primary/outlined buttons use 12dp corners and a minimum 48dp height. Auth CTAs
  are full width at their callers. Destructive styling supports filled, outlined
  and text variants.
- Filled fields have external labels, 10dp corners, a 48dp default input height,
  focus/error borders and supporting validation text. Tags belong to the actual
  `BasicTextField`, with native input, password, enabled/readonly, IME and error
  semantics. The trailing slot reserves at least 48dp for an action or readonly
  indicator. Password visibility is an accessible vector-icon action.
- Cards, chips, dividers, shared images/placeholders and quantity controls use the
  shared shapes/colors. Quantity retains controlled callbacks and enabled states.
- Seventeen finite outlined Canvas icons follow the existing renderer pattern;
  no dependency or wire enum was introduced.
- App bars use teal/white, one-line ellipsized titles and real icon buttons.
  Title, back and action tags attach once to their real nodes. App-bar insets are
  zero because `BaseScreen` already owns the status-bar spacer. Bottom navigation
  has four real icons, warm background, divider and a transparent selection pill;
  selection remains caller-controlled.
- Login has a teal brand heading, compact fields, stacked account/signup prompt
  and smaller secondary PIN/biometric alternatives. Login supports scrolling and
  IME padding. Registration retains its existing BaseScreen app bar, all five
  fields, consent callback and submit behavior, now using the shared styling.
- PIN uses 64dp circular beige keys, vector backspace and a non-actionable,
  descriptively labeled secure-lock indicator. Four drawn dots initially expand
  to five/six with actual input. Submit is absent when
  `continueButtonState.enabled == false`; enabled submission remains explicit.
  The existing 4–6 digit policy and six-digit payload are preserved. Login greets
  with `С возвращением!`, without account lookup/cache or a fabricated identity.

## RED evidence before production edits

References read first: `PLAN.md`, `docs/images/frontend-sketch.svg`, and
`docs/backend-fixes/IOS_VISUAL_AUDIT.md`. The audit supplies actual installed-app
visual RED evidence: purple/default surfaces, missing nav icons, outlined fields,
noncircular PIN keys and the unwanted initial Continue button. It is qualitative
visual evidence, not an automated screenshot baseline or pixel-match measurement.

The supplied six-module JVM baseline passed, exit 0 in 28 seconds. My initial
unchanged-production auth JVM+iOS baseline invocation reached native test execution
but timed out at 120 seconds; JVM results were up to date. A completed pre-edit
iOS baseline run is therefore **not** claimed.

After adding regressions, before any production change:

```sh
export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home
./gradlew :feature:auth:auth-ui:testDebugUnitTest :feature:auth:auth-ui:iosSimulatorArm64Test --continue --no-daemon --console=plain -q
```

- Exit 1, 29 seconds; JVM: 11 tests, one greeting failure, zero skips.
- The first new UI fixtures lacked digit-key data. Those fixture errors were
  corrected before treating the UI result as semantic RED.

Corrected semantic RED command, still with unchanged production:

```sh
./gradlew :feature:auth:auth-ui:iosSimulatorArm64Test --no-daemon --console=plain -q
```

Exit 1, 33 seconds: **15 tests, two failures, zero skips**:

1. `loginGreetingDoesNotInventAnAccountIdentity`: expected `С возвращением!`,
   actual `Иван, с возвращением!`.
2. `emptyAndPartialPinDoNotExposeSubmit`: `assertDoesNotExist` found the disabled
   `pin.submit` node in the empty state.

The new no-auto-submit/six-digit and backspace checks already passed on the
baseline. They are policy-preservation guards, not invented failing baselines.

## Test preservation and KMP preflight

- Mode: update; scope: update-scoped; profile: CMP UI-shared; archetype:
  form/command. `commonTest`, Android-hosted JVM execution and iOS test targets
  already existed. No Gradle/bootstrap/testability production seam was needed.
- Reused the existing `PinViewModelTest.setupKoin`, fake AuthService, navigator,
  strings and connectivity fixtures. Base dependencies: interface services use
  the existing fakes/real SingleClickServiceImpl; the platform DeviceService and
  common state managers use the existing constructor-safe harness setup. No
  second settings/session/cache/service identity was introduced.
- Public entry is `onUiEvent` or the production-generated control callback.
  Construction supplies PIN presentation; no extra lifecycle call is required.
  Assertions observe State, auth payload and navigation. Existing scheduler and
  harness ownership patterns are preserved; bounded PIN scenarios have no poll.
  Scheduler drains in the new command guards detect queued submission/navigation
  as well as running the explicit suspend submit.
- Both existing PIN VM tests and their setup remain intact. Added only greeting,
  explicit six-digit/no-auto-submit and backspace-to-partial scenarios.
- Preserved both existing UI tests and their callback assertions. The submit
  fixture now has valid `pin = "1234"` and `isVerifyEnabled = true`, because an
  empty PIN with enabled submit contradicted the accepted presentation contract.
  The email selector now targets `login.email`, since its label is external and
  the actual editable node owns input semantics.
- Added UI regressions for empty/partial submit absence and real backspace click.
  Five shared-control UI cases protect readonly/IME semantics, password reveal
  and validation, app-bar callbacks/disabled actions, legacy trailing-lambda
  notifications, and controlled navigation with old/new constructors.
- A new password test initially assumed revealing characters removes native
  password semantics. Runtime evidence showed that the password keyboard retains
  those semantics. The test now checks actual revealed/masked editable text and
  preserved secure semantics; production keyboard behavior was kept.
- No color-constant tests, ignored tests, deleted assertions or replacement VM
  harnesses were added.

## GREEN verification

All commands use the Java 17 path above. Tests execute through Gradle's KMP
targets, including iOS Compose UI tests; the installed baseline app was not rebuilt
or replaced. Dedicated file-read/search tools failed with `Bun is not defined`,
so Python was used for inspection and XML result counting; source edits used
`apply_patch`.

Successful affected-module verification, **exit 0, 61.71 seconds**:

```sh
./gradlew :feature:auth:auth-ui:testDebugUnitTest :feature:profile:profile-ui:testDebugUnitTest :feature:main:testDebugUnitTest :core:sdui-model:testDebugUnitTest :core:sdui-actions:testDebugUnitTest :core:sdui-renderer:testDebugUnitTest :feature:auth:auth-ui:iosSimulatorArm64Test :feature:profile:profile-ui:iosSimulatorArm64Test :feature:main:iosSimulatorArm64Test :core:sdui-renderer:iosSimulatorArm64Test :core:uikit:compileDebugKotlinAndroid :core:uikit:compileKotlinIosSimulatorArm64 :core:uikit:compileKotlinIosArm64 :feature:auth:auth-ui:compileDebugKotlinAndroid :feature:auth:auth-ui:compileKotlinIosSimulatorArm64 :feature:auth:auth-ui:compileKotlinIosArm64 --no-daemon --console=plain -q
```

After the final trailing-icon spacing adjustment, focused verification,
**exit 0, 49.01 seconds**:

```sh
./gradlew :feature:auth:auth-ui:testDebugUnitTest :feature:auth:auth-ui:iosSimulatorArm64Test :core:uikit:compileDebugKotlinAndroid :core:uikit:compileKotlinIosSimulatorArm64 :core:uikit:compileKotlinIosArm64 :feature:auth:auth-ui:compileDebugKotlinAndroid :feature:auth:auth-ui:compileKotlinIosSimulatorArm64 :feature:auth:auth-ui:compileKotlinIosArm64 --no-daemon --console=plain -q
```

XML results (test skips/failures/errors all zero in the table):

| Module | JVM tests | iOS simulator tests |
|---|---:|---:|
| auth-ui | 11 | 20 (11 common + 9 UI) |
| profile-ui | 1 | 1 |
| main | 31 | 31 |
| sdui-model | 7 | — |
| sdui-actions | 4 | — |
| sdui-renderer | 2 | 8 (2 common + 6 UI) |
| **Total** | **56** | **60** |

The unchanged sdui-model/actions JVM tasks were up to date; their valid existing
XML results are included above, not represented as newly executed tests.
Downstream main/profile/renderer sources also compiled on Android and iOS through
their test-task dependency graphs. UIKit/auth additionally compiled for physical
iOS arm64. Existing unrelated compiler warnings remain in MainContainerScreen,
SduiViewModel and common platform/deprecated APIs.

### Additional cart check: source-baseline mismatch for Task 3

An expanded run also executed `:feature:cart:cart-ui:iosSimulatorArm64Test`:
**two tests, one pass, one failure, zero skips**. The whole expanded command exited
1 in 1m 7s because of that test. Its failure is:

`CartScreenUiTest.populatedCartShowsTotalAndInvokesCheckout` cannot find
`Итого: 100,00 ₽`.

The existing `Money.formatRub()` emits `100 RUB` for this input; CartStateBuilder
and EpsCartTotal therefore render `Итого: 100 RUB`. Byte comparison against
`git show b9def939:<path>` confirmed that **Money.kt, CartScreenUiTest.kt,
CartStateBuilder.kt and EpsShopLayout.kt are all unchanged from the checkpoint**.
This establishes a source-baseline mismatch; a separate runtime checkout of the
checkpoint was not executed. The shopping owner should reconcile its presentation
contract and real assertion during Task 3. No money conversion, cart source edit,
test weakening or suppression was used to obtain the Task 1 GREEN results.

## Final shared APIs for Tasks 2/3

Packages are under `com.shopkmp.uikit.component` unless stated otherwise.
Existing composable call forms remain valid. Added state parameters are defaulted;
callbacks remain usable as trailing lambdas. Compatibility constructors preserve
old fully positional calls.

### Icons (`icon`)

```kotlin
enum class EpsIconName {
    Home, Catalog, Cart, Profile, Back, Search, Edit, Refresh, Lock, Backspace,
    ChevronRight, Notifications, Check, Device, Warning, Visibility, VisibilityOff,
}

@Composable
fun EpsIcon(
    name: EpsIconName,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    tint: Color = LocalContentColor.current,
)
```

Pass `null` for decorative icons whose parent/label already describes the control.

### App bar (`topbar`)

```kotlin
data class EpsTopBarActionState(
    val icon: EpsIconName,
    val contentDescription: String,
    val testTag: String,
    val enabled: Boolean = true,
    val onClick: () -> Unit,
)

data class EpsTopBarState(
    val title: String = "",
    val isVisible: Boolean = true,
    val showBackButton: Boolean = false,
    val showNotificationsButton: Boolean = false,
    val onBackClick: (() -> Unit)? = null,
    val onNotificationsClick: (() -> Unit)? = null,
    val titleTestTag: String = "",
    val backTestTag: String = "",
    val actions: List<EpsTopBarActionState> = emptyList(),
) {
    constructor(
        title: String = "",
        isVisible: Boolean = true,
        showBackButton: Boolean = false,
        showNotificationsButton: Boolean = false,
        onBackClick: (() -> Unit)? = null,
        onNotificationsClick: () -> Unit,
    ) : this(title, isVisible, showBackButton, showNotificationsButton, onBackClick,
        onNotificationsClick, "", "", emptyList())

    companion object {
        fun getMock() = EpsTopBarState(title = "Mock Title")
    }
}

@Composable
fun EpsTopBar(state: EpsTopBarState, modifier: Modifier = Modifier)
```

### Bottom navigation (`navigationbar`)

```kotlin
data class EpsNavigationItemState(
    val text: String,
    val selected: Boolean,
    val testTag: String = "",
    val icon: EpsIconName? = null,
    val onClick: () -> Unit,
) {
    constructor(text: String, selected: Boolean, testTag: String, onClick: () -> Unit) :
        this(text, selected, testTag, null, onClick)
}

@Composable
fun EpsNavigationBar(items: List<EpsNavigationItemState>)
```

Without an explicit icon, legacy four-item order resolves Home/Catalog/Cart/Profile.

### Buttons (`button.defaultbutton`)

```kotlin
data class EpsButtonState(
    val text: String = "",
    val type: EpsButtonType = EpsButtonType.Primary,
    val enabled: Boolean = true,
    val destructive: Boolean = false,
    val onClick: (() -> Unit)? = null,
) {
    constructor(text: String, type: EpsButtonType, enabled: Boolean, onClick: (() -> Unit)?) :
        this(text, type, enabled, false, onClick)

    companion object {
        fun getMock() = EpsButtonState(text = "Button")
    }
}

@Composable
fun EpsButton(state: EpsButtonState, modifier: Modifier = Modifier)
```

Use `type = Secondary, destructive = true` for outlined logout and apply
`Modifier.fillMaxWidth()` at its caller.

### Fields (`textfield.defaulttextfield`)

```kotlin
data class EpsTextFieldState(
    val value: String = "",
    val placeholder: String = "",
    val label: String = "",
    val error: String? = null,
    val enabled: Boolean = true,
    val singleLine: Boolean = true,
    val keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    val visualTransformation: VisualTransformation = VisualTransformation.None,
    val readOnly: Boolean = false,
    val keyboardActions: KeyboardActions = KeyboardActions.Default,
    val onValueChange: ((String) -> Unit)? = null,
) {
    constructor(
        value: String,
        placeholder: String,
        label: String,
        error: String?,
        enabled: Boolean,
        singleLine: Boolean,
        keyboardOptions: KeyboardOptions,
        visualTransformation: VisualTransformation,
        onValueChange: ((String) -> Unit)?,
    ) : this(value, placeholder, label, error, enabled, singleLine, keyboardOptions, visualTransformation,
        false, KeyboardActions.Default, onValueChange)
}

@Composable
fun EpsTextField(
    state: EpsTextFieldState,
    modifier: Modifier = Modifier,
    trailingIcon: (@Composable () -> Unit)? = null,
)
```

For readonly email, keep `enabled = true`, set `readOnly = true`, and supply a
nonclickable Lock icon in the trailing slot. Caller tags go directly on
`EpsTextField`'s modifier, not on a second wrapper/label.

### Theme and existing PIN APIs

`com.shopkmp.ui.theme.ShopTokens` exports Color values `Primary`, `Background`,
`Field`, `Border`, `PrimaryContainer`, `Text`, `SecondaryText`, `Danger`,
`OnPrimary`. `AppShapes` additionally exports `field`, `card`, and `material`;
`primary` is now 12dp. Existing PIN/auth state and composable signatures are
unchanged. Preserved tags: `pin.header`, `pin.digit.0`…`pin.digit.9`,
`pin.backspace`, `pin.submit` when enabled, and `pin.secondary`.

## Scope and handoff review

Self-review checked changed production/test hunks and new files against Task 1,
constructor compatibility, real-node tags, inset ownership and PIN policy.
`git diff --check` passes. Existing VM tests/harness were preserved.

Task 2 profile/avatars, Task 3 shopping/chrome/renderer, native adapters,
SduiViewModel, Go/PHP, Gradle/catalog and runtime configuration were not edited.
The unrelated pre-existing Dockerfile and `scripts/test_php_runtime.py` work was
preserved. No subagents, commits or pushes were used. The coordinator's separate
review, full-app installation and ten-screen before/after checks remain the next
handoff gates; this report does not substitute unit/UI semantics for that visual
verification.

## Installed fix round 2 — keyboard inset ownership

Date: 2026-10-27. This round follows the coordinator's installed-app check after
Tasks 2/3 integration. The earlier shared-test GREEN did not cover native keyboard
insets and did not establish that the installed registration flow worked.

### Installed RED and source trace

The coordinator's `docs/evidence/ios/xctest-summary.txt` records exit 65 after
103.692 seconds, failing `scroll_signup.password` after name/email/phone input.
The independent manual reproduction measured `signup.scroll` as 402×714 at y126,
then 402×112 with keyboard top y539 / height335. Content ended at y238, leaving a
301pt blank region before the keyboard. These are actual installed RED observations;
no XCTest selectors, assertions, timeouts or journey helpers were changed here.

Source inspection covered the actual application path:

`SwiftUI ContentView → default ComposeUIViewController → MainView → EpsRootScaffold
→ NavDisplay → BaseScreen → EpsSignUpContent`.

- The resolved Compose version is 1.10.3. Its cached published source
  `ComposeUIViewControllerConfiguration.uikit.kt:31` defaults to
  `FocusableAboveKeyboard`; `OffsetToFocusedRect.skiko.kt` pans placement and
  measures children with unchanged constraints. Disabling that behavior is not
  the fix. No controller configuration change was made.
- The resolved Navigation3 1.0.0-alpha06 `NavDisplay` sources introduce no IME
  padding; the app's modifier from EpsRootScaffold has top/bottom padding zeroed.
- `UIKitWindowInsetsManager.uikit.kt:120–123` exposes keyboard overlap as `ime`
  and UIKit bottom safe area as `navigationBars`. BaseScreen's bottom spacer
  reserves navigation space, but it is a **sibling** of the weighted content box.
  Its consumed-inset information therefore does not reach the auth form.
- The installed geometry is explained by that sibling boundary: with a 874pt
  root, 62pt status area and 64pt bar, bottom safe area 34 gives 714pt of form.
  Keyboard-safe bottom area 335 gives 413pt ending at y539. The newly added form
  IME padding subtracts a further 301pt, producing exactly 112pt ending at y238.
  The automated fixture models these source-backed inset channels from the
  observed geometry; raw inset values were not queried from the installed app.

### Automated RED before the production fix

Added `HostedAuthKeyboardUiTest` with the real EpsRootScaffold, NavDisplay,
BaseScreen and auth content, a 402×874 scene, and controlled platform insets.
The first two cases were run before modifying production:

```sh
export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home
./gradlew :feature:auth:auth-ui:iosSimulatorArm64Test --tests '*HostedAuthKeyboardUiTest*' --no-daemon --console=plain -q
```

**Exit 1, 36.79 seconds; two tests, two failures, zero skips:**

- Keyboard-safe-area case: actual 112dp, expected 413dp — the installed clipping
  reproduced exactly through the shared root hierarchy.
- Separate navigation/IME case: actual 379dp, expected 413dp — this also catches
  double-counting the host's navigation area with Android-style edge-to-edge
  insets. Removing keyboard avoidance globally would fail this contract too.

### Minimal production change and API

New common helper in
`core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/base/extension/HostedFormInsets.kt`:

```kotlin
@Composable
fun Modifier.hostedFormImePadding(): Modifier =
    consumeWindowInsets(WindowInsets.navigationBars).windowInsetsPadding(WindowInsets.ime)
```

Login and Signup now use this helper at their two former `imePadding()` call sites.
It applies only to forms whose host already reserves navigation-bar space. It
reports that space as consumed and adds only the remaining IME inset. It handles
both platform inset shapes without an iOS no-op, platform branches or expect/actual
plumbing: 335 already reserved → no additional 301; 34 reserved with IME335 →
301 additional, totaling 335 once. Other existing APIs are unchanged.

An initial `consumeWindowInsets(...).imePadding()` pairing did not change the RED
dimensions. Inspection of the resolved foundation source identified the reason:
`WindowInsetsPadding.skiko.kt:102–115` still implements Skiko's named padding
modifiers through legacy modifier locals (linked upstream issue CMP-8998), while
public `consumeWindowInsets` uses the newer node path in common source. The final
helper uses public `windowInsetsPadding` so both operations use the same consumption
path. The original two regression cases then passed, exit 0 in 32.76 seconds.

### Edit Profile audit

The native editor does **not** have the auth sibling-consumption defect.
MainContainerScreen's native-editor branch applies status/navigation padding on
an **ancestor** Column, and EditProfileContent's existing `imePadding()` uses the
same legacy consumption path as that ancestor's navigation padding. It already
excludes the reserved keyboard-safe area.

Added `EditorKeyboardInsetsUiTest` around the actual stateless editor and the same
ancestor inset wrapper. It confirms 714dp → 413dp, reachable address/save and a
visible fixed app bar. No production EditProfileScreen import/call or account guard
needed changing. The existing SDUI/scaffold inset handling was also left intact.

### Focused test details and final verification

- Auth now has three hosted keyboard cases: the two original RED cases plus Login
  viewport/reachability with the keyboard (778dp → 477dp without the registration
  app bar). Signup also restores its 714dp viewport when the keyboard hides. Tests
  retain real editable password input and actual control selectors.
- Initial unfiltered attempts timed out at the 360s command limit. Running the
  hosted tests alone exposed the intended layout failures promptly. The combined
  suite revealed that existing auth VM tests leave `Dispatchers.Main` on their
  completed test scheduler; hosted NavDisplay lifecycle work then stalled. A
  `BeforeTest` reset is confined to the new hosted UI class. Existing VM tests and
  their harness remain unchanged. The new UI tests have bounded 30s failure
  deadlines, with no production waits or delayed keyboard assertions.
- The new editor test initially tried `performScrollTo` on an uncomposed LazyColumn
  save item. It now uses the actual list's `performScrollToNode` and retains its
  visibility assertion. This adjusts only the new test's lazy-list setup, not the
  installed journey or the production clipping expectation.

Successful full affected iOS test execution, **exit 0, 36.26 seconds**:

```sh
./gradlew :feature:auth:auth-ui:iosSimulatorArm64Test :feature:profile:profile-ui:iosSimulatorArm64Test --continue --no-daemon --console=plain -q
```

Final affected test/platform compilation command, **exit 0, 8.10 seconds**:

```sh
./gradlew :feature:auth:auth-ui:testDebugUnitTest :feature:auth:auth-ui:iosSimulatorArm64Test :feature:profile:profile-ui:testDebugUnitTest :feature:profile:profile-ui:iosSimulatorArm64Test :core:uikit:compileDebugKotlinAndroid :core:uikit:compileKotlinIosSimulatorArm64 :core:uikit:compileKotlinIosArm64 :feature:auth:auth-ui:compileDebugKotlinAndroid :feature:auth:auth-ui:compileKotlinIosArm64 :feature:profile:profile-ui:compileDebugKotlinAndroid :feature:profile:profile-ui:compileKotlinIosArm64 --no-daemon --console=plain -q
```

| Module | JVM tests | iOS simulator tests | Skips / failures / errors |
|---|---:|---:|---:|
| auth-ui | 11 | 23 (11 common + 12 UI) | 0 / 0 / 0 |
| profile-ui | 14 | 20 (14 common + 6 UI) | 0 / 0 / 0 |
| **Total** | **25** | **43** | **0 / 0 / 0** |

Counts are from Gradle XML. The final 8.10s invocation reuses valid up-to-date test
results, including the completed 36.26s iOS run; it is not a claim that all tests
executed again in eight seconds. UIKit, auth and profile compile for Android,
iOS simulator arm64 and iOS device arm64. `git diff --check` passes.

### Remaining installed proof and round-2 scope

**Coordinator must rebuild the app and rerun the unchanged installed XCTest
journey next**, serially against the configured disposable backend. Verify that
Signup keeps the viewport to keyboard top y539, password/repeat/consent/submit are
reachable through the real keyboard sequence, and registration reaches PIN.
Login and native Edit Profile keyboard behavior should be included in that smoke.
The synthetic separate-IME case is not Android-emulator instrumentation proof.
No installed GREEN is claimed by this round's shared UI tests.

Round-2 production edits are restricted to the new common inset helper and the
two auth call sites. Two new UI test files and this report supplement them. No
device interaction, app installation, backend mutation, account/business-model,
A1 runtime, controller, journey-helper or assertion changes were made. Existing
coordinator/Task 2/Task 3 work was preserved. No subagents, commits or pushes.
