# Task 5 — installed mobile live-stack E2E

## Latest result

**Android: full installed CARD journey passed** after correcting the Yii CORS
compatibility regression. Latest parent-run instrumentation (2026-10-20):
**1 test, 30.789s**,
zero failures; runner returned PASS. Real Chrome form completion produced
**CAPTURED / PAID**, and its return link reopened the installed application.
Paid order `bbf0fce7-eb88-4948-b0c2-a1f7db0993ca`, quantity one, exact `50.01` total,
stock decrement and empty cart were independently verified. Orders, order detail,
Profile, Devices and final logout/Login were asserted through the app.

Command: `python3 scripts/run_android_installed_journey.py --serial emulator-5554`.
Current nonsecret evidence: `docs/evidence/android/instrumentation.txt`,
`product.png`, `checkout-confirmation.png`, `orders-paid.png`, `order-detail.png`.
The earlier failed runs below remain a historical debugging ledger.

**iOS: full installed CARD journey passed.** Actual run started
2026-10-19T13:43:59Z: **1 test, 1 passed, zero failures**, xcodebuild/helper exit0,
**156.525s actual XCTest duration**, **164.212s build/test wall time** on the
supplied iPhone17Pro/iOS26.2. All UI and
canonical milestones passed, including cold relaunch at Login after logout.
Paid order `b819b898-830f-445f-ad00-b27b86de06c4`, quantity1, exact `50.01`,
CAPTURED/PAID, stock -1 and empty cart independently verified. Sanitized evidence:
[`xctest-summary.txt`](evidence/ios/xctest-summary.txt),
[`product`](evidence/ios/product.png),
[`checkout confirmation`](evidence/ios/checkout-confirmation.png),
[`paid orders`](evidence/ios/orders-paid.png),
[`order detail`](evidence/ios/order-detail.png). All four images visually reviewed.
Affected feature/main JVM **31/31**, real iOS native **31/31**, and APK build
regression succeeded after the startup-router correction. Earlier failures and
the [security bridge repair](IOS_SECURITY_BRIDGE_REPORT.md) remain the ledger.
See [CORS regression](CORS_REGRESSION_REPORT.md) for the server correction/checks.

## Execution ledger

### iOS completion / review handoff — 2026-10-19

Final authored XCTest/runner executed successfully at13:43:59Z: **156.525s test,
164.212s build/test wall, total1/passed1/failed0, xcodebuild0/helper0**. Previous
13:37:44Z run also passed the full journey; the final run additionally asserts
six-digit keypad saturation, exact order-item price snapshot and full address,
and captured payment ID/order/method/amount/transaction correlation. Final order:
`b819b898-830f-445f-ad00-b27b86de06c4`, product
`96be9e50-793e-433f-82eb-689f5927b355`, total`50.01`, CARD, CAPTURED/PAID.

Execution-proven installed UI: unique signup → native strong six-digit PIN →
authenticated Home showing this account's buyer/email → logout → password login
→ real SDUI catalog search for persisted lamp → product title/price → AddToCart
→ cart/address form → selected finite CARD chip → local native confirmation.
Independent canonical reads asserted zero orders before confirmation, then one
native CheckoutUseCase order, quantity1, exact decimal total and price snapshot,
exact address, stock -1 and empty cart/0.00 total. Safari Complete payment ran
once on the current payment; trusted reads required CAPTURED/PAID and exact
payment/order/amount correlation. Return link and genuine Russian native Open
prompt returned to the app. Specific paid order card, detail/product, Profile,
persisted PIN device, logout/Login and terminate/relaunch/Login all passed.

XCUI observed common tags as native type52 fields and CARD as type9 with
`selected=true`. No hierarchy/value dumps were used. First browser completion
timeout's exact original cause was not recovered; the read-only probe found the
form still present. Later runs use explicit enabled/hittable readiness and
completion waits, with conditional handling only for actual native prompts.
No automatic payment retry was introduced; the two later full runs passed.

Implementation/review scope:

- `iosApp/iosAppUITests/InstalledJourneyTests.swift`: same existing test target,
  named journey helpers, stable selectors, explicit waits, fixed milestone
  activities, real password-authenticated read-only URLSession verifier. Generated
  credentials and PIN remain in memory and never enter the published summary.
- `scripts/run_ios_installed_journey.py` and
  `scripts/reset_ios_journey_identity.swift`: repeatable real build/install/run,
  scoped nonsecret local identity isolation, sanitized checkpoints, documented
  xcresult duration, fixed app screenshot export, temporary raw-result deletion.
- `feature/main/src/commonMain/kotlin/com/shopkmp/main/InitialSessionRouter.kt`
  and existing `commonTest/.../InitialSessionRouterTest.kt`: narrow no-saved-token
  startup correction, with two observed RED→GREEN regressions. All affected
  feature/main tests passed on JVM31/31 and native iOS31/31; APK build regression
  exit0. Commands/results: [regression summary](evidence/ios/regression-summary.txt).
- `docs/evidence/ios/` and this report: final safe evidence; all four final PNGs
  visually reviewed as nonsecret product/confirmation/paid order/detail content.

Exact independent-review rerun from repository root:

```sh
python3 scripts/run_ios_installed_journey.py \
  --udid 16B71172-52E2-47E6-A951-EFAB9627917B
```

The helper supplies the build-only58095 override and existing JDK17/Android SDK
paths. Ordinary8080 defaults are unchanged. All business mutations are installed
UI/Safari-driven; the separate verifier has no business mutation method, app
token/storage access, or capability fetch. No backend/infra/Android source edits,
commits, subagents, mock/test DI or broad simulator/Keychain resets were used.
Requested simulator journey has no unverified remaining stage. Parent independent
review is the remaining handoff; this continuation's review was inline self-review.

### iOS full journey — final logout regression checkpoint

Actual full run 2026-10-19T13:29:42Z reached Safari CAPTURED/PAID, observed native
Russian Open prompt, paid specific order card/detail and real persisted Devices.
It then failed `visible_login.email` at the final logout/relaunch boundary.
Four nonsecret app screenshots were captured. Native Open was confirmed by a
read-only XCUI probe as a hittable root Safari button (not `.alerts`). Temporary
diagnostic-only XCTest and runner flag have been removed.

Startup source prioritizes configured PIN/biometrics ahead of missing saved
tokens. Added two focused `InitialSessionRouterTest` regressions and ran
`:feature:main:testDebugUnitTest --tests com.shopkmp.main.InitialSessionRouterTest`:
**6 tests, 2 failed** (the new no-session PIN and biometric tests), 22s. Applied
the minimal missing-session guard before automatic fast-login routing. Saved
sessions retain the existing biometric→PIN→refresh flow; manual fast login is
still available from Login. New commonMain scope is only this startup router.
Next: full affected JVM/native suite, APK build regression, then actual installed
journey again. Resume: `python3 scripts/run_ios_installed_journey.py`.

PIN rerun correction: uninstall alone does **not** reset this app's installation
UUID; iOS `AppSettings` stores ordinary settings in Keychain too. A subsequent
actual run confirmed `PIN already set`, zero PIN devices for the fresh account.
The helper now uses the real Security.framework to delete **only** nonsecret
`shopkmp/backend_device_id` before a full test install; normal app generation
creates the fresh UUID. Tokens, PIN metadata and all other Keychain accounts are
preserved. `scripts/reset_ios_journey_identity.swift` performs no reads, token
injection, server/business operations, or blanket Keychain/simulator deletion.

### iOS continuation — 2026-10-19, first build/run checkpoint

Scope: iOS only, inline, no commits/subagents. Android's latest full PASS above
is preserved. Booted destination and Xcode 26.2 (17C52) verified. Existing
`UITestBase` / `InstalledJourneyTests.testRealCardJourney` and shared scheme are
the starting point; no application source changed. First `xcodebuild test`
invocation exceeded the tool's 120s timeout during build (`BUILD INTERRUPTED`),
before an XCTest result. This is **not a journey pass or selector failure**.

Exact resume command from repository root (allow at least 10 minutes):

```sh
JAVA_HOME=/Users/dmitrijbykov/Library/Java/JavaVirtualMachines/jbr-17.0.9/Contents/Home \
ANDROID_HOME=/Users/dmitrijbykov/Library/Android/sdk \
xcodebuild test -project iosApp/iosApp.xcodeproj -scheme iosApp \
  -configuration Debug \
  -destination 'platform=iOS Simulator,id=16B71172-52E2-47E6-A951-EFAB9627917B' \
  -derivedDataPath /var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/ios-installed-derived \
  -parallel-testing-enabled NO \
  -only-testing:iosAppUITests/InstalledJourneyTests/testRealCardJourney \
  BACKEND_SERVER_URL=http://127.0.0.1:58095 CODE_SIGNING_ALLOWED=NO -quiet
```

Next: obtain actual installed Login selector result, then extend this same test
using observed XCUI queries and the tested Android behavior. Ordinary app defaults
remain unchanged. Do not retain raw xcresult/credential activities as evidence.

First completed build/run: actual simulator XCTest **1 test, 1 failure** at
`Installed Login exposes stable email selector` (result summary independently
read using `xcresulttool`). No credentials entered. Adding allowlisted XCUI
identifier/type queries to distinguish startup state from accessibility mapping.
Repeatable continuation command: `python3 scripts/run_ios_installed_journey.py`.
The helper withholds raw activity output and deletes its temporary xcresult;
only sanitized summary is published in `docs/evidence/ios/xctest-summary.txt`.

#### Confirmed iOS startup blocker — latest actual run

Executed the original Login probe with the real built/installed common CMP app
against `BACKEND_SERVER_URL=http://127.0.0.1:58095`. Final helper run started
2026-10-19T12:30:26Z: **XCTest Failed, total1/passed0/failed1**, xcodebuild65,
61.296s build/test wall time. The helper returns1. Its final source retains the
original selector test; temporary accessibility enumeration was removed.

Root cause recovered from the actual simulator application's diagnostic log,
without dumping the UI hierarchy or response bodies:

```text
AuthServiceImpl → AuthApi → GetRequest → RequestExecutor → BackendSession
Caused by: kotlin.ClassCastException:
class kotlin.collections.HashMap cannot be cast to class kotlinx.cinterop.CPointer
```

The same diagnostic contains `SecurityKeychainSettings` stack frames. Source
inspection identifies the invalid native bridge:

- `core/network/src/commonMain/kotlin/com/shopkmp/network/BackendSession.kt:19`
  initializes account state by reading `userId` from secure storage.
- `core/settings/src/iosMain/kotlin/com/shopkmp/settings/SecurityKeychainSettings.kt:129–139`
  constructs a Kotlin `mapOf` and casts it directly to `CFDictionaryRef` (a native
  pointer) at line138 before calling `SecItemCopyMatching`. This throws even for
  a missing Keychain item, before Security.framework can report item-not-found.
- The adapter's write/delete paths also contain direct map-to-pointer casts at
  lines123/154. They have not been execution-tested in this continuation.

The installed process aborts during initial Compose content creation. Therefore
no actionable Login accessibility tree can be derived yet; no registration,
PIN, checkout, browser payment or screenshots occurred in these runs. Fresh or
persisted token state is not established because the read itself crashes. No
simulator reset, token injection, alternative storage, auth bypass, fake
biometrics, backend/infra/Android or commonMain edits were used.

**Scope boundary:** a genuine iOS secure-storage interop repair is required before
the journey can proceed. This exceeds the authorized UI-test/runner/report and
narrow commonMain accessibility edits. The secure-storage adapter is preserved
for explicit owner authorization rather than bypassed in test DI.

Exact repeatable command from repository root:

```sh
python3 scripts/run_ios_installed_journey.py \
  --udid 16B71172-52E2-47E6-A951-EFAB9627917B
```

The helper supplies JDK17, the existing Android SDK path (needed by the framework
build), serial XCTest execution and the build-only58095 override; ordinary8080
defaults stay in place. It withholds all raw XCTest output, exports diagnostics
only into an owner-private temporary directory, classifies the known exception
using a fixed nonsecret message, and deletes its xcresult/diagnostics on exit.
Only `xctest-summary.txt` is retained; no raw credential/keypad/browser attachments
or screenshots are published. Its result is explicitly a **Login selector probe**,
never a claim of full CARD success.

Resume after authorizing/fixing the native bridge: exercise actual isolated
Security.framework missing-item/read/write/remove behavior on this simulator,
rebuild and rerun the Login probe, then extend this same XCTest from observed
XCUI elements. All remaining iOS stages are **unverified**: UI unique signup,
strong six-digit PIN, authenticated Home identity, logout/password login, lamp
search/detail/cart, address/CARD chip, zero orders before local confirmation,
native checkout, external Safari Complete payment, canonical CAPTURED/PAID and
exact totals/stock/cart, return link/Open prompt, app paid orders/detail/profile/
devices and logout remaining at Login after relaunch. Use the existing passed
Android test only as behavioral reference. No full-journey scaffolding is
represented as executed coverage.

Date: 2026-10-18. In progress. Target: existing `shopkmp-migrated-e2e`
shared Nginx edge at port 58095; Android emulator-5554 (Pixel_8_API_35)
and iOS 26.2 iPhone 17 Pro `16B71172-52E2-47E6-A951-EFAB9627917B`.

Ruling: execute Task 5 inline in the supplied unborn/untracked repository,
without commits, worktrees or subagents, following the supplied detailed scope
and existing plan authorization. Preserve existing files; final review is a
self-review. Cost: no independent reviewer or Git commit baseline.

Design: platform automation drives the installed common CMP application using
stable commonMain semantics, real signup/password login and native PIN setup.
System browser automation completes the existing secret-bound development CARD
adapter. Independent authenticated canonical API reads verify committed state;
verifier credentials/tokens are never injected into the app. Explicit waits
assert screen/state outcomes. Screenshots are taken only after credential entry
and outside capability-bearing browser pages. No backend changes are planned.

Pre-flight: Task 2/3 client contract and latest CMP remediation report inspected;
Task 4 shared edge responds HTTP 200. Both supplied devices are connected/booted.
Installed Java 17.0.9, SDK at the supplied path, Xcode 26.2 build 17C52 verified.
The UI testing skill's unrelated TestingTask target is replaced by this repo's
new `iosAppUITests` target and `UITestBase`.

### Android continuation — 2026-10-18

Scope: Android only in the supplied workspace; retain this ledger, no new
planning/scaffold, backend/infra changes, commits or subagents. iOS execution is
deferred to the parent task. Existing debug URL override retains default 8080.

RED A: built unchanged app/test with JDK17 and
`./gradlew :composeApp:assembleDebug :composeApp:assembleDebugAndroidTest -PSHOPKMP_DEBUG_SERVER_URL=http://10.0.2.2:58095 --console=plain`
(`BUILD SUCCESSFUL`, 43s). Installed both APKs on emulator-5554, cleared app data,
ran `adb -s emulator-5554 shell am instrument -w -r -e class com.shopkmp.InstalledJourneyTest com.shopkmp.test/androidx.test.runner.AndroidJUnitRunner`.
Observed the intended failure after 30.684s: `Installed Login exposes stable
email selector`; tests 1, failures 1. No selectors existed in auth or Android
resource-ID mapping at this stage.

Public API discovery: `/api/v1/products/search?q=<URL-encoded name>&size=20`
returns persisted `Тестовая лампа`, ID `96be9e50-793e-433f-82eb-689f5927b355`,
price `50.01`, stock 994, active. `/products` ignores search/q (use `/search`).
The installed test will discover product ID/price/stock on each run and search
through the app; no client business-data fixture or mutation via verifier API.

Selector build correction: first build failed at `EpsCheckbox` because its API
had no modifier parameter. Added a defaulted modifier forwarded to the actual
checkbox (stable selector remains actionable), consistent with EpsTextField and
EpsButton. This was a compile error, not an additional observed test RED.

GREEN A: same build/install/clear/instrument command succeeded; original
`realCardJourney` selector assertion passed, `OK (1 test)`, 2.167s.
CommonMain testTags now label Login/Signup/PIN controls, Android root exports
them as resource IDs; CMP iOS consumes common testTags as accessibility IDs
without iOS-source changes. Extending this same installed test to the complete
journey; no duplicated host suite.

Run B1: complete-journey test first stopped before UI with `ConnectException`
to 10.0.2.2:58095. Host edge still returned 200; emulator `dumpsys connectivity`
reported **no default network**, Wi-Fi disconnected. Enabled emulator Wi-Fi/data
and connected saved `AndroidWifi` (`adb shell svc wifi enable`,
`adb shell cmd wifi connect-network AndroidWifi open`, `adb shell svc data enable`;
emulator console `gsm data home`, `gsm voice home`). No app/server change.

RED B2: reran installed test after connection, 51.854s. Actual UI registration,
PIN keypad/setup, authenticated Home SDUI and independent API profile + one PIN
device assertions passed. Failed as expected on missing `nav.profile` selector.
Adding stable destination-derived navigation tags at actual tab items.

GREEN B / RED C: next build/install/clear/instrument run (55.75s) passed
registration/PIN/Home plus profile-tab logout and real UI password login back to
Home; catalog/search action ran. Stopped on missing actionable
`product.<discovered-id>.open` selector. Existing SDUI node tags label wrapper
columns, so added child `.open` tags on actual product/order cards, and
`<field-id>.option.<value>` on actual select chips for explicit CARD selection.
These derive from server node identities, not labels or hardcoded business IDs.

Run C1 (15.912s): product `.open` now worked, real search selected the persisted
lamp and product title matched. Test price assertion failed because it assumed
comma localization, whereas the existing renderer deliberately displays the
exact decimal string (`50.01 ₽`). Corrected the test's expected display contract;
no production money logic changed. No checkout had happened.

Run C2 (13.981s): after search the network response replaced the catalog list
while the helper held its old UiObject2, causing `StaleObjectException` during
scroll. Corrected automation to reacquire the lazy container per gesture, bound
by the existing outcome deadline; stale read/scroll only is retried, never a
mutation. This is test synchronization, not an application behavior regression.

Run C3 (17.439s): catalog search/product exact title/price, UI AddToCart and cart
canonical quantity=1/total passed. Address fields filled through app. CARD chip
was actionable, but automation assumed Android `selected` exclusively; Compose
selectable chips can expose `checked` to Android accessibility. The assertion now
waits for checked **or** selected (and later independently asserts actual order
paymentMethod CARD). Native checkout has not run in this attempt.

GREEN C / browser run D1 (63.449s): CARD platform selection assertion passed.
Actual native confirmation was visible; API had **zero orders before confirm**.
Confirm created exactly one CREATED CARD order: amount matched discovered public
price, one discovered product ×1, delivery address persisted, stock reduced by
one, cart empty. Nonsecret product and native-confirmation screenshots captured.
Browser demo `Complete payment` action found outside app and clicked, but exact
`Payment completed` text timed out. No browser screenshot/hierarchy/URL logged.
Investigating this failure before any retry of its payment action.

Root cause D1 confirmed without any hierarchy dump: temporary diagnostic test
queried only allowlisted label presence in `com.android.chrome`. Page contains
`CORS_ORIGIN_DENIED` and `origin is not allowed`; no completed/return label.
Independent nonsecret public probe `GET /api/v1/categories` with
`Origin: http://10.0.2.2:58095` also returns **403 CORS_ORIGIN_DENIED**.
`backend-yii2/infrastructure/ApiKernel.php:36–39` checks its configured origin
allowlist before the demo GET/POST handler at lines49–55; Chrome's form POST
sends the Android origin whereas earlier command-line demo POST had none.

Ruling: preserve the real failing browser result; do not alter backend/infra or
bypass browser origin enforcement — these are outside the user's Android scope.
Cost: full paid journey cannot be green until the edge's Yii development origin
configuration permits the emulator origin (parent/server task). The repeatable
test now detects this exact error, independently verifies AUTHORIZED/CREATED,
continues installed app order/detail/devices/logout inspection, and fails at the
end rather than hiding the payment failure. No automatic payment retry. Removed
the temporary diagnostics-only test after identifying the cause.

Run D2 (32.261s), with current full test continuation: app flow completed through
Orders/**CREATED**, real order detail, Profile/Devices (visible persisted PIN
device name) and final logout → Login, including another normal launcher entry.
Independent API asserts payment **AUTHORIZED**, order **CREATED**, exactly one
order, exact decimal total and cart/stock/address/product state. Order ID
`e75221ff-5d4b-4fec-b12b-3fa46a594e8a`. Test correctly ended `Tests run: 1,
Failures: 1` with `Full installed journey requires browser payment completion`,
not a false green. Success-only CAPTURED/PAID and gateway-return branches remain
unexecuted. No application business-logic fixes were required.

Affected checks in the same build succeeded:
`:composeApp:assembleDebug :composeApp:assembleDebugAndroidTest
:core:uikit:testDebugUnitTest :core:sdui-renderer:testDebugUnitTest
:feature:main:testDebugUnitTest` with the URL flag. Renderer 2/2 and feature/main
29/29 existing JVM tests, zero errors/failures; UIKit has no JVM tests (NO-SOURCE).
No duplicate host test suite added. XML timestamps 2026-10-18T13:54:58–13:55:01Z.

Final self-review (no subagents per scope): reviewed authored commonMain selectors,
Android root export, full installed test, independent read-only verifier and
secret handling. Token parse errors are now sanitized (org.json can echo input);
PIN input is freshly generated in memory. Added an Android-only rerun helper
which builds/installs/clears/runs the same test, saves its real nonsecret output
and allowlisted app screenshots, and maps runner failure to shell exit1.

Helper run E1 (47.429s): new unrestricted random four-digit PIN input reached
registration but failed to reach Home after PIN submit. The helper correctly
saved runner output and exited1; no checkout or screenshots in this attempt.
Investigating whether generated input violated the real weak-PIN policy before
changing it. Previous D2 checkout/post-failure screen evidence remains in this
ledger; `docs/evidence/android` always represents the latest helper run only.

E1 investigation: server `Rules::pin` rejects common/repeated/paired/sequential
four-digit values, so unrestricted random input is unsuitable. A post-run
allowlist-only UI probe found neither PIN nor Home (instrumentation had already
ended the app process), so the exact E1 error was **not recovered**; weak input
is a hypothesis, not a confirmed diagnosis. Replaced it with fresh six-digit
distinct, nonsequential input satisfying the documented policy without a fixed
credential; added immediate detection of the visible weak-policy error, removed
the temporary probe. Rerunning the actual journey to verify this change.

Helper E2 (30.624s) reached every same milestone as D2 with the six-digit PIN.
Only final failure is the confirmed browser origin blocker; helper exit1 is
correct. Order `169d4c15-282d-49dc-b911-771a28f34cea`, canonical
AUTHORIZED/CREATED. Actual runner output and four app screenshots saved and
visually reviewed; all four contain nonsecret product/order/checkout content.
No PIN failure reproduced; E1's precise cause remains unrecovered as noted.

Self-review correction: the order status assertion originally searched the whole
screen, where filter chips also say CREATED/PAID. Scoped it to the specific
order's actionable card, preventing a filter-label false positive. Home identity
now also checks this generated account's email without logging or capturing it.

### Android handoff — latest verified run E3

Final authored test rebuilt/reinstalled and executed by the helper on
emulator-5554: **31.239s, tests 1, failures 1, helper exit1**. All installed app
milestones passed, including the corrected specific-order status assertion and
generated-account Home identity assertions. Only the final payment requirement
failed with:

```text
Full installed journey requires browser payment completion expected null, but was:<Browser CARD POST rejected: CORS_ORIGIN_DENIED for Origin http://10.0.2.2:58095>
FAILURES!!!
Tests run: 1,  Failures: 1
```

Latest actual order: `3df69e2c-2e5b-43fc-9195-f19c7900e660`, one lamp ×1,
`50.01` total, CARD, AUTHORIZED payment / CREATED order. Native confirmation
preceded order creation; API verified address, exactly one order, empty cart,
stock -1. Both six-digit-PIN runs reached checkout. E1's original precise
PIN-stage timeout cause was not recovered and should be investigated if it
recurs; it is not silently represented as a confirmed policy rejection.

Evidence (latest run, overwritten only by the same helper's next execution):

- [Actual instrumentation output](evidence/android/instrumentation.txt)
- [Product](evidence/android/product.png)
- [Native checkout confirmation](evidence/android/checkout-confirmation.png)
- Orders — CREATED, unpaid: historical capture replaced by the later
  [current paid-order evidence](evidence/android/orders-paid.png).
- [Order detail](evidence/android/order-detail.png)

Implemented selector contract in commonMain: `login.{email,password,submit,signup,pin}`,
`signup.{name,email,phone,password,passwordRepeat,terms,submit,scroll}`,
`pin.{header,submit,secondary,backspace,digit.<n>}`, `nav.{home,catalog,cart,profile,back,refresh}`,
existing SDUI node IDs, plus actionable product/order `<node-id>.open` and
select `<field-id>.option.<value>`. Android root exports testTags as resource
IDs; common testTags are available to the future CMP iOS automation without
new iOS code. Native confirmation uses the platform dialog's visible action.

Exact rerun (from repository root; helper clears test-account app data):

```sh
export ANDROID_HOME=/Users/dmitrijbykov/Library/Android/sdk
export JAVA_HOME=/Users/dmitrijbykov/Library/Java/JavaVirtualMachines/jbr-17.0.9/Contents/Home
python3 scripts/run_android_installed_journey.py --serial emulator-5554
```

The helper runs both `:composeApp:assembleDebug` and
`:composeApp:assembleDebugAndroidTest` with
`-PSHOPKMP_DEBUG_SERVER_URL=http://10.0.2.2:58095`, installs the two debug APKs,
then runs:

```sh
"$ANDROID_HOME/platform-tools/adb" -s emulator-5554 shell am instrument -w -r \
  -e class com.shopkmp.InstalledJourneyTest#realCardJourney \
  com.shopkmp.test/androidx.test.runner.AndroidJUnitRunner
```

Do not treat adb's exit0 as success: the helper checks actual JUnit output and
returns exit1 for this observed failed journey. Defaults for ordinary app builds
remain port8080; test58095 is supplied only by the existing build flag.

**Remaining Android steps:**

1. Parent/server owner must permit the legitimate development browser origin
   `http://10.0.2.2:58095` in the running Yii origin configuration (or provide a
   same-origin demo-form solution). This continuation made no backend/infra
   edits and performed no origin/auth bypass. A nonsecret probe with that Origin
   currently receives 403 CORS_ORIGIN_DENIED even on `/api/v1/categories`.
2. Rerun the command above against the same edge. It will register a fresh UI
   account and create its order; verifier credentials/tokens remain in memory.
3. Obtain actual browser `Payment completed` → canonical CAPTURED/PAID evidence,
   exercise its `Return to ShopKMP` link/possible external-app prompt, then
   confirm the PAID card, details/devices and final Logout → Login. Those
   success-only branches exist in the test but are **not yet execution-proven**.

Task5 remains **incomplete** for Android payment completion; other Android app
journey stages above are execution-proven. iOS remains the parent's next scope.
Self-review only; no independent reviewer or commits.

### iOS security startup milestone — 2026-10-19

Authorized native security repair completed inline. Actual RED reproduced the
HashMap-to-CPointer exception in isolated settings and biometric native tests.
Scoped native CFDictionary bridging, explicit CFBoolean and balanced CFData /
Create/Copy ownership repaired the adapters. Existing service/account names,
accessibility, persisted value format and real biometric/Secure Enclave policy
are preserved. Real Security.framework simulator suites: **settings10/10,
common12/12**; affected Android/common tests **5/5**; final simulator framework
linked. All native fixture namespaces/tags are UUID-generated and isolated.

The installed build additionally exposed real **-34018 missing entitlement**
under the runner's `CODE_SIGNING_ALLOWED=NO`. Added unsigned-simulator-only app
Keychain identity metadata and linked it into the executable rather than Xcode's
Debug dylib. Signed-build identity and screen logic retain their existing paths.
No UI-test or runner edits. Final command:

```sh
python3 scripts/run_ios_installed_journey.py \
  --udid 16B71172-52E2-47E6-A951-EFAB9627917B
```

Actual start **2026-10-19T13:06:24Z**, **11.314s**, **Passed / total1 / passed1 /
failed0**, xcodebuild **0**, helper **PASS installed iOS Login selector probe**.
Sanitized evidence remains `docs/evidence/ios/xctest-summary.txt`; raw diagnostics
are deleted by the unchanged runner. Full iOS CARD journey remains **NOT VERIFIED**.
Physical-device biometric/Secure Enclave success still needs hardware. Details,
intermediate failures, native commands and ownership review:
[IOS_SECURITY_BRIDGE_REPORT.md](IOS_SECURITY_BRIDGE_REPORT.md). No commits/subagents.

### iOS full journey run — 2026-10-19T13:12:47.646457+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:12:47.646457+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Build/test wall time: 166.381s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
Milestone: pin_home_identity_verified
Milestone: password_login_home_verified
Milestone: catalog_product_cart_verified
Milestone: confirmation_zero_orders_verified
Milestone: native_checkout_canonical_verified
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCUI field: login.email nativeType=52
XCUI field: login.password nativeType=52
XCUI field: catalog.q nativeType=52
XCUI field: checkout.city nativeType=52
XCUI field: checkout.street nativeType=52
XCUI field: checkout.house nativeType=52
XCUI field: checkout.apartment nativeType=52
E2E-SELECT: card_type=9 selected=true value_one=false
XCTest result: Failed; total=1; passed=0; failed=1
Failure: safari_payment_completed
xcodebuild exit: 65
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-19T13:16:21.982877+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:16:21.982877+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Build/test wall time: 99.560s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCTest result: Failed; total=1; passed=0; failed=1
Failure: visible_home.title
xcodebuild exit: 65
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-19T13:19:52.902640+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:19:52.902640+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Build/test wall time: 9.102s
E2E-BROWSER: Payment_completed exists=false type=0
E2E-BROWSER: CORS_ORIGIN_DENIED exists=false type=0
E2E-BROWSER: Payment_failed exists=false type=0
E2E-BROWSER: Return_to_ShopKMP exists=false type=0
E2E-BROWSER: Complete_payment exists=true type=9
E2E-PIN: PIN_already_set exists=false
E2E-PIN: PIN_must_not_be_common exists=false
E2E-PIN: PIN_must_contain exists=false
E2E-PIN: deviceName exists=false
XCTest result: Passed; total=1; passed=1; failed=0
xcodebuild exit: 0
FAIL installed iOS full CARD journey
```

iOS continuation diagnosis: the first full run executed through native checkout
and stopped after its one Safari Complete payment tap. Read-only XCUI inspection
of that browser page still finds Complete payment, not completed/return/CORS labels.
No payment retry was made. Next full run will record canonical payment/order state
on a browser timeout. A subsequent new-account run stopped at PIN; its exact UI
error was not retained. Schema inspection confirms `device_pins.device_id` is
globally UNIQUE, while the runner had preserved the prior installation's UUID.
The helper now uninstalls **only com.shopkmp.ios** before building/installing each
full run, giving the app a fresh normal installation UUID. Keychain is preserved;
any retained account session is logged out through UI. No simulator erase, UUID
injection, server cleanup or commonMain behavior change is involved. Temporary
read-only diagnostic test is not full-journey coverage and will be removed.

### iOS full journey run — 2026-10-19T13:21:10.674200+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:21:10.674200+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Build/test wall time: 106.572s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
E2E-PIN: PIN_already_set exists=true
E2E-PIN: PIN_must_not_be_common exists=false
E2E-PIN: PIN_must_contain exists=false
E2E-PIN: deviceName exists=false
E2E-PIN: persisted_count=0
XCTest result: Failed; total=1; passed=0; failed=1
Failure: pin_home_transition
xcodebuild exit: 65
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-19T13:24:36.410430+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:24:36.410430+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 176.422s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
Milestone: pin_home_identity_verified
Milestone: password_login_home_verified
Milestone: catalog_product_cart_verified
Milestone: confirmation_zero_orders_verified
Milestone: native_checkout_canonical_verified
Milestone: safari_captured_paid_verified
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCUI field: login.email nativeType=52
XCUI field: login.password nativeType=52
XCUI field: catalog.q nativeType=52
XCUI field: checkout.city nativeType=52
XCUI field: checkout.street nativeType=52
XCUI field: checkout.house nativeType=52
XCUI field: checkout.apartment nativeType=52
E2E-SELECT: card_type=9 selected=true value_one=false
XCTest result: Failed; total=1; passed=0; failed=1
Failure: return_link_app_foreground
xcodebuild exit: 65
Allowlisted attachment name: e2e-checkout-confirmation_0_1DD9B601-4A50-4EB8-86B9-6C03153B237B.png
Screenshot: docs/evidence/ios/checkout-confirmation.png
Allowlisted attachment name: e2e-product_0_86099F45-B05B-41F7-B984-58B11EDE8757.png
Screenshot: docs/evidence/ios/product.png
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-19T13:28:09.729758+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:28:09.729758+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Build/test wall time: 10.300s
E2E-BROWSER: Payment_completed exists=true type=1
E2E-BROWSER: CORS_ORIGIN_DENIED exists=false type=0
E2E-BROWSER: Payment_failed exists=false type=0
E2E-BROWSER: Return_to_ShopKMP exists=true type=42
E2E-BROWSER: Complete_payment exists=false type=0
E2E-PIN: PIN_already_set exists=false
E2E-PIN: PIN_must_not_be_common exists=false
E2E-PIN: PIN_must_contain exists=false
E2E-PIN: deviceName exists=false
E2E-PROMPT: Open exists=false hittable=false
E2E-PROMPT: Открыть exists=true hittable=true
E2E-PROMPT: Send exists=false hittable=false
E2E-PROMPT: Отправить exists=false hittable=false
E2E-PROMPT: Cancel exists=false hittable=false
E2E-PROMPT: Отменить exists=true hittable=true
E2E-PROMPT: Открыть exists=false hittable=false
E2E-PROMPT: Отменить exists=false hittable=false
XCTest result: Passed; total=1; passed=1; failed=0
xcodebuild exit: 0
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-19T13:29:42.080440+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:29:42.080440+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 208.928s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
Milestone: pin_home_identity_verified
Milestone: password_login_home_verified
Milestone: catalog_product_cart_verified
Milestone: confirmation_zero_orders_verified
Milestone: native_checkout_canonical_verified
Milestone: safari_captured_paid_verified
Milestone: native_open_prompt_handled
Milestone: paid_order_details_devices_verified
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCUI field: login.email nativeType=52
XCUI field: login.password nativeType=52
XCUI field: catalog.q nativeType=52
XCUI field: checkout.city nativeType=52
XCUI field: checkout.street nativeType=52
XCUI field: checkout.house nativeType=52
XCUI field: checkout.apartment nativeType=52
E2E-SELECT: card_type=9 selected=true value_one=false
XCTest result: Failed; total=1; passed=0; failed=1
Failure: visible_login.email
xcodebuild exit: 65
Screenshot: docs/evidence/ios/order-detail.png
Screenshot: docs/evidence/ios/orders-paid.png
Screenshot: docs/evidence/ios/checkout-confirmation.png
Screenshot: docs/evidence/ios/product.png
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-19T13:37:44.753403+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:37:44.753403+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 259.653s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
Milestone: pin_home_identity_verified
Milestone: password_login_home_verified
Milestone: catalog_product_cart_verified
Milestone: confirmation_zero_orders_verified
Milestone: native_checkout_canonical_verified
Milestone: safari_captured_paid_verified
Milestone: native_open_prompt_handled
Milestone: paid_order_details_devices_verified
Milestone: logout_relaunch_login_verified
Milestone: full_journey_pass
Milestone: logout_ui_login_verified
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCUI field: login.email nativeType=52
XCUI field: login.password nativeType=52
XCUI field: catalog.q nativeType=52
XCUI field: checkout.city nativeType=52
XCUI field: checkout.street nativeType=52
XCUI field: checkout.house nativeType=52
XCUI field: checkout.apartment nativeType=52
E2E-SELECT: card_type=9 selected=true value_one=false
E2E-ORDER: e11c0a30-ad0c-4162-acf4-edabb3a3526b product=96be9e50-793e-433f-82eb-689f5927b355 total=50.01 payment=CAPTURED order=PAID
XCTest result: Passed; total=1; passed=1; failed=0
xcodebuild exit: 0
Screenshot: docs/evidence/ios/order-detail.png
Screenshot: docs/evidence/ios/orders-paid.png
Screenshot: docs/evidence/ios/checkout-confirmation.png
Screenshot: docs/evidence/ios/product.png
PASS installed iOS full CARD journey
```

### iOS full journey run — 2026-10-19T13:43:59.163074+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-19T13:43:59.163074+00:00
Device: 16B71172-52E2-47E6-A951-EFAB9627917B
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 164.212s
XCTest test duration: 156.525s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
Milestone: pin_home_identity_verified
Milestone: password_login_home_verified
Milestone: catalog_product_cart_verified
Milestone: confirmation_zero_orders_verified
Milestone: native_checkout_canonical_verified
Milestone: safari_captured_paid_verified
Milestone: native_open_prompt_handled
Milestone: paid_order_details_devices_verified
Milestone: logout_ui_login_verified
Milestone: logout_relaunch_login_verified
Milestone: full_journey_pass
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCUI field: login.email nativeType=52
XCUI field: login.password nativeType=52
XCUI field: catalog.q nativeType=52
XCUI field: checkout.city nativeType=52
XCUI field: checkout.street nativeType=52
XCUI field: checkout.house nativeType=52
XCUI field: checkout.apartment nativeType=52
E2E-SELECT: card_type=9 selected=true value_one=false
E2E-ORDER: b819b898-830f-445f-ad00-b27b86de06c4 product=96be9e50-793e-433f-82eb-689f5927b355 total=50.01 payment=CAPTURED order=PAID
XCTest result: Passed; total=1; passed=1; failed=0
xcodebuild exit: 0
Screenshot: docs/evidence/ios/order-detail.png
Screenshot: docs/evidence/ios/orders-paid.png
Screenshot: docs/evidence/ios/checkout-confirmation.png
Screenshot: docs/evidence/ios/product.png
PASS installed iOS full CARD journey
```

### iOS full journey run — 2026-10-27T07:46:22.579727+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-27T07:46:22.579727+00:00
Device: 2E4889AB-D521-4B8C-A796-AD80A7536D00
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 103.692s
XCTest test duration: 57.811s
Milestone: public_product_discovered
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCTest result: Failed; total=1; passed=0; failed=1
Failure: scroll_signup.password
xcodebuild exit: 65
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-27T08:27:06.862879+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-27T08:27:06.862879+00:00
Device: 2E4889AB-D521-4B8C-A796-AD80A7536D00
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 102.864s
XCTest test duration: 69.080s
Milestone: public_product_discovered
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCTest result: Failed; total=1; passed=0; failed=1
Failure: enabled_signup.submit
xcodebuild exit: 65
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-27T08:34:40.761869+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-27T08:34:40.761869+00:00
Device: 2E4889AB-D521-4B8C-A796-AD80A7536D00
Evidence run: 20261027T083440761261Z
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 78.978s
XCTest test duration: 71.663s
Milestone: public_product_discovered
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
E2E-FIELD: signup.name stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=after_type valueMatches=false lengthMatches=false masked=false exists=true
E2E-FIELD: signup.passwordRepeat stage=after_type valueMatches=false lengthMatches=false masked=true exists=true
E2E-FIELD: signup.name stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=before_terms valueMatches=false lengthMatches=false masked=false exists=true
E2E-FIELD: signup.passwordRepeat stage=before_terms valueMatches=false lengthMatches=false masked=true exists=true
E2E-FIELD: signup.name stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=after_terms valueMatches=false lengthMatches=false masked=false exists=true
E2E-FIELD: signup.passwordRepeat stage=after_terms valueMatches=false lengthMatches=false masked=true exists=true
E2E-TERMS: stage=before exists=true selected=false valueChecked=false submitEnabled=false
E2E-TERMS: stage=after exists=true selected=true valueChecked=false submitEnabled=false
E2E-FRAME: signup.terms stage=before_terms target=16.0,563.3,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=before_tap target=16.0,563.3,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=after_terms target=16.0,563.3,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
XCTest result: Failed; total=1; passed=0; failed=1
Failure: enabled_signup.submit
xcodebuild exit: 65
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-27T08:37:33.537730+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-27T08:37:33.537730+00:00
Device: 2E4889AB-D521-4B8C-A796-AD80A7536D00
Evidence run: 20261027T083733537316Z
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 79.547s
XCTest test duration: 73.224s
Milestone: public_product_discovered
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
E2E-FIELD: signup.name stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=after_type valueMatches=false lengthMatches=false masked=false exists=true
E2E-FIELD: signup.passwordRepeat stage=after_type valueMatches=false lengthMatches=false masked=true exists=true
E2E-FIELD: signup.name stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=before_terms valueMatches=false lengthMatches=false masked=false exists=true
E2E-FIELD: signup.passwordRepeat stage=before_terms valueMatches=false lengthMatches=false masked=true exists=true
E2E-FIELD: signup.name stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=after_terms valueMatches=false lengthMatches=false masked=false exists=true
E2E-FIELD: signup.passwordRepeat stage=after_terms valueMatches=false lengthMatches=false masked=true exists=true
E2E-SECURE: signup.password revealAvailable=false
E2E-SECURE: signup.passwordRepeat revealAvailable=false
E2E-TERMS: stage=before exists=true selected=false valueChecked=false submitEnabled=false
E2E-TERMS: stage=after exists=true selected=true valueChecked=false submitEnabled=false
E2E-FRAME: signup.name stage=before_tap target=16.0,162.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.email stage=before_tap target=16.0,242.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.phone stage=before_tap target=16.0,322.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.password stage=before_tap target=16.0,83.3,370.0,49.3 viewport=0.0,126.0,402.0,166.0 centerInside=false frameInside=false hittable=true keyboard=true
E2E-FRAME: signup.passwordRepeat stage=before_tap target=16.0,483.3,370.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=before_terms target=16.0,563.3,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=before_tap target=16.0,563.3,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=after_terms target=16.0,563.3,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
XCTest result: Failed; total=1; passed=0; failed=1
Failure: enabled_signup.submit
xcodebuild exit: 65
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-27T08:40:55.797731+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-27T08:40:55.797731+00:00
Device: 2E4889AB-D521-4B8C-A796-AD80A7536D00
Evidence run: 20261027T084055797270Z
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 232.439s
XCTest test duration: 225.642s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
Milestone: pin_home_identity_verified
Milestone: password_login_home_verified
Milestone: catalog_product_cart_verified
Milestone: confirmation_zero_orders_verified
Milestone: native_checkout_canonical_verified
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCUI field: login.email nativeType=52
XCUI field: login.password nativeType=52
XCUI field: catalog.q nativeType=52
XCUI field: checkout.city nativeType=52
XCUI field: checkout.street nativeType=52
XCUI field: checkout.house nativeType=52
XCUI field: checkout.apartment nativeType=52
E2E-FIELD: signup.name stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=after_type valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.passwordRepeat stage=after_type valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.name stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=before_terms valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.passwordRepeat stage=before_terms valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.name stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=after_terms valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.passwordRepeat stage=after_terms valueMatches=false lengthMatches=true masked=true exists=true
E2E-TERMS: stage=before exists=true selected=false valueChecked=false submitEnabled=false
E2E-TERMS: stage=after exists=true selected=true valueChecked=false submitEnabled=true
E2E-FRAME: signup.name stage=before_tap target=16.0,162.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.email stage=before_tap target=16.0,242.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.phone stage=before_tap target=16.0,322.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.password stage=before_tap target=16.0,228.0,370.0,49.3 viewport=0.0,126.0,402.0,166.0 centerInside=true frameInside=true hittable=true keyboard=true
E2E-FRAME: signup.passwordRepeat stage=before_tap target=16.0,482.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=before_terms target=16.0,542.0,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=before_tap target=16.0,542.0,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=after_terms target=16.0,542.0,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.submit stage=before_tap target=16.0,606.0,370.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-SELECT: card_type=9 selected=true value_one=false
E2E-BROWSER: Payment_completed exists=false type=0
E2E-BROWSER: CORS_ORIGIN_DENIED exists=false type=0
E2E-BROWSER: Payment_failed exists=false type=0
E2E-BROWSER: Return_to_ShopKMP exists=false type=0
E2E-BROWSER: Complete_payment exists=true type=9
E2E-PAYMENT: payment=AUTHORIZED order=CREATED
XCTest result: Failed; total=1; passed=0; failed=1
Failure: safari_payment_completed
xcodebuild exit: 65
Screenshot: docs/evidence/ios/checkout-confirmation.png
Screenshot: docs/evidence/ios/product.png
FAIL installed iOS full CARD journey
```

### iOS full journey run — 2026-10-27T08:46:34.054937+00:00

Started actual xcodebuild installed test. Resume: `python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196`. Result pending; do not infer PASS if interrupted.

```text
Started UTC: 2026-10-27T08:46:34.054937+00:00
Device: 2E4889AB-D521-4B8C-A796-AD80A7536D00
Evidence run: 20261027T084634054255Z
Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved
Build/test wall time: 243.695s
XCTest test duration: 236.514s
Milestone: public_product_discovered
Milestone: registered_native_pin_visible
Milestone: pin_home_identity_verified
Milestone: password_login_home_verified
Milestone: catalog_product_cart_verified
Milestone: confirmation_zero_orders_verified
Milestone: native_checkout_canonical_verified
Milestone: safari_captured_paid_verified
Milestone: native_open_prompt_handled
Milestone: paid_order_details_devices_verified
Milestone: logout_ui_login_verified
Milestone: logout_relaunch_login_verified
Milestone: full_journey_pass
XCUI field: signup.name nativeType=52
XCUI field: signup.email nativeType=52
XCUI field: signup.phone nativeType=52
XCUI field: signup.password nativeType=52
XCUI field: signup.passwordRepeat nativeType=52
XCUI field: login.email nativeType=52
XCUI field: login.password nativeType=52
XCUI field: catalog.q nativeType=52
XCUI field: checkout.city nativeType=52
XCUI field: checkout.street nativeType=52
XCUI field: checkout.house nativeType=52
XCUI field: checkout.apartment nativeType=52
E2E-FIELD: signup.name stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=after_type valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=after_type valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.passwordRepeat stage=after_type valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.name stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=before_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=before_terms valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.passwordRepeat stage=before_terms valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.name stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.email stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.phone stage=after_terms valueMatches=true lengthMatches=true masked=false exists=true
E2E-FIELD: signup.password stage=after_terms valueMatches=false lengthMatches=true masked=true exists=true
E2E-FIELD: signup.passwordRepeat stage=after_terms valueMatches=false lengthMatches=true masked=true exists=true
E2E-TERMS: stage=before exists=true selected=false valueChecked=false submitEnabled=false
E2E-TERMS: stage=after exists=true selected=true valueChecked=false submitEnabled=true
E2E-FRAME: signup.name stage=before_tap target=16.0,162.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.email stage=before_tap target=16.0,242.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.phone stage=before_tap target=16.0,322.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.password stage=before_tap target=16.0,228.0,370.0,49.3 viewport=0.0,126.0,402.0,166.0 centerInside=true frameInside=true hittable=true keyboard=true
E2E-FRAME: signup.passwordRepeat stage=before_tap target=16.0,482.0,370.0,49.3 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=before_terms target=16.0,542.0,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=before_tap target=16.0,542.0,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.terms stage=after_terms target=16.0,542.0,48.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-FRAME: signup.submit stage=before_tap target=16.0,606.0,370.0,48.0 viewport=0.0,126.0,402.0,714.0 centerInside=true frameInside=true hittable=true keyboard=false
E2E-PAYMENT-FRAME: target=3.0,109.0,51.0,10.0 viewport=0.0,0.0,402.0,874.0 centerInside=true frameInside=true hittable=true enabled=true
E2E-SELECT: card_type=9 selected=true value_one=false
E2E-ORDER: a1acc888-eb33-4cfb-93d1-6bf8483fbe25 product=6c35a708-047f-433a-951d-b306fda833b3 total=50.01 payment=CAPTURED order=PAID
XCTest result: Passed; total=1; passed=1; failed=0
xcodebuild exit: 0
Screenshot: docs/evidence/ios/order-detail.png
Screenshot: docs/evidence/ios/orders-paid.png
Screenshot: docs/evidence/ios/checkout-confirmation.png
Screenshot: docs/evidence/ios/product.png
PASS installed iOS full CARD journey
```
