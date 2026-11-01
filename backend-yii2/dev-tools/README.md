# Isolated PHP analysis and tests

Task 5 of `docs/backend-fixes/round-7/AUDIT_PLAN.md`. Tooling evidence below was
collected on **2026-11-01**, starting from HEAD `160d2a5`, in a shared checkout
with concurrent implementation work. This is tooling verification, not a claim
that the full round-7 acceptance/security gates passed.

## Reproducible commands

From the repository root, with Docker/BuildKit:

```sh
make test-phpunit analyse-php audit-php-tools
```

`php-tools-build` first builds the existing application Dockerfile from the
current checkout, then supplies that image as Dockerfile.tools' named
`application` context. The independent `dev-tools` source context makes tools
available even when production excludes this directory. No host PHP, Composer
or vendor installation is required. The new Make targets and PHP CI step are
additive to the existing native tests, lint, runtime and security gates.

For explicit bootstrap/debug use with an existing application image:

```sh
docker build \
  --build-arg PHP_APPLICATION_IMAGE=shopkmp-round6-acceptance-e2e-yii2 \
  --build-context dev-tools=./backend-yii2/dev-tools \
  -f backend-yii2/Dockerfile.tools -t shopkmp-php-dev-tools backend-yii2
docker run --rm --network none shopkmp-php-dev-tools composer test
```

That override refreshes application source, but inherits the cached image's
runtime dependencies: use the default source build for integration evidence.
Unit tests and analysis run with `--network none`; the Composer advisory audit
needs network access. The image bypasses the application entrypoint, so no
service credentials, database, search service or Compose lifecycle is involved.

## Dependency and runtime boundary

- `composer.json` / `composer.lock` / `vendor/` in this directory are an
  independent Composer project. Its PHP requirement is **>=8.4.1**; the tools are
  pinned exactly to **PHPStan 2.3.4** and **PHPUnit 13.4.5**. No platform-ignore,
  simulated platform, baseline, advisory suppression or Composer plugin is used.
- Application `../composer.json` and `../composer.lock` retain **PHP >=8.3** and
  the original runtime graph. Bootstrap loads the application autoloader without
  HTTP/bootstrap configuration or service I/O. PHPUnit uses its own autoloader.
- The actual pinned application PHP **8.5.12** image already has DOM, XML,
  XMLWriter, libxml, filter, JSON and mbstring. Dockerfile.tools checks them,
  and Composer checks all transitive platform requirements, including Phar and
  tokenizer. There is no extension compiler/package installation in the new
  Dockerfile, and no change to the production Dockerfile or its XML/toolchain.
- **Coordinator integration:** exclude `dev-tools/` and `Dockerfile.tools` in
  `backend-yii2/.dockerignore` before production delivery. Its existing
  `COPY . .` otherwise copies these source files. No tool vendor directory was
  installed on the host by this task. The separate named context is already
  implemented so the exclusion does not break the tools build.

Authoritative exact-version manifests were fetched successfully during this
implementation:

- <https://raw.githubusercontent.com/phpstan/phpstan/2.3.4/composer.json>:
  PHP `^7.4|^8.0`, `phpstan` and `phpstan.phar` binaries.
- <https://raw.githubusercontent.com/sebastianbergmann/phpunit/13.4.5/composer.json>:
  PHP `>=8.4.1`, DOM/filter/JSON/libxml/mbstring/XMLWriter requirements.
- The wider upstream release/compatibility research is in
  `docs/backend-fixes/round-7/DEPENDENCIES.md`; it was read, not rewritten.

Composer 2.11.1 actually solved and installed **27 packages**, all in
`packages-dev`; `packages` is empty. The generated lock contains actual upstream
source/dist references. `composer show --locked --tree` and `composer why
phpstan/phpstan --tree` / `composer why phpunit/phpunit --tree` confirmed the two
direct tools. PHPUnit owns the remaining graph, including code-coverage's parser
and complexity/line-count components; coverage drivers are not required or
installed for these unit tests.

| Locked package | Version | Locked package | Version |
| --- | --- | --- | --- |
| phpstan/phpstan | 2.3.4 | phpunit/phpunit | 13.4.5 |
| myclabs/deep-copy | 1.14.0 | nikic/php-parser | v5.9.0 |
| phar-io/manifest | 2.0.4 | phar-io/version | 3.2.1 |
| phpunit/php-code-coverage | 14.5.0 | phpunit/php-file-iterator | 7.0.2 |
| phpunit/php-invoker | 7.0.0 | phpunit/php-text-template | 6.0.1 |
| phpunit/php-timer | 9.0.0 | sebastian/cli-parser | 5.0.1 |
| sebastian/comparator | 8.4.0 | sebastian/complexity | 6.0.0 |
| sebastian/diff | 9.0.1 | sebastian/environment | 9.3.3 |
| sebastian/exporter | 8.2.1 | sebastian/file-filter | 1.0.0 |
| sebastian/git-state | 1.1.0 | sebastian/global-state | 9.0.1 |
| sebastian/lines-of-code | 5.0.2 | sebastian/object-enumerator | 8.1.0 |
| sebastian/object-reflector | 6.1.0 | sebastian/recursion-context | 8.0.1 |
| sebastian/type | 7.0.2 | sebastian/version | 7.0.1 |
| staabm/side-effects-detector | 1.0.5 | | |

The application graph/why was independently executed too: Yii2 2.0.55 owns
cebe/markdown 1.2.1, ezyang/htmlpurifier v4.19.1 and yii2-composer 2.0.11. Its
replaced Bower assets are not additional installed PHP packages.

## Strict analysis scope and integration handoff

`phpstan.neon` uses **level max**, targets production compatibility **PHP 8.3
(`80300`)**, and analyzes exactly:

1. `infrastructure/ReadinessBudget.php`
2. `infrastructure/ReadinessPolicy.php`
3. `infrastructure/CoreReadinessProbe.php`
4. `infrastructure/ReadinessSql.php`
5. `infrastructure/ReadinessAdmission.php`
6. `application/PaymentService.php`
7. `domain/Ids.php`

No legacy-directory scan, ignored-error baseline, excluded selected files or
globally disabled rules is used. Dependencies are discovered through the real
application autoloader. `stubs/payment.stub` describes only the repository
boundary: an open payment-row shape for the columns actually consumed, the
literal payment DTO emitted by `ShopRepository::paymentDto()`, and a
string-keyed order DTO forwarded without inspection. `stubs/ids.stub` accurately
expresses `timestamp()`'s conditional nullability: only null input returns null;
non-null input returns a string or throws. Neither stub supplies executable code.

**Historical handoff — superseded by [latest integrated results](../../docs/backend-fixes/round-7/TEST_RESULTS.md).**
The pending items in this paragraph and the handoff below describe the original
task checkpoint, not current integration status; RED evidence is retained.
At this task's verification
point the three readiness classes did not exist. They intentionally remain in
the config, so the full command fails instead of silently omitting them. No
readiness API or placeholder implementation/test was invented.

The existing-files diagnostic run also identified missing PHPDocs on the six
array-bearing PaymentService methods. Stubs resolve dependency/call-site types,
but cannot satisfy PHPStan's declaration-level checks on the analyzed source.
Coordinator should add accurate annotations with its PaymentService changes:

- `payment()`, `refund()`, `void()`: return
  `array{id:string, orderId:string, method:string, status:string, amount:string, transactionId:string|null}`.
- `confirm()`: return `array{paymentId:string, status:string, paymentUrl:string|null}`.
- `webhook()`: input `array<array-key, mixed>` (untrusted values validated in
  PaymentOperations; do not pretend every incoming webhook is already valid).
- `cancel()`: return `array<string, mixed>` for its forwarded order DTO, or the
  complete accurate shape from OrderDtoMapper.

Then rebuild and run **`make test-phpunit analyse-php audit-php-tools`** after
readiness/payment integration. Resolve real findings in the owned source, with
accurate array/callable PHPDocs where needed; do not lower the level or suppress
errors. Add data-provider budget tests against the actual ReadinessBudget API
once available: invalid/nonfinite/zero/negative budgets, valid boundary values,
and deterministic remaining/deadline behavior with an injected clock if exposed.
The existing custom/native tests remain required and are not discovered as
PHPUnit tests.

## Executed evidence

Image aliases used below: `TOOLS=shopkmp-round7-tools-task5`,
`APP=shopkmp-round7-tools-application-task5`. Commands are from the repository
root unless a working directory is given.

| Actual command / operation | Exit / observed result |
| --- | --- |
| `docker image inspect shopkmp-round6-acceptance-e2e-yii2 --format '{{.Id}} {{.Architecture}} {{.Os}}'` | 0; `sha256:c3fafb30783031b7c4a0115aa8ce03020e075206fce596af2d01d81344ca95ae`, linux/arm64. |
| `docker run --rm --entrypoint composer --mount type=bind,src="$PWD/backend-yii2/dev-tools",dst=/tools --workdir /tools --env COMPOSER_VENDOR_DIR=/tmp/shopkmp-tools-vendor shopkmp-round6-acceptance-e2e-yii2 update --no-interaction --prefer-dist --no-plugins --no-scripts` | 0; real lock write + 27 installs + no advisory findings. Vendor stayed in the disposable container. |
| `make php-tools-build PHP_TOOLS_IMAGE=shopkmp-round7-tools-task5 PHP_TOOLS_APPLICATION_IMAGE=shopkmp-round7-tools-application-task5` | 0; source application build and named-context tools build both succeeded. |
| Tools image: `php --version`, `composer --version`, `vendor/bin/phpstan --version`, `vendor/bin/phpunit --version` | 0 each; PHP 8.5.12, Composer 2.11.1, PHPStan 2.3.4, PHPUnit 13.4.5. |
| Tools image: `composer validate --strict`, `composer check-platform-reqs` | 0 each; valid manifest/lock and all real platform requirements satisfied. |
| Tools image: `vendor/bin/phpunit --validate-configuration` | 0; PHPUnit 13.4 XML configuration is valid. |
| `docker run --rm shopkmp-round7-tools-task5 composer audit --locked` | 0; no security vulnerability advisories found. |
| Tools image: `composer show --locked --tree`, `composer show --locked --format=json`, `composer why phpunit/phpunit --tree`, `composer why phpstan/phpstan --tree` | 0 each; real solved graph/direct roots inspected. |
| `docker run --rm --network none shopkmp-round7-tools-task5 composer test` | 0; **19 tests / 24 assertions**, no warnings/deprecations. Five valid-case/idempotence rows and fourteen malformed/opaque rejection rows; all expectations are literal fixtures. |
| Tools image: `composer analyse` | 1; `ReadinessBudget.php` does not exist, expected integration blocker. |
| Tools image: `vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress ../application/PaymentService.php ../domain/Ids.php` | 1; **six** missing source PHPDocs in PaymentService; Ids nullability and repository call-site shapes resolved by accurate stubs. Not claimed as a green full analysis. |
| Tools image: `vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress ../domain/Ids.php` | 0; `[OK] No errors` at max level / PHP 8.3 target. This diagnostic override is not the configured full gate. |
| Application project inside tools image: `composer validate --strict && composer check-platform-reqs && composer test && composer lint` | 0; **74 native tests** passed; syntax check passed for **136 files** at that run. Expected injected-failure diagnostics were emitted by native transaction/search tests. |
| Application project: `composer audit --locked`, `composer show --locked --tree`, `composer why yiisoft/yii2 --tree` | 0 each; no advisory findings, original four-package runtime graph. |
| `python3 scripts/test_php_runtime.py --image shopkmp-round7-tools-application-task5` | 0; all five runtime gates passed, including no compiler/build packages/headers, resolved shared libraries, extensions/TLS/codecs/processes, Composer CLI and live FPM ping. |
| `backend-sdui`: `GOFLAGS=-mod=readonly sh scripts/check.sh` | 0; Go 1.27.3, tidy diff, checksum verification, formatting, vet, full tests/race/build/contracts, pinned golangci-lint 2.15.0 (`0 issues.`), govulncheck 1.9.0 (`No vulnerabilities found.`). |
| `tools/ops`: `go mod tidy -diff && go mod verify && go vet ./... && go test ./... -count=1 && go test -race ./... -count=1 && go build ./...` | 0; module checks, full tests/race/vet/build passed. |
| `tools/ops`: `test -z "$(gofmt -l .)"` | 0; no unformatted Go files. |
| `tools/ops`: `GOFLAGS=-mod=readonly go run golang.org/x/vuln/cmd/govulncheck@v1.9.0 ./...` | 0; `No vulnerabilities found.` Source-scan success does not establish advisory freshness or final-image security. |

Retained troubleshooting facts: the initial PHPUnit run returned 0 but emitted
one runner deprecation for `--do-not-cache-result`; it was replaced with the
verified PHPUnit 13 options `--do-not-record-test-run-history` and
`--do-not-cache-test-index`, and runner deprecations now fail the suite. The
initial existing-files PHPStan run had nine errors before boundary stubs. A
temporary attempt to describe analyzed PaymentService declarations in stubs
did not satisfy declaration checks and was removed rather than suppressing the
errors. The first direct `./scripts/check.sh` invocation exited 126 because the
existing script is not executable; the successful canonical invocation above
uses `sh` without changing its mode.

The independent 72-hour Go vulnerability-database freshness gate and delivered
artifact scans remain the coordinator's full acceptance gates. They were not
waived or changed. Review of these tooling changes was an author self-review;
no child agents, staging, commits or pushes were used.

### Image and source bindings

After the final source build/test/audit run, `docker image inspect` returned
these local linux/arm64 image IDs (exit 0):

```text
shopkmp-round7-tools-task5
sha256:a5bcef3b335daaaf75627fcbcd0a9c97fc3ee168c52b954807262ade5eb98aa5
shopkmp-round7-tools-application-task5
sha256:4bedfe155f5307959fd49447ff43170f7023aacae93ce135e35891c778defb83
```

`shasum -a 256` at the final build returned (exit 0):

```text
26a32bb1d74c8d3e084204d6ec90044e6df70d42b71a5cd915e41c1755719cc5  backend-yii2/composer.json
b1243946d4605de892fc4f4cba9b2ab938fec56af56e06456a29c0aa61f004a7  backend-yii2/composer.lock
12711a557e8dcd712a42341e7130aceafa4296ab2d8c39f240307eb72d8a9965  backend-yii2/dev-tools/composer.json
28e9b47d7ef0b3597d7a76ebc2048dff11aeda9e80b46bd2d945e72bfae79c85  backend-yii2/dev-tools/composer.lock
8268db5e9cc7eb84efa57ca35bdea2f9f011954fa2af76b122810b169eecdbd1  backend-yii2/application/PaymentService.php
437940a314e11293cbf4d38a99e9bf08ab11ba20cde51217274a6d801ab49810  backend-yii2/domain/Ids.php
```

The first two hashes match the pre-task measurement and dependency research.
PaymentService is concurrent coordinator-owned work, not edited by this task.
Images include the tooling/readme snapshot at build time; later evidence-only
README additions do not change the verified manifest/lock/config/test contents.
