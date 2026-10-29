#!/bin/sh
set -eu

# Run from backend-sdui/. Pin tools separately from production/test modules.
: "${GOTOOLCHAIN:=go1.27.3}"
export GOTOOLCHAIN
go version
go mod tidy -diff
go mod verify
unformatted=$(gofmt -l .)
if [ -n "$unformatted" ]; then
  printf 'Unformatted Go files:\n%s\n' "$unformatted" >&2
  exit 1
fi
go vet ./...
go test ./... -count=1
go test -race ./... -count=1
go build ./...
go test ./internal/bff -run 'Test(Published|Baseline|CommandSchema|IndependentSchema)' -count=1
go run github.com/golangci/golangci-lint/v2/cmd/golangci-lint@v2.15.0 run --config .golangci.yml
go run golang.org/x/vuln/cmd/govulncheck@v1.9.0 ./...
