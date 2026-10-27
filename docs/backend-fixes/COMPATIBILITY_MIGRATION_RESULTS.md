# Final contract compatibility and real migration-runner verification

Date: **2026-10-26**. Baseline: `01d84b3a0ca19e66cc3a0ede878f4b67777df6c9`
plus the current backend-fixes working tree.

**Result: PASS for this compatibility/migration scope.** The existing CMP readers
accept the retained routes, SDUI v1 envelopes, money strings and public statuses.
Real concurrent Go CLI migrations passed on both a clean database and a populated
001–011 clone. **This is not a claim that externally observable behavior is
entirely unchanged:** forced legacy reauthentication, credential-family
revocation, uncertain financial outcomes, provider-error reporting and trusted
client accounting are intentional policy changes described below.

**2026-10-27 final contract correction:** the source/reader checks below initially
compared the unchanged OpenAPI. The final specification now adds the already
implemented logout/password-change 502/503 (and stale-password 409) descriptions;
the operation set, request/response models, money and enum schemas are retained.
The newly added recovery error flag was corrected from boolean to string
`"true"` to satisfy the existing OpenAPI/CMP string-only details map, with real
RED 10/5 → GREEN 10/0 and affected revocation/logout suites passing. Thus the
later OpenAPI is no longer byte-identical, while its client models stay compatible.

Requirements were read from `PLAN.md`, `MIGRATION_ROLLBACK.md`, `PAYMENTS.md`,
`SESSIONS.md`, `TRUST_AND_RATE_LIMITS.md`, the payment/session fix and re-review
reports, `RR1_FIX_REPORT.md`, and the integration/live/security results in this
directory. Earlier RED/GREEN and live results remain attributed to those reports.
The existing Go race/vet/build results were not rerun for this task. This narrow
PASS does not supersede separate live-stack or security-gate results.

## 1. Published contract versus actual readers

Fresh `python3 scripts/route_matrix.py --check`: **exit 0**,
`54 exact operations/policies; eight screens and six commands: matrix matches source`.
The canonical JSON, actual PHP route registry and OpenAPI operation set agree:
**54 = 15 public + 32 protected + 7 admin**. `ApiKernel` also instantiated
successfully against the upgraded database; its constructor checks every
registered service method (`backend-yii2/infrastructure/ApiKernel.php:20–26`).
This is registry/reader verification, not a fresh execution of all 54 operations.
Mobile consumes the relevant native subset plus the eight screens/six commands;
it is not being represented as a consumer of every admin/webhook operation.

The canonical routes, OpenAPI, PHP route registry and all three BFF JSON schemas
are **byte-identical to HEAD**. The read-only checker additionally cross-checked
the screen/command/order enum sets against CMP and the actual monetary DTO types.

| Contract | Exact backend producer / published schema | Exact existing CMP reader / handler | Finding |
| --- | --- | --- | --- |
| Eight screens: `home`, `catalog`, `product`, `cart`, `orders`, `order`, `profile`, `devices` | `backend-sdui/internal/bff/http.go:156–174`; `backend-sdui/contracts/screen.schema.json:8–14` | `core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/Screen.kt:6–19`; `core/network/src/commonMain/kotlin/com/shopkmp/network/sdui/SduiFacade.kt:25–40` | Same finite names and resource-ID routing. |
| Screen `schemaVersion: 1` | `backend-sdui/contracts/screen.schema.json:10`; `backend-sdui/internal/sdui/model.go` | `core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/ScreenDecoder.kt:18–26` | CMP explicitly rejects another version; version remains 1. |
| Six commands: `AddToCart`, `UpdateQuantity`, `RemoveFromCart`, `CancelOrder`, `UpdateProfile`, `RevokeDevice` | `backend-sdui/contracts/action-request.schema.json:7–13`; `backend-sdui/internal/bff/commands.go:150–186` | `core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/Command.kt:11–25,38–48`; `core/network/src/commonMain/kotlin/com/shopkmp/network/sdui/SduiFacade.kt:42–48` | Discriminators, finite inputs and explicit cancellation/revocation confirmation agree. |
| Command acknowledgement: `{schemaVersion, command, outcome, refresh}` | `backend-sdui/internal/bff/commands.go:40–45,249–254`; `backend-sdui/contracts/action-response.schema.json:6–11` | `core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/Command.kt:34`; `core/sdui-network/src/commonMain/kotlin/com/shopkmp/sdui/network/SduiRepository.kt:43–51`; `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:319–340` | CMP requires v1, the matching command and `outcome="applied"`, then follows the validated refresh destination. No new acknowledgement field is required. |
| Money remains JSON **string**, including price, snapshot, order/cart total and payment amount | `backend-yii2/contracts/openapi.yaml:284–288,636–653,669–685`; `backend-yii2/repositories/ShopRepository.php:20–22,35–44,46–58`; `backend-sdui/internal/domain/values.go:12–36` | `core/network/src/commonMain/kotlin/com/shopkmp/network/shop/ShopApiModel.kt:6–11,24–46,63–79,123–144`; `core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/Node.kt:70`; `core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/Screen.kt:59–66`; `ScreenDecoder.kt:104–105,115,120–124` in the same directory | No float conversion added. Actual upgraded cancellation returned `totalAmount="20.46"`. OpenAPI's broad string-money regex and the stricter pre-existing nonnegative bounded BFF/CMP regex are not being claimed to be identical. |
| Order statuses | `backend-yii2/contracts/openapi.yaml:644–659`; `backend-sdui/contracts/screen.schema.json:47,154`; `backend-sdui/internal/bff/screens.go:304–307,323–350` | `core/sdui-model/src/commonMain/kotlin/com/shopkmp/sdui/model/Screen.kt:49–51`; `feature/home/home-data/src/commonMain/kotlin/com/shopkmp/home/data/BackendShopRepository.kt:264–297` | Same `CREATED, PAID, ASSEMBLING, DELIVERING, COMPLETED, CANCELLED`; CMP has a real `CANCELLED` member/mapping. |
| Payment statuses and native confirmation | `backend-yii2/contracts/openapi.yaml:322–325,669–685`; `backend-yii2/application/PaymentService.php:13–18` | `core/network/src/commonMain/kotlin/com/shopkmp/network/shop/ShopApiModel.kt:115–120`; `core/network/src/commonMain/kotlin/com/shopkmp/network/shop/ShopApi.kt:140–141`; `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/NativeCheckoutAdapter.kt:27–37` | Public enum remains `PENDING, AUTHORIZED, CAPTURED, FAILED, REFUNDED, CANCELLED`. Native confirmation reads `status: String`, nullable `paymentUrl`; CMP does not have a new closed payment enum to update. Internal operation `UNKNOWN` is **not** a public payment status. |
| Old opaque access/refresh rejection | `backend-yii2/application/SessionService.php:47–65`; `backend-yii2/application/AuthService.php:47–52,66–70`; `backend-yii2/domain/ApiError.php:14` | `core/network/src/commonMain/kotlin/com/shopkmp/network/api/request/RequestExecutor.kt:92–107,132–168,280–286,322–350`; `core/sdui-network/src/commonMain/kotlin/com/shopkmp/sdui/network/SduiRepository.kt:54–58`; `feature/auth/auth-data/src/commonMain/kotlin/com/shopkmp/auth/data/AuthServiceImpl.kt:43–79` | Native `UNAUTHORIZED` and normalized BFF authentication challenges use existing refresh handling. Refresh refusal with 401 clears tokens; SDUI remaining 401 becomes unauthorized. Business PIN/assertion errors are not confused with these auth challenges. |
| Logout success/provider failure | `backend-yii2/application/AuthService.php:54–64`; `backend-yii2/infrastructure/Keycloak.php:43–80`; `backend-yii2/domain/ApiError.php:20,26` | `core/network/src/commonMain/kotlin/com/shopkmp/network/auth/AuthApi.kt:107–108`; `RequestExecutor.kt:156–168` at the full path above; `feature/auth/auth-data/src/commonMain/kotlin/com/shopkmp/auth/data/AuthServiceImpl.kt:317–327`; `feature/main/src/commonMain/kotlin/com/shopkmp/main/sdui/SduiViewModel.kt:376–396` | 204 still decodes as `Unit`; 502/503 use the existing generic server-error path. Client local token clearing/root transition are `finally` behavior. This does **not** make failed provider logout globally successful. |

### Cancellation: order outcome and financial outcome are distinct

The current Go client calls `POST /api/v1/orders/{id}/cancel` and reads an **Order**
(`backend-sdui/internal/yii/client.go:330–336`), matching OpenAPI's 200 Order
(`backend-yii2/contracts/openapi.yaml:129–130`) and the PHP facade's actual return
(`backend-yii2/application/PaymentService.php:23`). The BFF checks resource/account
ownership and acknowledges `refresh: {screen:"order",id:...}`
(`backend-sdui/internal/bff/commands.go:224–237,249–254`). It does not promise a
refund in its acknowledgement. CMP then reloads the order screen. The existing
action test uses a cancelled Order response
(`backend-sdui/internal/bff/actions_test.go:29`), and the live readback explicitly
requires `CANCELLED` (`backend-sdui/internal/bff/live_integration_test.go:362–371`).

**Required compatibility matrix:**

| Situation | Order/cancel response | Financial payment status / operation | Existing reader consequence |
| --- | --- | --- | --- |
| Never-sent post-upgrade external payment, or local on-delivery cancellation | Successful cancel returns Order `CANCELLED` | Local cancellation can become payment `CANCELLED`; unsent authorization is aborted | Existing order enum and acknowledgement/refresh work. |
| Legacy external `PENDING`, including a provider-sent/lost-response row | Successful local cancel returns Order `CANCELLED` | Payment can stay **`PENDING`**; imported authorize is `UNKNOWN`, not replayed | `PENDING` is still in the public payment enum and accepted by the native string DTO. A cancelled order is not proof of settled funds. **Freshly exercised here.** |
| Initiated `PENDING`/`AUTHORIZED`, or captured payment awaiting reversal | Order cancellation is committed; an ambiguous synchronous send can still return the existing 400 `PAYMENT_GATEWAY_UNAVAILABLE` | Prior `PENDING`/`AUTHORIZED`/`CAPTURED` is retained until verified reversal; internal work can be `SENT`/`UNKNOWN` | Existing error handler can refresh the source screen after failure (`SduiViewModel.kt:336–340` at the full path above). Error does not prove local cancellation rolled back. |
| Confirmed void/refund | Order remains cancelled, except completed-order refund policy preserves `COMPLETED` | `CANCELLED` for uncaptured reversal; `REFUNDED` only after capture | Both pre-existing public statuses remain supported; completed purchase stock is not returned. |

Source: `backend-yii2/application/PaymentOperations.php:36–104,114–158` and
`backend-yii2/infrastructure/PaymentGateway.php:21–38`. The fresh lost-response
case returned **200 / Order `CANCELLED` / string total `20.46`** while the linked
payment remained **`PENDING`** and its operation remained **`UNKNOWN`**.

### External policy changes that must be communicated

1. **Migration 013 forces reauthentication.** All unlinked legacy opaque access
   and refresh rows are deleted; a device/credential is not guessed from user ID.
   PIN/bio deletion and terminal lockout revoke the associated families; password
   changes fence prior opaque sessions and block new issuance until recovery
   completes. User-wide logout invalidates all of that user's opaque families.
   Other users' families retain their ownership boundary. Old unlinked writers
   now fail required-family constraints. See `SESSIONS.md:20–46,48–89,112–118`.
2. **JWT expiry remains a separate boundary.** Already-issued, offline-verified
   Keycloak JWT access tokens are not made instantly invalid by local opaque
   revocation or provider logout. See `SESSIONS.md:110` and the separately recorded
   live Keycloak results. This task exercised opaque migration rejection, not a
   new JWT denylist or introspection mechanism.
3. **Uncertainty is observable.** Every pre-existing external `PENDING` row is
   quarantined, including rows with no saved URL/transaction. Confirmation can
   return the existing `PENDING`/nullable URL shape without sending again.
   Cancellation/expiry do not fabricate settled reversals. The gateway now
   requires structured correlated authorize results and exact empty 204 for
   synchronous reversal; 202/unexpected responses remain uncertain. Signed early
   callbacks lacking verified linkage are quarantined rather than used to invent
   correlation. There is no provider lookup endpoint or replay guarantee added.
   Historical terminal settlement still requires independent evidence. See
   `PAYMENTS.md:34–65,119–125`.
4. **Logout provider errors are now surfaced after local cleanup.** The old
   implementation swallowed provider failure. Current code returns sanitized
   `IDENTITY_PROVIDER_ERROR` **502** or `IDENTITY_PROVIDER_UNAVAILABLE` **503**;
   service-account grant rejection is not a caller's `INVALID_CREDENTIALS` 401.
   Remote targeting is the authenticated user, not the owner of an arbitrary
   supplied refresh token. Empty/opaque-only logout remains local-only. CMP
   clears local tokens even on these errors, but currently does not present or
   retry incomplete remote cleanup as a separate success guarantee. The provider
   response matrix is existing evidence in `SESSION_FIX_REPORT.md:37–66`, not a
   fresh provider-error suite execution here.
5. **OpenAPI operation-level error coverage is incomplete.** In particular,
   `backend-yii2/contracts/openapi.yaml:27–28` still lists only logout 204, although
   502/503 can now be surfaced through the existing ErrorEnvelope. The route and
   response-shape checks pass; that does not establish an exhaustive match of
   every runtime error response to each operation's response list. This report
   records the external change without silently editing the published spec.
6. **Trust/rate policy changes require deployment configuration.** Original client
   context is accepted only from exact trusted edge/BFF peers, not arbitrary
   forwarding headers or legacy CIDR trust. A malformed trusted-edge claim can
   return 400 before business calls; correctly separated client networks now have
   independent shared limiter accounting. Public clients need no new trusted
   identity header. The webhook source/HMAC boundary remains distinct. Source:
   `backend-sdui/internal/bff/trusted_client.go:8–25`,
   `backend-yii2/infrastructure/Network.php:26–48`; policies and prior live evidence
   are in `TRUST_AND_RATE_LIMITS.md` / `TRUST_TEST_RESULTS.md`.
7. **Schema downgrade/writer compatibility changed.** Drain old writers before
   migration. Migration 012 is roll-forward-only; its down SQL refuses with
   `0A000`. Family-less session writers cannot be mixed with the new schema.
   Install 014 before the recovery worker. See `MIGRATION_ROLLBACK.md:8–19,129–157`.

### Original Go regressions retained

Fresh byte comparison against HEAD passed for all of these existing files:

- UUID value equality, ownership, cancellation and opaque-input preservation:
  `backend-sdui/internal/bff/review_followup_uuid_test.go:26,61,125,167,226,279` and
  `backend-sdui/internal/domain/review_followup_test.go:5`.
- Large device/address collections and atomic failure for unrepresentable data:
  `backend-sdui/internal/bff/review_followup_collections_test.go:40,156,197`
  (including 250 devices and 1,000 addresses).
- Int32 output boundaries and exact money preservation:
  `backend-sdui/internal/bff/review_followup_int_test.go:12,62,82,135` and
  `backend-sdui/internal/sdui/review_followup_int_test.go:9`.

No assertions/goldens were weakened or regenerated. Fresh
`git diff --name-only HEAD -- core feature composeApp iosApp` was empty. CMP was
read only; compatibility here is source/reader analysis, not a new mobile build
or device run.

## 2. Real migrator environment and procedure

Only the two new databases **`migration_checks_final`** and
**`migration_upgrade_final`** were mutated by this verification:

- Existing standalone PostgreSQL: `shopkmp-backend-fixes-pg`.
- Host: `127.0.0.1:55493`; container port 5432.
- Existing network: `shopkmp-backend-fixes-20261026`.
- Disposable user/password: `backend_fixes` / `disposable_backend_fixes_only`.
- Clean database created from `template0`.
- Upgrade database created with `createdb -T backend_fixes`; original history was
  exactly versions 1–11 before cloning. No destructive workspace checkout,
  direct new-migration SQL application or checksum/history rewrite was used.

Actual successful invocation argument sets:

```sh
docker exec shopkmp-backend-fixes-pg createdb -U backend_fixes -T template0 migration_checks_final
docker exec shopkmp-backend-fixes-pg createdb -U backend_fixes -T backend_fixes migration_upgrade_final

# Two independent subprocesses per target, with N=1 and N=2:
DATABASE_URL="postgres://backend_fixes:disposable_backend_fixes_only@127.0.0.1:55493/${DB}?sslmode=disable&application_name=final_${DB}_${N}" \
  GOMAXPROCS=2 go -C tools/ops run ./cmd/migrate

# Independent normal third invocation per target, application_name=final_rerun:
DATABASE_URL="postgres://backend_fixes:disposable_backend_fixes_only@127.0.0.1:55493/${DB}?sslmode=disable&application_name=final_rerun" \
  GOMAXPROCS=2 go -C tools/ops run ./cmd/migrate
```

The two target pairs ran sequentially to limit host load, with only two Go CLI
children at a time. To establish genuine concurrency, a separate `psql` session
held **the runner's actual advisory lock `6002424246314344528`**. Both normal
migrators were started, then `pg_stat_activity` was polled until **two** independent
`application_name=final_*` connections reported
`wait_event_type='Lock', wait_event='advisory'`. Only then was the gate released.
The gate uses the same ordinary database/lock mechanism; `Apply` was not replaced.
The clean target had no `schema_migrations` table while blocked; the clone had
its original table. Both children then completed successfully on each target.

Source of actual runner behavior: `tools/ops/cmd/migrate/main.go:17–35` calls
`migrations.Apply`; `tools/ops/migrations/migrate.go:74–109` locks and serializes,
`:175–207` checks recorded name/checksum and atomically records each migration.

### Populated legacy fixtures and application-level checks

The untouched source database contained **12 users, 4 payments, 2 access sessions
and 2 refresh sessions**. Its clone additionally received two test users, one
address, nine order/payment pairs and one unexpired legacy access/refresh pair
(digest-only rows, no family columns). Thus the upgrade had real existing data
and three rows in each legacy token table, not merely an empty schema.

| Seeded payment shape | Imported operation | Correlation / stock result |
| --- | --- | --- |
| CARD `PENDING`, no URL/transaction; actual baseline POST followed by unusable response | `UNKNOWN`, `sent_at` set | No fabricated transaction link. |
| SBP `PENDING`, no URL/transaction | `UNKNOWN`, `sent_at` set | No fabricated transaction link. |
| CARD `PENDING`, unique saved transaction | `UNKNOWN` | Existing unambiguous transaction link retained; saved transaction is not proof of unsent/safely replayable initiation. |
| CARD `AUTHORIZED`, unique saved transaction | `SUCCEEDED` | Exact existing link retained. |
| CARD and SBP `CAPTURED`, shared duplicate transaction | Both `UNKNOWN` | Duplicate transaction not linked to either payment; completed order not marked stock-restored. |
| CARD `FAILED`, unique transaction, cancelled order | `SUCCEEDED` imported authorize | Existing link retained; old cancelled reservation marked already restored. |
| CARD `CANCELLED`, missing transaction, cancelled order | `UNKNOWN` | No invented link; old cancelled reservation marked already restored. |
| ON_DELIVERY `PENDING` | No external operation | No external link fabricated. |

Assertions covered **eight imported external operations, six UNKNOWN and two
SUCCEEDED**, and exactly the three legitimate seeded transaction links. All
pre-existing payment fields, including status, amount and timestamps, were
compared before/after migration and preserved. The two historical cancelled
seeded orders received the stock guard; the completed one did not.

The private fixture `shopkmp-migration-final-provider` served only on the disposable
Docker network, with no published host port. The existing frozen predecessor
path `backend-yii2/tests/payment-baseline-confirm.php:9–30` made a **real HTTP POST**
to it before migration. The fixture recorded a synthetic authorization/transaction
then returned truncated JSON. The predecessor transaction rolled back its response
fields, leaving exactly `PENDING / gateway_url=NULL / transaction_id=NULL`.
No real payment service was contacted.

After the real upgrade, current PHP classes and `ApiKernel` were executed from
read-only source mounts against `migration_upgrade_final`:

- Legacy access request: **401 `UNAUTHORIZED`**.
- Legacy refresh request: **401 `INVALID_REFRESH_TOKEN`**.
- Fresh internal family issuance and authenticated profile request: **200**.
- Confirmation of the legacy uncertain payment: no new authorization.
- Native cancellation through `ApiKernel`: **200**, Order **`CANCELLED`**, money
  string **`20.46`**; payment still **`PENDING`**.
- Capture for the fixture transaction without verified local linkage:
  `INVALID_TRANSACTION_ID`, persisted unresolved callback inbox entry, no
  `processed_webhooks` marker and no false transaction link.
- Bounded recovery retained authorize **`UNKNOWN`**. Fixture journal before/after
  confirm/cancel/callback/recovery was identical: **one POST attempt total**.

The callback check calls the business handler directly to isolate correlation;
it does not claim a fresh signed HTTP ingress/HMAC exercise. Those ingress cases
are existing evidence in the payment/live reports. The private fixture container
was removed successfully at the end; both final databases remain for inspection.

## 3. History, manifest, actual constraints and indexes

**All 22 original SQL files (001–011, up and down) are byte-identical to HEAD.**
The original 22 manifest lines are identical, and the only six additions are
012–014 up/down. All **28** manifest checksums match current file bytes. The
already-updated manifest was verified, not rewritten by this task.

Both real databases contain **exactly 14 unique history rows, versions 1–14**.
Every recorded name and SHA-256 matches the current embedded up file. The clone's
entire original 11-row history, including `applied_at`, was compared for exact
equality before/after; third CLI invocations left all 14 rows exactly unchanged.

| Version / recorded name | Recorded and source SHA-256 |
| --- | --- |
| `001_init.up.sql` | `e2d09e0fdf91fd7ef1bccfbffe3e783da1e70c1915ddf6dd0adee8f90cc8441f` |
| `002_shop_state.up.sql` | `336ac1d7332f9744da314d6dd8b9c283ed5e6ab7fcd16e074fa498f2232c85b6` |
| `003_shared_runtime_state.up.sql` | `39f973530fd773b957641588b745ccd30ee0e5f0a586993927fcc2a1a9c2ad27` |
| `004_shop_state_version.up.sql` | `35f18dccda80846686ce0825b77f569d62137cac9a2c25dfd6ec99916c19ba51` |
| `005_normalized_runtime.up.sql` | `ceaf08aaa1620e71b52e03345ca286c183fc1208f1576f8889b8e5615e0c6c27` |
| `006_orders_source_of_truth.up.sql` | `d078a503c68be54927c2fb230bd8d72dd730f8c853540779cc9f20c560634d5a` |
| `007_multi_replica_runtime.up.sql` | `33e0b772772de6d18776279bc5cb27ab5194d42d8e059561db4118bd2ebafa49` |
| `008_biometric_auth.up.sql` | `ffd23b749953db7d04e3461fe1008447c537933bd88d06aedbe79a2d20107683` |
| `009_biometric_credential_ids.up.sql` | `d5418f8eccd4f358b52b3d90fa5a50d109a30b371e9f83d902679cc51f08f916` |
| `010_remove_snapshot_runtime.up.sql` | `81a131ddc15617f273070231bcd59587029fbb1936661e733392ab7598d30e39` |
| `011_legacy_import_marker.up.sql` | `a21ff58c88d7edf59e34f3e9587bcaaeb21f0a68df11ebfd938914474973b407` |
| `012_payment_operations.up.sql` | `35bd9b19a7a6411c6ae461dd07b3303c638093931979122abc4f4eb3e498552c` |
| `013_session_revocation.up.sql` | `711c980aaf6aa906b5cbcb902846cb82522a1dfbb5bb68ff2954537dfaaab200` |
| `014_recovery_schedule.up.sql` | `aaa9d92afeab9046e1a62278bb8afb8c555467e6f28563ee7fd35c64186e8a5f` |

Clean and upgrade public catalogs were compared in full: **30 tables, 188
columns, 92 constraints and 62 indexes**, with matching column types/defaults/
nullability, constraint definitions and index definitions. All 92 constraints
were validated; all 62 indexes were independently checked **valid and ready** in
each database. This covers the complete final 001–014 schema, not just the new
table names.

Actual PostgreSQL writes additionally verified **29 expected rejections per
database** (58 total), plus positive same-owner token/retry/ticket writes and
family-delete cascades. Each negative statement ran in a PL/pgSQL subtransaction;
the surrounding fixture transaction was rolled back.

| Invariant exercised on both databases | Expected SQLSTATE / result |
| --- | --- |
| One live payment operation; one payment/kind; unique operation key | `23505` for each |
| Operation kind/state enums; SENT requires lease token, lease deadline and send time | `23514` for each |
| Operation payment FK; observation operation FK | `23503` |
| One observation per operation/lease | `23505` |
| Transaction has one owner; payment has one transaction; transaction payment FK | `23505`, `23505`, `23503` |
| Callback status and operation FK | `23514`, `23503` |
| Nonnegative user security version; family user FK; credential-kind enum and internal/credential-ID consistency | `23514`, `23503`, three `23514` |
| Access and refresh family must belong to the row's same user | Both **`23503`**, through composite `(family_id,user_id)` FKs |
| Old family-less access and refresh writers | Both **`23502`** |
| One active password-session operation; allowed operation state; sanitized stored error | `23505`, `23514`, `23514` |
| Recovery attempts below zero/above five; invalid operator ticket | Three **`23514`** |
| Matching-family tokens, attempt=5 and `OPS-123` | Accepted; deleting that family cascaded both token types |

Indexes inspected include `payment_operations_one_live` (READY/SENT/UNKNOWN
predicate), `payment_operations_recovery`, both operation-key/payment-kind unique
indexes, observation `(operation_id,lease_token)` uniqueness, transaction-link
uniqueness, unresolved callback partial index, live credential-family index,
access/refresh family indexes, active password-operation uniqueness, and
`password_session_operations_due_idx` on `(next_retry_at,id)` with
`state='REVOKE_PENDING' AND recovery_attempts<5`. Exact definitions are retained in
the catalog evidence. Family credential ownership is also validated by application
issuance (`SessionService.php:23–35`); the database composite FKs specifically
enforce token-to-family user ownership, not a polymorphic FK to both credential
tables.

Original `backend_fixes` history and the four original data/token counts were
unchanged afterward. No SQL writes targeted it or any other existing database.
Both final targets had **zero idle-in-transaction sessions** at completion.

## 4. Commands, exits and retained evidence

All scripts/evidence outside the report are in the preapproved temporary area:

```text
/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/
  migration_final_verify.py
  migration_final_constraints.sql
  compatibility_final_check.py
  migration-final-20261026/       # first setup failure and cleanup exits
  migration-final-20261026-r2/    # second harness failure and cleanup exits
  migration-final-20261026-r3/    # successful real verification and catalogs
```

Successful harness command:

```sh
python3 /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/migration_final_verify.py
python3 /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode/compatibility_final_check.py
```

These are one-shot, guarded scratch-database checks. The first script refuses
existing target databases/evidence output rather than silently erasing them.
The preserved targets are the successful run, not a suggestion to rerun setup
against occupied names.

| Actual invocation / phase | Exit(s) | Evidence / interpretation |
| --- | --- | --- |
| Initial migration harness | **1** | Fixture's immediate readiness probe returned connection-refused, exit 1; stopped **before migration**. Fixture start/removal, creation/seeding and read-only setup commands exited 0. |
| Fixture startup diagnostic: same `docker run`, `docker logs`, `docker exec ... php -r` readiness request | **0 / 0 / 0** | Confirmed startup race. Harness readiness changed to bounded condition polling. |
| Cleanup before retry 2: remove task-owned provider; drop only the two task-owned final DBs | **0 / 0 / 0** | `migration-final-20261026/setup-cleanup.json`. |
| Second migration harness | **1** | Docker PHP command omitted `-i`, so its source stdin was not executed; process exit 0 was rejected by the mandatory provider-journal assertion. No migration ran. This was not an executed lost-response pass. Fixed stdin forwarding and required explicit PASS output. |
| Cleanup before retry 3: drop only the two task-owned final DBs | **0 / 0** | `migration-final-20261026-r2/setup-cleanup.json`. |
| Final migration harness, including provider cleanup | **0** | `migration-final-20261026-r3/commands.json`: **72 child commands, each exit 0**, with exact argv and individual stdout/stderr/SQL logs. |
| 22 original SQL byte comparisons and original/current manifest verification | **0** | Included in final harness; `baseline-*.log`, `immutable.json`. All 28 checksum entries matched; only 012–014 are additions. |
| Route matrix | **0** | `route-matrix.log`, included in the final harness. |
| Clean concurrent normal Go CLI pair | **0 / 0** | `migration_checks_final-migrator-{1,2}.log`; observed advisory wait count **2** before release. |
| Populated upgrade concurrent normal Go CLI pair | **0 / 0** | `migration_upgrade_final-migrator-{1,2}.log`; observed advisory wait count **2** before release. |
| Independent idempotent normal Go CLI rerun per target | **0 / 0** | `*-idempotent-rerun.log`; full 14-row histories unchanged. |
| Baseline fixture POST/rollback, current upgraded PHP API/financial checks | **0 / 0** | `legacy-sent-rollback.log`, `upgrade-native-contract.log`; provider journals prove one attempt. |
| Transactional constraint exercise on each target | **0 / 0** | `*-constraints.log`: 29 rejection notices plus positive/cascade notice each. |
| Final live catalog/index/history/quarantine probes | **0**, all seven commands | `final-catalog-probes.json`; all indexes valid/ready, history `14/1/14/14`, 30 tables; unresolved inbox retained and false-link count 0. |
| Initial read-only compatibility checker | **1** | Harness assumed inline `screenId.enum`; schema actually uses `$ref: #/$defs/screenName`. Corrected checker to resolve the existing definition; no contract source changed. |
| Final read-only compatibility checker | **0** | `compatibility-result.json`, `compatibility-command-exits.json` (all 12 git subprocesses exit 0), `compatibility-source-hashes.json`. |

The successful migration logs include every subprocess exit and SQL stdin;
`result.json` describes the successful assertions. Its command count is 71 at
the pre-cleanup checkpoint; the authoritative `commands.json` contains **72**
after successful fixture removal. Supplemental catalog probes and compatibility
checks have their own exit ledgers as listed above. The exploratory dedicated
read/glob tools failed with `Bun is not defined`; shell/Python reads were used.

Only this report was added inside the repository by this task. Temporary
verification scripts were sufficient; no production or mobile source edit,
subagent, commit or push was needed. Final report/source whitespace and snapshot
checks are recorded in the final evidence directory.
