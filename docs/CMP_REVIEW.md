# Independent review — Task 3 CMP SDUI integration

Date: 2026-10-18. Reviewed against Task 3, global constraints and review focus in
`docs/IMPLEMENTATION_PLAN.md:18–34,67–84`, `docs/MIGRATION_PLAN.md`, and the
**current reviewed** `docs/SDUI_CONTRACT.md` and published wire artifacts.

## Verdict

**Specification: substantially implemented, but acceptance requires changes.**
The four commonMain modules, eight real destinations, UIKit rendering, finite
network boundary, native adapters, local Back and payment-retry correction are
present. The five Important findings below leave logout, reconciled state,
per-intent idempotency, safe rendering and authentication recovery incomplete.

**Quality: changes required. 0 Critical, 5 Important findings.** Two minor
findings are separate. No direct bearer bypass, arbitrary remote execution,
server-selected payment origin, second application HTTP client or cross-account
SDUI cache was identified in the inspected implementation.

## Method and verification limits

Read `docs/CMP_IMPLEMENTATION_REPORT.md`, the three published schemas and all 13
fixtures; inspected `core/sdui-*` commonMain/tests, executor/facade/session and DI,
feature/main host/state/native adapters, native checkout changes/tests, Gradle,
navigation serializers and relevant existing auth/profile/UIKit implementations.
Compared relevant originals in `Shop-Go-master.zip`: Git has no commits, so there
is no usable SHA/diff baseline. In particular, the old executor and repository
were distinguished from the newly added SDUI paths.

No tests or builds were rerun. Existing Android XML was inspected for the model,
facade, ViewModel and render-plan suites: respectively 6, 2, 10 and 2 tests, with
zero recorded failures/errors. The broader Android/build/coverage/detekt and 66
simulator-test results remain the implementation report's recorded evidence,
not independent executions by this reviewer. Findings below are source-level
execution traces and concrete regression cases, not claimed runtime reproductions.
The existing cases do not exercise these particular sequences.

**A full installed, authenticated live-device BFF → Yii → checkout/payment →
orders → logout journey remains unverified**, as already acknowledged by the
implementation report. Physical biometric prompts, payment-app return and
Android process-death restoration also remain verification gaps; they are not
counted as newly discovered defects.

## Important findings

### I1 — Switching tabs can cancel logout before local credentials are cleared

**Locations:**
`feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt:60–69`;
`feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:128–158,289–311`;
`feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/ShopNativeSduiAdapter.kt:39–43`;
`feature/auth/auth-data/src/commonMain/kotlin/com/shopkmp/auth/data/AuthServiceImpl.kt:317–324`.

Start Logout while its HTTP call is suspended, then select another bottom tab.
Tab callbacks remain enabled. `selectTab → transition → leave` cancels
`mutationJob`, which is also running `auth.logout()`. Cancellation propagates
through the executor, so AuthService never reaches `clearTokens()` after its
suspended request; the adapter never returns `SignedOut`. The host instead loads
the selected tab with the locally retained session. Server-side logout may or
may not have committed, but local sign-out has definitely not been completed.

**Impact:** a user-initiated security transition can leave credentials and
personalized UI available. This conflicts with the logout/account-clearing
requirements (`SDUI_CONTRACT.md:332,366–369`). It is an integration regression:
the archived profile logout ran in its own ViewModel scope and was not cancelled
by a main-container tab selection.

**Correction:** give logout a lifecycle that guarantees local credential/state
clearing and the Login root transition even if the remote request is cancelled
or the visible destination changes. Keep ordinary screen-load cancellation.
**Regression:** suspend logout, switch tabs, then verify cleared tokens, cleared
account content/drafts and Login as the final root.

### I2 — Native profile saves are shadowed by stale generic-form values

**Locations:**
`feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:136–137,299–302,329–333,353–355`;
`feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt:64–65`.

Every successful load saves **all** fields as a draft, including unchanged server
values. Subsequent loads unconditionally overlay that draft on the new server
fields. Open Profile, change name/phone through native Edit Profile, save and
return. Both native callbacks call `back()`, which fetches Profile but does not
invalidate its draft. The header displays the newly saved profile while the
generic form still contains the old name/phone. Saving that form, including a
phone-only edit, sends both stale fields and can undo the native update.

**Impact:** a supported native action causes contradictory UI and later data
loss. Retaining inputs on an error is required, but treating every fetched value
as a permanent local edit is not reconciliation.

**Correction:** track dirty edits separately from the server baseline; invalidate
or rebase the profile draft after native save and successful UpdateProfile.
Distinguish native Save from Cancel where necessary. Preserve unsent input on
validation/network failure.
**Regression:** native-save a changed name, return, edit only phone in the generic
form, and verify both displayed fields and the submitted name retain the saved
value.

### I3 — An unresolved AddToCart key is reused for a later, distinct purchase intent

**Locations:**
`feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:96,257–281,347–350`.

`intentKeys` identifies an intent solely by the value-equal `Command` payload.
After an unknown AddToCart outcome, its entry survives GET reconciliation,
navigation and successful commands with different payloads/types. Only a
successful replay of that exact AddToCart, a qualifying failure or account reset
removes it. There is no separate recovery/abandon/new-intent interaction.

Concrete sequence: Add product P, quantity 1; the write commits but its response
is lost. Refresh/read the cart, successfully remove P, reopen P, and press Add
again. The second, new purchase uses the first key. Yii correctly replays the
first successful result without adding anything (`backend-yii2/application/CommerceService.php:17–27`),
the BFF acknowledges success, and the refreshed cart remains empty. The new
command has silently become a no-op.

**Impact:** a normal recovery-followed-by-shopping sequence loses purchase intent.
Preserving a key for explicit same-intent recovery is correct; reusing it for
every future identical payload is not (`SDUI_CONTRACT.md:408–412,429–437`).

**Correction:** represent the pending intent and its fixed payload explicitly;
separate recovery of it from beginning a fresh AddToCart intent. A fresh intent
must receive a fresh key without losing the old key needed for chosen recovery.
**Regression:** verify same-intent recovery reuses its key, but the
unknown-result → successful removal → new Add sequence uses a new key and really
adds the product. The existing duplicate-submit test checks only immediate retry.

### I4 — Accepted option/category duplicates can crash the lazy renderer

**Locations:**
`core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/ScreenDecoder.kt:95–102,109`;
`core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiScreen.kt:139–142,204–210`;
`backend-sdui/contracts/screen.schema.json:128–141`.

Node/action IDs are checked for uniqueness, but select-option values and category
item IDs are not. These are subsequently used directly as `LazyRow` keys. For
example, duplicate the first `catalog.sort` option in the catalog fixture, leaving
all node/action IDs and bounds intact. The decoder accepts the document; two
visible options now have the same Compose lazy key. Duplicate category entries
take the same path. Compose's duplicate-key invariant fails during lazy layout,
outside the decoding/error boundary.

**Impact:** a small accepted payload produces a render-time exception instead of
a usable screen or local error. This is not the already-fixed synthetic grid-key
collision. The published schema does not forbid these array duplicates, so its
validation alone does not protect the renderer.

**Correction:** ensure every accepted collection has collision-free local keys,
or validate collection uniqueness before rendering and return the local document
error. Do not silently execute ambiguous duplicate controls.
**Regression:** exercise duplicate option values and category IDs through the
decoder-to-renderer boundary, with both duplicates visible; verify safe fallback
or noncolliding rendering and no uncaught layout exception.

### I5 — Published sanitized BFF 401s skip the existing refresh path

**Locations:**
`core/network/src/commonMain/kotlin/com/shopkmp/network/api/request/RequestExecutor.kt:93–98,276–277`;
`backend-sdui/internal/bff/http.go:235–245`.

The new bounded facade refreshes a 401 only when its parsed error code is null,
`UNAUTHORIZED` or `INVALID_TOKEN`. The reviewed BFF deliberately returns
`401 / BFF_UPSTREAM_SECURITY` when Yii rejects authentication with a malformed,
empty or oversized security body (`SDUI_CONTRACT.md:462–467`). That is a known
authentication refusal, but the facade skips refresh and immediately clears all
tokens. A valid refresh token therefore cannot recover the session. The same
predicate also excludes the BFF's finite `BFF_UNAUTHORIZED` code.

**Impact:** the reviewed security-error normalization changes an otherwise
refreshable authentication challenge into forced logout. Reusing the mutex does
not suffice if the actual BFF challenge never reaches it. The existing facade
test uses an empty 401 body, which takes the allowed null-code branch and misses
this integration mismatch.

**Correction:** recognize the finite BFF authentication-challenge codes at this
boundary and use the existing single refresh/reissue path; keep 403 distinct and
clear account state on final authentication refusal. Do not broaden native
business-credential 401s such as INVALID_PIN into mutation retries.
**Regression:** return the exact sanitized BFF 401, allow refresh to succeed,
then return the screen; assert one refresh, recovered content and no premature
token clearing. Also cover final refusal and 403.

## Minor findings

1. **403 loses its business error information.**
   `core/network/src/commonMain/kotlin/com/shopkmp/network/api/request/RequestExecutor.kt:98–103`
   replaces every 403 with local `FORBIDDEN` and a generic message before decoding
   the available envelope. Preserve bounded code/message/details for valid 403
   errors, using the local fallback only when needed. The new boundary otherwise
   preserves these details for nonsecurity HTTP errors.
2. **Flattened nested LazyColumn layout props are discarded.**
   `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/RenderPlan.kt:17–21`
   removes every LazyColumn wrapper, while `SduiScreen.kt:94–100` applies only the
   single root layout's padding/spacing and no root alignment. Nested list
   padding/spacing/alignment therefore have no effect. Preserve those layout
   semantics in a non-scrolling render plan while retaining one vertical scroll
   owner. Current production fixtures predominantly use the simple root case.

## Other boundary assessments

| Area | Assessment |
| --- | --- |
| All eight destinations | Home, Catalog, Product, Cart, Orders, Order Details, Profile and Devices resolve through the typed facade and active SDUI host. The four tabs, product/order pushes, native Edit Profile and Change PIN routes are connected; no fixture-backed production repository was found. |
| Model and malformed payloads | Typed nodes/actions and bounded raw decoding cover bytes, depth, node/action counts, strings, duplicate JSON keys, leaf children, references, controlled fields, money, queries and versions. Unknown component subtrees are bounded before discard; unknown actions disable controls. I4 concerns a remaining render invariant, not absence of those guards. |
| State, cancellation and duplicate submits | Generation checks, cancellable loads, synchronous mutation guard, account-ID flow, final-401 clearing and source cart-page reconciliation exist. Destination/account-scoped drafts and keys are present. I1–I3 affect specific lifecycle/reconciliation paths; ordinary rapid duplicate taps are guarded. |
| Forms and collection paging | Canonical phone and UTF-8 search bounds, local-only field updates, exhaustive checkout payment mapping, independent category paging and effective cart-page adoption agree with the reviewed wire. Form/input preservation needs the correction in I2. |
| Native security and media | AuthService and hardware/key services are reused; destructive commands and checkout obtain local confirmation. Successful current-device revoke clears its local PIN/biometric metadata without a second revoke. Public media is same-origin relative; protected avatar initials avoid an unauthenticated avatar request. Logout remains blocked by I1. |
| Checkout/payment | Archive comparison confirms the inherited `retryOnceIfIndeterminate` confirmation call/helper were removed. Confirmation is one attempt, then a protected order read on error with matching order ID/owner checks. Partial outcome is surfaced, and order-creation retry keys remain. The five-argument CheckoutUseCase and all three payment mappings are used. Exact locally allowlisted HTTP(S) origins, no credentials and no scheme-relative URLs guard launches. No new blind ambiguous payment retry was found. |
| Navigation/restoration | Explicit local and platform Back, finite bounded stack/draft restoration, account matching, tab coordinates and saveable list state are implemented. Change PIN is registered in the Navigation3 serializer and has portable/native SavedState tests. Physical back/process-death behavior is not inferred from those tests. |
| HTTP and DI/Gradle | SDUI reuses the existing singleton executor/client/refresh mutex, fixed routes and typed commands, bounded streaming reads, no-store and the dedicated validated AddToCart header. All four KMP modules and Kover wiring are present. I5 is a challenge-classification mismatch in that new facade path. |

Resolve I1–I5 with focused regressions before accepting Task 3. The documented
build/test successes support the implemented foundation but do not close these
specific cases or establish the still-unverified authenticated device journey.

This review writes only `docs/CMP_REVIEW.md`; no implementation/test edits,
commits or subagents were used.

## Scoped independent re-review — remediation of I1–I5 and minor 1/2

Date: 2026-10-18. This section supersedes the original acceptance verdict for
these seven findings; the original review above is retained as historical evidence.
Scope is the remediation and its directly affected behavior, not a new full-project
audit or live-device acceptance run.

### Method and evidence

Read `docs/CMP_IMPLEMENTATION_REPORT.md:286–411` and inspected the amended
AuthService logout, SDUI host/ViewModel/events, model validation, executor error
handling and renderer/layout plan, together with their focused common/iOS tests.
Traced cancellation/finalization, profile Save versus Cancel, pending-intent
creation/recovery/discard/restoration, BFF challenge classification, duplicate
collection rejection and nested layout ownership against the original findings.

No tests/builds were rerun: source inspection and the supplied execution evidence
provided no concrete reason to repeat the documented suites. Existing Android XML
was inspected for AuthService (28), SduiViewModel (17), CartIntentRecovery (3),
SduiFacade (6), Contract (7) and RenderPlan (2): **63 recorded cases, zero
failures/errors**. The report's broader full Android/build/coverage/detekt results
and **91 executed iOS simulator cases** remain implementer-recorded evidence, not
new executions by this reviewer. The new Compose test source directly covers the
duplicate-key UI fallback and measured layout semantics.

### Important finding dispositions

#### I1 — Addressed

`feature/auth/auth-data/src/commonMain/kotlin/com/shopkmp/auth/data/AuthServiceImpl.kt:317–327`
now clears local session credentials in `finally`, including remote and caller
cancellation. `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:125–150,183–190,376–397`
blocks new tab/navigation/event work during sign-out, enters logout immediately
with `CoroutineStart.UNDISPATCHED`, and unconditionally clears account state and
replaces the native root in its own finalizer. Leaving may cancel the remote
operation, but no longer cancels local sign-out completion through a generation
check.

The real AuthService/BackendSession cancellation cases are in
`feature/auth/auth-data/src/commonTest/kotlin/com/shopkmp/auth/data/AuthServiceImplTest.kt:551–572`.
Host cases in
`feature/main/src/commonTest/kotlin/com/shopkmp/main/sdui/SduiViewModelTest.kt:195–243`
cover suspended logout with tab selection/leaving, remote cancellation and host
lifetime cancellation, including cleared content/drafts and final Login-root
notification. These address the original lifecycle regression.

#### I2 — Addressed

`feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:79–84,168–182,324–334,414–421,442–455`
stores dirty edits and field errors separately from the fetched baseline. Native
Save invalidates profile edits before fetching; native Cancel retains unsent
values and the previous error. Acknowledged UpdateProfile stops being an unsent
edit, allowing normalized server values to be adopted. The actual callbacks are
distinct in
`feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt:67–68`.

`feature/main/src/commonTest/kotlin/com/shopkmp/main/sdui/SduiViewModelTest.kt:12–78`
covers the original native-save → phone-only generic submit, clean restoration
against an updated server baseline, normalization after successful submit, and
Cancel following validation/network failures. Stale clean profile fields no
longer shadow the supported native save.

#### I3 — Addressed

`feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:60–84,198–202,267–273,303–349,457–473`
replaces payload-equality memoization with explicit pending Add intents. Recovery
selects the stored key and fixed input; a fresh purchase generates a fresh key,
with an explicit choice while an unresolved intent exists. Recovery/discard are
separate local events, GET reconciliation does not POST, and pending intents are
account-cleared, bounded to 16 and validated on account-matched restoration.
The recovery actions are wired into the host's existing vertical list at
`feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt:85–96,112–123`.

`feature/main/src/commonTest/kotlin/com/shopkmp/main/sdui/CartIntentRecoveryTest.kt:14–71`
uses stateful commit/replay semantics: unknown committed Add → read → remove →
fresh Add really changes the cart with a new key, while later recovery of the
original key does not add again. It also verifies fixed-payload recovery after
quantity editing/restoration, GET-only retry and explicit discard. The original
silent no-op sequence is closed without introducing automatic mutation recovery.

#### I4 — Addressed

`core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/ScreenDecoder.kt:95–113`
rejects duplicate option values and category IDs before producing Content. Thus
the existing renderer's value/ID lazy keys cannot receive these duplicates from
the network decoding boundary; malformed input produces the local document error.

`core/sdui-model/src/commonTest/kotlin/com/shopkmp/sdui/model/ContractTest.kt:72–86`
covers both duplicate arrays, alongside the existing 13-fixture compatibility
case. `core/sdui-renderer/src/iosTest/kotlin/com/shopkmp/sdui/renderer/SduiScreenUiTest.kt:43–44,83–101`
drives both through decoding into UI and asserts visible document-error fallback
and zero events. The report records the actual pre-fix duplicate-key failures and
post-fix simulator passes; no such execution is claimed by this re-review.

#### I5 — Addressed

`core/network/src/commonMain/kotlin/com/shopkmp/network/api/request/RequestExecutor.kt:93–101,280–286`
adds the two finite BFF authentication codes only to the SDUI challenge predicate.
They reach the existing refresh mutex and single refresh/reissue path; final 401
still clears the session, and 403 does not enter refresh. The native credential
predicate remains separate, avoiding INVALID_PIN mutation replay.

`core/network/src/commonTest/kotlin/com/shopkmp/network/api/request/SduiFacadeTest.kt:21–62`
covers both BFF codes, concurrent recovery with one refresh and retained rotated
tokens, plus final refusal after one reissue. The existing native no-refresh,
one-request INVALID_PIN check remains in `RequestExecutorTest.kt:244–273` in the
same test directory. This closes the reviewed wire/error-code mismatch without
adding a client or refresh loop.

### Minor finding dispositions

| Finding | Disposition and evidence |
| --- | --- |
| Minor 1 — 403 business details | **Addressed.** `core/network/src/commonMain/kotlin/com/shopkmp/network/api/request/RequestExecutor.kt:98–102` preserves valid bounded error code/message/details and uses sanitized fallback when decoding fails or the body is oversized. `core/network/src/commonTest/kotlin/com/shopkmp/network/api/request/SduiFacadeTest.kt:64–95` verifies valid details, no refresh/token clearing, and malformed/oversized fallback without raw-body leakage. |
| Minor 2 — nested vertical layout props | **Addressed.** `core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/RenderPlan.kt:21–32` unwraps only a single vertical root. `SduiScreen.kt:95–106,248–274` in the same directory applies root alignment/padding/spacing and renders nested vertical wrappers as non-scrolling Columns with their own props. `core/sdui-renderer/src/iosTest/kotlin/com/shopkmp/sdui/renderer/SduiScreenUiTest.kt:16–41` measures nested padding/spacing/End alignment, root End alignment, and exactly one vertical scroll owner. |

### Introduced-regression assessment and updated verdict

**No newly introduced Critical or Important regression identified within the
reviewed remediation.** Logout finalization is cancellation-safe; clean profile
state is refreshed without sacrificing unsent failure drafts; pending purchases
retain account-scoped fixed recovery inputs while fresh intents receive new keys.
The recovery UI uses a disjoint local lazy key, and the nested-layout correction
retains the existing grid-key namespaces and one vertical scroll owner. Decoder
uniqueness checks and the finite BFF predicate/error-detail corrections preserve
the existing bounds and native credential-error policy.

**Specification verdict: the seven reviewed findings are addressed; the prior
Task 3 acceptance blockers are cleared in this scope.**
**Quality verdict: acceptable for this scoped remediation — 0 outstanding
Critical/Important findings and both reviewed minors closed.**

This does not establish the full installed/authenticated live-device journey,
physical credential prompts, payment-app return or Android process-death behavior.
Those explicitly acknowledged verification gaps remain unchanged. This re-review
only appends `docs/CMP_REVIEW.md`; no implementation/test edits, commits or
subagents were used.
