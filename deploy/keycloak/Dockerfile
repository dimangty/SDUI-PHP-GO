# Build context: tools/ops (from the repository root).
# Добавляет статический HTTP-probe проекта в зафиксированный официальный образ Keycloak.
FROM golang:1.26-alpine3.24 AS healthcheck-build
WORKDIR /src
COPY go.mod ./
COPY cmd/healthcheck ./cmd/healthcheck
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" \
    -o /shopkmp-healthcheck ./cmd/healthcheck

FROM quay.io/keycloak/keycloak:26.3.3
COPY --from=healthcheck-build /shopkmp-healthcheck /opt/keycloak/bin/shopkmp-healthcheck
