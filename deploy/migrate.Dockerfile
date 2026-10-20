# Build context: tools/ops (from the repository root).
FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY cmd/migrate ./cmd/migrate
COPY migrations ./migrations
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /migrate ./cmd/migrate
FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /migrate /migrate
USER nonroot:nonroot
ENTRYPOINT ["/migrate"]
