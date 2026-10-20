# Browser payment / CORS compatibility regression

> **Historical regression record — before decommissioning.** Results and source
> comparisons below describe the original run. The parity runner and Go reference
> source have since been retired; their commands are not current rerun instructions.
> PHP CORS/browser-form regressions remain active. See
> [decommissioning](LEGACY_REMOVAL.md) and [deployment](DEPLOYMENT.md).

The installed Android journey exposed a real migration incompatibility: Chrome's
same-origin demo-payment form POST carries an `Origin` header; Yii rejected it
before validating the payment capability. Earlier HTTP integration tests omitted
that browser header and therefore missed the defect.

## Root cause and correction

The original `backend/internal/transport/http/server.go:209–233` grants CORS
response permissions only to explicitly configured origins, and rejects unknown
origins on OPTIONS preflight only. Yii had instead rejected every actual request
with an unknown origin. `backend-yii2/infrastructure/ApiKernel.php` now implements
the original policy, including trimmed origins, `Vary: Origin`, exact explicit
allowlisting, ignored wildcard entries and canonical allowed/exposed headers.

This does not grant cross-origin response access: an unlisted origin receives
no `Access-Control-Allow-Origin`. Bearer authorization, resource ownership,
webhook signatures and the payment-specific HMAC capability still apply.
Production still has no development payment route.

## Actual verification after deployment

The amended Yii image is running in `shopkmp-migrated-e2e`. Executed:

```sh
docker compose --env-file .env.example -p shopkmp-migrated-e2e exec -T yii2 php tests/cors.php
docker compose --env-file .env.example -p shopkmp-migrated-e2e exec -T yii2 composer test
docker compose --env-file .env.example -p shopkmp-migrated-e2e exec -T yii2 php tests/security.php
python3 scripts/run_android_installed_journey.py --serial emulator-5554
```

Results: **7 CORS cases**, **7 unit cases**, **6 security cases** passed. The
seven CORS cases cover actual unknown origins, rejected preflights, exact
allowlisted permissions, empty origins, wildcard rejection, forged/missing/
payment-mismatched capabilities and absence of production demo routes.

The installed Android journey then passed: **1 test, 29.907 seconds**, real
browser `Complete payment` → canonical **CAPTURED / PAID** → app return → paid
order details → devices → logout. Order `f513dcf2-1dc8-4bbe-a930-2ad2ab907af8`.
No CORS allowlist workaround, browser bypass or direct test payment POST was used.

Independent focused review and final full-suite results, when executed, are
recorded separately; these targeted checks do not imply they already ran.

Subsequent verification: the independent [final review](FINAL_REVIEW.md) accepted
the CORS parity/security boundary. The final full PHP suite, including all seven
CORS groups and the real demo-form Origin case, passed as part of
`python3 scripts/parity.py --disposable --regressions` on October20:77 tests,
66 syntax checks. The later Android run also passed after the shared startup
correction (30.789s); [mobile evidence](MOBILE_E2E_REPORT.md) is the current record.
