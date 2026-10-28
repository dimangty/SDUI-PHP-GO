# Actual installed ten-state visual evidence

**COMPLETE: final expanded iOS and Android journeys passed serially, with
13/13 accepted app PNGs on each platform.** There are 26 full-resolution app
exports, two ten-state contact sheets and a checksum/source manifest. This is
actual installed-state evidence, not a pixel-perfect or feature-completeness
claim.

## Final accepted results

| Platform | Accepted receipt ID | Actual case time | Actual wall measurement | Tests / failures / skips | Runner exit | Accepted PNGs |
|---|---|---:|---|---|---:|---:|
| iPhone 17 Pro, iOS 26.2 | `20261028T014901619564Z` | 298.542s | 315.906s build + test | 1 / 0 / 0 | 0 | 13/13 |
| Pixel 10, Android 16 API 36 | `20261028T020941547858Z` | 54.192s | 55.476s instrumentation | 1 / 0 / 0 | 0 | 13/13 |

- [iOS actual after contact sheet](evidence/ios-after-contact-sheet.png)
- [Android actual after contact sheet](evidence/android-after-contact-sheet.png)
- [26-image and two-sheet integrity manifest](evidence/installed-images.json)
- Safe current receipts: [iOS](../evidence/ios/xctest-summary.txt),
  [Android](../evidence/android/instrumentation.txt).

After resuming checkpoint `32675b2`, executed **one iOS** expanded case (passed)
and **four Android** expanded cases (three functional passes and one failed
rendered-release gate). The first two Android functional passes had PIN images
rejected during privacy review; only the final Android receipt is accepted for
the final visual set. All attempts and the privacy RED are retained below.

## Historical checkpoint and execution log

At checkpoint `32675b2`, the original status of this document was deliberately:

> PENDING: the expanded ten-state/13-image installed run has not completed.

That interruption checkpoint was not a passing visual acceptance claim. The
following original resume instructions and incremental results are retained as
history; the current accepted results are the table above.

The shared production UI implementation and the preceding installed CARD flows
have passing evidence in TASK1–3 reports and `CHECKPOINT.md`. The screenshot
extension adds real six-digit PIN login, native readonly-editor/back checks and a
finite privacy-safe export set. Existing four commerce screenshots/summaries are
valid for their recorded earlier runs, not the expanded source.

## Resume serially on disposable local devices

Use JDK 17 at
`/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home`.

```sh
python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196 --tmp-root /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode
python3 scripts/run_android_installed_journey.py --serial emulator-5558 --backend-url http://10.0.2.2:58196
```

Do not run both commerce journeys concurrently: their exact stock checks use the
same disposable SKU. Require an actual passing test, full-journey milestone and
13/13 named screenshots on each platform. Preserve all failures/receipt IDs and
privacy restrictions; no browser/capability, plaintext credential or raw hierarchy
export. After capture, visually compare all ten app states with the reference,
including the real paid-order card, native editor and compact profile.

The honest photo-upload/notification limitations and the distinct unresolved A1
backend release gate remain in force.

## Execution resumed from UI checkpoint

Source checkpoint: `32675b249b861f1e19bfb1149fdbd44ea7734dd2`.
The pending statement above is the historical checkpoint status. Expanded
verification is now being executed serially: iOS first, Android second. No
expanded passing result or 13-image acceptance is claimed until its actual
receipt is recorded below. Production UI/A1 sources are outside this work.

### Expanded iOS — actual PASS, 2026-10-28

```sh
JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196 --tmp-root /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode
```

- Runner/xcodebuild exit **0**; **1 passed / 0 failed / 0 skipped**.
- XCTest **298.542s**; build/test wall time **315.906s**.
- Named app exports: **13/13**; actual six-digit PIN-login, native readonly-editor
  cancel, canonical CAPTURED/PAID and terminal full-journey milestones present.
- Receipt ID: `20261028T014901619564Z`; private receipt/images:
  `/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/ios-journey-evidence-20261028T014901619564Z/`.
- Canonical order `880c3eb8-f482-4af0-9e55-ed0fdcd3777a`, exact `50.01`;
  real biometric snapshot checked=false/enabled=false, not changed for capture.

Android expanded execution is next; its earlier four-image PASS is still not
presented as expanded-source evidence. Contact-sheet inspection is pending.

### Expanded Android — actual PASS, 2026-10-28

Started only after the iOS runner had completed; no concurrent SKU mutation.

```sh
JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home python3 scripts/run_android_installed_journey.py --serial emulator-5558 --backend-url http://10.0.2.2:58196
```

- Runner exit **0**; **1 passed / 0 failed / 0 skipped** (`OK (1 test)`).
- JUnit **73.459s**; instrumentation wall time **74.519s**.
- Named app exports: **13/13**; real six-digit PIN login, readonly editor/back
  without profile mutation, CAPTURED/PAID, and final logout/relaunch all passed.
- Receipt ID: `20261028T015510976479Z`; private receipt/images:
  `/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/android-journey-evidence-20261028T015510976479Z/`.
- Canonical order `ee8e874d-d015-46e3-8876-fc7c0234c23b`; real biometric
  snapshot checked=false/enabled=false, not changed for capture.

At this point in execution, the expanded functional source had passed on both
platforms in one attempt each. Subsequent image review rejected the first Android
PIN captures; the later final accepted receipt supersedes them for visual proof.

### Android PIN-image privacy RED found during actual image review

Both expanded journeys above passed their functional checks, but review of the
first Android contact sheet found transient pressed-key shading in the two PIN
images. Such shading can reveal input history, so those captures are **rejected
for final evidence** despite the 13/13 file count and passing JUnit result. No
pressed digit values are recorded here. The temporary preview was stopped.

Minimal test-only correction: before a partial-PIN screenshot, tap the neutral
PIN header and wait for UiAutomator UI idle, then verify the actual native
two/three-count description and capture. No digit, counter, UI production code,
validation, timeout or submission assertion is changed. A fresh serial Android
journey and regenerated image manifest/contact sheet are required next. The
first Android receipt remains historical functional evidence, not final
count-only screenshot acceptance.

The neutral-header/UI-idle candidate also functionally passed (receipt
`20261028T020209138762Z`, JUnit 46.365s, instrumentation 47.572s, 13/13 files),
but actual PNG review still found transient shading. Those PIN images are also
rejected. Accessibility idle is therefore not treated as proof of rendered
release. The final test-only gate compares all ten rendered digit-key regions
against their actual pre-entry released state in memory, under the existing
bounded condition wait. The live partial-count check remains separate. Exported
images are still fresh unmodified full-screen app captures, never key-region
replacement or masking. A new Android run is required for this stronger gate.

Rendered-release gate run `20261028T020647409207Z` correctly failed closed during
PIN login: **1 test / 1 failure**, JUnit **57.06s**, instrumentation **58.046s**,
runner exit **1**, only **3/13** images exported. Setup's released-key capture
passed, but login did not match its initial reference within the unchanged
bounded wait; no PIN-login image or commerce was exported/executed after that
failure. Fixed boolean-only diagnostics are being added to distinguish an
unstable initial reference from a still-pressed current frame before adjusting
the gate. No key identities or pixel values are logged.

### Final Android rendered-release and full-flow GREEN

Repeated the same Android command above after adding fixed boolean-only frame
diagnostics; no production UI change, image masking, palette-constant assertion,
timeout relaxation or payment-action retry was introduced.

- Receipt **`20261028T020941547858Z`**; runner exit **0**.
- JUnit **1 passed / 0 failed / 0 skipped**, **54.192s**; instrumentation
  **55.476s**; **13/13** required exports.
- For both partial PIN states, the diagnostics observed real pressed layers
  before the bounded wait (`currentUniform=false`, `pixelsMatch=false`) and an
  actual released frame afterward (`baselineUniform=true`, `currentUniform=true`,
  `boundsMatch=true`, `pixelsMatch=true`). No pressed key identities were logged.
- The live native count was separately checked, all six digits were entered,
  four digits did not auto-submit, and actual PIN login submitted explicitly.
- Readonly email/no host navigation, disabled photo/caption, Back without Save,
  independent canonical business checks, CAPTURED/PAID and logout/relaunch passed.
- Canonical final Android order: `9d2a7daa-a440-4007-af1d-5933db7c53d0`.
- Accepted private evidence:
  `/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/android-journey-evidence-20261028T020941547858Z/`.

The unchanged shared SKU was used serially. The failed gate aborted before
commerce; later complete runs used fresh accounts and new explicit intents.
No prior ambiguous payment or rejected capture was replayed into acceptance.

## Ten actual reference states and inspection

Source authority: [`frontend-sketch.svg`](../images/frontend-sketch.svg), the
same user reference. Inspected the final actual contact sheets using a loopback
read-only HTTP preview after the file-reading tool returned `Bun is not defined`.
The preview served only the public evidence folder and was stopped after review.
No device/browser capability or raw result directory was served.

| # | State | Actual PNGs | Observed result |
|---|---|---|---|
| 1 | Login | [iOS](../evidence/ios/login.png) · [Android](../evidence/android/login.png) | Empty inputs, teal brand, warm filled fields; no typed credential/autofill capture. |
| 2 | Registration | [iOS](../evidence/ios/registration.png) · [Android](../evidence/android/registration.png) | Empty five-field form, real terms control and teal app bar. Disabled empty submit is intentional. |
| 3 | Create PIN | [iOS](../evidence/ios/pin-create-partial-2.png) · [Android](../evidence/android/pin-create-partial-2.png) | Exactly two masked dots, circular keys, no partial submit; final keys show no entry-history shading. |
| 4 | PIN Login | [iOS](../evidence/ios/pin-login-partial-3.png) · [Android](../evidence/android/pin-login-partial-3.png) | Exactly three masked dots and real lock indicator; actual six-digit authentication follows the capture. |
| 5 | Home | [iOS](../evidence/ios/home.png) · [Android](../evidence/android/home.png) | ShopKMP chrome, real search/profile controls, bounded category paging, compact product cards and actual navigation icons. |
| 6 | Product | [iOS](../evidence/ios/product.png) · [Android](../evidence/android/product.png) | Image first, actual name/rating/price/description/stock, quantity label and full-width add CTA. |
| 7 | Cart/checkout | [iOS](../evidence/ios/cart-checkout.png) · [Android](../evidence/android/cart-checkout.png) | Real cart thumbnail/item, exact total, controlled real address/payment summaries; no order created before confirmation. |
| 8 | Orders PAID | [iOS](../evidence/ios/orders-paid.png) · [Android](../evidence/android/orders-paid.png) | Actual paid order and localized teal status; canonical API PAID/CAPTURED assertions remain unchanged. |
| 9 | Profile | [iOS](../evidence/ios/profile.png) · [Android](../evidence/android/profile.png) | Actual synthetic identity/address, compact action rows, actual disabled/unconfigured biometric state and red outlined logout. |
| 10 | Native editor | [iOS](../evidence/ios/edit-profile.png) · [Android](../evidence/android/edit-profile.png) | Name loaded, readonly email/lock, honest unavailable photo caption, full-width Save, no bottom/ordinary host navigation; left by Back without Save. |

Retained supplemental proof:

- Profile actions: [iOS](../evidence/ios/profile-actions.png),
  [Android](../evidence/android/profile-actions.png).
- Native confirmation before order creation:
  [iOS](../evidence/ios/checkout-confirmation.png),
  [Android](../evidence/android/checkout-confirmation.png).
- Actual purchased order detail:
  [iOS](../evidence/ios/order-detail.png),
  [Android](../evidence/android/order-detail.png).

The four original commerce export names remain in the final sets. Public account
identifiers in Profile/editor use randomness independent of the password. No
plaintext password, PIN sequence, provider browser page/URI, token or raw
hierarchy is in the accepted exports. Biometric state was observed, not changed.

## Contact-sheet generation and integrity proof

Generated the two sheets from the saved PNGs, in explicit reference order, using
a private Swift/AppKit helper based on the existing before-sheet tool:

```sh
swift /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/make-installed-after-evidence.swift /Users/dmitry/Documents/Go/SDUI/SDUI-PHP-GO
```

Exit **0**. Each sheet is **1540 × 1530** and uses exactly the ten named app PNGs;
the helper preserves aspect ratios and draws captions outside the screenshots.
It does not draw or replace UI content. The three supplemental images are not
substituted for any reference state.

Verified all **26** full-resolution PNG signatures, dimensions and SHA-256 hashes
against their distinct private accepted run copies. iOS images are
**1206 × 2622**; Android images are **1080 × 2424**. Both private evidence
directories are mode **0700**. `installed-images.json` records all source hashes,
actual receipt IDs/test times, test-source hashes and each sheet's ten explicit
input paths and checksum. Failed/rejected Android runs are excluded from that
manifest and from the final public images.

## Remaining visual differences and limits

- This is a qualitative comparison of real app states, not a normalized
  pixel-match measurement. Native status/navigation bars, viewport, fonts and
  actual content differ from the conceptual sketch.
- Empty Login/Registration captures intentionally have disabled submits, unlike
  the reference's prefilled enabled examples. Credential privacy takes priority;
  enabled submission was exercised later in the real flows.
- Home's real 766-category dataset requires a range label and full-width next-page
  control before popular products. These extra controls occupy more space than
  the reference's three static category chips; pagination was not hidden or faked.
- Cart retains its separate Delete and collection-count rows and explicit summary
  Edit controls. Some title/CTA copy and left-aligned app-bar titles also differ
  from the reference, including `История заказов` / `Оформить заказ`.
- Long synthetic profile emails wrap in the overview and scroll/clip inside the
  readonly field. The full native values were verified. Actual sample products,
  media, price, stock and one-item/one-order data differ from the reference's
  headphones/blender and several-order examples; they were not replaced with
  decorative fixtures.
- **Photo change remains disabled**, with its actual unavailable caption. There
  is no native photo/upload feature to certify. **Notifications have no API/action
  and are absent**. Both captured biometric controls honestly show the current
  unavailable/unconfigured state; no enrollment was fabricated for a picture.
- Filled PAID cards were captured, not every populated canonical status. These
  local simulator/emulator runs do not certify physical devices, every small-screen
  or accessibility configuration, or 100% reference/feature conformance.
- The historical browser missed-click uncertainty remains in TASK3_REPORT;
  no blind payment retry was added. The separate unfinished A1 runtime-image
  release gate remains outside these passing UI/test-only results.

## Scope and handoff

After checkpoint `32675b2`, source changes are limited to the Android installed
test's rendered PIN-release privacy guard and its runner's fixed safe diagnostic
patterns, plus evidence/README/report updates. Shared production UI, backend,
schemas, runtime image and original SQL were not edited or rebuilt here. Full
frontend gates remain the coordinator's next step after this test-only change.
No subagents, commits or pushes were used.

Final handoff checks passed: PNG signatures/dimensions/SHA-256 and private-run
copies, current installed-test source hashes, runner AST parsing, working-tree
and staged whitespace checks. Staged only **22 new verified evidence artifacts**:
20 PNGs (18 new individual states plus two sheets), the integrity manifest and
its README. No build result, raw log, secret or A1/source file was staged. Existing
tracked image/receipt/report updates and test-only changes remain visible in the
working tree for coordinator review; no new commit was created.
