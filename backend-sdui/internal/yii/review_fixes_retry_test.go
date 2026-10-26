package yii

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"sync/atomic"
	"testing"
	"time"
)

func TestReviewFixR3RetryDelayRespectsBudget(t *testing.T) {
	for _, value := range []string{"37", "999999999999999999999999", time.Now().Add(time.Hour).UTC().Format(http.TimeFormat)} {
		t.Run(value, func(t *testing.T) {
			var calls atomic.Int32
			c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
				calls.Add(1)
				w.Header().Set("Retry-After", value)
				w.WriteHeader(503)
				fmt.Fprint(w, `{"error":{"code":"DOWN","message":"wait","traceId":"up"}}`)
			}, 4096)
			start := time.Now()
			_, err := c.Cart(context.Background(), Identity{})
			var upstream *UpstreamError
			if !errors.As(err, &upstream) || calls.Load() != 1 || time.Since(start) > 500*time.Millisecond {
				t.Fatalf("retry before Retry-After / unbounded wait: calls=%d err=%v elapsed=%s", calls.Load(), err, time.Since(start))
			}
		})
	}
}

func TestReviewFixR3GETWaitsAndCancellationStopsRetry(t *testing.T) {
	for _, cancelWait := range []bool{false, true} {
		t.Run(fmt.Sprint(cancelWait), func(t *testing.T) {
			var calls atomic.Int32
			first := make(chan struct{})
			c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
				if calls.Add(1) == 1 {
					w.Header().Set("Retry-After", "1")
					w.WriteHeader(503)
					fmt.Fprint(w, `{"error":{"code":"DOWN","message":"wait","traceId":"up"}}`)
					close(first)
					return
				}
				fmt.Fprint(w, emptyCartJSON)
			}, 4096)
			c.timeout = 3 * time.Second
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			if cancelWait {
				go func() { <-first; time.Sleep(30 * time.Millisecond); cancel() }()
			}
			start := time.Now()
			_, err := c.Cart(ctx, Identity{})
			if cancelWait {
				if !errors.Is(err, context.Canceled) || calls.Load() != 1 || time.Since(start) > 500*time.Millisecond {
					t.Fatalf("canceled wait replayed/delayed: %v calls=%d", err, calls.Load())
				}
			} else if err != nil || calls.Load() != 2 || time.Since(start) < time.Second {
				t.Fatalf("early/missing retry: %v calls=%d elapsed=%s", err, calls.Load(), time.Since(start))
			}
		})
	}
}
