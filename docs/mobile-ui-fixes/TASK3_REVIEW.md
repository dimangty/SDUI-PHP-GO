# Task 3 — independent read-only review

Date: 2026-10-27. Diff baseline: `b9def93963ef08b1d19bedf957c0b3d3d003add7`.

## Verdict

- **Specification: partial pass.** The shared chrome, shopping composition,
  presentation-only BFF changes and preserved protocol boundaries substantially
  implement Task 3. Checkout's automatically disclosed address editor has the
  functional regression in M1, so the task is not ready for acceptance.
- **Code quality: changes requested — one Medium finding.** No concrete Critical
  or High regression was established in the reviewed Task 3 changes.
- Installed Android/iOS journeys, ten-screen visual comparison and
  small-screen/keyboard checks remain the coordinator's acceptance gate. The
  implementation report correctly does not claim those checks passed.

Reviewed `PLAN.md`, `TASK3_REPORT.md`, the reference
`docs/images/frontend-sketch.svg`, `docs/backend-fixes/IOS_VISUAL_AUDIT.md`, the
actual diff and new Task 3 files. Scope included MainContainerScreen/SduiChrome;
renderer RenderPlan, forms, presentation and icons; UIKit cart/order integration;
Go presentation builders/tests and all seven changed fixtures; integer money
display/tests; installed journeys/runners and contract documentation. Task 1's
prior PASS is retained. The separately tracked Task 2 hidden-Koin dependency
finding and its concurrent correction are not counted again.

## Finding

### M1 — Medium: automatically opened checkout inputs disappear while being filled

**Primary location:**
`core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiForms.kt:58–65`.

`editAddress` starts as `false`. A missing address is displayed only because
`invalidAddress` is true; that automatic expansion never opens the persistent
disclosure state. As soon as the last required field becomes nonblank and its
error is cleared, `editAddress || invalidAddress` becomes false and removes
**all four address inputs during editing**.

**Concrete path:** a new account has blank city/street/house/apartment. Fill city
and street, then start entering house `51`. The first `5` satisfies the only
remaining required-value check, so the house field is removed from composition
and loses focus before normal character-by-character entry can finish. The
optional apartment also disappears before it has been entered. A user must find
and reopen `Изменить` to continue. Resolving the last field error in an
automatically opened section has the same premature-close problem.

This follows the real data path:

- `backend-sdui/internal/bff/components.go:123–142` supplies city/street/house as
  required, apartment as optional, and the actual trusted prefill (empty when no
  address exists).
- `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:262–274`
  immediately publishes each accepted field value and removes that field's
  error. No submit is needed to trigger the renderer's false condition.
- The new installed helpers at
  `iosApp/iosAppUITests/InstalledJourneyTests.swift:180–188` and
  `composeApp/src/androidInstrumentedTest/kotlin/com/shopkmp/InstalledJourneyTest.kt:120–128`
  skip clicking Edit when the city input already exists. On this normal
  fresh-account path the disclosure remains unlatched: filling house can hide
  apartment before the next typing operation, making the journey time out while
  looking for an input that is no longer rendered.

**Verification:** evaluated the exact disclosure predicate with the required-field
metadata from the real `cart.json` fixture and a blank-address state. Address
visibility was `true → true → true → false` for initial state, city, street, then
house `5`, with `editAddress=false` throughout. This is a deterministic source-level
state trace, not a claimed installed-app or failing Compose-test execution.

**Why existing GREEN coverage misses it:**
`core/sdui-renderer/src/iosTest/kotlin/com/shopkmp/sdui/renderer/ShoppingPresentationUiTest.kt:56–71`
explicitly toggles Edit before clearing the final invalid state. The case at
lines 121–138 starts with a valid prefilled address and also explicitly opens
Edit. Neither exercises an automatically opened, initially blank section through
the final required-field edit.

**Action:** keep an automatically revealed address section open throughout its
editing session, including after its last blank/error is resolved. Collapse it
only through an explicit local disclosure decision. Preserve host-controlled
values/errors and the existing confirmation-before-checkout boundary. Add a
focused UI regression that starts with all address fields blank, enters a
multi-character house and an apartment without clicking Edit first, and asserts
that controls remain reachable and no checkout Activate occurs. Exercise the
last-error-to-valid transition as well, then rerun the affected renderer UI tests
and both fresh-account installed journeys. Changing only the journey helper to
click Edit would conceal the user-facing regression.

## Specification and boundary checks

Source paths below refer to the reviewed working tree; positive results are
source/targeted-test findings, not a pixel-match or installed-device claim.

| Requirement | Review result and evidence |
|---|---|
| One branded app bar; real actions and selectors | `feature/main/src/commonMain/kotlin/com/shopkmp/main/container/SduiChrome.kt:15–50` promotes an actual ScreenTitle Text and Buttons backed by the existing typed catalog/profile/Login/EditProfile actions. Disabled button state and host busy state are retained. `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/RenderPlan.kt:24–35` removes only named Text/Button nodes and traverses Layout/Form children. Product's real title remains body content, including legacy ScreenTitle styling. Chrome tests exercise one real title/edit control and real Back/Refresh callbacks; no invisible duplicate selectors were added. |
| Account/editor callback integration and inset ownership | `feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt:60–104` retains account-keyed saveable state and host restore/save/leave, captures raw owner plus editor ID, and supplies both to cancel/save callbacks. The editor branch is outside bottom navigation and owns status/navigation padding; ordinary chrome is inside the existing navigation Scaffold. The shared app bar applies zero system insets. Actual keyboard/safe-area geometry still requires the pending installed check. |
| Compact Home without losing fail-closed ownership | `backend-sdui/internal/bff/screens.go:47–138` retains the authenticated device requirement, concurrent session read, security-error rejection/cancellation, session/user ownership check, account metadata and cart count. Search/categories/paging/popular cards precede ancillary links. The new Profile shortcut uses an existing Navigate action; anonymous Profile uses Login. The removed Home ProfileHeader does not remove these security checks. Focused existing Home security/ownership regressions pass. |
| Product image first, real body data and full-width CTA | `backend-sdui/internal/bff/screens.go:192–235` keeps permitted deduplicated image sources/IDs, then actual name, rating/review count, price, complete description chunks, stock, quantity label/stepper and original purchase action. `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiScreen.kt:152–180,242–243` retains bounded media sizing and makes the actual shopping Button full width. No fake product/business data was introduced. |
| Cart media, warnings and non-submitting summaries | `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/Presentation.kt:45–51` passes the real cart image through unchanged `MediaPolicy.publicUrl`, retaining oversold/unavailable warnings and absolute quantity callbacks. UIKit forwards that URL to the existing image loader. `SduiForms.kt:55–69` preserves the structured fields, canonical payment options, controlled values/errors and explicit checkout action; disclosure callbacks themselves only change local state. **M1 blocks the missing/invalid-address editing requirement.** |
| Localized orders with canonical APIs | `backend-sdui/internal/bff/components.go:83–89`, `screens.go:305–310,339` and renderer `Presentation.kt:22–43` localize labels only. OrderCard props, option values, queries and payment/status enums remain canonical. Orders retain explicit Apply, pagination and empty-state handling; the real order card is scoped in the changed journey assertions, while independent API checks still assert `PAID`, `CAPTURED`, `CARD` and exact amounts. |
| Profile overview, actual addresses and legacy forms | `backend-sdui/internal/bff/screens.go:356–380` checks every address owner before publishing, selects the actual default/first address and keeps the complete original address nodes/chunks in source order. `profile_content.go:12–57` retains bounded all-address generation. Renderer `SduiForms.kt:102–110` discloses non-default address text/blocks. `SduiScreen.kt:222–243` renders actual profile routes/native actions and typed destructive logout. The legacy profile Form builder, decoder/model, renderer fallback, SubmitForm dispatch and UpdateProfile command remain available; removing the current inline editor did not delete the legacy wire capability. |
| Controlled biometric truth | `SduiScreen.kt:225–230` requires the original typed ManageBiometric action plus idle state and available-or-configured capability. Checked state comes exclusively from the nonserialized configured snapshot, including configured-but-unavailable. `EpsBiometricRow` owns one row toggle callback with no optimistic checked state; Task 2's existing bridge refreshes native truth after completion/failure/cancellation. Renderer UI tests cover unavailable/unknown-action disabling and configured truth after a tap. No fabricated notifications action or enabled photo upload was introduced. |
| Exact money and no display truncation in formatting | `feature/home/home-domain/src/commonMain/kotlin/com/shopkmp/home/domain/Money.kt:22–32` only changes display output: integer Long division/remainder, grouping, comma and two kopeck digits. Canonical `toString`, parsing, arithmetic and types are unchanged. `MoneyTest.kt:18–35` includes zero, whole rubles, one kopeck, grouping and exact `Long.MAX_VALUE` kopecks. Renderer `Presentation.kt:16–20` groups the validated decimal string directly; there is no Float/Double conversion or dropped fraction. |
| Protocol/business/security preservation | Eight screens, six commands and schemaVersion 1 remain. All three JSON schemas and inspected canonical Kotlin models/Forms are byte-identical to HEAD. No Task 3 edits occur in the Yii client/domain or command handler, canonical PHP contracts/business sources, media policy or payment URL policy. The seven fixture diffs are intentional presentation changes; fixtures remain test-only builder documents, separate from installed journeys' API-discovered synthetic product/accounts. No mutation retry or origin/security relaxation was added. |
| Runners and honest evidence | Both runners feed one validated `--backend-url` into app build configuration and the independent verifier. iOS uses the corrected existing temporary root and a private `TemporaryDirectory` for raw xcresult/diagnostics, with allowlisted evidence extraction; no new raw-credential logging path is introduced by the diff. The Android verifier receives the same origin as its Gradle build override. Both retain independent business-state assertions and the zero-orders-before-confirmation check. The real installed execution remains pending, and M1 must be addressed before claiming the adjusted journeys pass. |

## Verification performed for this review

- Dedicated read/search tools returned `Bun is not defined`; Python was used for
  read-only inspection and source/state checks.
- `python3 scripts/route_matrix.py --check` — **exit 0**:
  **54 exact operations/policies, eight screens and six commands**.
- `git diff --check` — **exit 0** at review time.
- Python AST parse of both installed runners — **pass**, without running them.
- Byte comparison against HEAD of the three schemas and inspected canonical
  Kotlin models/Forms — **unchanged**. Exactly seven changed JSON fixtures, all
  with `schemaVersion: 1`.
- Focused Go execution, fixture-update mode explicitly removed — **exit 0**,
  package test time **0.894s**:

  ```sh
  env -u UPDATE_FIXTURES go -C backend-sdui test ./internal/bff -run '^Test(Presentation|Published|TrustedAccountOwnershipMismatchIsNotPublished|HomeAggregatesConcurrentlyAndOnlyNonsecurityFailuresArePartial|HomeOrdinaryFailureCannotHideLaterSiblingSecurityFailure|ReviewI1MalformedSecurityBodyFailsClosedAndCancelsHomeSiblings|FollowupN1NeighboringUUIDOwnership|CommandSchemaMatchesActualAdapter)' -count=1
  ```

- Inspected existing renderer/chrome/main/home-domain/cart XML results and the
  relevant test implementations. Task 3 reports **92 JVM and 112 iOS target
  executions, zero failures/errors/skips**, and **110 Go top-level passes plus
  one existing opt-in live-test skip**. These remain attributed to its recorded
  run, not presented as a fresh full-suite execution by this reviewer. Concurrent
  Task 2 source/test work is outside this task's verdict.
- No full-suite rerun, app/device operation, backend mutation or payment operation
  was performed during this review. Photo-upload capability and notifications
  remain honestly unsupported as documented; they are not additional findings.

## Acceptance action

Correct M1 and verify the two missing disclosure transitions. Then complete the
coordinator's installed/live and visual gates already listed in the plan. The
focused passing protocol/security checks do not establish checkout usability or
reference-UI conformance on either installed platform.

Only this review report is authored by this review; no implementation/test file,
subagent, commit or push is part of it.
