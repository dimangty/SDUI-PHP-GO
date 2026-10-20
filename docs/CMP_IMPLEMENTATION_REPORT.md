# Task 3 — commonMain CMP SDUI implementation report

Date: 2026-10-18. Task 3 implementation and the checks listed below are complete.
This is client implementation/build/test evidence; it is not a Task 4 deployment
or a claim of an authenticated production end-to-end journey.

## Delivered modules and integration

| Boundary | Implementation |
| --- | --- |
| `core/sdui-model` | Serializable typed document, sealed nodes/actions, finite destinations/queries, controlled wire state, forms, command inputs/acknowledgements/error envelopes, decimal-string money and media policy |
| `core/sdui-network` | Typed repository, document/acknowledgement validation, distinct security/HTTP/transport/protocol errors, Koin registration |
| `core/sdui-actions` | Typed event registry, controlled fields, local catalog/orders/profile/checkout adapters, UTF-8 query bound, canonical phone validation, finite payment mapping and payment-origin policy |
| `core/sdui-renderer` | `SduiScreen` composable, all 26 published component types, real UIKit APIs, local vectors, stable lazy keys and one vertical scroll owner |
| `core/network` | Public fixed `SduiFacade`, backed by the existing singleton `RequestExecutor`, bounded streaming responses, existing refresh mutex, fixed GET/POST routes, account-ID change flow, fixed owned-order reconciliation read |
| `feature/main` | Eight actual SDUI destinations: Home, Catalog, Product, Cart, Orders, Order Details, Profile, Devices; `SduiViewModel` StateFlow; DI; native semantic adapters and confirmations |
| `core/navigation` | `NavigateToChangePin` included in the existing Navigation3 saved-route serializer registry; portable registry and native SavedState regression tests |
| Gradle | Four KMP Android/iOS modules, existing Android convention/version catalog, settings dependencies and aggregate Kover wiring |
| `feature/home/home-data` | Single-attempt payment confirmation, trusted order reconciliation and regression tests; SQLDelight/cache implementation retained |

No PHP, Go, backend schema/fixture, infrastructure, or other native feature source
was edited. No commits, subagents, dependency-version changes or additional
application Ktor clients were introduced. Existing native root auth/PIN navigation
and native Edit Profile remain the integration points for those capabilities.

## Contract and renderer behavior

The three published schemas and all 13 deterministic fixtures were inspected.
Tests generate Kotlin fixture constants from the original JSON files at build
time, rather than maintaining a divergent copied fixture set. Every fixture
decodes to known typed nodes/actions. The 10,000-code-point product description
is checked against its exact original Unicode sequence.

The decoder enforces the complete 1,048,576-byte limit, 500 total component nodes,
500 actions, depth 16, unique bounded IDs, resolved references, finite field IDs,
field lengths/UTF-8 bounds, pagination, UUIDs, money and quantity constraints.
It rejects duplicate JSON keys and children on known leaf nodes. A lexical
nesting guard runs before the recursive JSON parser. Unknown optional component
subtrees are bounded before being discarded and replaced with a local fallback.
Unknown actions remain typed inert actions, and their controls are disabled.
Unsupported versions and malformed/oversized documents produce a local error and
explicit load retry; no action from an invalid document is executed.

Raw `JsonElement` processing is confined to protocol decoding. Renderers consume
typed props and construct UIKit callback/state objects locally. Text and money
remain strings; money validation/conversion uses integer kopecks, never floating
point. Rating is separately a nullable numeric value.

The UIKit mapping uses `EpsText`, `EpsImage`/placeholders, `EpsButton`,
`EpsTextField`, `EpsProductCard`, `EpsChip`, `EpsQuantityStepper`, `EpsCartItem`,
`EpsOrderRow`, `EpsProfileHeader`/`EpsProfileField`, `EpsEmptyState`, `EpsLoader`,
`EpsErrorMessage`, `EpsSpacer` and `EpsDivider`. Search uses the allowed controlled
`EpsTextField` binding. Icons are a finite local vector registry.

The outer lazy list owns vertical scrolling. Product grids become keyed two-card
rows; nested vertical layouts are non-scrolling. Horizontal collections retain
their own lazy scrolling. Synthetic grid-row keys and actual node keys have
disjoint namespaces, preventing collisions with otherwise valid server IDs.
EpsChip lacks an enabled API, so its local adapter applies disabled appearance
and accessibility semantics when callbacks are unavailable.

Public media resolves only safe relative paths against the configured business
origin. UIKit has no authenticated avatar-image API, so uploaded avatars use its
locally derived initials fallback and never enter the unauthenticated image
loader. This is the contract's explicitly supported fallback, not an external
image request with a bearer.

## State, navigation and native boundaries

* Superseded loads and requests on leaving are cancelled. Generation checks
  prevent obsolete responses from updating state. Re-entering resumes a cancelled
  load; loads are not keyed to their own changing render state.
* Loading/content/empty/error, form values, field errors, global errors,
  confirmation and `actionInProgress` are exposed through typed StateFlow state.
* Mutations are single flight, with the guard set before launching a coroutine.
  Unknown outcomes cause a separate GET reconciliation, not an automatic repeated
  command. Explicit same-intent AddToCart recovery retains the original key and
  payload while that intent remains in the account-scoped ViewModel.
* Catalog submission clears sort for a nonempty search, resets product page and
  retains the independent category window. Cart mutation acknowledgement reloads
  the effective source cart page/size; normalized collection coordinates are
  adopted from the document. Category continuation and pinned selection fixtures
  are covered.
* The bounded saved snapshot contains selected tab, finite destination stack,
  product/order IDs, tab coordinates and per-destination form drafts, partitioned
  by account ID. It contains no access/refresh tokens. Snapshot writes wait until
  initial restoration completes. A per-account saveable-state holder retains
  screen/list state across tab/detail transitions.
* Account changes immediately clear content/drafts/intents and cancel work.
  Final authentication refusal clears state and returns to native Login. Logout
  calls AuthService first, then clears SDUI state and replaces the native root.
* There is an explicit local Back button and platform BackHandler for detail/
  nested destinations. Previous collection page is not used as navigation Back.
* Cancel Order, Revoke Device and checkout require local confirmation. Successful
  current-device revocation also clears corresponding local fast-login metadata;
  biometric revocation deletes the local key through the existing hardware
  service. It does not issue a second revoke command.
* Edit Profile and Change PIN use the existing native screen/Navigation3 route.
  Manage Biometric uses the existing AuthService hardware/key flow.

`ExecutorSduiFacade` accepts finite typed destinations/commands, never server
paths, arbitrary methods, origins or headers. The existing executor performs
bounded streaming reads inside `prepareRequest().execute`, sends no-store and
reuses its existing refresh mutex. Commands have a 32-KiB request cap. Only the
AddToCart command can supply the dedicated validated idempotency header. No
second refresh loop, HTTP client or personalized conditional-cache path is added.

## Checkout and payment correction

The local checkout adapter validates the address/payment selection and calls
`CheckoutUseCase.execute(city, street, house, apartment, paymentMethod)` with
exactly those five arguments. All three wire methods have explicit native enum
mappings. The native result determines success/partial success and order-history
navigation.

`BackendShopRepository.checkout` no longer wraps `confirmPayment` in
`retryOnceIfIndeterminate`. Confirmation makes one attempt. After a failed
confirmation, a protected owned-order GET reconciles the result; the returned
order ID and owner must match. A trusted paid/later order can resolve the failure.
Otherwise the created order is returned as partial success with an unknown/
failed initiation indication. The host informs the user to inspect the order;
it neither automatically confirms again nor repeats checkout. The existing
same-intent order idempotency-key behavior is preserved and tested.

Only absolute HTTP(S) payment URLs with exact locally allowlisted origins and no
credentials can launch. Business origin is allowed by default. Additional payment
origins use Android `SHOPKMP_PAYMENT_ORIGINS` (Gradle property/environment, CSV)
or iOS `PaymentOrigins` (Info.plist CSV). No payment origin comes from SDUI JSON.
The normal existing :8080 defaults remain; the test BFF port :58091 is not a
default or hardcoded endpoint in client implementation.

## RED → GREEN evidence

| Regression/slice | Observed RED | Final GREEN evidence |
| --- | --- | --- |
| Initial contract slice | New common tests failed compilation because the decoder/model API did not exist | Six model tests pass on Android and iOS; all 13 fixtures decode, no unknown published types |
| Ambiguous native payment confirmation | `indeterminatePaymentIsSingleAttemptAndKeepsCreatedOrderAsPartialSuccess` failed against the inherited two-attempt implementation | Single attempt, paid-order reconciliation, partial success and preserved keyed order recovery pass |
| Initial form/ViewModel/renderer slices | Missing Forms, SduiViewModel and render-plan APIs failed the new test compilations | Form, cancellation, duplicate-submit, state and render-plan suites pass |
| Duplicate JSON keys / leaf children | Invalid document was accepted as Content | Decoder rejects both; exact 500-node/depth-16 boundaries also pass and overflows fail safely |
| Leaving/re-entering | Cancelled load remained without a document after `start()` | `leavingAndReenteringResumesTheCancelledLoad` passes |
| Checkout validation | Invalid-address test failed because native checkout was entered | `invalidAddressNeverEntersNativeCheckout` passes, zero repository calls |
| Unknown category control | iOS UI test found an enabled AssistChip with click semantics | `unknownCategoryActionIsVisiblyDisabled` passes with disabled semantics |
| Relative payment URL | URL policy accepted `//shop.example/pay` | HTTP-origin policy test now rejects scheme-relative URLs |
| Change PIN restoration | Removing its registry entry made the portable route round-trip test fail | Registry test and explicit-polymorphic native SavedState round trip pass |
| Lazy key collision | Valid text ID and generated grid ID produced one unique key for two rows | Namespaced-key regression passes on Android and iOS |

Additional checks cover confirmation dismissal, logout ordering, immediate account
clearing, source cart-page recovery, exact checkout arguments, command envelopes,
ack version/outcome/command/target validation and preserved HTTP error details.
MockEngine tests use the real singleton-executor implementation boundary:
concurrent SDUI 401s refresh once; oversized bodies fail; an ambiguous mutation
transport failure causes exactly one mutation attempt.

## Actual verification commands and results

All Gradle invocations used the installed Java 17.0.9 x86_64 JetBrains runtime,
wrapper 8.14.3 and:

```sh
export ANDROID_HOME=/Users/dmitrijbykov/Library/Android/sdk
```

No JDK was installed or substituted. SDK 36 and Xcode/iOS simulator 26.2 were
available. Initial SDK discovery failed without ANDROID_HOME; supplying the
existing SDK location resolved it.

Final Android verification (exit 0):

```sh
./gradlew testDebugUnitTest koverVerifyDebug :composeApp:assembleDebug --console=plain --quiet
./gradlew :koverVerify --console=plain --quiet
./gradlew :koverLog --console=plain --quiet
```

The full repository Android debug unit-test task set passed. The explicit root
`:koverVerify` passed the existing aggregate 70% rule without lowering the gate;
`:koverLog` reported **75.3508% application line coverage** with the existing root
filters. The initial unqualified `koverVerifyDebug` only selected subproject
variant tasks (there is no root `:koverVerifyDebug` task); it was not used as proof
of the aggregate gate. Selected boundary-suite results are:

| Module | Android tests | iOS simulator tests executed |
| --- | ---: | ---: |
| sdui-model | 6 | 6 |
| sdui-network | 2 | 2 |
| sdui-actions | 4 | 4 |
| sdui-renderer | 2 | 4 (includes two Compose UI tests) |
| core/network | 15 | 15 |
| core/navigation | 1 | 2 (includes native SavedState) |
| feature/main | 19 | 19 |
| home-data | 14 | 14 |
| **Selected boundary suites** | **63** | **66** |

Android XML evidence is under each module's
`build/test-results/testDebugUnitTest/`; existing suites outside this table also
ran through the root task.

Final framework/test linking (exit 0):

```sh
./gradlew :composeApp:linkDebugFrameworkIosSimulatorArm64 \
  :core:navigation:linkDebugTestIosSimulatorArm64 \
  :core:network:linkDebugTestIosSimulatorArm64 \
  :core:sdui-renderer:linkDebugTestIosSimulatorArm64 \
  :feature:main:linkDebugTestIosSimulatorArm64 --console=plain --quiet
```

Model, network-adapter, actions and home-data test executables were also linked
in earlier successful explicit invocations. Gradle's `iosSimulatorArm64Test`
tasks reported **SKIPPED** in this x86_64-JBR setup; they are not counted as test
passes. Actual execution used the booted iPhone 17 Pro simulator instead:

```sh
xcrun simctl boot 16B71172-52E2-47E6-A951-EFAB9627917B
xcrun simctl bootstatus 16B71172-52E2-47E6-A951-EFAB9627917B -b
# Executed separately for each of the eight modules in the table:
xcrun simctl spawn 16B71172-52E2-47E6-A951-EFAB9627917B \
  "$PWD/core/sdui-renderer/build/bin/iosSimulatorArm64/debugTest/test.kexe" \
  --ktest_logger=SIMPLE
```

Every test case in those final simulator suites printed `Passed`; 66 passed,
zero failed. These are real ARM64 iOS simulator test processes, including Compose
UI semantics/input tests, rather than a claim that skipped Gradle tests ran.

Actual commonMain static analysis (exit 0, zero reported issues):

```sh
./gradlew :core:sdui-model:detektMetadataCommonMain \
  :core:sdui-network:detektMetadataCommonMain \
  :core:sdui-actions:detektMetadataCommonMain \
  :core:sdui-renderer:detektMetadataCommonMain \
  :feature:main:detektMetadataCommonMain \
  :core:network:detektMetadataCommonMain --console=plain --quiet
```

Plain `detekt` tasks initially reported NO-SOURCE; that was not treated as
analysis evidence. The real metadata tasks found wildcard imports, which were
corrected; the final reports are under each module's
`build/reports/detekt/commonMain.*`.

Built deliverables:

* `composeApp/build/outputs/apk/debug/composeApp-debug.apk`
* `composeApp/build/bin/iosSimulatorArm64/debugFramework/ComposeApp.framework`

## Review, rulings and remaining verification gaps

Final review: **self-review**, following the review checklist; no subagent or
independent reviewer was used. Reviewed contract bounds/references, account
partitioning, native security and logout ordering, request ownership, cancellation,
mutation retry behavior, payment URLs, saved routes and lazy key namespaces.

Rulings:

1. Work inline in the supplied unborn repository against the published reviewed
   design/plan, as explicitly requested; creating a worktree would require an
   unsolicited initial commit. No commits/delegation were performed.
2. Use the contract-approved initials fallback for protected avatars because
   UIKit exposes no authenticated image request state. Cost: no avatar photo in
   the shared renderer until an authenticated media API is added.
3. Execute linked simulator tests through simctl because Gradle skipped its run
   tasks on this host setup. Cost: these manual invocations do not generate the
   normal Gradle native-test XML reports; the exact reproducible commands and
   observed case results are recorded here.

Remaining verification gaps:

* No installed full-app, authenticated BFF → Yii → payment-provider journey was
  driven here. The host is wired to real endpoints; deployment/live multi-service
  runtime verification belongs to Task 4. No live-flow success is inferred from
  deterministic fixtures, MockEngine, APK/framework linking or Compose tests.
* Physical biometric hardware, native credential prompts and an external payment
  application's return journey were not exercised on a physical device. Existing
  native services are reused; the local adapter and payment boundary are tested.
* Saved snapshot/route restoration and retained navigation/forms are unit tested;
  Android process-death instrumentation was not run. Android Bundle operations
  cannot run directly in plain JVM tests, so native SavedState was checked in the
  iOS simulator and the shared route registry was checked portably.
* Build output retains a deprecated BackHandler warning (the functional local/
  platform Back affordance is implemented), a redundant exhaustive-when warning,
  and existing native platform/deprecation warnings. These did not prevent either
  requested build. Full-project test-source style/lint and release builds were
  not claimed by the scoped commonMain detekt/debug verification above.

## Independent-review remediation — I1–I5 and both minors

Date: 2026-10-18. Read every finding in `docs/CMP_REVIEW.md` and verified its
execution trace against the actual implementation and existing native APIs before
editing. All five Important findings and both minor findings were reproduced and
corrected. **The independent review document was not edited.** This appendix is
the implementer's remediation evidence, not an independent re-review or a live
Task 4 acceptance claim. It supersedes the earlier descriptions of draft/key
handling and the earlier test/coverage counts where they differ.

### Changes and observed RED → GREEN results

| Finding | Observed RED | Correction and GREEN evidence |
| --- | --- | --- |
| **I1: logout lifecycle** | Two new AuthService cancellation regressions failed (`28 tests, 2 failed`); cancelling the host lifetime during suspended logout failed the native-root transition (`22 tests, 1 failed`) | AuthService clears access/refresh/account data in `finally`. SDUI logout has its own immediately entered job and unconditional state-clear/Login-root `finally`, independent of destination generations. Tabs/events cannot start new work during sign-out. Scope/remote cancellation, tab switching/leaving, cleared drafts and final root are covered; AuthService and host suites pass. |
| **I2: stale profile drafts** | Native-save/phone-only submit, native Cancel with unsent validation errors, and restored clean baseline regressions all failed (`25 tests, 3 failed`) | Drafts now contain only values differing from the current server baseline plus field errors. Native Save and Cancel have distinct callbacks. Save and acknowledged UpdateProfile invalidate/rebase profile drafts; Cancel preserves unsent values and the prior validation/network error. Clean fields follow a fresh server read and clean restored fields do not overwrite server updates. All cases pass, including an added explicit profile network-failure/Cancel case. |
| **I3: payload-equal forever key** | Stateful unknown-commit → cart read → remove → fresh Add regression failed (`26 tests, 1 failed`) | Replaced payload-key memoization with explicit pending Add intents containing their fixed key/payload. Chosen recovery uses the original pair; a separately confirmed fresh purchase always gets a new key while the older intent remains recoverable. The stateful replay test now really adds after removal, and a later replay of the old intent does not add again. Recovery after quantity editing/restoration, GET-only reconciliation and explicit discard are also covered. |
| **I4: duplicate lazy keys** | Model duplicate-array regression failed (`7 tests, 1 failed`). Both decoder-to-renderer iOS tests reproduced actual Compose `Key ... was already used` exceptions with both duplicate controls visible (`2 failed`) | Decoder rejects duplicate select-option values and category IDs before producing Content. Both iOS cases now display local document-error UI and emit no events. All 13 original fixtures continue to decode as known typed content. |
| **I5: BFF authentication codes** | Finite BFF-code concurrent refresh and final-refusal regressions failed, alongside the valid-403 regression (`19 network tests, 3 failed`) | Only the fixed SDUI boundary additionally recognizes `BFF_UPSTREAM_SECURITY` and `BFF_UNAUTHORIZED`. Both use the existing executor refresh mutex and a single refresh/reissue path. Concurrent requests recover without premature token clearing; final 401 clears the session. The native `INVALID_PIN` no-refresh/no-replay regression remains green. |
| **Minor 1: 403 details** | A valid bounded DEVICE_FORBIDDEN response lost its code/message/details | Preserve valid bounded 403 code, message and string details. Malformed/oversized bodies retain a sanitized 403 fallback, without refresh, token clearing or raw-body leakage. These tests pass on Android and iOS. |
| **Minor 2: layout semantics** | iOS test could not find the flattened nested wrapper; root End alignment expected a 16px right inset but measured 984px (`2 failed`) | Extract only a single vertical root's layout props into the outer lazy list. Nested vertical lists remain ordinary non-scrolling Columns retaining their own padding/spacing/alignment; root alignment is applied. Actual iOS measurements verify nested 24dp padding, 32dp spacing, End alignment and exactly one vertical scroll owner. |

Key implementation boundaries:

* `feature/auth/auth-data/AuthServiceImpl.logout`: narrow local-cleanup correction;
  no auth/PIN/biometric flow rewrite. Its two cancellation regressions use the
  real service and real BackendSession over test settings.
* `feature/main/sdui/SduiViewModel`: logout finalization, dirty-baseline draft
  reconciliation, separate native Save/Cancel behavior and explicit pending-intent
  recovery. Native profile Save is wired to `nativeProfileSaved`; native Back is
  the cancellation path. An acknowledged generic profile save stops being a dirty
  edit before the next read.
* Pending Add intents survive ordinary navigation/cancellation and account-matched
  saved-state restoration, remain bounded to 16, and are cleared with account
  state. They serialize idempotency keys and fixed Add inputs, **not auth tokens**.
  There is no automatic payload-equality lookup or automatic POST recovery.
* The commonMain recovery UI offers **Check cart**, **Recover the previous
  request**, and **Stop recovering**. With a pending operation, Add explicitly
  distinguishes a new purchase from recovery. Its local content participates in
  the existing outer vertical list; it does not add a nested vertical scroller.
* `core/sdui-model/ScreenDecoder` enforces option/category uniqueness as a local
  rendering invariant. `core/network/RequestExecutor` keeps BFF challenge handling
  separate from native business-credential challenge handling. No client, refresh
  mutex, arbitrary HTTP target or backend operation was added.

### Post-remediation verification

All commands below completed successfully against the changed code using the
same installed Java 17.0.9 runtime, wrapper 8.14.3, SDK 36 and Xcode 26.2. Commands
used `ANDROID_HOME=/Users/dmitrijbykov/Library/Android/sdk`.

Final full Android test, root coverage and APK invocation (exit 0):

```sh
./gradlew testDebugUnitTest :koverVerify :koverLog :composeApp:assembleDebug \
  --console=plain --quiet
```

The full Android debug unit-test task set passed. The explicit root 70% aggregate
gate passed; root `:koverLog` reported **76.5252% application line coverage**.
The gate and existing coverage exclusions were not weakened.

Framework and affected native-test linking (exit 0):

```sh
./gradlew :composeApp:linkDebugFrameworkIosSimulatorArm64 \
  :core:sdui-model:linkDebugTestIosSimulatorArm64 \
  :core:network:linkDebugTestIosSimulatorArm64 \
  :feature:auth:auth-data:linkDebugTestIosSimulatorArm64 \
  :feature:main:linkDebugTestIosSimulatorArm64 \
  :core:sdui-renderer:linkDebugTestIosSimulatorArm64 --console=plain --quiet
```

After the final additional profile network-error test, framework/Main test linking
and the full Android/coverage/APK command were rerun successfully. Actual native
execution used the already-booted iPhone 17 Pro, not skipped Gradle run tasks:

```sh
# Run separately for each affected module in the table below:
xcrun simctl spawn 16B71172-52E2-47E6-A951-EFAB9627917B \
  "$PWD/feature/main/build/bin/iosSimulatorArm64/debugTest/test.kexe" \
  --ktest_logger=GTEST
```

| Affected module | Final Android tests | Actual post-change iOS simulator tests |
| --- | ---: | ---: |
| `core/sdui-model` | 7 | 7 |
| `core/network` | 19 | 19 |
| `feature/auth/auth-data` | 28 | 28 |
| `feature/main` | 29 | 29 |
| `core/sdui-renderer` | 2 | 8 (six Compose UI tests) |
| **Selected affected suites** | **85** | **91** |

All **91** native cases printed `[ OK ]`/`[ PASSED ]`; zero final failures.
The earlier 90-case run was followed by the final 29-case Main rerun after adding
the extra profile network-failure case. This table is the selected changed
boundary set; the root Android command also ran the other repository debug suites.
Android XML results are in each module's `build/test-results/testDebugUnitTest/`.

Actual commonMain detekt tasks (exit 0; zero reported issues):

```sh
./gradlew :core:sdui-model:detektMetadataCommonMain \
  :core:sdui-network:detektMetadataCommonMain \
  :core:sdui-actions:detektMetadataCommonMain \
  :core:sdui-renderer:detektMetadataCommonMain \
  :core:network:detektMetadataCommonMain \
  :feature:main:detektMetadataCommonMain \
  :feature:auth:auth-data:detektMetadataCommonMain --console=plain --quiet
```

One intermediate Kotlin incremental-compilation cache diagnostic fell back to
full compilation; its test result XML was green and the final fresh invocations
completed normally. No toolchain substitution was needed. Build outputs retain
the documented BackHandler/exhaustive-when deprecation warnings; no final test,
coverage, static-analysis, APK or framework build failure remains in these checks.

Built artifacts remain:

* `composeApp/build/outputs/apk/debug/composeApp-debug.apk`
* `composeApp/build/bin/iosSimulatorArm64/debugFramework/ComposeApp.framework`

Scope and remaining verification: no backend/infra edits, commits or subagents.
The full installed/authenticated device journey, physical credential prompts,
payment-app return and Android process-death instrumentation remain Task 4/device
verification gaps; none is inferred from these regressions or build results.

### Subsequent installed verification

The installed Android and iOS CARD journeys were later implemented and passed,
including browser capture/return, all eight SDUI destinations and logout. Native
iOS Keychain and startup fixes have additional real native tests. The final
Android unit suite/APK, iOS framework and76.5252% root Kover gate also passed on
October20. [Current mobile evidence](MOBILE_E2E_REPORT.md) supersedes the installed
journey gap above; physical hardware/provider and process-death limits remain.
