package yii

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"sync/atomic"
	"testing"
)

func TestReviewI1SecurityStatusSurvivesMalformedBoundedAndTruncatedBodies(t *testing.T) {
	for _, status := range []int{401, 403} {
		for _, kind := range []string{"html", "oversize", "truncated"} {
			t.Run(fmt.Sprintf("%d/%s", status, kind), func(t *testing.T) {
				var calls atomic.Int32
				c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
					calls.Add(1)
					if kind == "truncated" {
						w.Header().Set("Content-Length", "100")
					}
					w.WriteHeader(status)
					if kind == "oversize" {
						fmt.Fprint(w, strings.Repeat("x", 1025))
					} else {
						fmt.Fprint(w, "<html>secret</html>")
					}
				}, 1024)
				_, err := c.Cart(context.Background(), Identity{Bearer: "test"})
				var protocol *TransportError
				var statusError interface{ HTTPStatus() int }
				if !errors.As(err, &protocol) || !errors.As(err, &statusError) || statusError.HTTPStatus() != status || calls.Load() != 1 {
					t.Fatalf("security status/retry lost: %v calls=%d", err, calls.Load())
				}
			})
		}
	}
}
