# Round 7 — unchanged Android installed CARD journey

**2026-11-01: actually executed, FAIL. One test, one failure, zero skips.**
Payment completed through Chrome and reached canonical **CAPTURED / PAID**. The
failure is the independent verifier's authenticated order read after final logout:
expected HTTP **200**, received **401**. Gradle and adb process success do not
make this a journey PASS.

**Resource deviation:** `-memory 1536` was requested, but emulator 36.3.10 raised
guest RAM to **2048 MiB**, exceeding the requested 1024–1536 MiB bound. This was
discovered in the post-run hardware audit. The emulator is stopped. This execution
does not establish acceptance within the requested memory limit.

## Current source/backend binding

Read before execution: `PLATFORM_RESULTS.md`, the authoritative current 503-input
section of `TEST_RESULTS.md`, `FINAL_FIXES.md`, the unchanged instrumentation test,
and `scripts/run_android_installed_journey.py` for syntax/fixed-safe extraction.

Private evidence definitions:

```sh
T=/var/folders/d7/k1tf674x10sfsc44ztv9sb900000gp/T/opencode
D="$T/round7-android-installed-oA2I1ACh"
E="$D/attempt-3"
P=shopkmp-round7-acceptance-e2e
R="$T/round7-final-fixes/artifacts.json"
```

- HEAD: `160d2a5a535791c75c36c2f70a90515153339e87`.
- Receipt: **`tested:true`**, build/test exits **0/0**, **503** source inputs;
  sorted compact-JSON source-map SHA-256
  **`023f7403f3dbc97165e3a553eef55ab43473d107a249b1bf815ecb8328769bf3`**.
- Before any start, `verify_receipt(..., require_tested=True)` matched current
  sources and all **12** stopped, existing owned container/image bindings.
  Only the retained exact containers were resumed with `compose start --wait`.
  Healthy-runtime inventory and the same receipt check passed before the Android
  setup, immediately before instrumentation, and after instrumentation.
- Final stopped-state verification again matched all 503 inputs, all 12 image
  bindings and the original container IDs. Accepted runtime images were neither
  rebuilt nor pulled. The existing explicit environment was loaded from
  `$T/round7-verification/environment.json`; resolved credentials/config were kept
  private.

## Device, build and actual commands

Selected Temurin **21.0.11+10-LTS** from the cached JDK recorded in the platform
report, SDK `/Users/dmitrijbykov/Library/Android/sdk`, emulator **36.3.10**, and
installed **API 34 Google APIs ARM64**, revision **13**.

The new owned AVD was **`shopkmp-round7-android-a4aea4b4`**, serial
**`emulator-5580`**. Its homes are under `$E/{avd,android-user,emulator-home}`.
Chrome was installed and resolved HTTP VIEW intents. The effective hardware file
confirms **two cores**, `swiftshader_indirect`, and the **2048 MiB RAM deviation**
above. The existing `Pixel_8_API_35` AVD was not managed.

```sh
export JAVA_HOME=/Users/dmitrijbykov/.gradle/jdks/eclipse_adoptium-21-aarch64-os_x.2/jdk-21.0.11+10/Contents/Home
export ANDROID_HOME=/Users/dmitrijbykov/Library/Android/sdk
export PATH="$JAVA_HOME/bin:$PATH"
export ANDROID_AVD_HOME="$E/avd"
export ANDROID_USER_HOME="$E/android-user"
export ANDROID_EMULATOR_HOME="$E/emulator-home"

"$ANDROID_HOME/cmdline-tools/latest/bin/avdmanager" create avd \
  -n shopkmp-round7-android-a4aea4b4 \
  -k 'system-images;android-34;google_apis;arm64-v8a' -d pixel_6
# stdin: no; exit 0

"$ANDROID_HOME/emulator/emulator" -avd shopkmp-round7-android-a4aea4b4 \
  -port 5580 -memory 1536 -cores 2 -gpu swiftshader_indirect \
  -no-window -no-audio -no-boot-anim -no-snapshot \
  -camera-back none -camera-front none -no-metrics

./gradlew :composeApp:assembleDebug :composeApp:assembleDebugAndroidTest \
  -PSHOPKMP_DEBUG_SERVER_URL=http://10.0.2.2:58207 \
  --no-daemon --console=plain

"$ANDROID_HOME/platform-tools/adb" -s emulator-5580 install -r \
  composeApp/build/outputs/apk/debug/composeApp-debug.apk
"$ANDROID_HOME/platform-tools/adb" -s emulator-5580 install -r \
  composeApp/build/outputs/apk/androidTest/debug/composeApp-debug-androidTest.apk
"$ANDROID_HOME/platform-tools/adb" -s emulator-5580 shell pm clear com.shopkmp

"$ANDROID_HOME/platform-tools/adb" -s emulator-5580 shell am instrument -w -r \
  -e class 'com.shopkmp.InstalledJourneyTest#realCardJourney' \
  -e backendServerUrl http://10.0.2.2:58207 \
  com.shopkmp.test/androidx.test.runner.AndroidJUnitRunner
```

The generated `core/network` debug `BuildConfig.SERVER_URL` independently equals
**`http://10.0.2.2:58207`**, matching the verifier and the same owned backend.

| Command/check | Exit / actual result |
| --- | --- |
| Existing-container infrastructure/application `start --wait` | **0 / 0** |
| Build, 07:55:56–07:56:23 UTC | **0**, 26.299 s; 623 actionable tasks, 67 executed |
| App install / test APK install / owned app clear | **0 / 0 / 0** |
| Instrumentation, **07:56:25–07:57:28 UTC** | adb **0**; **1 test / 1 failure / 0 skips** |
| Instrumentation timing/status | wall **62.712 s**, JUnit **60.326 s**; test status **-2**, runner final code **-1** |
| Private journey result parser | **FAIL**, based on the actual JUnit failure summary |
| Owned emulator stop / backend stop | **0 / 0** |
| Final read-only integrity/resource audit | **0**; memory-bound violation explicitly recorded |

Two earlier preparation attempts executed **zero instrumentation tests**. The
first found that the API 34 Play Store directory contained only an incomplete
installer, not an installed image. The second encountered an already-created
SDK-owned directory inside its new private temp home. Their evidence remains in
`$D/` and `$D/attempt-2/`; both stopped their resumed backend services cleanly.
Only private runner setup was corrected. The final attempt above used the
complete installed Google APIs image. There was **one journey invocation**.

## Actual journey evidence and coordinator handoff

Fixed-safe milestones confirm UI registration, six-digit PIN setup/login,
count-only partial PIN evidence, authenticated Home/Profile identity, password
login, catalog/product/cart, native confirmation and canonical CARD checkout.
Checkout verified one CREATED order, exact total, one-unit stock decrement and
empty cart. Chrome completion then verified **CAPTURED / PAID**, returned to the
installed app, and passed paid-order details, profile/editor and device checks.

The final logout/relaunch reached Login and passed the check that authenticated
navigation was absent. The next assertion failed:

```text
safe code: post_logout_verifier_orders_http_401
java.lang.AssertionError: Verifier GET /orders HTTP status (body withheld) expected:<200> but was:<401>
at com.shopkmp.Verifier.request(InstalledJourneyTest.kt:530)
at com.shopkmp.Verifier.request$default(InstalledJourneyTest.kt:516)
at com.shopkmp.Verifier.get(InstalledJourneyTest.kt:513)
at com.shopkmp.InstalledJourneyTest.realCardJourney(InstalledJourneyTest.kt:221)
```

**Coordinator finding — post-logout observer/session contract mismatch:** the
test uses its separate verifier session for `GET /orders` after the UI logs out.
`backend-yii2/application/AuthService.php:54` delegates to
`SessionService::logout()`; `SessionService.php:92–103` revokes **all** user session
families, increments the security version and deletes access/refresh sessions.
`backend-yii2/tests/session_revocation.php:56–57` explicitly requires account-wide
logout. These three files are unchanged from HEAD (`git diff --quiet`, exit 0).
The observed 401 is consistent with that existing contract. The coordinator must
resolve this gate-level incompatibility; the full installed journey remains red.

Independent, read-only diagnostics for the exact instrumentation window:

| Fixed-safe observation | Actual |
| --- | --- |
| Demo payment edge GET | **1**, HTTP **200** |
| Demo payment edge POST | **1**, HTTP **200** |
| Synthetic run SQL aggregate | **1 order PAID / payment CAPTURED** |

All account/commerce mutations used the unchanged installed UI. The verifier's
independent auth POST and business GETs were unchanged. The browser's visible
Complete payment action was clicked once, with **no payment-action retry or hidden
payment mutation**. No browser capability URL, hierarchy or screenshot was
exported. Edge diagnostics retain only method/status counts; SQL exports only
aggregate statuses/counts. Raw instrumentation stays private in `$E/`.

## Integrity, evidence and shutdown

- **393 tracked client/build files**, exact same before/after source-map SHA-256:
  **`2a4e395e1869333d071eb9d4eedd156d38ad850bf804dda6c962f59f3e7c80d0`**.
  Both scoped `git diff --exit-code` checks exited **0**.
- Unchanged `InstalledJourneyTest.kt` SHA-256:
  `389511724679fb3127d6127bffcfc7719fc3675854fe49f097181e6bfd1d8f0a`.
- Debug APK SHA-256:
  `04f6fc42d23ce78ba445ca8144b7b2b18b3c7c5b591e63e805e68d58c5431a08`.
  Test APK SHA-256:
  `6f6cd7c3c2825b682b86c77b75dbd55e819bd7e6b04b21e9b7523e13b3541518`.
- `$E/{instrumentation,build}.json` record exact argv, UTC intervals and exits;
  `$E/instrumentation-safe.txt`, `safe-diagnostics.json`, source snapshots and
  `*-binding.json` retain the fixed-safe evidence. `$D/final-audit.json` adds the
  exact sanitized assertion, effective hardware resources, final source/image/
  container checks and preservation results. Private raw logs remain outside Git.
- `adb -s emulator-5580 emu kill` exited **0**, emulator process exit **0**, at
  **07:57:30 UTC**. Final `adb devices -l`: **zero devices**. The owned AVD is retained.
- The nine resumed backend runtime services stopped with **exit 0** at
  **07:57:31–07:57:32 UTC**. All **12** required original containers are stopped;
  application/worker/SQL/init exits **0**, OpenSearch normal signal exit **143**.
  PostgreSQL/search volumes, synthetic journey state and evidence are retained.
- All **62** initially running foreign containers remained running. Preexisting
  repository inputs/evidence match their initial hashes. This report is the sole
  repository addition; no code/client/Gradle/UI/assertion edits, agents, commits,
  pushes or foreign container/device lifecycle operations occurred.

The missing `SHOPKMP_RELEASE_SERVER_URL` remains a separate Android release gate.
The existing iOS/Safari and security blockers retain their separately recorded
status. **No platform release approval or full Android installed-journey PASS is
issued.**
