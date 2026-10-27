.PHONY: run up down logs seed migrate reindex integration-up integration-check test-php test-infra test-scheduler prod-up prod-config prod-build prod-infra prod-drain prod-migrate prod-reindex prod-app prod-workers prod-edge prod-rollback-stop prod-down prod-logs compose-config test test-race vet coverage check build e2e frontend-unit frontend-test frontend-lint frontend-build frontend-ios frontend-check check-offline check-all

ENV_FILE ?= .env
PROD_ENV_FILE ?= .env.prod
WAIT_TIMEOUT ?= 2100
SCHEDULER_TEST_IMAGE ?= shopkmp-task4-scheduler-tests
PHP_TEST_IMAGE ?= shopkmp-php-tests
E2E_PROJECT ?= shopkmp-migrated-e2e
E2E_PORT ?= 58095
COMPOSE = python3 scripts/compose.py --env "$(ENV_FILE)"
PROD_COMPOSE = python3 scripts/compose.py --env "$(PROD_ENV_FILE)" --prod

run:
	$(COMPOSE) up -d --build --wait --wait-timeout $(WAIT_TIMEOUT)
	$(MAKE) seed reindex ENV_FILE="$(ENV_FILE)"

up:
	$(COMPOSE) up -d --build --wait --wait-timeout $(WAIT_TIMEOUT)

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs --follow --tail 100

seed:
	$(COMPOSE) run --rm --no-deps seed

migrate:
	$(COMPOSE) run --rm migrate
	$(COMPOSE) run --rm --no-deps yii-migrate

reindex:
	$(COMPOSE) exec -T yii2 php yii maintenance/reindex

integration-up:
	# A rebuilt dependency must not be gated by an old running proxy's failed readiness.
	EDGE_PORT=$(E2E_PORT) python3 scripts/compose.py --env .env.example --project $(E2E_PROJECT) stop edge sdui yii2-http maintenance reconcile payment-recovery yii2
	# Prepare ingress/FPM proxy containers before the full dependency startup.
	EDGE_PORT=$(E2E_PORT) python3 scripts/compose.py --env .env.example --project $(E2E_PROJECT) up --no-start --no-deps yii2-http edge
	EDGE_PORT=$(E2E_PORT) python3 scripts/compose.py --env .env.example --project $(E2E_PROJECT) up -d --build --wait --wait-timeout $(WAIT_TIMEOUT)
	EDGE_PORT=$(E2E_PORT) python3 scripts/compose.py --env .env.example --project $(E2E_PROJECT) run --rm --no-deps seed
	EDGE_PORT=$(E2E_PORT) python3 scripts/compose.py --env .env.example --project $(E2E_PROJECT) exec -T yii2 php yii maintenance/reindex

integration-check:
	EDGE_PORT=$(E2E_PORT) python3 scripts/verify.py --env .env.example --project $(E2E_PROJECT) --url http://127.0.0.1:$(E2E_PORT) --disposable

.PHONY: test-backend-fixes test-trust-live
test-backend-fixes:
	python3 scripts/backend_fixes.py --env .env.example --project $(E2E_PROJECT) --disposable

test-trust-live:
	python3 backend-yii2/tests/trust-live/run.py

test-php:
	docker build -t "$(PHP_TEST_IMAGE)" backend-yii2
	docker run --rm --network none "$(PHP_TEST_IMAGE)" composer test
	docker run --rm --network none "$(PHP_TEST_IMAGE)" composer lint

test-infra:
	$(MAKE) test-scheduler
	python3 scripts/test_backend_integration.py
	python3 scripts/test_infrastructure.py --production-env "$(PRODUCTION_TEST_ENV)"
	python3 scripts/test_deployment_commands.py --production-env "$(PRODUCTION_TEST_ENV)"

test-scheduler:
	docker build -t "$(SCHEDULER_TEST_IMAGE)" backend-yii2
	python3 scripts/test_scheduler.py --image "$(SCHEDULER_TEST_IMAGE)"

prod-config:
	$(PROD_COMPOSE) config --quiet

prod-up:
	$(PROD_COMPOSE) up -d --build --wait --wait-timeout $(WAIT_TIMEOUT)

prod-build:
	$(PROD_COMPOSE) build

prod-infra:
	$(PROD_COMPOSE) up -d --build --wait --wait-timeout $(WAIT_TIMEOUT) postgres keycloak-postgres keycloak opensearch

prod-drain:
	$(PROD_COMPOSE) stop edge
	$(PROD_COMPOSE) stop sdui yii2-http maintenance reconcile payment-recovery yii2

prod-migrate:
	$(PROD_COMPOSE) run --rm --no-deps migrate
	$(PROD_COMPOSE) run --rm --no-deps yii-migrate

prod-reindex:
	$(PROD_COMPOSE) run --rm --no-deps reindex

prod-app:
	$(PROD_COMPOSE) up -d --no-deps --wait --wait-timeout $(WAIT_TIMEOUT) yii2 yii2-http sdui

prod-workers:
	$(PROD_COMPOSE) up -d --no-deps --wait --wait-timeout $(WAIT_TIMEOUT) maintenance reconcile payment-recovery

prod-edge:
	$(PROD_COMPOSE) up -d --no-deps --wait --wait-timeout $(WAIT_TIMEOUT) edge

prod-rollback-stop: prod-drain

prod-down:
	$(PROD_COMPOSE) down

prod-logs:
	$(PROD_COMPOSE) logs --follow --tail 100

compose-config:
	python3 scripts/compose.py --env .env.example config --quiet

test: test-php
	go -C backend-sdui test ./...
	go -C tools/ops test ./...

test-race:
	go -C backend-sdui test -race ./...
	go -C tools/ops test -race ./...

vet:
	go -C backend-sdui vet ./...
	go -C tools/ops vet ./...

coverage:
	@set -eu; coverage_profile=$$(mktemp "$${TMPDIR:-/tmp}/shopkmp-bff-coverage.XXXXXX"); \
		trap 'rm -f "$$coverage_profile"' EXIT; \
		go -C backend-sdui test -coverprofile="$$coverage_profile" ./...; \
		go -C backend-sdui tool cover -func="$$coverage_profile"

check: test test-race vet compose-config

build:
	$(COMPOSE) build

e2e:
	python3 scripts/integration.py --url "$${SHOPKMP_E2E_URL:-http://127.0.0.1:8080}" --disposable

frontend-unit:
	./gradlew testDebugUnitTest --no-daemon

frontend-test:
	./gradlew testDebugUnitTest koverVerify --no-daemon

frontend-lint:
	./gradlew detektMetadataCommonMain detektAndroidDebug --no-daemon

frontend-build:
	./gradlew :composeApp:assembleDebug --no-daemon

frontend-ios:
	./gradlew :core:common:iosSimulatorArm64Test :feature:auth:auth-ui:iosSimulatorArm64Test :feature:cart:cart-ui:iosSimulatorArm64Test :composeApp:linkDebugFrameworkIosSimulatorArm64 --no-daemon

frontend-check: frontend-test frontend-lint frontend-build frontend-ios

check-offline: test frontend-unit

check-all: check frontend-check
