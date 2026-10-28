# Task 3 — shopping composition, native chrome and renderer

Date: 2026-10-27. Base checkpoint: `b9def939`.

**Task 3 implementation and scoped automated verification pass.** Final installed
app builds, ten-screen visual comparison and full installed journeys are the
coordinator's next gate, as requested. No installed-app/pixel-match claim is made.

## Delivered

- One shared teal app bar with white real Back/Refresh/action icons. Existing
  heading IDs move to the actual app-bar title; existing action node IDs move to
  their real icon controls. Only promoted Text/Button nodes leave the body.
  Product keeps its actual name at `product.title` below the images; its local
  app-bar title is `Товар`, including for old documents with ScreenTitle styling.
- Home uses `ShopKMP`, a compact accessible controlled search input with explicit
  original submit, categories/paging and compact popular cards before ancillary
  links. Its Search/Profile shortcuts use existing typed catalog/profile routes
  (Login for anonymous Profile). Home still validates session ownership and
  retains authenticated account metadata/cart count.
- Native Edit Profile is outside both ordinary chrome and bottom navigation.
  The host captures raw `viewModel.stateOwner` and `state.profileEditorId`, keys
  by both and supplies account/editor-bound cancel/save callbacks. The ordinary
  navigation Scaffold owns its system padding; the standalone editor branch
  supplies status/navigation-bar padding once because the editor app bar has
  zero insets. Host restore/save/leave and the account-keyed saveable state holder
  are retained, as are pending-add recovery, security errors and confirmations.
- Product builders emit images first, actual body title, real rating/review
  count, price, complete description chunks, real stock, `Количество`, controlled
  stepper and full-width `Добавить в корзину`. Original image/quantity/purchase
  action IDs and description chunking remain intact.
- Cart uses its permitted real image through `MediaPolicy.publicUrl` and the
  existing shared image loader. Normal rows are compact; oversold/unavailable
  warnings and absolute reduction/removal remain. Total has `Итого` left and
  the real `cart.total` value right.
- Rounded warm checkout summaries expand the existing structured address and
  canonical payment controls locally. Missing required values/errors unfold
  automatically. Expanding/collapsing preserves host-controlled values/errors
  and sends no events or requests. The original full-width checkout control and
  native confirmation/zero-orders-before-confirmation flow remain.
- Orders use compact filled cards, localized display labels and gray/teal/
  amber/green/red status colors. Status/payment props and filter values retain
  canonical enums. The filter is locally disclosed; explicit Apply and real
  pagination remain. Empty-order rendering is retained.
- New Profile overview omits the inline editor. It displays the trusted default
  (or first returned) address summary and discloses all additional address data;
  original address nodes/chunks and source order remain in the document. Native
  action rows have chevrons; Logout uses its typed action with full-width outlined
  destructive styling. Legacy profile forms still decode/render and UpdateProfile
  remains one of the six unchanged command schemas.
- The biometric row consumes Task 2's real nonserialized snapshots. Configured
  stays checked even if unavailable; enablement requires idle + the original
  typed ManageBiometric action + available-or-configured. Each toggle emits that
  original Activate once, with no optimistic flip. Notifications are omitted:
  there is no notifications API/action. Photo capability remains Task 2's honest
  disabled control; this task adds no picker/upload functionality.

### Owned API additions

```kotlin
// SduiScreen: these defaulted parameters are immediately before final onEvent.
chromeNodeIds: Set<String> = emptySet()
biometricAvailable: Boolean = false
biometricConfigured: Boolean = false

// Appended defaulted UIKit state properties; old constructors/copy calls remain valid.
EpsCartItemState.imageUrl: String? = null
EpsOrderRowState.compact: Boolean = false
```

The renderer also retains a compatibility overload for the original fully
positional eight-argument invocation. Chrome planning/control helpers are
internal to feature/main; form/disclosure/presentation helpers are internal to
the renderer. No API change was required in another owner's source files.

## Exact display-money correction

Root cause: the unchanged source-baseline `Money.formatRub()` intentionally
returned `100 RUB` for whole rubles, or `4 990.50 RUB` for fractional amounts.
CartStateBuilder/legacy EpsCartTotal consumed that result, while the approved cart
UI test expected `Итого: 100,00 ₽`.

Only display formatting changed: the same integer `Long` kopecks now produce
grouped rubles, a comma, two fractional digits and `₽`. The formatter regression
covers zero, whole rubles, one kopeck, grouping and exact Long.MAX_VALUE kopecks:
`92233720368547758.07` → `92 233 720 368 547 758,07 ₽`. It also asserts unchanged
canonical `toString()` for every case. No Float/Double/string-float round trip,
money type, arithmetic or wire change was introduced. Renderer wire-price display
similarly groups the validated decimal string without converting through floats.

`CartScreenUiTest.kt`, CartStateBuilder and Task 2-owned EpsShopLayout were not
edited by Task 3. The actual existing cart UI regression now passes.

## RED evidence

Read first: PLAN, TASK1_REPORT/TASK2_REPORT exact APIs, installed visual audit and
reference SVG. Dedicated read/search tools returned `Bun is not defined`; Python
was used for inspection and all source edits used `apply_patch` (Go formatting
used gofmt).

All Gradle commands use:

```sh
export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home
```

1. Before BFF presentation changes:

   ```sh
   go -C backend-sdui test ./internal/bff -run TestPresentation -count=1
   ```

   Exit **1**: **4 tests failed**, zero skips. Actual failures: oversized Home
   identity block/wrong brand/interrupted hierarchy; Product text before image,
   ScreenTitle body name and missing quantity label; raw filter enum labels;
   inline Profile form and missing trusted-default summary.

2. Money regression, before production formatting change:

   ```sh
   ./gradlew :feature:home:home-domain:testDebugUnitTest :core:sdui-renderer:iosSimulatorArm64Test --continue --no-daemon --console=plain -q
   ```

   Exit **1**: home-domain **7 tests / 2 failures / zero skips**. Both failures
   were the Russian-display expectation. This first combined run also had a new
   UI fixture's Kotlin list-inference compile error; that compile failure is not
   represented as semantic RED.

3. Corrected fixture, still before renderer changes:

   ```sh
   ./gradlew :core:sdui-renderer:iosSimulatorArm64Test --no-daemon --console=plain -q
   ```

   Exit **1**, 34s: **10 tests / 2 failures / zero skips**. Existing checkout city
   was visible before expansion; checkout CTA was narrower than its actual form.

4. Chrome API scaffold returned metadata/empty promotions before behavior:

   ```sh
   ./gradlew :feature:main:testDebugUnitTest :core:sdui-renderer:iosSimulatorArm64Test :feature:home:home-domain:testDebugUnitTest :feature:cart:cart-ui:iosSimulatorArm64Test --continue --no-daemon --console=plain -q
   ```

   Exit **1**, 48s: main **43 tests / 3 failures / zero skips**. Failures proved
   missing actual-heading/edit promotion, incorrect Product app-bar title and
   missing typed Home shortcuts. Renderer/home-domain/cart had become GREEN.

5. Detail payment display, before changing its Text builder:

   ```sh
   go -C backend-sdui test ./internal/bff -run TestPresentationOrderPaymentLabelIsDisplayOnly -count=1
   ```

   Exit **1**: **1 failure / zero skips** (`ON_DELIVERY` leaked into display text).
   The same test protects canonical OrderCard paymentMethod `ON_DELIVERY`.

6. Self-review found the compact Home input needed an accessible name after text
   replaced its placeholder. Before adding that semantics:

   ```sh
   ./gradlew :core:sdui-renderer:iosSimulatorArm64Test --no-daemon --console=plain -q
   ```

   Exit **1**, 31s: **21 tests / 1 failure / zero skips**. Actual editable text was
   `лампа`, but its real `catalog.q` node lacked `Поиск товаров` contentDescription.
   The fix names the actual input, keeps its readOnly property and does not add
   a duplicate node. Search submit is still the original accessible icon action.

Other new security/controlled-state/compatibility guards passed when introduced;
they are not falsely described as failing baselines. Existing critical host VM
tests and the original cart UI assertion were preserved.

## Final GREEN verification

Final scoped command, **exit 0**. `--rerun` is applied to each test task, so the
table below is freshly executed test results, not reused up-to-date XML. Compile
tasks can be up to date and are not described as fresh compilations.

```sh
./gradlew :feature:auth:auth-ui:testDebugUnitTest --rerun :feature:profile:profile-ui:testDebugUnitTest --rerun :feature:main:testDebugUnitTest --rerun :core:sdui-model:testDebugUnitTest --rerun :core:sdui-actions:testDebugUnitTest --rerun :core:sdui-renderer:testDebugUnitTest --rerun :feature:home:home-domain:testDebugUnitTest --rerun :feature:cart:cart-ui:testDebugUnitTest --rerun :feature:auth:auth-ui:iosSimulatorArm64Test --rerun :feature:profile:profile-ui:iosSimulatorArm64Test --rerun :feature:main:iosSimulatorArm64Test --rerun :core:sdui-renderer:iosSimulatorArm64Test --rerun :feature:home:home-domain:iosSimulatorArm64Test --rerun :feature:cart:cart-ui:iosSimulatorArm64Test --rerun :core:uikit:compileDebugKotlinAndroid :core:uikit:compileKotlinIosSimulatorArm64 :core:uikit:compileKotlinIosArm64 :feature:main:compileDebugKotlinAndroid :feature:main:compileKotlinIosSimulatorArm64 :feature:main:compileKotlinIosArm64 :core:sdui-renderer:compileKotlinIosArm64 :composeApp:compileDebugAndroidTestKotlinAndroid --no-daemon --console=plain -q
```

| Module | JVM tests | iOS simulator tests |
|---|---:|---:|
| auth-ui | 11 | 20 |
| profile-ui | 13 | 18 |
| main | 43 | 44 |
| sdui-model | 7 | — |
| sdui-actions | 4 | — |
| sdui-renderer | 6 | 21 |
| home-domain | 7 | 7 |
| cart-ui | 1 | 2 |
| **Total target executions** | **92** | **112** |

All **failures/errors/skips are zero**. Common tests run on both targets; these
are target executions, not 204 distinct scenarios. Main includes the unchanged
26-case critical SduiViewModel suite. New cases include one real heading/edit
control, real icon callbacks, legacy positional renderer invocation, controlled
biometrics, no-submit disclosures/value-error preservation, full-width CTA,
real default/additional addresses, permitted/forbidden cart images, oversold
absolute quantities and localized order cards/canonical enums. Main iosTest adds
only the existing catalog `libs.compose.ui.test` reference.

Affected Android, simulator-arm64 and physical-iOS-arm64 compile tasks pass;
Android installed-journey test sources compile. Existing BackHandler deprecation
and SduiViewModel redundant-else warnings remain.

Go, **all exit 0**:

```sh
# Explicit reviewed fixture regeneration (run only for intended presentation changes):
UPDATE_FIXTURES=1 go -C backend-sdui test ./internal/bff -run TestPublished
# Ordinary, non-update verification:
go -C backend-sdui test ./... -count=1
go -C backend-sdui test -race ./... -count=1
# Final race verification also collected exact JSON counts:
go -C backend-sdui test -race -json ./... -count=1
go -C backend-sdui vet ./...
python3 scripts/route_matrix.py --check
git diff --check
```

Final race run: **31.07s**, **110 top-level tests passed**, **537 pass events
including subtests**, **one skip**: existing opt-in
`TestLiveYiiEightScreensAndActions`. No failures. That live test was not enabled,
consistent with reserving installed/live journeys for the coordinator. Broad
UUID, large-collection/description, int32, security-status, mutation/retry and
all-six-command regressions remain in the passing suite. Route matrix:
**54 exact operations/policies, 8 screens, 6 commands**. Normal fixture checks run
with UPDATE_FIXTURES absent, including in the final race run.

Only seven builder fixtures changed: Home and its category continuation;
Product and its long-description variant; Profile; Orders; Order payment display.
Schemas/unknown fields/command models were not changed.

### Journey portability verification

- Both runners accept one `--backend-url` used for **both app build and verifier**.
  Defaults: iOS `http://127.0.0.1:58196`; Android `http://10.0.2.2:58196`.
- iOS runner requires `--udid`, uses the preapproved
  `/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode` by default and accepts
  an existing `--tmp-root`. Raw XCTest activities/results remain private and
  temporary. The documented xcodebuild `TEST_RUNNER_` prefix passes the verifier
  origin; the same value sets `BACKEND_SERVER_URL` for the app. No old-port verifier
  fallback remains. Android passes the same origin as app Gradle property and
  instrumentation argument.
- Initial PIN detection is `pin.header`; valid six-digit input still explicitly
  clicks `pin.submit`. Identity is checked through the Home Profile shortcut on
  Profile, checkout disclosures open before typing/choosing payment, and the
  specific paid order card expects `Оплачен`. Independent API assertions remain
  `PAID`/`CAPTURED`, with the confirmation-before-zero-orders assertion intact.
- Python AST parse of both runners: **exit 0**. Swift journey type-check:
  **exit 0**, using the simulator SDK plus its XCTest framework and Swift overlay
  include path. The initial standalone type-check lacked the XCTest Swift overlay
  path and failed on existing XCTFail/runActivity APIs; adding the correct
  `-I <platform>/Developer/usr/lib` resolved that tooling error without source
  workarounds. No full installed test or payment operation was run.

## Self-review and handoff

Reviewed all owned production/test files and fixture diffs against the approved
composition, constructor/call compatibility, typed-action/unknown-action guards,
account/editor callbacks, inset ownership, canonical values, all-address/media
bounds and unchanged host lifecycle/recovery/confirmation handling. The Home
accessible-name issue was found and fixed through a real RED/GREEN case.
`git diff --check` passes. Separate coordinator review and final app build/install,
small-screen/keyboard checks and ten-screen visual evidence are next.

No subagents, commits, pushes, PHP/business/runtime/SQL changes or final app
installations were performed. Task 3 did not edit concurrent Task 2's VM/adapter/
editor/profile/avatar/layout files or Task 1's reviewed tokens/primitives/auth/
navigation/top-bar files. Interrupted A1 work remains outside this task.

## Complete Task 3 file inventory

```text
backend-sdui/internal/bff/screens.go
backend-sdui/internal/bff/components.go
backend-sdui/internal/bff/profile_content.go
backend-sdui/internal/bff/presentation_test.go (new)
backend-sdui/contracts/fixtures/home.json
backend-sdui/contracts/fixtures/home-continuation.json
backend-sdui/contracts/fixtures/product.json
backend-sdui/contracts/fixtures/product-long-description.json
backend-sdui/contracts/fixtures/orders.json
backend-sdui/contracts/fixtures/order.json
backend-sdui/contracts/fixtures/profile.json
core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiScreen.kt
core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/RenderPlan.kt
core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiIcon.kt
core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiForms.kt (new)
core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/Presentation.kt (new)
core/sdui-renderer/src/commonTest/kotlin/com/shopkmp/sdui/renderer/PresentationTest.kt (new)
core/sdui-renderer/src/iosTest/kotlin/com/shopkmp/sdui/renderer/ShoppingPresentationUiTest.kt (new)
core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/shop/EpsCart.kt
core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/shop/EpsOrder.kt
core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/shop/EpsOrderRowState.kt
feature/home/home-domain/src/commonMain/kotlin/com/shopkmp/home/domain/Money.kt
feature/home/home-domain/src/commonTest/kotlin/com/shopkmp/home/domain/MoneyTest.kt
feature/main/build.gradle.kts
feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt
feature/main/src/commonMain/kotlin/com/shopkmp/main/container/SduiChrome.kt (new)
feature/main/src/commonTest/kotlin/com/shopkmp/main/container/ChromeTests.kt (new)
feature/main/src/iosTest/kotlin/com/shopkmp/main/container/ChromeUiTests.kt (new)
iosApp/iosAppUITests/InstalledJourneyTests.swift
composeApp/src/androidInstrumentedTest/kotlin/com/shopkmp/InstalledJourneyTest.kt
scripts/run_ios_installed_journey.py
scripts/run_android_installed_journey.py
docs/SDUI_CONTRACT.md (presentation documentation only)
docs/mobile-ui-fixes/TASK3_REPORT.md (new)
```

## Fix round 1 — review M1: latch automatic checkout address disclosure

Date: 2026-10-27. Reviewed `TASK3_REVIEW.md`; addressed its one Medium finding.

**Root cause:** automatic address visibility depended on `invalidAddress` while
the remembered Edit flag remained false. Entering the first character of the
last required field, or clearing the final field error, removed the entire
address editor in the same editing session.

**Fix:** initialize the existing form-keyed address disclosure from its actual
validation state and latch every automatic reveal in a Compose SideEffect.
Resolving blanks/errors no longer clears that flag. Explicit disclosure clicks
can collapse only a currently valid address section; invalid/error exposure stays
open. A valid saved address still starts compact. The existing
`rememberSaveable(node.id)` identity and enclosing account/destination saveable
scope are retained: neither changing values nor clearing errors changes the key.
Host-controlled values/errors, existing selectors, field validation and checkout
submission remain unchanged. SideEffect/disclosure controls only change local
presentation state; they emit no SDUI event or request.

### Two new iOS UI regressions, added before the fix

1. `automaticallyOpenedBlankAddressStaysOpenThroughMultiCharacterHouseAndApartment`:
   starts with all four address strings blank, never clicks Edit to open, enters
   city/street, then house `5` and `1` separately. It checks that the house input
   remains displayed/focused after `5`, accepts `51`, and keeps apartment available
   for `24`. Explicit collapse/reopen retains both values. The exact event list
   contains only the five expected FieldChanged events, with no Activate or
   disclosure event; canonical payment value stays `CARD`.
2. `automaticallyExposedAddressErrorStaysOpenAfterLastErrorIsCleared`: starts with
   a valid saved address and verifies compact presentation, then injects a street
   error to exercise later automatic exposure. The host-style FieldChanged
   callback updates the controlled value and removes that final error. The editor
   remains reachable for an apartment edit, and explicit collapse/reopen preserves
   its draft and resolved errors. The exact event list contains only the two
   actual field edits.

Original renderer/chrome UI assertions were not weakened or changed. No color
constant tests, journey-helper workaround or other-owner field/VM edit was used.

### Actual RED / GREEN commands and results

All commands use:

```sh
export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home
```

**RED, before production edit:**

```sh
./gradlew :core:sdui-renderer:iosSimulatorArm64Test --rerun --no-daemon --console=plain -q
```

Exit **1**, **31s**: **23 tests, 2 failures, 0 errors, 0 skips**. The new blank
address case could not find `checkout.house` immediately after entering `5`;
the new validation case could not find `checkout.street` immediately after its
last error was cleared. These were the two intended semantic failures; the
other 21 tests passed.

**Focused GREEN, after the latch fix:**

```sh
./gradlew :core:sdui-renderer:iosSimulatorArm64Test --rerun --no-daemon --console=plain -q
```

Exit **0**: **23 tests passed, 0 failures/errors/skips**.

**Final renderer/chrome suites and platform compiles:**

```sh
./gradlew :core:sdui-renderer:testDebugUnitTest --rerun :feature:main:testDebugUnitTest --rerun :core:sdui-renderer:iosSimulatorArm64Test --rerun :feature:main:iosSimulatorArm64Test --rerun :core:sdui-renderer:compileDebugKotlinAndroid :core:sdui-renderer:compileKotlinIosSimulatorArm64 :core:sdui-renderer:compileKotlinIosArm64 :feature:main:compileDebugKotlinAndroid :feature:main:compileKotlinIosSimulatorArm64 :feature:main:compileKotlinIosArm64 --no-daemon --console=plain -q
```

Exit **0**, **50.02s**. Every listed test task was explicitly rerun:

| Module | Fresh JVM executions | Fresh iOS simulator executions |
|---|---:|---:|
| sdui-renderer | 6 | 23 |
| main, including existing chrome suites | 43 | 44 |
| **Total** | **49** | **67** |

All **failures/errors/skips are zero**. Renderer includes all 11 shopping UI
tests; main includes the three common ChromeTests and the actual-control
ChromeUiTests. Android, simulator-arm64 and physical-iOS-arm64 compile tasks
pass; compile tasks that were up to date are not represented as freshly executed
compilations. Existing MainContainer BackHandler deprecation and SduiViewModel
redundant-else warnings remain; there were no compiler errors.

`git diff --check`: **exit 0**. This round changes only `SduiForms.kt`,
`ShoppingPresentationUiTest.kt` and this report. No Go/fixture, other-owner VM,
shared Task 1 field, app/device or installed-journey operation was performed.
No subagents, commits or pushes. Ready for the coordinator's BFF rebuild and
final app/installed-journey/visual verification.

## Fix round 2 — explicit imports and full frontend lint/build

Date: 2026-10-27. The coordinator reported `frontend-test` (including Kover)
passed in 85s, then full lint stopped the combined make invocation on six owned
WildcardImport findings. Android build had not run in that invocation. That test
result is attributed to the coordinator; this import-only round does not claim
a new test/Kover execution.

Changed only the six wildcard import declarations to the explicit symbols used:
one model import in `SduiChrome.kt`, and runtime/model/button/text/textfield imports
in `SduiForms.kt`. No logic, lint configuration/filters, suppressions or tests were
changed. This report is the only additional edited file in this round.

All commands below used the actual Java 17 home:

```sh
export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home
```

### Reproduced failure before the import edit

```sh
make frontend-lint
# Runs: ./gradlew detektMetadataCommonMain detektAndroidDebug --no-daemon
```

**make exit 2**, underlying Gradle **exit 1**, **4.79s wall time**. Exactly six
WildcardImport findings: five in renderer `SduiForms.kt`, one in main
`SduiChrome.kt`. Two common-main Detekt tasks failed. Gradle reported 53 actionable
tasks: 13 executed, 40 up to date.

### Full lint GREEN after explicit imports

```sh
make frontend-lint
```

**Exit 0**, **16.74s wall time**, `BUILD SUCCESSFUL`. All findings resolved;
owned common-main Detekt reports show zero code smells, and the full requested
common-main/Android-debug target completed. Gradle reported 312 actionable tasks:
45 executed, 267 up to date; cached tasks are not described as fresh analyses.

The full lint invocation's Android compilation emitted only the existing:

- `MainContainerScreen.kt:66` — deprecated `BackHandler` API.
- `SduiViewModel.kt:241` — redundant `else` in an exhaustive `when`.

No remaining import findings or package/namespace warnings were emitted.

### Android build, run after lint passed

```sh
make frontend-build
# Runs: ./gradlew :composeApp:assembleDebug --no-daemon
```

**Exit 0**, **19.09s wall time**, `BUILD SUCCESSFUL`. Gradle reported 594 actionable
tasks: 44 executed, 550 up to date; `packageDebug` and `assembleDebug` completed.
Confirmed output: `composeApp/build/outputs/apk/debug/composeApp-debug.apk`
(53,551,141 bytes). No compiler or package/namespace warnings were emitted by
this build invocation. No APK installation/device operation was performed.

`git diff --check`: **exit 0**. No unrelated A1/PHP or other-owner files were
edited. No subagents, commits or pushes. Ready for the coordinator's ongoing
iOS and installed-app acceptance checks.

## Installed fix round 3 — registration focus/viewport diagnosis and full iOS PASS

Date: 2026-10-27. Device: local iPhone 17 Pro / iOS 26.2,
`2E4889AB-D521-4B8C-A796-AD80A7536D00`. Actual disposable edge/BFF:
`http://127.0.0.1:58196`. Java: Temurin 17 at the recorded system path.

The coordinator's two earlier installed REDs and separate Task 1
`hostedFormImePadding` production correction are preceding evidence, not changes
authored by this round. This round diagnosed the remaining
`enabled_signup.submit` failure against that corrected app.

### Actual root cause, demonstrated before changing the helper

Added fixed, privacy-safe diagnostics around the real inputs, terms control and
reachable viewport. No form values, credentials, email, nonce, capability URI,
keyboard contents or raw hierarchy are exported. The first two fresh installed
executions still failed at the original enabled-submit assertion.

- Name/email/phone values matched their intended inputs, both after typing and
  before/after terms. These comparisons exported booleans only.
- Terms was fully visible and hittable: actual frame
  `(16,563.3,48,48)` inside viewport `(0,126,402,714)`. Its selected trait changed
  **false → true** after the actual checkbox tap. Submit remained disabled.
  This rules out an unchanged EpsCheckbox callback or a missed terms tap on this
  observed path.
- The second RED measured `signup.password` **before the helper's tap**:
  actual frame `(16,83.3,370,49.3)`, keyboard-free form viewport
  `(0,126,402,166)`. `isHittable` was **true**, but `centerInside=false` and
  `frameInside=false`: only **6.6 points** of the input intersected the viewport.
  The existing reveal helper stopped on that partial sliver after upward-only
  scrolling. Its tap did not enter the intended complete password; the masked
  input's length comparison failed while the other supplied fields matched.

The fix is entirely in the XCTest helper: reveal now computes the actual form
viewport intersected with the app frame, excluding a visible keyboard; requires
the whole control inside that viewport with a small inset; and uses bounded,
bidirectional drags inside the visible region. It scrolls down for a target above
the viewport instead of continuing to overshoot upward. The original 40-second
condition deadline, enabled/hittable checks and business assertions are retained.
There is no forced enablement, arbitrary sleep, API registration, mutation retry
or changed production input/checkbox/validation behavior.

The corrected registration run measured the password at
`(16,228,370,49.3)` within the same keyboard-free `(0,126,402,166)` viewport:
**centerInside=true, frameInside=true**. Both protected password controls reported
matching lengths and masking. Their `valueMatches=false` flags are expected for
masked accessibility strings, not a claim to compare the plaintext password.
Terms selected became true and **submitEnabled=true**. Successful independent
password authentication and subsequent real UI password login additionally
verified that the intended credential reached the backend.

### Fresh installed executions — exact commands and results

The following command was executed **four times**, with fresh synthetic account
intents and distinct evidence-run IDs. Only this test app's container and the
helper's single nonsecret `backend_device_id` were reset; no simulator/global
Keychain or production datastore reset was performed.

```sh
JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196 --tmp-root /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode
```

| Evidence run ID | Change/state under test | Build/test wall time | XCTest case time | Actual result |
|---|---|---:|---:|---|
| `20261027T083440761261Z` | Boolean/terms diagnostics; original reveal helper | 78.978s | 71.663s | runner exit **1**, xcodebuild **65**; **1 test / 1 failure**, `enabled_signup.submit` |
| `20261027T083733537316Z` | Added input-frame diagnostics; original reveal helper | 79.547s | 73.224s | runner exit **1**, xcodebuild **65**; **1 test / 1 failure**, same assertion; clipped-password geometry captured |
| `20261027T084055797270Z` | Viewport-aware helper | 232.439s | 225.642s | runner exit **1**, xcodebuild **65**; registration/PIN/login/shopping/native checkout passed; **1 test / 1 failure**, `safari_payment_completed` |
| `20261027T084634054255Z` | Same corrected helper plus fixed browser-prompt/frame diagnostics | 243.695s | 236.514s | runner exit **0**, xcodebuild **0**; **1 passed / 0 failed / 0 skipped**, `full_journey_pass` |

The intermediate Safari failure remains recorded. After the original single
Complete payment click, its completion page was absent and independent trusted
API reads still returned `AUTHORIZED` / `CREATED`; no CORS/error-page label was
observed. That payment was **not automatically retried**. The next run created a
new explicit UI intent/account/order. No provider, payment, Safari action logic or
assertion was changed between these two runs; only fixed browser geometry/prompt
diagnostics were added. The final run passed with one Complete payment click.
This is a genuine latest full-journey PASS, not a claim that every previous
installed attempt passed or that the preceding Safari timeout has a proven cause.

### Final verified full-flow outcomes

Final canonical order: `a1acc888-eb33-4cfb-93d1-6bf8483fbe25`.
API-discovered product: `6c35a708-047f-433a-951d-b306fda833b3`.
Exact amount: **`50.01`**, with independent payment **`CAPTURED`** and order
**`PAID`** assertions, correct payment/order linkage, `CARD` method and a nonempty
real demo transaction ID.

The passing execution retained and exercised:

- UI signup and actual enabled submit; initial `pin.header`; six distinct strong
  PIN digits and explicit `pin.submit`; one persisted PIN device.
- Authenticated Profile identity via Home shortcut, logout and real password
  login; no API-created customer or app-session injection.
- Catalog search → real Product price → AddToCart, one canonical item/quantity
  and exact cart total.
- All four structured address inputs and actual CARD selection; **zero orders
  before native confirmation**; one created order after confirmation; exact
  address/price snapshots, empty cart/zero total and stock reduced by exactly one
  relative to that run's initial read.
- External Safari payment completion, native return/open prompt, real app
  foreground, localized specific paid order card and purchased order detail.
- Actual persisted PIN device display, final logout, relaunch at Login with no
  authenticated navigation, and unchanged canonical paid-order/count/stock checks.

### Privacy, fresh evidence and source inventory

Each runner invocation now keeps a separate **mode 0700** directory:

```text
/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/ios-journey-evidence-<evidence-run-id>/
```

These contain only the allowlisted summary and, where reached, the existing
nonsecret commerce screenshots. All four receipts are present; the last contains
fresh `product.png`, `checkout-confirmation.png`, `orders-paid.png` and
`order-detail.png`. Existing latest files under `docs/evidence/ios/` and the
runner's append-only `docs/MOBILE_E2E_REPORT.md` were refreshed normally; failed
attempts are not overwritten in the distinct private receipts. No signup/login,
credential, browser-capability or raw-hierarchy screenshot was captured.

Raw stdout/xcresult/diagnostics remain inside the private TemporaryDirectory and
are destroyed on completion. Verified after the final run: **no raw
`ios-journey-*` helper temp directory remains** (only the distinct safe evidence
directories). New extraction patterns accept only finite control IDs/stages,
boolean equality/length/masking/selection flags, numeric rectangles and fixed
browser prompt labels/counts. They never export actual field values or URLs.

Authored source/report changes in this round:

```text
iosApp/iosAppUITests/InstalledJourneyTests.swift
scripts/run_ios_installed_journey.py
docs/mobile-ui-fixes/TASK3_REPORT.md
```

Generated evidence updates: `docs/MOBILE_E2E_REPORT.md`,
`docs/evidence/ios/xctest-summary.txt` and the four existing commerce PNG paths
listed above. Swift standalone type-check and Python AST parse passed before the
helper's installed verification; the final installed build compiled the final
Swift test source and executed it successfully. `git diff --check`: **exit 0**.

No production UIKit/auth/VM, backend/BFF/A1/PHP/SQL or Android source was edited.
No assertion weakening, global reset, subagent, commit or push. Local iOS is
available to the coordinator again after the completed passing run.

## Installed fix round 4 — preserved Android failure diagnosis and two fresh PASSes

Date: 2026-10-27. Local Pixel 10 / Android 16 API 36, ADB
`emulator-5558`, mobile tool ID `Pixel_10`. Disposable app/verifier origin:
`http://10.0.2.2:58196`; host/root edge `http://127.0.0.1:58196`.

**Latest final-source installed full journey: PASS — one test, zero failures,
zero skips, runner exit 0.** A preceding fresh cold-process run also passed.
The earlier coordinator RED is retained below; no payment from that failed intent
was retried or modified to manufacture a passing result.

### Preserved-state diagnosis before any new commerce

The coordinator reported the original full journey's **73.406s** test failure at
`gateway completed state; no payment action retry`, after registration, PIN,
password login, cart and native checkout assertions passed. Original order:
`320d4e84-8871-4745-8a19-5fd136782a7c`. Command:

```sh
JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home python3 scripts/run_android_installed_journey.py --serial emulator-5558 --backend-url http://10.0.2.2:58196
```

Performed these actual read-only checks on the unchanged failed browser state:

- First safe foreground query: **Chrome**, `ChromeTabbedActivity`.
- Private, read-only Chrome DevTools DOM queries exported only finite label/error
  flags, viewport/control rectangles and activation booleans. The visible tab
  was a fully loaded demo-payment document; **Complete payment remained present**,
  completion/return labels were absent, and no known CORS, forbidden, payment-error
  or insecure-form interstitial was observed.
- Crucially, the preserved document reported
  **`navigator.userActivation.hasBeenActive=false`** and the payment control was
  not focused. Thus the failed attempt had not produced a DOM activation on that
  unchanged document. No second click/POST was attempted on it.
- A private in-memory Android screen capture was read by local Vision OCR. Only
  whitelisted labels/rectangles and fixed error booleans were returned. It found
  **Complete payment**, with no known security/error prompt. No raw browser PNG,
  address bar, capability URI, token or arbitrary OCR text was exported.
- A temporary read-only instrumentation entry inspected native controls without
  launching/clicking the app. The actual Complete payment node was enabled and
  clickable at **`(8,410,141,25)`**; Chrome's WebView was
  **`(0,284,1080,2077)`**, and the URL bar **`(210,145,460,131)`**. The button was
  fully inside the content region, not the toolbar. Fixed queries for Send/Send
  anyway/Go back/Continue/first-run and known backend-error labels were negative.
  Inspector result: **1 passed / 0 failed**, **0.721s**.
- Root-only SQL inspection used the container project identified by **port
  58196**, and a `BEGIN READ ONLY ... ROLLBACK` transaction selecting only order/
  payment IDs, statuses, exact text amounts, method and payment-order linkage.
  The old order was still **CREATED / AUTHORIZED**, CARD, exact **`"50.01"`** for
  order and payment, with correct payment-order association. There were no DB
  writes, payment capability requests or calls against another backend stack.

The standalone ADB hierarchy tool exited **137**; the separate read-only
instrumentation inspector initially could not acquire UiAutomation and reported
`already registered` / process-crashed status. This was a tooling ownership
conflict, not a commerce assertion failure. Disconnected only the identified
`com.mobilenext.mobilecli.DeviceServer` tooling process; Chrome/app data and the
preserved page stayed intact. The inspector then passed. Its temporary test
entry was removed after inspection, leaving only the original full-journey test
method in the delivered class. Raw device XML scratch paths were deleted.

### Delivered harness changes

- Added fixed Chrome control/readiness/error/geometry diagnostics around the
  existing **single** Complete payment click and on timeout. Neither the selector,
  payment action, consent behavior nor business assertions was replaced.
- The existing **40-second** result deadline starts immediately after the click,
  **before** diagnostics, so diagnostic work cannot extend the payment wait.
  No larger timeout, fixed sleep, JavaScript click, automatic payment retry,
  API-created customer/order or speculative product/backend fix was added.
- Android runner now exports only allowlisted milestones, browser control codes,
  booleans/rectangles, native Chrome resource names and JUnit counters. Raw
  instrumentation output/failures stay in process memory rather than public
  evidence or console. The verdict still requires a passing JUnit result/no
  failures and additionally requires the real final full-journey milestone.
- Added distinct mode-0700 private evidence directories and safe receipt copies,
  with optional existing `--tmp-root`, analogous to the iOS runner. Both app and
  verifier retain the same configured backend origin.

### Actual commands and fresh GREEN results

Instrumentation compilation, **exit 0**:

```sh
JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home ./gradlew :composeApp:assembleDebugAndroidTest -PSHOPKMP_DEBUG_SERVER_URL=http://10.0.2.2:58196 --no-daemon --console=plain -q
```

For each fresh full journey, first stopped **only Chrome's process** to exercise
cold process startup; Chrome profile/onboarding/history data were **not cleared**.
Then ran the coordinator's exact standard journey command. The helper performed
only its normal authorized `pm clear com.shopkmp` test-app reset.

```sh
/Users/dmitry/Library/Android/sdk/platform-tools/adb -s emulator-5558 shell am force-stop com.android.chrome
JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home python3 scripts/run_android_installed_journey.py --serial emulator-5558 --backend-url http://10.0.2.2:58196
```

| Private evidence run | JUnit test duration | Instrumentation wall time | Result |
|---|---:|---:|---|
| `20261027T092010854335Z` | 44.534s | 45.655s | **1 passed / 0 failed / 0 skipped**, runner exit **0**, full-journey PASS milestone |
| `20261027T092509175355Z` | 36.44s | 37.903s | **1 passed / 0 failed / 0 skipped**, runner exit **0**, final delivered source, full-journey PASS milestone |

The final run followed removal of the temporary read-only inspector entry and
placing diagnostic work inside the original result-deadline budget. The first
PASS is recorded as the preceding diagnostic candidate, not falsely presented
as a second execution of identical final test-source bytes.

ADB instrumentation process exit was **0** in both; the runner also checked JUnit
and the explicit terminal success milestone rather than treating ADB exit alone
as success. Each fresh run used a new synthetic account/order and one explicit
payment submission. No old ambiguous intent was replayed.

Both passing executions retained UI registration, strong explicit six-digit PIN,
one persisted PIN device, actual Profile identity, logout/password login,
API-discovered catalog product and exact price, one cart item/quantity/total,
controlled structured checkout and CARD selection, **zero orders before native
confirmation**, native order creation, immutable product/price snapshot,
cart-empty and exact stock-minus-one assertions. They then verified trusted
**CAPTURED / PAID**, external browser return, the specific localized paid order
card/detail, the actual PIN device and final logout/relaunch at Login with no
authenticated navigation. No original assertion was weakened.

Final root-only **read-only** SQL cross-check, amounts cast to text:

| Order | Order / payment status | Method | Order / payment amount | Payment belongs to order |
|---|---|---|---|---|
| Original failed `320d4e84-8871-4745-8a19-5fd136782a7c` | CREATED / AUTHORIZED | CARD | `50.01` / `50.01` | true |
| First fresh PASS `de4ae413-6092-4294-a309-66977c50ef84` | PAID / CAPTURED | CARD | `50.01` / `50.01` | true |
| Final PASS `b9c03370-c289-4a49-89e7-078276b61466` | PAID / CAPTURED | CARD | `50.01` / `50.01` | true |

### Evidence, privacy and remaining limits

Distinct safe receipts/screenshots are under:

```text
/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/android-journey-evidence-20261027T092010854335Z/
/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/android-journey-evidence-20261027T092509175355Z/
```

Verified both directories are **0700** and contain passing safe receipts. The
latest public `docs/evidence/android/instrumentation.txt` and four declared app
commerce PNGs are fresh; hashes of all four PNGs match the final distinct private
run. No browser/credential screenshot or raw hierarchy/URL was published.
Python AST parse and four positive/negative privacy-allowlist checks passed;
`git diff --check` exited **0**.

**Limit:** the exact historical reason that the original single tap failed to
activate the DOM cannot be reconstructed from its post-failure state. The
observed evidence does not establish a CORS/provider bug, wrong selector,
specific first-run consent prompt or production UI fault. No such unobserved
cause is claimed as repaired. Cold **process** startup passed twice with the
original selector/single click; this is not a claim to recreate virgin Chrome
profile onboarding. The earlier failed intent remains untouched in its observed
AUTHORIZED/CREATED state. Final diagnostics preserve evidence if it recurs.

Source/report changes this round:

```text
composeApp/src/androidInstrumentedTest/kotlin/com/shopkmp/InstalledJourneyTest.kt
scripts/run_android_installed_journey.py
docs/mobile-ui-fixes/TASK3_REPORT.md
```

Generated changes are the safe Android instrumentation summary and four existing
commerce screenshots. Read-only private diagnostic tools used only standard
Python/Swift system facilities in the preapproved temporary directory; no new
project dependency was introduced. No product/shared UIKit, iOS, BFF/PHP/A1/SQL
source edit, unrelated stack operation, global/Chrome-profile reset, subagent,
commit or push. The prior genuine iOS PASS remains unchanged and is not presented
as freshly rerun in this Android round.
