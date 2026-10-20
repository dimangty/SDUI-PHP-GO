# ДЗ №3: чек-лист сдаваемых артефактов

> **Historical pre-migration checklist.** Artifact paths and results below refer
> to the retired Go business stack and are not the current launch/CI instructions.
> The user-authorized source/archive removal preserves contracts, SQL and ops
> tools at the locations in [decommissioning](LEGACY_REMOVAL.md). Current commands
> are in [the root README](../README.md) and [deployment](DEPLOYMENT.md).

> Запуск и проверка выполняются только по [`HomeWork/README.md`](../README.md).
> Корневой `../README.md` — неизменяемый текст задания и содержит исторические
> примеры портов и команд.

Порты, имена сервисов и health endpoints уточнены относительно ранних примеров:
актуальны `Keycloak :8081`, сервис `keycloak-postgres` и
`/health/live` + `/health/ready`. Полные команды находятся только в
`HomeWork/README.md`. Канонический контракт расположен в
`backend/internal/transport/rest/openapi.yaml`.

Этот файл отделяет обязательные результаты проектного ДЗ №3 от реализации,
сделанной сверх задания. Источник требований — корневой `../README.md`
относительно каталога `HomeWork/`.

| Требование ДЗ №3 | Основной артефакт | Дополнительное подтверждение | Готово |
| --- | --- | --- | --- |
| Название, назначение и решаемая проблема | Корневой `README.md`: «Что решает проект» | `HomeWork/README.md`: обзор реализации | ✅ |
| Целевая аудитория и портреты | Корневой `README.md`: «Целевая аудитория» | 4 сегмента с целями и болями | ✅ |
| MVP и границы | Корневой `README.md`: «MVP» | 15 сценариев, включая опциональную биометрию, и список вне scope | ✅ |
| Эскиз Compose Multiplatform | `docs/images/frontend-sketch.png` | Текстовые wireframe в корневом `README.md` | ✅ |
| Сущности, поля и методы API | Корневой `README.md`: «Сущности приложения и методы backend API» | `backend/internal/transport/rest/openapi.yaml` и `docs/REQUIREMENTS_TRACEABILITY.md` | ✅ |
| Редактируемая архитектурная схема | `docs/shopkmp-architecture.drawio` | `docs/images/architecture.png`, `docs/ARCHITECTURE.md` | ✅ |
| Dockerfile backend | `backend/Dockerfile` | Сборка Dockerfile в CI | ✅ |
| docker-compose инфраструктуры | `backend/docker-compose.yml` | `backend/docker-compose.prod.yml` фиксирует production-границу | ✅ |
| Мониторинг / ELK-эквивалент | OpenSearch, Fluent Bit, Dashboards в compose | Offline: `docs/images/grafana-dashboard.jpg`, `docs/images/opensearch-dashboards.jpg` | ✅ |
| Keycloak | Сервисы Keycloak и PostgreSQL в compose | Realm JSON и offline-снимок `docs/images/keycloak-realm-clients.jpg` | ✅ |

Каталожные PNG и SVG в demo-данных намеренно генерируются backend как
детерминированные placeholder-ы. Это позволяет воспроизводить интерфейс без
хранения бинарного медиакаталога в учебном репозитории.

## Дополнительная реализация (bonus, вне ДЗ №3)

Каталоги `composeApp/`, `iosApp/`, `core/`, `feature/` и исполняемый Go backend
демонстрируют решения из проектных артефактов, но не подменяют их. При проверке
сначала следует показывать только таблицу выше, затем — при необходимости —
работающий full-stack. Обязательные артефакты считаются зафиксированными:
расширение bonus-реализации не меняет их состав и не создаёт новых требований
ДЗ №3.

## Отклонения от примеров стека

Решение и последствия зафиксированы в
[`ADR 0003`](adr/0003-stack-deviations.md), а подробная таблица трассируемости —
в [`REQUIREMENTS_TRACEABILITY.md`](REQUIREMENTS_TRACEABILITY.md#зафиксированные-отклонения-от-проектных-примеров).

## Быстрая проверка

```bash
make check-offline
make compose-config
```

Первая команда выполняет unit-тесты backend и KMP-клиента без Docker и запуска
инфраструктурных сервисов. Вторая только валидирует базовый Compose-файл через
установленный Docker Compose CLI. CI дополнительно проверяет форматирование,
`go mod tidy -diff`, race detector, покрытие, PostgreSQL persistence contract,
golangci-lint, Dockerfile, оба Compose-конфига, Android APK, iOS framework и
full-stack E2E.

## История и атрибуция

Существующая история не переписывается задним числом: искусственное изменение
авторства или дат сделало бы атрибуцию менее достоверной. Новые изменения
следует коммитить смысловыми группами (domain rules, persistence, client,
documentation/CI), а не сообщением вида `fix review findings`. Автор должен
уметь объяснить назначение каждого обязательного артефакта и ключевые
компромиссы из `docs/ARCHITECTURE.md`.
