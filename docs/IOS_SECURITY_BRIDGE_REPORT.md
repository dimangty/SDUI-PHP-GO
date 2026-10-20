# iOS security bridge repair — 2026-10-19

## Result

**Startup blocker repaired.** Real ARM64 iOS simulator security tests: **22 passed,
zero failed**. Affected Android/common tests: **5 passed, zero failed**. Final
`ComposeApp.framework` linked. The unchanged installed Login selector probe:
**1 passed, zero failed**, xcodebuild exit **0**, wall time **11.314s**.
At this earlier bridge-only milestone, the full CARD journey was not yet tested.
It subsequently passed through Safari payment and cold logout/relaunch; current
results are in [MOBILE_E2E_REPORT.md](MOBILE_E2E_REPORT.md).

## Authorized scope and execution plan

Fix the verified Kotlin Map-to-CFDictionaryRef startup crash in the iOS settings
adapter and the identical biometric adapter defect. Inline execution, no commits
or subagents. Preserve Keychain service/account names, accessibility, stored
values, Secure Enclave and biometric authentication requirements.

1. Add isolated real Security.framework iOS tests and observe the original
   ClassCastException on simulator `16B71172-52E2-47E6-A951-EFAB9627917B`.
2. Introduce a scoped native dictionary bridge in core/common as required;
   balance dictionary and Create/Copy ownership, including zero-length CFData.
3. Verify settings persistence/registry/isolation and native biometric dictionary
   lifetimes plus fail-closed unsupported hardware, without the app's key tag.
4. Run affected Android/common tests, link the final simulator framework, and run
   the existing sanitized installed Login selector probe.
5. Record actual commands/results and remaining physical-device limits here;
   append the mobile milestone to `MOBILE_E2E_REPORT.md`.

## Initial evidence

Read the latest mobile report and `docs/evidence/ios/xctest-summary.txt`: the
previous installed probe had total=1/passed=0/failed=1, xcodebuild=65 and the
confirmed HashMap-to-CPointer exception. Actual source has invalid casts at
settings lines 123/138/154 and biometric lines 99/126/157. The simulator is booted
on iOS 26.2. Gradle's Intel-JBR test execution limitation is documented in the CMP
report; native tests will be linked and executed explicitly through simctl.

## RED evidence and diagnosis

All commands below ran from the repository root, using:

```sh
export JAVA_HOME=/Users/dmitrijbykov/Library/Java/JavaVirtualMachines/jbr-17.0.9/Contents/Home
export ANDROID_HOME=/Users/dmitrijbykov/Library/Android/sdk
```

First linked the new settings integration tests against the original adapter:

```sh
./gradlew :core:settings:linkDebugTestIosSimulatorArm64 --console=plain
xcrun simctl spawn 16B71172-52E2-47E6-A951-EFAB9627917B \
  "$PWD/core/settings/build/bin/iosSimulatorArm64/debugTest/test.kexe" \
  --ktest_logger=SIMPLE
```

Observed **3 existing common tests passed / 5 new native tests failed**. Actual
failure, including `missingReadReturnsNullAndDefaults` through `getStringOrNull`
and `getRaw`, before `SecItemCopyMatching`:

```text
kotlin.ClassCastException: class kotlin.collections.HashMap cannot be cast to class kotlinx.cinterop.CPointer
```

Exposed only an internal biometric constructor tag parameter (production default
unchanged) so native tests could use generated tags. Linked/executed
`:core:common:linkDebugTestIosSimulatorArm64` in the same manner. Observed the same
exception in `deletingMissingKeyUsesRealSecurityFrameworkWithIsolatedTag`.
After extracting the existing creation/query maps into scoped builder functions,
the three native biometric structure/unsupported-enclave tests also reproduced
the same exception before replacing those casts. The unenrolled simulator's
availability guard passed from the beginning.

### Distinguishing real security errors from the cast

After native dictionary construction replaced settings casts, direct execution
reached Security.framework and returned **-34018**, missing entitlement. An
isolated ad-hoc `.app` packaging attempt was denied launch by SpringBoard and ran
**zero tests**; it is not a pass and is not the final test method.

Embedding a dedicated test entitlement in the simulator executable's
`__TEXT,__entitlements` section allowed direct `simctl spawn` access. Reads then
returned **-50**, while writes/deletes reached the framework. A native value-type
test caught the remaining bridge problem:

```text
Failed: securityValuesHaveNativeBooleanNumberAndStringTypes
kotlin.AssertionError: Expected <21>, actual <22>.
```

The independently queried CFBoolean type ID was 21; Kotlin Boolean had bridged
as CFNumber, type ID 22. Explicit `kCFBooleanTrue` / `kCFBooleanFalse` fixed
Security's Boolean query contract. The final runner uses the linked executable
directly, with its test entitlement; there is no app-host success claim.

## Repair and ownership

- `core/common/src/iosMain/kotlin/com/shopkmp/common/security/CoreFoundationBridge.kt`
  creates real CFDictionary/NSDictionary-compatible dictionaries using
  `kCFTypeDictionaryKeyCallBacks` and `kCFTypeDictionaryValueCallBacks`. Native
  constants/references are passed as native pointers. Strings, numbers, NSData
  and LAContext are converted using `CFBridgingRetain`; each temporary +1 is
  released after the dictionary has retained it. Booleans use CFBoolean.
- Scoped `useCF` balances +1 Create/Copy/Retain references in `finally`.
  Dictionaries release their retained contents on normal and exceptional exits.
  Scoped `withCFData` copies pinned bytes, supports empty arrays without indexing
  element zero, and releases its CFData after the consumer finishes.
- `SecurityKeychainSettings.kt` now bridges all add/read/delete queries and
  releases the CFData returned by `SecItemCopyMatching` after copying its bytes.
  Existing NSData creation already handles empty strings and remains valid.
- `BiometricKeyService.ios.kt` uses native dictionaries for creation, nested
  private-key attributes, lookup and deletion. Access control, created/copied
  keys, public keys, exported public bytes, signatures, challenge/tag data and
  CFError Create/Copy references have balanced ownership. Enrollment still
  authenticates and signs before exposing the public key.

Production settings service **`shopkmp`**, account names, UTF-8 values, registry
format and **AfterFirstUnlockThisDeviceOnly** accessibility are preserved.
Biometric default tag **`com.shopkmp.biometric.login.es256`**, P-256/ES256,
Secure Enclave token, permanent private key, **WhenUnlockedThisDeviceOnly**,
**BiometryCurrentSet | PrivateKeyUsage**, and real LAContext authentication are
preserved. No storage migration, auth bypass, software-key fallback or blanket
Keychain deletion was added.

### Installed app's additional, confirmed entitlement blocker

The first installed run after bridge repair still failed (77.003s, 1/1 failed,
xcodebuild65). A temporary allowlist-only diagnostic wrapper around the existing
runner identified **`Keychain read status -34018`** (61.347s rerun). Raw diagnostic
messages were withheld and the runner still deleted its private xcresult and
diagnostics. No UI test or runner source was edited.

The existing runner requests `CODE_SIGNING_ALLOWED=NO`. That leaves the simulator
app without the Keychain identity normally synthesized by Xcode signing. The
minimal app-required security build correction adds:

- `iosApp/iosApp/SimulatorKeychain.entitlements`: the unsigned simulator app's
  own `com.shopkmp.ios` application identifier and default access group.
- `iosApp/iosApp.xcodeproj/project.pbxproj`: link that section only for
  **unsigned simulator** builds. Xcode Debug initially put it in
  `iosApp.debug.dylib`, confirmed using `otool`, rather than the main executable;
  the 65.794s probe therefore remained red. Disable the debug dylib only for
  unsigned simulator Debug so SecTask can read the executable's identity.

A manual console launch used for diagnosis was terminated; an intervening probe
reported **Failed to launch**, not success. Final unchanged-runner execution then
passed. Build-setting expansion and final Mach-O inspection confirmed:

```text
unsigned simulator: embedded-keychain-flags=True; debug-dylib=NO
signed simulator: embedded-keychain-flags=False; debug-dylib=YES
unsigned device: embedded-keychain-flags=False; debug-dylib=YES
Final app executable contains __TEXT,__entitlements: True
```

This metadata grants the unsigned simulator app its own Keychain identity; it
does not grant another app's access group or substitute storage. Signed builds
continue using Xcode's identity. Application screens/business logic are untouched.

## Final verification

```sh
./gradlew :core:common:testDebugUnitTest :core:settings:testDebugUnitTest \
  :core:common:linkDebugTestIosSimulatorArm64 \
  :core:settings:linkDebugTestIosSimulatorArm64 \
  :composeApp:linkDebugFrameworkIosSimulatorArm64 --console=plain
```

Actual output: **BUILD SUCCESSFUL in 2m 44s**. Framework and both test executables
linked. Android tasks were initially UP-TO-DATE, so forced actual execution:

```sh
./gradlew :core:common:testDebugUnitTest :core:settings:testDebugUnitTest \
  --rerun-tasks --console=plain
```

Actual output: **BUILD SUCCESSFUL in 42s**. Fresh XML results at
`core/{common,settings}/build/test-results/testDebugUnitTest/`: common **2 tests**,
settings **3 tests**, all with failures=0/errors=0/skipped=0. XML timestamps
2026-10-19T12:57:52Z and 12:57:56Z. Existing Kotlin expect/actual beta and datetime
deprecation warnings remain; the invalid-cast warnings in the repaired adapters
are gone.

Native execution (the helper invokes the exact simctl spawn command above for
each module and checks SIMPLE test output plus process exit):

```sh
python3 core/settings/src/iosTest/run_security_tests.py \
  --udid 16B71172-52E2-47E6-A951-EFAB9627917B
```

```text
Native settings: passed=10; failed=0; simctl=0; PASS
Native common: passed=12; failed=0; simctl=0; PASS
```

Native coverage includes missing reads/defaults; Unicode/nonempty and empty
string write/read/update/remove; Boolean/Int persistence; registry reopening and
clear; service isolation; independently seeded preexisting Keychain values and
registry; actual accessibility attributes; preservation of unregistered accounts
even in the same service. Every fixture service/tag uses a new NSUUID, never the
application's service/tag. Cleanup targets only that generated service.

Common native coverage checks CFBoolean/CFNumber/CFString/CFData types, nested
key attributes, exact Secure Enclave query requirements, LAContext identity,
access-control and data retain/release lifetime (including throwing consumers),
empty CFData, actual isolated missing-key deletion, and fail-closed unenrolled /
unsupported Secure Enclave behavior. Existing SPKI compatibility tests also pass.

Finally ran the requested existing installed selector probe:

```sh
python3 scripts/run_ios_installed_journey.py \
  --udid 16B71172-52E2-47E6-A951-EFAB9627917B
```

Historical sanitized selector-probe output (the evidence path now contains the
later complete CARD journey instead):

```text
Started UTC: 2026-10-19T13:06:24.245357+00:00
Build/test wall time: 11.314s
XCTest result: Passed; total=1; passed=1; failed=0
xcodebuild exit: 0
PASS installed iOS Login selector probe
Full installed CARD journey: NOT VERIFIED
```

## Review and remaining limits

Self-reviewed native ownership, preserved query identity/accessibility, generated
fixture isolation, conditional app build settings and actual test output. The
repository remains wholly untracked/unborn, so no Git baseline diff is available;
no commits or subagents were used. No backend, infrastructure, UI-test, runner or
screen-logic edits were made. Only the existing runner's sanitized summary was
refreshed as installed evidence.

Physical-device Secure Enclave key creation, biometric prompt success/cancel,
signatures, enrollment/invalidation after biometric-set changes, first-unlock
device binding and signed-device entitlement behavior still require real
hardware. Simulator rejection is verified and no software-key substitute is
used. Framework verification here targets iosSimulatorArm64. This bridge-only
milestone proved Login availability; the subsequently authored full CARD journey
passed and has separate [execution evidence](MOBILE_E2E_REPORT.md).
