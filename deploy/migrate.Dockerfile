# Build context: tools/ops (from the repository root).
FROM golang:1.26.10-alpine3.24@sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308 AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY cmd/migrate ./cmd/migrate
COPY migrations ./migrations
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /migrate ./cmd/migrate
FROM gcr.io/distroless/static-debian13:nonroot@sha256:18f4cc26c9ffa7f20a359298be7deadac5c607c8e03a02b8119f1a5fc2f82c99
COPY --from=build /migrate /migrate
USER nonroot:nonroot
ENTRYPOINT ["/migrate"]
