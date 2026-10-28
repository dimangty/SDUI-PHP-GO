# Final whole-UI implementation review

Date: **2026-10-28**. Baseline:
`b9def93963ef08b1d19bedf957c0b3d3d003add7` → UI checkpoint
`32675b249b861f1e19bfb1149fdbd44ea7734dd2`, including the current post-checkpoint
Android verification/privacy guard, runner, documentation and evidence.

## Verdict

- **Code quality: PASS for the reviewed UI delta. Critical: 0; High: 0;
  Medium: 0.** No new concrete defect at these severities was established.
- **Specification: PASS for the agreed implementation scope**, including the
  explicitly accepted capability limits. The ten-state composition, real input
  and action semantics, account-safe editor, controlled biometrics and preserved
  commerce/protocol boundaries are supported by the source and recorded tests.
- **Installed functional verification is corroborated:** the final iOS and
  Android receipts each record one passing full journey and 13/13 app exports.
  The manifest matches the current test sources and all 26 public PNGs match
  their accepted private-run copies.
- **Direct visual/reference acceptance is not independently established by this
  review.** The image-read tool is unavailable (`Bun is not defined`). I inspected
  the SVG source, production implementation, receipts and binary integrity, but
  did not directly view the PNGs/contact sheets. The qualitative observations in
  `INSTALLED_VISUAL_REPORT.md` remain attributed to that inspection, not to this
  reviewer. No pixel-match or complete-feature claim is made.

This is an **AI expert source/evidence review**, not an independent human review.
The main/coordinator's concurrently running final frontend and iOS compilation
gates are separate execution evidence. The unresolved **A1 backend runtime-image
gate still blocks overall release/push**. Local tracking was **0 behind / 2 ahead**;
that is not permission to push.

## Findings

| Severity | New concrete findings | Disposition |
|---|---:|---|
| Critical | 0 | None established |
| High | 0 | None established |
| Medium | 0 | None established |

Previously closed findings remain closed on the current source:

- **Task 2 M1 — global-Koin editor fallback:**
  `feature/profile/profile-ui/src/commonMain/kotlin/com/shopkmp/profile/ui/EditProfileViewModel.kt:44–67,113–120`
  has an explicitly optional account source, not a service-locator default.
  The production screen always supplies the live lookup and matching flow from
  one existing BackendSession
  (`feature/profile/profile-ui/src/commonMain/kotlin/com/shopkmp/profile/ui/EditProfileScreen.kt:36–45`).
  The source-free legacy entry is documented as editor-lifetime-only. The direct
  construction/edit/save regression remains in
  `feature/profile/profile-ui/src/commonTest/kotlin/com/shopkmp/profile/ui/EditProfileViewModelTest.kt:26–44`.
- **Task 3 M1 — address auto-collapse during input:**
  `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiForms.kt:67–84`
  initializes and latches automatic disclosure; resolving the final blank/error
  does not remove the active editor. Both actual-input regressions remain at
  `core/sdui-renderer/src/iosTest/kotlin/com/shopkmp/sdui/renderer/ShoppingPresentationUiTest.kt:122–189`.
- **Installed auth IME clipping:**
  `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/base/extension/HostedFormInsets.kt:11–23`
  and the Login/Signup callers consume the host-reserved navigation inset before
  applying the remaining IME inset. Hosted-root regressions and the subsequent
  completed installed registration flows support the correction. The earlier
  failed attempts remain recorded; they are not treated as current failures.

There is no new evidence warranting reopening those reports. Unsupported upload,
notifications and unexecuted device configurations below are limitations, not
invented source findings.

## Scope and method

Read `PLAN.md`, all `TASK1–3_REPORT.md`, `TASK1_REVIEW.md`, both Task 2/3 initial
reviews and rereviews, `CHECKPOINT.md`, the current `INSTALLED_VISUAL_REPORT.md`,
`docs/SDUI_CONTRACT.md`, and `docs/images/frontend-sketch.svg`.

Inspected the actual baseline-to-current production diff: **55 commonMain Kotlin
files, three Go presentation files and two feature Gradle files**, plus all seven
changed presentation fixtures, the new/changed regression tests, both installed
journeys and both runners. Complete relevant editor/host implementations and
their existing repository/session/native boundaries were read to check behavior
across the diff. Dedicated read/search tools failed; read-only Python and git
inspection were used instead.

Post-checkpoint source inspection confirms only these verification files differ
from the UI checkpoint within the reviewed app/BFF/runner paths:

1. `composeApp/src/androidInstrumentedTest/kotlin/com/shopkmp/InstalledJourneyTest.kt`
2. `scripts/run_android_installed_journey.py`

The other continuation changes are reports/evidence. The separately modified
`backend-yii2/Dockerfile` and untracked `scripts/test_php_runtime.py` are unrelated
A1 work and are not counted as UI implementation files.

## Specification and implementation checks

### Shared UIKit, auth and native input

- `core/uikit/src/commonMain/kotlin/com/shopkmp/ui/theme/ShopTokens.kt:5–15`,
  `core/uikit/src/commonMain/kotlin/com/shopkmp/ui/theme/Theme.kt:8–55` and
  `core/uikit/src/commonMain/kotlin/com/shopkmp/ui/theme/AppShapes.kt:20–31`
  implement the teal/warm reference
  palette, explicit Material surface roles and shared field/button/card shapes.
  Button, card, chip, divider, placeholder, image, text and surface changes remain
  presentation changes; they do not replace business values or media routing.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/textfield/defaulttextfield/EpsTextField.kt:45–100`
  puts the caller tag and accessible label/error on the actual BasicTextField,
  forwards enabled/readOnly, keyboard options/actions and transformation, and
  retains visible validation. External labels do not create duplicate input
  semantics. Password visibility remains a real, capability-enabled icon action
  in `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/textfield/passwordtextfield/EpsPasswordTextField.kt:18–40`;
  masking/security semantics are covered by real UI tests.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/topbar/EpsTopBar.kt:19–64` and
  `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/navigationbar/EpsNavigationBar.kt:21–47` use actual
  icon controls and caller-owned selection/callbacks. Title/action tags are not
  duplicated into hidden body nodes. The finite Canvas registry introduces no
  remote icon lookup or new wire enum. Compatibility constructors retain the
  inspected old positional and trailing-lambda call forms.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/auth/EpsAuthContent.kt:47–82,91–120,130–159`
  keeps scrollable Login/Registration/PIN content and the real existing auth
  alternatives.
  `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/pin/EpsPinInput.kt:35–108` draws
  expandable masked dots, circular keys, real backspace and a non-actionable lock.
  `feature/auth/auth-ui/src/commonMain/kotlin/com/shopkmp/auth/ui/pin/PinViewModel.kt:32–46,60,113–116,128–175`
  retains **4–6 digits and explicit submission**: entering four digits does not
  authenticate or truncate a six-digit credential. Generic greeting replaces
  the fabricated account name without adding identity lookup/state.
- Insets have a consistent owner: BaseScreen reserves auth status/navigation
  space; the hosted form helper accounts for its sibling boundary. The native
  editor's ancestor owns its system padding; its app bar is fixed outside the
  scrolling body. Existing hosted-root tests exercise both modeled inset shapes,
  not a no-op keyboard workaround.

### Native editor and biometric truth

- `feature/profile/profile-ui/src/commonMain/kotlin/com/shopkmp/profile/ui/EditProfileViewModel.kt:49–185`
  invalidates editor revisions, cancels owned work, checks the live account before
  queued work/results/callbacks, sets the save flight synchronously, snapshots the
  submitted fields, preserves failed-save drafts and distinguishes Back from
  accepted Save. The repository command still has no email argument.
- `feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt:60–77`
  captures the **raw account owner plus editor ID**, keys the editor by both and
  places it outside ordinary chrome/bottom navigation. Host callbacks verify
  current account/open editor/ID at
  `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:187–216`.
- `feature/profile/profile-ui/src/commonMain/kotlin/com/shopkmp/profile/ui/EditProfileStateBuilder.kt:35–65`
  supplies readonly/selectable email and **no photo callback**.
  `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/shop/EpsShopLayout.kt:157–203`
  enables photo only for a non-null capability and otherwise shows the unavailable
  caption. Its non-null UI-test branch proves callback wiring, not a real upload
  implementation. The lock is not a fake support/upload action.
- `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:61–88,131–141,377–405,431–434,464–472`
  derives biometric availability/configuration from the real adapter, refreshes
  at relevant host/native transitions, clears on account reset and does not
  serialize snapshots in SavedHost. Generation/account guards reject stale host
  completions; the native action is single-flight and not optimistically checked.
- `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/ShopNativeSduiAdapter.kt:27–50`
  preserves configured-but-unavailable truth and permits revocation in that
  state. The existing authenticated disable path needs no hardware prompt.
  `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiScreen.kt:225–230`
  requires the actual typed ManageBiometric action, idle host and available-or-
  configured capability. The controlled row owns one toggle callback.

### Chrome, shopping renderer, exact money and BFF

- `feature/main/src/commonMain/kotlin/com/shopkmp/main/container/SduiChrome.kt:22–58`
  promotes real typed nodes/actions; Product retains its actual name in the body.
  `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/RenderPlan.kt:24–48`
  excludes only promoted Text/Button nodes, keeps stable lazy identity and
  discloses address content without adding remote actions. Host lifecycle,
  account-keyed saveable state, pending-add recovery, original intent keys and
  native confirmation remain in the actual main/VM path.
- `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiForms.kt:47–126`
  keeps editable values/errors host-controlled. Checkout/payment/address and
  order-filter disclosures modify local presentation only. Actual submit/Apply
  remains explicit. The address latch protects blank-to-valid and last-error
  transitions. Additional addresses remain reachable, including bounded blocks.
- `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/Presentation.kt:16–51`
  formats validated decimal strings without Float/Double and applies the unchanged
  MediaPolicy to cart thumbnails. Oversold/unavailable warnings, absolute quantity
  reduction and increase ceilings remain. `SduiScreen.kt:143–206,222–279` in that
  package keeps real price/stock/title/media, typed action enablement, full-width
  shopping CTAs and canonical order/payment values with local display labels.
- `feature/home/home-domain/src/commonMain/kotlin/com/shopkmp/home/domain/Money.kt:22–32`
  changes only Russian display formatting using integer division/remainder.
  Parsing, arithmetic, canonical toString and wire types are unchanged. Coverage
  includes exact Long.MAX_VALUE kopecks and the preserved cart UI expectation.
- `backend-sdui/internal/bff/screens.go:47–138,199–237,305–310,330–381`,
  `backend-sdui/internal/bff/components.go:83–89` and
  `backend-sdui/internal/bff/profile_content.go:12–57` change composition/labels,
  not schema or business policy. Home still rejects sibling security failures
  and mismatched session owners before publication. Profile checks every address
  owner before selecting the actual default/first summary; all returned address
  nodes/chunks remain. Product retains safe image identity/full descriptions;
  order/filter enum values remain canonical. The seven fixture changes follow
  these builders and are not installed-data substitutes.
- All **three JSON schemas are byte-identical to baseline**. Go SDUI/domain/Yii
  boundary code, common model/action code, network/auth/profile data and native
  checkout data sources have no UI delta. The 54-operation / eight-screen /
  six-command contract remains the recorded verification result. The existing
  legacy profile decoder/form/UpdateProfile command is retained despite omission
  of the inline form from the current overview.
- Checked the real unchanged payment path rather than inferring safety from a
  passing screenshot: `feature/home/home-data/src/commonMain/kotlin/com/shopkmp/home/data/BackendShopRepository.kt:165–215`
  retains same-intent order idempotency and one confirmation followed by trusted
  reconciliation on error. The current adapter does not replay checkout/payment.
  No UI/BFF diff introduces mutation retries or relaxes media/payment URL policy.

## Test preservation and evidence provenance

Applied the KMP skill in **review / update-scoped** mode. Editor is a CMP shared
form/command with lifetime races; host is a state/command/callback machine. Existing
commonTest targets and local fakes are reused. Repository/account inputs are
replaceable common interfaces/callbacks/flows; plain lifecycle ViewModels use
test Main, and host collectors belong to the harness backgroundScope. Suspended
fakes exercise late results without substituting terminal states for cleanup.

Fresh read-only baseline comparisons established:

- **17/17 original SduiViewModel test bodies** and **2/2 original PIN VM test
  bodies** remain exact. The existing ProfileStateBuilderTest and
  CartScreenUiTest files are byte-identical to baseline.
- Auth UI changes retain the original callback assertions, move email input to
  the actual tag and make the submit fixture valid for the agreed PIN display
  contract. Money's changed display assertion retains exact canonical assertions.
  New tests cover actual inputs/callbacks, duplicate saves, stale editors/accounts,
  native outcome snapshots, unsupported capabilities and no-submit disclosures.
- Existing XML at inspection contained **93 JVM and 119 iOS simulator target
  executions**, with zero failures/errors/skips in those files. They have
  **2026-10-27 timestamps** and are **existing results**, not fresh review runs or
  a verdict on the concurrently executing final gates. Historical RED/GREEN and
  compile evidence remains attributed to the task reports.

### Accepted installed runs

| Platform | Receipt | Case time | Wall time | Tests / failures / skips | App PNGs |
|---|---|---:|---:|---:|---:|
| iOS | `20261028T014901619564Z` | 298.542s | 315.906s build/test | 1 / 0 / 0 | 13/13 |
| Android | `20261028T020941547858Z` | 54.192s | 55.476s instrumentation | 1 / 0 / 0 | 13/13 |

Both safe receipts contain the terminal full-journey milestone and exit 0, plus
actual six-digit PIN login, native readonly-editor/Back checks and canonical
CAPTURED/PAID verification. Sources inspected:

- `iosApp/iosAppUITests/InstalledJourneyTests.swift:24–144,168–206,265–328,376–401,566–572`
- `composeApp/src/androidInstrumentedTest/kotlin/com/shopkmp/InstalledJourneyTest.kt:49–225,232–367,430–453,491–495`

They perform UI registration, six-digit creation **and actual subsequent PIN
login**, assert no auto-submit at four, then real password login. They check zero
orders before confirmation, actual order/stock/cart state, browser completion and
logout/relaunch. Both independently authenticate a read-only business observer;
observer tokens are not injected into the app. Each browser completion control
has one payment click, with no blind replay on timeout. The accepted runs use the
same API-discovered SKU serially, as recorded; a concurrent rerun was not launched.

### Privacy guard and safe exports

- Public synthetic email/buyer identifiers use randomness **independent of the
  password** (`InstalledJourneyTests.swift:25–29`; `InstalledJourneyTest.kt:50–54`).
  Partial PIN captures expose count-only masked state, not entered digit values.
  Empty credential forms are captured before typing; browser/capability and raw
  hierarchy screenshots are not in the explicit app export sets.
- The post-checkpoint Android guard at
  `composeApp/src/androidInstrumentedTest/kotlin/com/shopkmp/InstalledJourneyTest.kt:251–311`
  compares all ten real rendered key regions/bounds against their pre-entry
  released appearance in memory under the existing bounded wait. Count semantics
  are checked separately. The saved export is a **fresh full app screenshot**;
  no pixels are replaced, masked or fabricated. Diagnostics export booleans only,
  not key identities, pixels or input history.
- `scripts/run_android_installed_journey.py:30–74,128–160` fullmatches finite safe
  diagnostic patterns, withholds raw instrumentation output and requires passing
  JUnit/full-journey evidence plus every required PNG. Its new patterns add only
  the released-frame booleans/fixed failure code.
  `scripts/run_ios_installed_journey.py:70–101,145–235` keeps raw XCTest material
  private/temporary, redacts PIN digit IDs from exported failure codes and extracts
  only allowlisted app attachments/diagnostics. Both app/verifier origins are
  configured together; no API commerce-write helper was added.
- Independently verified **all 26 PNG signatures, dimensions, byte lengths and
  SHA-256 values**, exact accepted private-run copies, both contact-sheet hashes
  and their ten explicit ordered input paths, both current test-source hashes,
  safe receipt equality and private directory modes **0700** against
  `evidence/installed-images.json`. iOS is 1206×2622; Android is 1080×2424.
  Contact sheets are 1540×1530. Hash integrity is not visual proof of their content.
- Both current public receipts contain no email, password, bearer/token, URL or
  entered-PIN-key markers in the performed scan. This corroborates source-level
  export filtering; it is not an OCR/visual privacy audit of PNG pixels.
- Retained the earlier Android receipts: two functional passes with rejected PIN
  captures, then the **57.06s failed-closed guard run with 3/13 PNGs**, followed by
  the accepted 54.192s run. All distinct private receipts still exist. Rejected
  attempts are excluded from the final manifest. Historical browser missed-click
  uncertainty likewise remains in Task 3; a later fresh intent did not replay the
  old ambiguous payment.

## Review verification and remaining limits

This review executed read-only git/source comparisons, Python AST parsing of both
runners, the manifest/PNG/receipt checks above, and scoped baseline-to-current plus
staged `git diff --check`; all completed successfully. No concrete new defect
required an additional runtime reproduction. Full frontend-test/lint/build, Go
race/vet, installed journeys and iOS arm64/x64 compilation were **not rerun by this
reviewer**; the main/coordinator's concurrent executions supply their own results.

Remaining acceptance limits are explicit:

1. **Actual-image judgment:** main/coordinator must make its direct assessment
   using the real final PNGs and the same reference. This review cannot endorse
   unviewed pixels or normalize away the recorded layout/copy/data differences.
2. **Capabilities:** photo change is disabled with an honest caption; no native
   upload adapter exists. Notifications have no API/action and are omitted.
   Forgot-password behavior is inherited and is not certified as a newly delivered
   feature. The installed biometrics show actual unavailable/unconfigured state;
   physical enrollment/revocation is not proved by those captures.
3. **Coverage:** local simulator/emulator success and hosted inset fixtures do not
   establish every small screen, Dynamic Type/VoiceOver/TalkBack configuration,
   physical device, or every populated order status. iOS target compilation is
   not physical-device execution.
4. **Integration/release:** the main/coordinator's final gate results must be
   recorded separately; A1 remains unresolved. UI review does not authorize
   backend release or push.

Reviewed production snapshot digest (sorted changed commonMain files, three Go
presentation files and two Gradle files, path/NUL/content/NUL):
`c87b5c7fd2803e9427ed5507b3f171c2b628556b1cbccd3cc35523e8636e8677`.

Only this `FINAL_REVIEW.md` was authored by this review. No source/test/index
changes, subagents, commits, pushes or app/device operations were performed.
