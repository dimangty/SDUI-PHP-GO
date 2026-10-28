# ShopKMP reference UI — final verification, 2026-10-28

**Shared UI implementation and functional verification: PASS within the declared
scope.** Major visual mismatches from the before-audit are corrected. Actual
Android/iOS ten-state evidence is available. This is not a pixel-perfect or
complete-feature certification; remaining differences are explicit below.
**Overall backend release/push remains BLOCKED by unrelated A1 image findings.**

## Delivered

- Teal `#0F766E`, warm `#FFFDF8`, beige filled fields and consistent bordered
  cards/12dp primary buttons across native and SDUI screens.
- Real app bars/back/search/profile/edit/refresh icons and four bottom icons.
- Compact Login/Registration, circular PIN keys, teal count-only dots, vector
  backspace/lock, no disabled submit in partial states; explicit **4–6 digit**
  submission and generic pre-auth greeting.
- Image-first Product, exact Russian display money, real cart thumbnail and
  compact address/payment summaries with safe local disclosure.
- Localized order statuses; compact Profile/action rows; real controlled
  biometric state; red outlined Logout; account-safe native editor with readonly
  email/lock, no bottom navigation and full-width Save.
- Existing v1 schemas, API money strings/enums, eight screens, six commands,
  upstream business ownership, media policy, account guards, checkout
  confirmation and no-blind-mutation-replay behavior retained.

## Executed gates

| Check | Actual result |
|---|---|
| `make frontend-test` (unit tests + Kover) | PASS, exit 0; thresholds/exclusions were not relaxed |
| `make frontend-lint` | PASS, exit 0; the earlier six wildcard imports were fixed |
| `make frontend-build` | PASS, exit 0; Android Debug APK produced |
| `:composeApp:compileKotlinIosArm64` and `:composeApp:compileKotlinIosX64` | PASS, exit 0; compile, not physical-device execution |
| Focused VM/Compose suites | 93 JVM / 119 iOS simulator target results, zero failures/errors/skips in the reviewed XML; common scenarios overlap across targets |
| BFF normal/race/vet, ordinary fixture checks | PASS; no update-mode check substituted for normal verification |
| Explicit live `TestLiveYiiEightScreensAndActions` with `-race -count=1` | PASS, exit 0, 4.93s case; 77 recorded real HTTP steps, actual eight screens and six commands |
| `scripts/route_matrix.py --check` | PASS, exit 0; 54 PHP operations / eight screens / six commands |
| Expanded installed iOS | PASS, xcodebuild/runner exit 0; 1 passed / 0 failed / 0 skipped; 298.542s test; **13/13** accepted PNGs |
| Expanded installed Android | PASS, runner exit 0; 1 passed / 0 failed / 0 skipped; 54.192s test; **13/13** accepted PNGs |
| Separate source/evidence review | No new confirmed Critical/High/Medium findings; see FINAL_REVIEW.md |

The last coordinator command ran all three frontend Make gates and both iOS
compile targets together successfully. Gradle reused some up-to-date/cache
outputs; the invocation is not described as re-executing every scenario.
Existing expect/actual, BackHandler and date API warnings remain.

## Installed and visual evidence

- [iOS ten-state sheet](evidence/ios-after-contact-sheet.png)
- [Android ten-state sheet](evidence/android-after-contact-sheet.png)
- [26-image integrity/source manifest](evidence/installed-images.json)
- [Complete serial run receipts and failure history](INSTALLED_VISUAL_REPORT.md)
- [Before-audit](../backend-fixes/IOS_VISUAL_AUDIT.md)

The coordinator directly viewed **both actual after contact sheets** through a
temporary loopback, read-only PNG preview. This is separate from the source
reviewer's integrity checks; no unviewed-pixel claim is attributed to that reviewer.
The real captures show the corrected palette/chrome/navigation, circular PIN
layout, compact commerce/Profile and standalone native editor. They come from
actual installed apps and real disposable SQL-backed data, not design renders.

Both flows verified real six-digit PIN setup **and login**, no auto-submit at
four, UI password login, buyer identity, exact order/amount/stock/cart,
zero orders before confirmation, CAPTURED/PAID, browser return, readonly editor
and Back without profile mutation, and final logout/relaunch.
App and independent verifier use the same configured origin: iOS
`http://127.0.0.1:58196`, Android `http://10.0.2.2:58196`.

The live Go case also verified all six commands against HTTP, large category
paging, conflict/ownership rejection, actual checkout/payment/cancel/stock
restoration, revoked PIN-family 401, and logout 401. No real provider or production
customer was involved.

## Regressions found and corrected

1. Global-Koin editor fallback removed; real screen supplies one live session
   callback/flow, while legacy VM calls have a documented lifetime-only fallback.
2. Automatic checkout disclosure latches open while a blank/error address is
   completed; the last valid character cannot hide house/apartment input.
3. Installed iOS registration exposed double keyboard-inset consumption: actual
   112pt viewport is corrected to 413pt through the common hosted-inset helper.
4. The XCTest helper accepted a hittable **6.6pt** field sliver; it now scrolls
   bidirectionally and requires the real control inside the visible viewport.
5. Android partial-PIN images with transient key shading were rejected. The
   bounded, test-only rendered-release guard captures a fresh unmodified frame
   only after all key regions match their released state. Failed/rejected
   attempts remain recorded; no digit sequence or image masking is exported.

Historical browser timeouts were not erased. Later fresh logical operations
passed; no uncertain payment action was automatically retried into success.

## Remaining differences and limits

- **Photo upload is unavailable:** the proper photo control is disabled with its
  honest caption. No picker/upload/private-avatar feature was fabricated.
- **Notifications have no API/action and are absent.** Biometric captures reflect
  the actual unavailable/unconfigured local state rather than the sketch's “on”.
- Empty auth captures intentionally have disabled submit; product names/prices,
  profile identity/address, 766 fixture categories and one paid order are real test
  data, not the reference's literal demonstration data.
- Pagination/refresh/filter/delete/edit controls, some title/CTA wording and
  app-bar alignment differ. They preserve working functionality; no exact
  pixel-match percentage or literal all-state equality is claimed.
- Tests used local iPhone 17 Pro/iOS 26.2 simulator and read-only Pixel 10/Android
  16 emulator. Physical devices, every small screen, dark mode, all accessibility
  configurations and every populated order status were not certified.

## Git and continuation

- Backend local checkpoint: `b9def93963ef08b1d19bedf957c0b3d3d003add7`.
- User-requested UI local checkpoint:
  `32675b249b861f1e19bfb1149fdbd44ea7734dd2`.
- Post-checkpoint Android evidence privacy guard and final verification artifacts
  follow that checkpoint and are included in the next user-requested working
  variant commit; `git log` identifies the resulting SHA.
- Subsequent supported runtime integration and current backend/TLS/security
  results: [A1 runtime report](../backend-fixes/A1_RUNTIME_RESULTS.md). Remaining
  vendor Java High findings still block release/push. No push/remote CI or
  production deployment occurred.
