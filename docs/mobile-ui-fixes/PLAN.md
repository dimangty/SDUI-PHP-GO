# ShopKMP reference UI implementation plan

> For agentic workers: use the installed subagent-driven-development workflow,
> task-scoped review and final verification. Do not commit or push automatically.

**Goal:** correct the shared CMP UI against the user's ten-screen sketch.
**Architecture:** PHP business ownership and the Go HTTP/SDUI boundary stay in
place. Shared Kotlin components own visual tokens and native chrome; existing
v1 nodes/actions remain supported. BFF edits are presentation-only.
**Spec:** the user-provided image, `docs/images/frontend-sketch.svg`, and
`docs/backend-fixes/IOS_VISUAL_AUDIT.md`.

## Baseline and constraints

- HEAD `b9def93963ef08b1d19bedf957c0b3d3d003add7`, branch
  `refactor/backend-production-hardening`.
- The latest user instruction expressly authorizes mobile UI changes; this
  overrides the earlier backend task's mobile-edit prohibition for this work.
- Preserve the interrupted, unrelated A1 edits in `backend-yii2/Dockerfile` and
  `scripts/test_php_runtime.py`. A1 still blocks the overall backend release.
- Native iOS baseline: local iPhone 17 Pro/iOS 26.2; real Debug app and disposable
  backend `http://127.0.0.1:58196`. Before screenshots are in the private
  `.../T/opencode/ios-check-20261027/visual-*.png` evidence directory.
- Six-module Android-hosted KMP unit baseline command below: exit 0, 28 seconds.
  Profile has existing common tests; the new EditProfile ViewModel suite must
  reuse or minimally extend that test environment.
- No new serialized nodes/actions, API enums, money types, authentication paths,
  unsafe media URLs, mutation retries, brokers or persistence mechanisms.
- Existing selectors stay on real editable/actionable elements. No invisible
  duplicate nodes or weakened tests. Preserve 4–6 digit PIN support.

## Shared interfaces

1. `ShopTokens` in `core/uikit/.../ui/theme`: Primary `#0F766E`, Background
   `#FFFDF8`, Field `#F1EFE8`, Border `#D7D2C8`, PrimaryContainer `#D9EFEC`, Text
   `#1F1F1B`, SecondaryText `#5D5B55`, Danger `#B91C1C`.
2. A finite local `EpsIconName`/`EpsIcon` registry, no additional icon dependency.
3. Defaulted `EpsTopBarState.titleTestTag`, `backTestTag`, `actions`;
   `EpsTopBarActionState(icon, contentDescription, testTag, enabled, onClick)`.
4. Optional explicit navigation icon without breaking trailing-lambda callers;
   `EpsButtonState.destructive=false`; field `readOnly=false`, keyboard actions,
   and defaulted trailing-icon composable slot.
5. Defaulted read-only native-adapter biometric availability/configuration,
   nonserialized HostState snapshots, and controlled `EpsBiometricRow`.
6. `EditProfileScreen(..., accountKey)` for account-safe ownership;
   `SduiScreen` gains defaulted named chrome-node/biometric presentation arguments.
   Server models/schemas are unchanged.

## Task 1: shared design system, authentication and PIN

**Owner/files:** shared theme/tokens, EpsColor/surfaces, button/field/text/card/
chip/divider/placeholder/quantity, icon/topbar/navigation components,
`EpsAuth*`, `EpsPinInput`, auth Pin presentation and related existing tests.

- [ ] Apply explicit teal/warm colors, compact filled fields and approximately
  12dp/48dp primary CTAs, rounded cards/media and compact quantity controls.
- [ ] Implement app-bar and navigation icons, test-tag semantics and inset policy.
- [ ] Align Login/Registration layout; keep existing auth alternatives reachable.
- [ ] Circle PIN keys, teal dots, vector backspace/lock, generic greeting.
  Hide submit only for invalid/partial state; valid explicit submit still accepts
  six digits. Never auto-submit four and break existing six-digit credentials.
- [ ] Add focused RED/GREEN cases around changed PIN presentation/semantics and
  execute existing common/UI tests. Report exact new interfaces for later tasks.

## Task 2: native profile and biometric-state bridge

**Owner/files:** profile EditProfile screen/state builder/ViewModel/tests/Gradle;
EpsProfile/Avatar/EditAvatar/BiometricRow; SduiViewModel, native adapter and tests.
Only this task edits those shared files.

- [ ] Fixed teal Edit Profile app bar, horizontal avatar/action, readonly email
  with lock, full-width save and stable actual-control selectors.
- [ ] Guard account/editor ownership, late callbacks, duplicate saves and errors;
  bootstrap the smallest missing commonTest seam using the installed KMP skill.
- [ ] Expose real biometric state, refresh after native actions/account changes,
  retain checked-but-unavailable truth and no optimistic toggle.
- [ ] No photo-picker/upload adapter exists. Render an honest capability-aware
  photo control; do not fake enabled upload or bypass protected-media policy.
  Record that remaining functional limitation rather than claim upload works.
- [ ] Focused state/error/account-race RED/GREEN tests and affected target compiles.

## Task 3: main chrome, renderer and BFF presentation

**Owner/files:** MainContainerScreen/chrome helper, SDUI renderer/plan/icons/forms,
shopping product/cart/order components, Go BFF screen/component/profile-content
builders, deliberate fixtures, renderer/chrome tests and installed journeys.

- [ ] One branded app bar; promote only real chrome nodes/actions and preserve
  their tags; move native Edit Profile outside bottom navigation.
- [ ] Compact Home search/categories/cards instead of large profile block;
  keep account metadata/ownership checks, paging and ancillary navigation.
- [ ] Product image first, then real name/rating/price/description, quantity
  label and full-width add CTA. Preserve large-text/media bounds.
- [ ] Real cart image through existing media policy; compact total/address/payment
  summary. Expand existing structured fields on missing/invalid address or Edit;
  no new freeform-address wire model or accidental submission.
- [ ] Compact/localized order statuses and filters with canonical API assertions.
- [ ] Compact Profile actions, actual default/additional addresses, real biometric
  switch, red outlined logout. Retain legacy profile-form decoding and command.
- [ ] Update only presentation fixtures and genuinely changed journey assertions;
  parameterize app/verifier backend URL together and preserve private raw logs.

## Verification and final review

- [ ] Unit/VM and Compose UI tests, existing cart UI regression, both platform
  compiles and Android build; do not lower coverage or ignore failures.
- [ ] Go tests/race/vet, normal fixture verification, route matrix 54/8/6.
- [ ] Rebuild only BFF presentation on the known disposable stack; do not build
  interrupted A1 PHP changes accidentally.
- [ ] Installed iOS and available local Android smoke/journeys, before/after ten
  screen captures, real empty and populated-order checks, keyboard/small-screen
  checks and truthful capability limitations.
- [ ] Separate code review; fix Critical/High, rerun affected checks. Publish
  actual results in `docs/mobile-ui-fixes/RESULTS.md`; A1 is a distinct gate.

### Baseline/focused commands

Use `JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home`.

```sh
./gradlew :feature:auth:auth-ui:testDebugUnitTest :feature:profile:profile-ui:testDebugUnitTest :feature:main:testDebugUnitTest :core:sdui-model:testDebugUnitTest :core:sdui-actions:testDebugUnitTest :core:sdui-renderer:testDebugUnitTest --no-daemon
./gradlew :feature:auth:auth-ui:iosSimulatorArm64Test :feature:profile:profile-ui:iosSimulatorArm64Test :feature:main:iosSimulatorArm64Test :core:sdui-renderer:iosSimulatorArm64Test :feature:cart:cart-ui:iosSimulatorArm64Test --no-daemon
make frontend-test frontend-lint frontend-build
go -C backend-sdui test -race ./...
go -C backend-sdui vet ./...
python3 scripts/route_matrix.py --check
```
