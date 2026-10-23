# SDUI compatibility and application boundaries

Three JSON schemas and13 published fixtures are unchanged. No UPDATE_FIXTURES
run was used. schemaVersion=1; stable IDs, component types, finite native/
navigation actions, controlled fields, pagination, Russian locale, revision
hashing and response DTO JSON remain compatible.

| GET screen suffix (under /api/v1/screens/) | Anonymous | Sources |
|---|---|---|
| home |yes|categories/popular; authenticated user/session|
| catalog |yes|products/search + categories|
| product/{UUID} |yes|product|
| cart |no|user/cart; addresses only if nonempty|
| orders |no|user/orders|
| order/{UUID} |no|user/order|
| profile |no|user/addresses|
| devices |no|user/PIN+biometric device lists|

Yii authentication precedes personalized aggregation. Account/resource ownership
is checked before publication. Home401/403 cancels siblings; ordinary section
failures retain explicit partial/Error/Retry states. Empty/loading models remain
finite. Public cache policy and personalized no-store are preserved.

POST `/api/v1/sdui/actions` accepts exactly AddToCart, UpdateQuantity,
RemoveFromCart, CancelOrder, UpdateProfile, RevokeDevice. Input validation precedes
network authentication; every invocation authenticates again. Mutation success
is acknowledged without a second read that could hide a committed write.
CancelOrder retains pre-write ownership/resource checks. No arbitrary URL/method,
code, credential, Checkout or payment command was added.

## Internal changes

Application.Screen/Execute orchestrate without HTTP request/response objects;
consumer-owned auth/read/write ports inject the real Yii adapter. HTTP owns
route/method/header/query/body parsing, strict JSON, error mapping and serialization.
prepareCommand creates typed private command inputs. The large former HTTP action
handler no longer owns mutation sequencing. Shared Money, identifier and query
contracts live in independent domain; sdui no longer imports Yii. Yii type aliases
preserve consumers. Existing explicit upstream DTO→sealed SDUI Props mappings stay.

Encode still validates budgets, hashes deterministic content excluding clocks/
correlation/token data, and serializes controlledv1 JSON. Render telemetry measures
validation/revision/serialization, not network time. No runtime schema interpreter
or arbitrary component execution was introduced.

Existing eight-screen golden/schema, continuation/description/primitives and
negative component/action tests remain. New characterization tests cover status/
code/Allow/cache/trace envelopes and independent request/response schemas for all
six commands. Application tests cover authentication, validation-before-network,
exact mutation delivery and refresh. Fuzzing covers malformed/deep/duplicate input.
