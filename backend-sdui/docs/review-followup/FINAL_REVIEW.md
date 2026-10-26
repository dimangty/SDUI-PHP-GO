# Go SDUI follow-up — final review

## Changes and compatibility

| Finding | Minimal change | Evidence |
|---|---|---|
| N1 | `domain.EqualID` accepts equivalence only between two strictly valid UUIDs. Resource, cancellation, account ownership, category and default-address comparisons use it. Controlled category options retain selected spelling for CMP. | Native RED/GREEN for lower/upper/mixed resource IDs; one cancel POST; real mismatches rejected; syntax rejected before upstream. Every affected mutation ownership path checked. |
| N2 | Ordinary screens retain existing IDs/golden output. Large devices use adjacent multiline Text + individual Button; large profiles pack whole address lines into bounded Text. | 0/1/99/100/150/249 devices, 250 rejection; 0/1/487/488/1000 addresses with UTF-8/escaping. No labels, addresses, order, revoke actions or confirmations lost. |
| N3 | Cart checks every addition before arithmetic against CMP Int maximum, including hidden rows. Final model validation enforces numeric wire ranges. | MaxInt32 succeeds with exact count/total; MaxInt32+1, negative quantities and invalid wire fields fail atomically. Text-only and non-emitted large numbers remain legal. |

All changes in this task are inside `backend-sdui/`. HTTP Yii remains the sole
business boundary; no SQL, hardcoded business records or moved business rules.
Public routes, six commands, supported components/actions, schemaVersion=1,
decimal money and published schemas/golden fixtures are unchanged. No library or
toolchain updates. Validators are tightened for representation, never weakened.

N1 does not normalize outgoing request payloads, paths, tokens, device IDs or
idempotency keys. In particular, AddToCart preserves its original productId
spelling and key, retaining PHP's saved idempotency-fingerprint semantics.
EqualFold is restricted to validated UUID comparison; existing unrelated
case-alias rejection continues unchanged. Different valid UUIDs and malformed
IDs do not become equivalent. Ownership checks still reauthenticate commands.

## Real finite composition limits

Global v1 limits remain **500 nodes, 500 actions, depth 16, 8192 code points per
string, 1,048,576 bytes in the entire encoded document including revision**.
The default upstream response cap remains **2 MiB**, checked before composition.
All screen documents still go through Screen.Encode before sending any HTTP success/body.
Unrepresentable outputs return atomic `502 BFF_INVALID_DOCUMENT`; upstream
transport/shape/size/security errors keep their existing error mapping.

### Devices

- Empty: root/title/empty = 3 nodes and refresh = 1 action.
- 1–99 combined PIN + biometric entries: original `2 + 5D` nodes and `1 + D`
  actions. No fixture/UI identity change.
- 100+ entries: flat Text + Button per entry, `2 + 2D` nodes and `1 + D` actions.
  The Text contains exactly the previous name, ID, kind and last-used value,
  in order. Separate enabled buttons preserve stable button/action IDs, the
  exact opaque deviceId, correct kind and original confirmation.
- This compact layout supports **at most 249 combined entries** under the node
  budget: 249 = 500 nodes; 250 = 502 nodes and fails atomically. Each PIN and
  biometric entry counts separately even when their IDs are identical. Device
  IDs differing only by case also remain distinct. This is the chosen layout's
  bound, not a claim that no alternative v1 layout could be more compact.
- Text/action strings and final escaped bytes must also fit their unchanged
  bounds; 249 is not a guarantee for arbitrarily long malformed data. No revoke
  action is hidden or dropped to make a screen fit.

### Profile addresses

- 13 fixed nodes, 10 fixed actions. Through 487 addresses, keep one original
  `address.<id>` Text per address. Empty profile does not invent an empty state.
- Above 487, use `address.block.<index>` Text blocks, greedy packing of complete
  `city, street, house, apartment` lines with a single added newline separator.
  Every line's characters and original collection order are preserved.
- Each complete packed line must be at most **8192 UTF-8 bytes**; each block,
  including separators, is at most **8192 UTF-8 bytes**, stricter than the global
  code-point cap. At most **487 blocks** can accompany the 13 fixed nodes.
- Full encoded bytes are checked independently. Address count alone cannot
  define the supported limit: 1000 short addresses use 3 blocks (16 nodes,
  28,187 bytes), rich UTF-8/escaped addresses use 72 blocks (85 nodes,
  827,217 bytes). A 1000-address escaped-name case fits the upstream cap but
  exceeds final MaxBody and fails atomically. No pagination or silent truncation.
- Ownership of **all** addresses is checked before packing. No-store remains.

Read-only CMP compatibility review: `SduiScreen.kt` maps Text to EpsText and
flat root children to independent lazy items; EpsText forwards the full string
to Material Text without truncation/maxLines. Buttons use their own action refs.
No CMP edits or Android/iOS runtime/visual execution occurred.

### Numeric v1 representation

The unchanged client parses cartItemsCount, pagination coordinates/totals,
ProductCard stock/reviewsCount, CartItem stock and OrderCard number as Kotlin Int.
All are now checked at final validation against **0..2147483647**, with the
existing stricter rules retained (positive order number; page size 1..100).
Collection consistency uses overflow-safe ceiling division.

The other wire integer fields were audited: schemaVersion is exactly 1; grid
columns exactly 2; form lengths/UTF-8 byte cap, steppers, quantities and destination
query page/size already have smaller explicit bounds. Command acknowledgements
have fixed schemaVersion=1 and bounded destinations. No Int field is broadened.

Cart addition checks a negative quantity or `quantity > MaxClientInt - count`
before summation, so neither Go int overflow nor Kotlin Int overflow can publish
a successful screen. No clamping, reset, skipped row, monetary recomputation or
PHP business-range changes. This is a finite **v1 representation limitation**;
supporting larger numeric wire values requires an agreed client protocol change.
Product detail stock/reviews formatted as strings and category sortOrder not
serialized into CMP Int fields are deliberately not range-rejected.

## Prior R1–R4 and tests

Full native/race suites and pinned backend check script pass. Prior regression
tests retain 164/165/200 item orders, exact prices/total/comment/cancel, explicit
revoke 204 success and unexpected 200/202 rejection, unexpected 204 collections,
required/nullable/case-alias contracts, Retry-After allowlist/deadline semantics,
single-attempt mutations, fail-closed damaged/oversize 401/403, and final-attempt
telemetry after network failure. Real HTTP/ownership/no-store behavior remains.

The old R1 adjacent collection test is retained, with only 100-device and
488-address expectations changed to success. New negative boundaries exercise
the compact layout and actual escaped-byte/string limits. Historical R1 reports
describe their original limits; this document supersedes those limits for N2.
No expected fixture output was automatically regenerated.

## Review attribution and remaining risks

An actual separate read-only `general` reviewer was dispatched with the installed
requesting-code-review instructions, session `ses_e89ad360fffeeKu1dpZ5vtxs2H`.
It inspected the complete tracked/untracked code and compatible PHP/CMP sources,
ran targeted native tests, and reported no blocking production defect. Two minor
test gaps were verified and addressed: mutation-account UUID coverage and each
device label's association with its button plus stable identities at transition.
The implementer reran all mandatory checks afterward. No independent CI or mobile
verification is implied by the agent review.

The same reviewer re-reviewed the strengthened tests and final documents,
confirmed both test gaps closed, and approved code readiness with no critical or
important findings. It reran focused race/schema/golden tests across BFF/domain/
SDUI and inspected the Docker image ID. Its sole minor documentation correction
(clarifying that Screen.Encode applies to screen documents, not command
acknowledgements) was applied and the final diff checked.

Evidence and the initial test-only lint failure/fix are in TEST_RESULTS.md.
Docker image build and a **68-step real disposable Yii HTTP journey** passed;
the latter runs current host BFF code, not a newly deployed image. No Android/iOS,
device visual, remote CI or root PHP/ops/Compose checks are claimed.

Residual limits are explicit: finite document/text/action budgets, 249-device
chosen-layout limit, content-dependent profile capacity and CMP Int ceiling.
Large collection UX and alternate protocol/layout designs remain separate work.
Nothing here promises unbounded data or changes persisted idempotency semantics.

Baseline was clean at `7415d59`; root `.golangci.yml` newline changes already
belong to that commit/history. This task does not claim those changes. Commit and
push are gated on successful checks and a final explicit-path staged review;
the final handoff supplies actual local and remote commit SHAs.
