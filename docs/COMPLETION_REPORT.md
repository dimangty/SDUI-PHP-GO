# Migration handoff — 2026-10-20

Current layout and run commands below reflect the authorized source/archive
decommissioning. The parity and executed-verification sections retain the
**pre-decommissioning results**; they are not new passes of removed comparisons.
See [decommissioning](LEGACY_REMOVAL.md) for extracted artifacts and current checks.

## Delivered runtime

```text
Android / iOS common CMP
         │ one public origin
       Nginx
         ├─ /api/v1/screens/*, /api/v1/sdui/* → independent Go BFF
         │                                      │ fixed internal HTTP
         └─ /api/v1/*, media, callbacks → Yii HTTP → PHP-FPM / Yii2
                                                       ├─ PostgreSQL
                                                       ├─ private Keycloak + identity DB
                                                       ├─ OpenSearch
                                                       └─ payment adapter
```

PHP owns business operations. The Go BFF has no PostgreSQL driver, database
credentials or business-data fallback. The independent `tools/ops` module contains
the checksum-validating one-shot migrator (with pinned pgx v5.10.0), all 22 unchanged
SQL up/down files and the standard-library Keycloak HTTP probe. The original Go
business source and archive have been removed. No database-backed Go business
runtime is present.

## Modules and contracts

| Location | Responsibility |
| --- | --- |
| `backend-yii2/` | Yii2 application services, domain rules, SQL repositories, Keycloak/opaque auth, PIN/biometric, catalog/search, profile/media, cart/orders/payments/reviews/admin |
| `backend-sdui/` | Bounded typed Yii HTTP client, eight screen builders, finite commands, v1 JSON Schema, fixtures, health and graceful shutdown |
| `backend-yii2/contracts/` | Unchanged canonical OpenAPI and frozen 54-operation method/path/public/protected/admin manifest |
| `tools/ops/`, `deploy/keycloak/` | Independent Go 1.26 migrator/probe, immutable SQL/checksum baseline, official Keycloak 26.3.3 image and preserved realm |
| `core/sdui-model/` | Typed serialization, bounded tolerant decoding, version/component/action fallback, state/forms/errors |
| `core/sdui-network/` | Typed repository over the existing RequestExecutor/client/refresh mutex |
| `core/sdui-actions/` | Controlled fields, validation, finite navigation/commands and native adapters |
| `core/sdui-renderer/` | All26 published types mapped to existing Eps UIKit in commonMain |
| `feature/main/` | Eight integrated destinations, StateFlow host, drafts, cancellation, confirmations, account/logout lifecycle |
| Existing client modules | Additive network/navigation/UIKit wiring; single-attempt payment confirmation; repaired real iOS CoreFoundation/Keychain/biometric bridge |
| `deploy/`, root Compose, `scripts/`, Makefile | Single edge, internal services, migration/reindex/maintenance, TLS production overlay, disposable verification and mobile runners |

Screens: **Home, Catalog, Product, Cart, Orders, Order Details, Profile, Devices**.
Components: Text/Image/Icon; Column/Row/Box/LazyColumn/LazyRow; Button/TextField/
SearchField; ProductCard/ProductGrid/CategoryList/Price/QuantityStepper/CartItem/
OrderCard/ProfileHeader; Empty/Loading/Error; Spacer/Divider; Form/FormField.
The six server commands are AddToCart, RemoveFromCart, UpdateQuantity,
CancelOrder, UpdateProfile and RevokeDevice. Navigation, fields, refresh/retry and
native checkout/login/logout/PIN/biometric semantics are finite client adapters.
No server JSON chooses executable code, HTTP URLs/methods or arbitrary routes.

## Historical API parity — before decommissioning

The paired captures/reports remain evidence. Their Go differential harness,
reference profiles/writers and cross-runtime comparison tests have been retired;
current verification runs the retained PHP business/API/concurrency/security
coverage and frozen-route checks without a reference origin.

The canonical registry contains **54**, not55, operations. Every operation has
an actual matching successful Go/Yii status/body/JSON-shape sample; mandatory
access policies are tested on both implementations.

| Area | Operations | Result |
| --- | ---: | --- |
| Password auth |5 | Matching real Keycloak registration/login/refresh/password/logout contracts |
| Session/PIN/biometric |13 | Matching sampled device/credential/challenge/opaque-session contracts |
| Catalog/reviews/admin stock |14 | Matching successful DTO/mutation contracts; disclosed supplemental legacy corrections below |
| Profile/addresses/avatar |7 | Matching sampled owner/profile/address/avatar-clear contracts |
| Cart |5 | Matching quantities, exact money, snapshots, mutations and replay |
| Orders |5 | Matching checkout/history/detail/transitions/cancel with SQL readbacks |
| Payments/webhooks |5 | Matching sampled confirm/capture/refund/void, dedup and ownership |
| **Total** |**54** | **194 paired cases;189 matches;5 disclosed corrections;0 unexpected mismatches** |

All39 protected-route missing-bearer probes and7 ordinary-user/admin probes match.
UUIDs, verified secrets and validated runtime timestamps are narrowly normalized;
money, stock, status, permissions, business text, nullability and references are
not removed from comparisons. The harness rejects13 mutations of real responses.

Five supplemental differences remain explicit, not counted as matching samples:

1. Product update with no images: Go emits `null`, Yii emits canonical `[]`.
2. Stock update with no images: the same canonical nonnullable-array correction.
3. Search with no images: the same correction, required by OpenAPI/Kotlin DTOs.
4. Search stock is rehydrated from current SQL instead of returning stale index stock.
5. Historical-order address deletion returns409 `ADDRESS_IN_USE` instead of a
   foreign-key error mapped to503; both preserve the address.

The unnecessary opaque-refresh error-message difference was removed after an
observed failing differential test. Full case mapping, normalization boundaries,
timestamps and remaining untested matrices: [API parity](API_PARITY_REPORT.md),
[route matrix](ROUTE_MATRIX.md), [independent review](API_PARITY_REVIEW.md).

## Historical executed verification

These results and counts are unchanged records from the migration milestones,
including tests that have since been removed with the retired implementation.

| Check | Actual result |
| --- | --- |
| Final disposable Go/Yii parity + complete PHP regressions | PASS;54 operations,194 pairs;77 PHP tests;66 syntax checks |
| PHP concurrency | Last-stock/same-key checkout, mixed address-delete race, refresh, counters, biometric replay and webhook dedup passed |
| Independent BFF | Live schema/builders/actions/auth/cancellation/bounds/retry suite, race detector and vet passed;13 published fixtures |
| Original Go reference | Full suite passed; original PostgreSQL and E2E passed in integration verification |
| Final Android/KMP | Full debug unit task set, APK, iOS simulator framework and root Kover **76.5252%** passed |
| CMP iOS component suites |91 real simulator tests passed at the reviewed CMP milestone, including6 Compose UI tests |
| Later native security/startup checks |22 Security/Keychain/common native cases;31 feature/main native regressions passed |
| Installed Android | Full real CARD journey,1 test passed,**30.789s**, after shared startup correction |
| Installed iOS | Full real CARD journey,1 test passed,**156.525s**, including cold relaunch after logout |
| Infrastructure | Fresh Compose build/up, shared-edge CARD E2E, spoof/access/bounds, worker failure/recovery, graceful stop/restart and disposable HTTPS/internal TLS passed |
| Review | Subsystem and final independent reviews: no remaining Critical/Important issue; minor follow-ups/remediation are documented |

Both installed tests drive UI signup/PIN/password login, all eight SDUI screens,
cart/native checkout confirmation, actual Chrome/Safari demo form submission,
CAPTURED/PAID readbacks, return to app and logout. Test API verifiers read committed
state; they do not inject application tokens or perform commerce mutations.

The full root integration command passed at Task4; its subsequently added
mandatory parity phase and final PHP suite were also executed independently.
Do not combine overlapping test counts or relabel simulated-provider payment as
real acquiring settlement. [Mobile screenshots/output](MOBILE_E2E_REPORT.md),
[detailed verification](VERIFICATION_REPORT.md), [final review](FINAL_REVIEW.md).

## Run and reproduce

```sh
cp .env.example .env
make run
# One public edge: http://127.0.0.1:8080
# Development Swagger: http://127.0.0.1:8080/swagger/

make integration-up
make integration-check
make check
# CARD-only journey against the already-running disposable stack:
SHOPKMP_E2E_URL=http://127.0.0.1:58095 make e2e

export ANDROID_HOME="$HOME/Library/Android/sdk"
./gradlew testDebugUnitTest :koverVerify :composeApp:assembleDebug
./gradlew :composeApp:linkDebugFrameworkIosSimulatorArm64

# Mobile test runners use the disposable shared edge at58095, not normal8080.
python3 scripts/run_android_installed_journey.py --serial emulator-5554
python3 scripts/run_ios_installed_journey.py --udid <booted-iPhone-simulator-UDID>
```

The isolated integration stack uses **http://127.0.0.1:58095**, project
`shopkmp-migrated-e2e`; normal development remains 8080. Current integration checks
cover canonical routes, scheduler/seed/reindex, edge CARD, live BFF races/vet,
ops tests/vet, complete retained PHP suites through the edge with a provider
fixture, and runtime probes. No removed Go suite/E2E/parity writer is run. CI
checks both Go modules, PHP Docker unit/lint, OpenAPI/routes, image builds and root
integration; existing Android/iOS jobs and the KMP 70% gate are unchanged.
`make coverage` reports BFF coverage only; the deleted Go business/financial
coverage gates have no claimed equivalent replacement.

Full environment, private PostgreSQL/Keycloak setup,
production TLS/secrets, health, seed/reindex/maintenance, exact production staging
and rollback commands: [DEPLOYMENT.md](DEPLOYMENT.md). New components/screens:
[SDUI_CONTRACT.md](SDUI_CONTRACT.md). Service commands:
[Yii README](../backend-yii2/README.md), [BFF README](../backend-sdui/README.md).

## Remaining boundaries

* The54-operation samples are not exhaustive coverage of every malformed/legacy
  input, transition or interleaving; five declared differences are observable.
* Physical Android/iOS biometric hardware, signed-device entitlements and Android
  process-death restoration need device verification. Simulator tests verify real
  native bridges and fail-closed unsupported hardware, not physical enclave success.
* Real production acquiring/refund/void settlement, full JWKS outage/rotation,
  sustained load/failover and restored existing-data/backup/rollback rehearsal
  remain release work. Disposable PKI/TLS tests prove mechanics, not actual deployment.
* No production switch was performed. Use the documented single-writer drain /
  migration / verification / edge-switch procedure; retain an immutable prior
  release externally and verified business/identity backups for rollback.
  Existing `schema_migrations` versions/names/checksums/applied-at values and data
  layout are preserved; Yii uses its own migration history. Snapshot-only legacy
  import requires the external release's controlled offline procedure; no Go
  importer is bundled in this checkout.

Historical implementation decisions: the initial unborn Git workspace used the
original archive as baseline and isolated reference writers for comparison;
canonical nonnullable arrays and the disclosed stock/address corrections were
retained. The later explicitly authorized decommissioning removes that archive,
business source and comparison infrastructure after extracting essentials.
These decisions do not substitute for the remaining production-release verification.
