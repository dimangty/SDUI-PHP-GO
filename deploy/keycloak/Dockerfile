# Build context: tools/ops (from the repository root).
# Добавляет статический HTTP-probe проекта в зафиксированный официальный образ Keycloak.
FROM golang:1.26.10-alpine3.24@sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308 AS healthcheck-build
WORKDIR /src
COPY go.mod ./
COPY cmd/healthcheck ./cmd/healthcheck
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" \
    -o /shopkmp-healthcheck ./cmd/healthcheck

FROM quay.io/keycloak/keycloak:26.8.1@sha256:a78d6b79572168cb25886b80a8b5b8d4ed4390743e3eecb810538683e209dd6b
COPY --from=healthcheck-build /shopkmp-healthcheck /opt/keycloak/bin/shopkmp-healthcheck
