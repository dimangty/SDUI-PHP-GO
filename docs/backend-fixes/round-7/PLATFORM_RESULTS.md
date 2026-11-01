# Round 7 — current unchanged client/platform checks

**Later execution update:** the statuses below are the original 05:52–06:01 UTC
checkpoint. The subsequent Safari test actually failed as recorded in TEST_RESULTS;
the unchanged Android installed journey also actually ran and failed as recorded
in [ANDROID_INSTALLED_RESULTS.md](ANDROID_INSTALLED_RESULTS.md). Android's UI payment
completed CAPTURED/PAID; the failure is its authenticated verifier read after global
logout. Neither installed journey is PASS. Original commands/results are retained.

**2026-11-01, 05:52–06:01 UTC. Platform release gates remain BLOCKED.**

Unit tests, coverage, Detekt, Android debug assembly, the iOS auth/cart suites,
framework linking, and native app/UI-test compilation passed. The required iOS
common suite failed reproducibly. Android release assembly failed on missing
configuration. Installed native CARD journeys are **NOT RUN**. These are separate
release blockers; backend fixes or immutable CMP sources do not waive them.

## Evidence and unchanged source

All commands ran from `/Users/dmitrijbykov/Documents/GoLang/SDUI`. Define:

```sh
E=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode/round7-platform-20261101
UDID=8D6B490D-89CB-4D2E-A24D-A48C0A8E0F73
export JAVA_HOME=/Users/dmitrijbykov/.gradle/jdks/eclipse_adoptium-21-aarch64-os_x.2/jdk-21.0.11+10/Contents/Home
export ANDROID_HOME=/Users/dmitrijbykov/Library/Android/sdk
export PATH="$JAVA_HOME/bin:$PATH"
```

`$E/run.py` executes the recorded command unchanged with those toolchain overrides,
captures stdout/stderr, and writes `<label>.json` containing exact argv, UTC start/
finish, elapsed time, exit code and log path. `<label>.log` is the complete output.
`$E/summary.json` records JUnit counts, timestamps, failure messages, per-report
hashes, log hashes and built-artifact hashes. No historical test result is counted.

- HEAD throughout: `160d2a5a535791c75c36c2f70a90515153339e87`.
- `client-before.json` / `client-after.json`: **393 tracked client/build files**,
  identical SHA-256 source map:
  `2a4e395e1869333d071eb9d4eedd156d38ad850bf804dda6c962f59f3e7c80d0`.
- `git diff --exit-code -- core feature composeApp iosApp gradle build-logic
  build.gradle.kts settings.gradle.kts gradle.properties gradlew gradlew.bat config`:
  **exit 0**, `client-diff.log`.
- This task's sole repository source addition is this report. Generated build/test
  outputs and private evidence are test resources. Existing concurrent backend work
  was preserved. No commit, push or subagent was used.

## Actual host prerequisites

| Command | Exit | Current observation |
| --- | ---: | --- |
| `java -version` before overrides | 0 | Default JBR **17.0.9**, x86_64 |
| `/usr/libexec/java_home -V` | 0 | Registered JDKs are 17/14/8; a separate cached JDK 21 exists |
| `"$JAVA_HOME/bin/java" -version` | 0 | Actual selected **Temurin 21.0.11+10-LTS**, arm64 |
| `./gradlew --version` | 0 | **Gradle 8.14.3**, launcher/daemon **21.0.11**, macOS **26.1 aarch64**; `gradle-version.log` |
| `"$ANDROID_HOME/cmdline-tools/latest/bin/sdkmanager" --list_installed` | 0 | Actual SDK contains **platform android-36 rev 2**, build-tools **35.0.0**, platform-tools **35.0.1**, emulator **36.3.10** and ARM64 API 34/35 images; `android-sdk.log` |
| `"$ANDROID_HOME/platform-tools/adb" version` | 0 | ADB **1.0.41 / 35.0.1-11580240** |
| `"$ANDROID_HOME/platform-tools/adb" devices -l` | 0 | **No attached devices/emulators**; `android-devices.log` |
| `"$ANDROID_HOME/emulator/emulator" -list-avds` | 0 | Existing AVD `Pixel_8_API_35`; not booted by this task |
| `xcodebuild -version` | 0 | **Xcode 26.2 / 17C52**; `xcode-version.log` |
| `xcrun --sdk iphonesimulator --show-sdk-version` | 0 | SDK **26.2** |
| `xcrun swift --version` | 0 | **Swift 6.2.3**, arm64 Apple target |
| `xcrun simctl list runtimes -j` | 0 | Available **iOS 26.2 / 23C54**, arm64 |

The SDK tool emitted an XML-version compatibility warning, but SDK discovery and
the Android checks below completed successfully. Project dependencies remain the
existing Kotlin **2.3.10**, Compose Multiplatform **1.10.3**, AGP **8.11.2** and Kover
**0.9.8**; the wrapper's embedded Kotlin version is not the project compiler version.

## Executed gate ledger

| Command | UTC interval | Exit | Actual result / evidence label |
| --- | --- | ---: | --- |
| `make frontend-unit` | 05:52:42–05:53:27 | **0** | **193 tests**, 39 suites / 23 modules, zero failures/errors/skips; `frontend-unit` |
| `make frontend-test` | 05:53:35–05:54:06 | **0** | Root **70% minimum line-coverage verification passed**; also **185 release-variant unit tests**, 37 suites / 21 modules, zero failures/errors/skips; `frontend-test` |
| `make frontend-lint` | 05:54:12–05:54:26 | **0** | `detektMetadataCommonMain detektAndroidDebug`, zero reported code smells; `frontend-lint` |
| `make frontend-build` | 05:54:36–05:55:04 | **0** | `:composeApp:assembleDebug` packaged APK; `frontend-build` |
| `frontend-ios` task set, explicitly device-pinned below | 05:56:43–05:58:30 | **1** | Common suite **12 tests / 1 failure**; auth **23/23 passed**; framework initially up-to-date; cart test left unexecuted by fail-fast; `frontend-ios` |
| `./gradlew :composeApp:assembleRelease --no-daemon` | 05:58:45–05:58:52 | **1** | Missing `SHOPKMP_RELEASE_SERVER_URL`; `android-release` |
| Remaining iOS cart test + forced framework link below | 05:59:12–05:59:38 | **0** | Cart **2/2 passed**; framework link actually executed; `ios-remaining` |
| Forced common-suite recheck below | 05:59:48–05:59:59 | **1** | Same **12 tests / 1 failure**, identical failing assertion; `ios-common-recheck` |
| Native `xcodebuild build-for-testing` below | 06:00:07–06:00:30 | **0** | **TEST BUILD SUCCEEDED**, including current `InstalledJourneyTests.swift`; `ios-native-build` |

The Make recipes executed exactly:

```sh
./gradlew testDebugUnitTest --no-daemon
./gradlew testDebugUnitTest koverVerify --no-daemon
./gradlew detektMetadataCommonMain detektAndroidDebug --no-daemon
./gradlew :composeApp:assembleDebug --no-daemon
```

All 23 nonempty debug unit-test tasks executed in the first invocation; dependency
compilation/resources could use cache. The coverage invocation reused those debug
results, executed its release-variant tests and `:koverVerify`. No exclusions,
coverage thresholds, assertions or build scripts were changed.

### Owned iOS simulator and exact task commands

These commands all exited **0**:

```sh
xcrun simctl create shopkmp-round7-platform-ios-72f19a41 \
  com.apple.CoreSimulator.SimDeviceType.iPhone-17-Pro \
  com.apple.CoreSimulator.SimRuntime.iOS-26-2
xcrun simctl boot "$UDID"
xcrun simctl bootstatus "$UDID" -b
./gradlew help --task :core:common:iosSimulatorArm64Test --no-daemon
```

The last command confirms the existing `--device` option. The iOS Make recipe's
four tasks were expanded directly, adding only device selection for each test:

```sh
./gradlew \
  :core:common:iosSimulatorArm64Test --device "$UDID" \
  :feature:auth:auth-ui:iosSimulatorArm64Test --device "$UDID" \
  :feature:cart:cart-ui:iosSimulatorArm64Test --device "$UDID" \
  :composeApp:linkDebugFrameworkIosSimulatorArm64 --no-daemon
# exit 1

./gradlew :feature:cart:cart-ui:iosSimulatorArm64Test --device "$UDID" \
  :composeApp:linkDebugFrameworkIosSimulatorArm64 --rerun --no-daemon
# exit 0

./gradlew :core:common:iosSimulatorArm64Test --device "$UDID" --rerun --no-daemon
# exit 1

xcodebuild build-for-testing -project iosApp/iosApp.xcodeproj -scheme iosApp \
  -configuration Debug -destination "platform=iOS Simulator,id=$UDID" \
  -derivedDataPath "$E/ios-derived" -parallel-testing-enabled NO \
  CODE_SIGNING_ALLOWED=NO -hideShellScriptEnvironment
# exit 0; build only, no installed journey execution
```

**Exact common-suite failure:**
`com.shopkmp.common.biometric.BiometricNativeDictionaryIosTest.lookupKeepsNativeTagAndAuthenticationContextAlive[iosSimulatorArm64]`,
at `core/common/src/iosTest/kotlin/com/shopkmp/common/biometric/BiometricNativeDictionaryIosTest.kt:74`.
`SecItemDelete(deletion)` returned **-25291** instead of expected **-25300**.
`xcrun security error -25291` exited **0** and decoded it as **“No keychain is
available.”** This is a current native keychain gate failure, not a backend HTTP
failure. The deeper reason for unavailable keychain service was not established.

Reports are in `core/common/build/reports/tests/iosSimulatorArm64Test/index.html`
and `core/common/build/test-results/iosSimulatorArm64Test/TEST-com.shopkmp.common.biometric.BiometricNativeDictionaryIosTest.xml`.
Auth/cart results are under their respective `build/test-results/iosSimulatorArm64Test/`.
The successful build-only check does not satisfy this failed test gate.

## Current native Safari/CARD gate: NOT RUN

The actual current source was inspected:
`iosApp/iosAppUITests/InstalledJourneyTests.swift`,
`InstalledJourneyTests/testRealCardJourney`; SHA-256
`6e53a08f4d6e8674dd7b4a662689110efe5d78d8fd4d732e6c7b81d587d1668d`.

- It registers a fresh synthetic account through the installed UI, selects CARD,
  verifies canonical checkout, waits for external Safari, taps the visible
  **Complete payment** button exactly once, and requires **Payment completed**,
  CAPTURED/PAID state, return-to-app, order details and logout/relaunch.
- Its independent `JourneyVerifier` permits business **GET** requests and an
  independent `/auth/login` POST. It does not perform a hidden payment POST.
- `JOURNEY_BACKEND_URL` must point to the same owned disposable backend used by
  the app. This test mutates commerce state through the UI, so a healthy, tested
  demo backend is a prerequisite. No real-payment environment was used.

Read-only coordination command, **exit 0**:

```sh
docker ps -a --filter label=com.docker.compose.project=shopkmp-round7-acceptance-e2e \
  --format '{{.Names}}\t{{.Status}}\t{{.Image}}'
```

At initial inspection the full acceptance project was stopped. At **06:01:21Z**,
`acceptance-final.log` showed **yii2, yii2-http, sdui, edge and OpenSearch still
exited**; PostgreSQL and the payment-provider fixture had become healthy during
other work. This partial environment is not a stable tested application backend.
This task issued only Docker inspection commands and did not start, stop, rebuild,
reconfigure or test against that stack. No separate tested backend was available
to this task, so no `xcodebuild test`/`test-without-building` command was issued.

The earlier round-6 runner was read only to identify command syntax. Its backend,
credentials, existing simulator and runtime artifacts were not reused. The current
Swift test compiled successfully in the fresh build above. **No current Safari
pass/failure or F3 causal explanation can be inferred from compilation, backend
payment tests or historical browser results.** This required gate remains blocking.

The Android installed `InstalledJourneyTest.realCardJourney` is also **NOT RUN**:
current `adb devices -l` is empty and the same stable disposable-backend prerequisite
is unavailable. No foreign AVD was started. Neither installed-platform journey has
been satisfied by the JVM or Compose simulator suites.

## Artifacts and handoff

| Current artifact | SHA-256 |
| --- | --- |
| `composeApp/build/outputs/apk/debug/composeApp-debug.apk` | `a769c11fbb106908b232e6b3e1e62ec4ee596024507cfa660ab8aa9617879c68` |
| `composeApp/build/bin/iosSimulatorArm64/debugFramework/ComposeApp.framework/ComposeApp` | `7d3894a75ad71a3b44c869617fc0d181fb0fca4ca862208a428e7996722a50d8` |
| `$E/ios-derived/Build/Products/Debug-iphonesimulator/iosApp.app/iosApp` | `369f5b2f261b2a83d74f78be6252b43d5b48670cb428926a5658d9bd98f5f229` |
| `$E/ios-derived/Build/Products/Debug-iphonesimulator/iosAppUITests-Runner.app/PlugIns/iosAppUITests.xctest/iosAppUITests` | `fffec24c1cd0100808e5ea2789eb54e3bdf48c552a4259d7e6c092a0fd252e83` |
| `$E/ios-derived/Build/Products/iosApp_iphonesimulator26.2-arm64.xctestrun` | `24ecd3b998061d6d9c27c589ec21529910768ba32c9583da65ca1dfbc995706f` |

`xcrun simctl shutdown "$UDID"` exited **0** at **06:01:30Z**. Final `simctl`
inspection confirms the owned simulator is **Shutdown**; it is retained with the
private build artifacts. No lifecycle command targeted any preexisting simulator,
emulator or container.

Release follow-through requires **all** of:

1. A genuine passing unchanged iOS common test gate; the observed keychain failure
   remains unresolved under the immutable-client constraint.
2. Configured `SHOPKMP_RELEASE_SERVER_URL` and a successful Android release assembly;
   no substitute URL was injected to manufacture a pass.
3. Current unchanged iOS Safari/CARD and Android installed journeys against an
   owned, stable, source-bound tested demo backend, with fresh synthetic identities
   and owned devices. The F1/backend coordinator must first finish its acceptance
   work before any shared-stack use is considered.

These platform blockers stand independently of the separately reported backend
functional/security gates. **No platform release approval is issued.**
