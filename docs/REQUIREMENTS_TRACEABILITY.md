# Трассируемость требований ShopKMP

> **Historical pre-migration traceability.** Paths and tests below describe the
> retired Go business implementation; they are not current build/run dependencies.
> Contracts, SQL and ops tools were preserved before authorized source/archive
> removal. See [decommissioning](LEGACY_REMOVAL.md),
> [architecture](ARCHITECTURE.md) and [deployment](DEPLOYMENT.md).

Источник требований — таблицы API в корневом `README.md`. Каноническое дерево
маршрутов и public/protected/admin-политика находятся в
`backend/internal/transport/http/router.go`; там же обработчики связывают
wire-запросы с агрегатными портами composition root.
HTTP-поведение находится в `transport/http/route_handlers.go` и
`commerce_handlers.go`, а единый
исполняемый HTTP-smoke — в `backend/cmd/e2e`. Названия тестов ниже находятся в
`backend/internal/shop/memory`, `backend/internal/shop/postgres` и
`backend/internal/transport/http`; тесты внешних адаптеров — рядом с ними в
`identity` и `search`.

Исторические названия `register*Routes` в третьем столбце сохранены как
логические группы для чтения таблицы; их пути и access policy теперь являются
строками единого `httpx.apiRoutes`, а не функциями composition root.

| Сценарий задания | Endpoint | Логическая группа transport | Автоматическая проверка |
| --- | --- | --- | --- |
| Регистрация | `POST /api/v1/auth/register` | `registerPublicAuthRoutes` | `TestAuthPinAndProfileFlows`, full-stack E2E |
| Вход | `POST /api/v1/auth/login` | `registerPublicAuthRoutes` | `TestAuthPinAndProfileFlows`, full-stack E2E |
| Refresh | `POST /api/v1/auth/refresh` | `registerPublicAuthRoutes` | `TestAuthPinAndProfileFlows` |
| Logout | `POST /api/v1/auth/logout` | `registerProtectedAuthRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Сессия | `GET /api/v1/auth/session` | `registerProtectedAuthRoutes` | `TestAuthPinAndProfileFlows` |
| Установка PIN | `POST /api/v1/auth/pin` | `registerProtectedAuthRoutes` | `TestAuthPinAndProfileFlows`, full-stack E2E |
| Вход по PIN | `POST /api/v1/auth/pin/login` | `registerPublicAuthRoutes` | `TestAuthPinAndProfileFlows`, full-stack E2E |
| Смена PIN | `PUT /api/v1/auth/pin` | `registerProtectedAuthRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Удаление PIN | `DELETE /api/v1/auth/pin` | `registerProtectedAuthRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| PIN-устройства | `GET /api/v1/auth/pin/devices` | `registerProtectedAuthRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Отзыв PIN-устройства | `DELETE /api/v1/auth/pin/devices/{deviceId}` | `registerProtectedAuthRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Включение биометрии | `POST /api/v1/auth/biometric` | `registerProtectedAuthRoutes` | `TestBiometricHTTPChallengeLoginAndReplayProtection`, KMP `enableBiometricCreatesHardwareKeyAndRegistersPublicKey` |
| Challenge биометрии | `POST /api/v1/auth/biometric/challenge` | `registerPublicAuthRoutes` | `TestBiometricHTTPChallengeLoginAndReplayProtection` |
| Вход по биометрии | `POST /api/v1/auth/biometric/login` | `registerPublicAuthRoutes` | `TestBiometricHTTPChallengeLoginAndReplayProtection`, `TestBiometricEdDSAAndLockout`, KMP `biometricLoginSignsServerChallengeAndSavesTokens` |
| Отключение биометрии | `DELETE /api/v1/auth/biometric` | `registerProtectedAuthRoutes` | доменный контракт `DeleteBiometric`, OpenAPI contract |
| Биометрические устройства | `GET /api/v1/auth/biometric/devices` | `registerProtectedAuthRoutes` | доменный контракт `BiometricDevices`, OpenAPI contract |
| Отзыв биометрии устройства | `DELETE /api/v1/auth/biometric/devices/{deviceId}` | `registerProtectedAuthRoutes` | доменный контракт `DeleteBiometric`, OpenAPI contract |
| Профиль | `GET /api/v1/users/me` | `registerUserRoutes` | `TestAuthPinAndProfileFlows` |
| Редактирование профиля | `PUT /api/v1/users/me` | `registerUserRoutes` | `TestAuthPinAndProfileFlows` |
| Аватар | `PUT /api/v1/users/me/avatar` | `registerUserRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Смена пароля | `POST /api/v1/users/me/change-password` | `registerUserRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Корневые категории | `GET /api/v1/categories` | `registerCatalogRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage`, full-stack E2E |
| Категория | `GET /api/v1/categories/{id}` | `registerCatalogRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Дочерние категории | `GET /api/v1/categories/{id}/children` | `registerCatalogRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Карточка товара | `GET /api/v1/products/{id}` | `registerCatalogRoutes` | `TestPublicProductContractAndCORSHeaders` |
| Каталог | `GET /api/v1/products` | `registerCatalogRoutes` | `TestCatalogFiltersPaginationAndImages`, full-stack E2E |
| Поиск | `GET /api/v1/products/search` | `registerCatalogRoutes` | `TestSearchQueryLengthIsBounded`, full-stack E2E |
| Популярные товары | `GET /api/v1/products/popular` | `registerCatalogRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Остаток | `PATCH /api/v1/products/{id}/stock` | `registerReviewAndAdminRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Корзина | `GET /api/v1/cart` | `registerCartRoutes` | `TestCartAndCheckoutFlow` |
| Добавление в корзину | `POST /api/v1/cart/items` | `registerCartRoutes` | `runRepositoryBehaviorContract`, full-stack E2E |
| Изменение количества | `PATCH /api/v1/cart/items/{productId}` | `registerCartRoutes` | `TestCartAndCheckoutFlow` |
| Удаление позиции | `DELETE /api/v1/cart/items/{productId}` | `registerCartRoutes` | `TestCartAndCheckoutFlow` |
| Очистка корзины | `DELETE /api/v1/cart` | `registerCartRoutes` | `TestMutationRateLimitIsPerAuthenticatedPrincipal` |
| Создание заказа | `POST /api/v1/orders` | `registerOrderAndPaymentRoutes` | `runRepositoryBehaviorContract`, full-stack E2E |
| История заказов | `GET /api/v1/orders` | `registerOrderAndPaymentRoutes` | `TestCartAndCheckoutFlow` |
| Детали заказа | `GET /api/v1/orders/{id}` | `registerOrderAndPaymentRoutes` | `TestCartAndCheckoutFlow` |
| Отмена заказа | `POST /api/v1/orders/{id}/cancel` | `registerOrderAndPaymentRoutes` | `runRepositoryBehaviorContract` |
| Адреса | `GET /api/v1/addresses` | `registerAddressRoutes` | `TestCartAndCheckoutFlow` |
| Создание адреса | `POST /api/v1/addresses` | `registerAddressRoutes` | `runRepositoryBehaviorContract`, full-stack E2E |
| Обновление адреса | `PUT /api/v1/addresses/{id}` | `registerAddressRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Удаление адреса | `DELETE /api/v1/addresses/{id}` | `registerAddressRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Статус платежа | `GET /api/v1/payments/{id}` | `registerOrderAndPaymentRoutes` | `TestPaymentsWebhookAndCancellation`, full-stack E2E |
| Инициация платежа | `POST /api/v1/payments/{id}/confirm` | `registerOrderAndPaymentRoutes` | `runRepositoryBehaviorContract`, full-stack E2E |
| Webhook оплаты | `POST /api/v1/payments/webhook` | `apiRoutes` | `TestPaymentsWebhookAndCancellation` |
| Refund | `POST /api/v1/payments/{id}/refund` | `registerOrderAndPaymentRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Void | `POST /api/v1/payments/{id}/void` | `registerOrderAndPaymentRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |
| Отзывы | `GET /api/v1/products/{id}/reviews` | `registerCatalogRoutes` | `TestReviewsRequirePurchase` |
| Создание отзыва | `POST /api/v1/products/{id}/reviews` | `registerReviewAndAdminRoutes` | `runRepositoryBehaviorContract` |
| Удаление отзыва | `DELETE /api/v1/reviews/{id}` | `registerReviewAndAdminRoutes` | `TestDocumentedBusinessRoutesHaveBehaviorCoverage` |

Расширения сверх исходной таблицы (`admin/products`, переход статуса заказа и
`dateOfBirth`) перечислены отдельно в `HomeWork/README.md` и полностью описаны
в OpenAPI.

## Зафиксированные отклонения от проектных примеров

Корневой `../README.md` содержит ранний рекомендуемый стек и примеры контракта.
Ниже явно сопоставлена фактическая реализация, чтобы замены не выглядели как
пропущенные зависимости.

| В корневом задании | В реализации | Причина и проверка |
| --- | --- | --- |
| `golang-migrate/migrate v4.19.1` | Собственный one-shot `cmd/migrate` | Forward-only миграции встроены в binary; таблица `schema_migrations` хранит SHA-256 checksum и останавливает запуск при изменении применённого SQL. |
| `testcontainers-go v0.42.0` | Docker Compose + `cmd/e2e` | Один и тот же full-stack сценарий поднимает PostgreSQL, Keycloak и OpenSearch локально и в CI без второго способа описания инфраструктуры. |
| `opensearch-go v4.6.0` | Ограниченный HTTP-адаптер `backend/internal/search/opensearch_searcher.go` | Проекту нужны только index/search/health; адаптер имеет timeout, лимиты ответа и unit-тесты wire-формата. |
| `swaggo/swag v1.16` | Ручной `openapi.yaml` | Спецификация является каноническим контрактом и сверяется с Kotlin DTO отдельным contract-тестом. |
| Деньги в JSON-примерах числами | Decimal-строки (`"4990.00"`) | Исключена потеря точности через floating point; backend использует `Money`, KMP — единый exact parser/value type с unit-тестами. |
| Keycloak `localhost:8180` | `localhost:8081` в demo compose | Порт явно опубликован только на loopback и одинаково указан в quick start и E2E overlay. |
| Единственный `GET /health` | `/health/live` и `/health/ready` на internal listener `:9091`; dev также оставляет aliases на `:8080` | Liveness отделён от dependency-aware readiness; production public listener не публикует operational routes. |
| По-доменная структура `internal/auth`, `users`, `catalog`, `cart`, `orders`, `payments`, `reviews` | Эти пакеты содержат общие правила и агрегатные порты; `internal/shop` — компактный composition facade, реализации изолированы в `shop/memory` и `shop/postgres`, HTTP — в `transport/http` | Компилятор запрещает transport/SQL-зависимости в доменных пакетах и взаимный доступ адаптеров к внутреннему состоянию; обе реализации вызывают общие правила и проходят один contract-test. |
| Списки `items/page/size/totalPages/totalItems`, денежные поля в примерах числовые | Та же envelope-пагинация; деньги внутри элементов — decimal-строки | Форма списков совпадает с заданием. Единственное wire-отклонение — exact money strings, проверенные OpenAPI/Kotlin contract-тестом. |

Эти замены не меняют перечень сущностей и методов задания. Канонические поля,
nullable-ограничения и wire-форматы определяет
`backend/internal/transport/rest/openapi.yaml`.
