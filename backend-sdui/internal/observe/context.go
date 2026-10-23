// Package observe provides bounded in-process telemetry. It never accepts HTTP
// bodies, credentials, raw URLs, query values or arbitrary attribute maps.
package observe

import (
	"context"
	"regexp"
	"strings"
)

type correlationKey struct{}
type correlation struct{ requestID, traceID, parent string }

var requestPattern = regexp.MustCompile(`^[A-Za-z0-9._-]{1,128}$`)
var parentPattern = regexp.MustCompile(`^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$`)

// Only W3C version 00 is propagated. Invalid/all-zero IDs are ignored. A trace
// header is correlation metadata, never authentication or an asserted identity.
func WithCorrelation(ctx context.Context, requestID, parent string) context.Context {
	c := correlation{}
	if requestPattern.MatchString(requestID) {
		c.requestID = requestID
	}
	if match := parentPattern.FindStringSubmatch(parent); match != nil && match[1] != strings.Repeat("0", 32) && match[2] != strings.Repeat("0", 16) {
		c.traceID, c.parent = match[1], parent
	}
	return context.WithValue(ctx, correlationKey{}, c)
}

func TraceParent(ctx context.Context) string {
	c, _ := ctx.Value(correlationKey{}).(correlation)
	return c.parent
}
