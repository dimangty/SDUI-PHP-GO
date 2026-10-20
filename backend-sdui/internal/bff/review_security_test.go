package bff

import (
	"fmt"
	"net/http"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestReviewI1MalformedSecurityBodyFailsClosedAndCancelsHomeSiblings(t *testing.T) {
	for _, status := range []int{401, 403} {
		for name, body := range map[string]string{"empty": "", "html": "<html>UPSTREAM_SECRET</html>", "oversize": strings.Repeat("UPSTREAM_SECRET", (2<<20)/15+1)} {
			t.Run(fmt.Sprintf("%d/%s", status, name), func(t *testing.T) {
				allStarted := make(chan struct{})
				var started, canceled atomic.Int32
				h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
					if r.URL.Path == "/api/v1/users/me" {
						fixtureUpstream(w, r)
						return
					}
					if started.Add(1) == 3 {
						close(allStarted)
					}
					select {
					case <-allStarted:
					case <-r.Context().Done():
						return
					}
					if r.URL.Path == "/api/v1/auth/session" {
						w.WriteHeader(status)
						fmt.Fprint(w, body)
						return
					}
					<-r.Context().Done()
					canceled.Add(1)
				})
				start := time.Now()
				w := request(h, "GET", "/api/v1/screens/home?deviceId=test-device", "", "valid")
				if w.Code != status {
					t.Fatalf("known security status downgraded: got %d, want %d", w.Code, status)
				}
				if time.Since(start) > time.Second {
					t.Fatalf("siblings were not promptly canceled: %s", time.Since(start))
				}
				if !strings.Contains(w.Body.String(), `"code":"BFF_UPSTREAM_SECURITY"`) || strings.Contains(w.Body.String(), "UPSTREAM_SECRET") || strings.Contains(w.Body.String(), "authenticated") || strings.Contains(w.Body.String(), uid) {
					t.Fatalf("failure not sanitized: %s", w.Body)
				}
				deadline := time.Now().Add(time.Second)
				for canceled.Load() != 2 && time.Now().Before(deadline) {
					time.Sleep(time.Millisecond)
				}
				if canceled.Load() != 2 {
					t.Fatalf("canceled siblings=%d, want 2", canceled.Load())
				}
				if w.Header().Get("Cache-Control") != "no-store" {
					t.Fatal("security error cacheable")
				}
			})
		}
	}
}

func TestReviewI1MalformedSecurityAtAuthenticationGateKeepsStatus(t *testing.T) {
	for _, status := range []int{401, 403} {
		t.Run(fmt.Sprint(status), func(t *testing.T) {
			var other atomic.Int32
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path != "/api/v1/users/me" {
					other.Add(1)
				}
				w.WriteHeader(status)
				fmt.Fprint(w, "PRIVATE_AUTH_FAILURE")
			})
			w := request(h, "GET", "/api/v1/screens/profile", "", "valid")
			if w.Code != status || other.Load() != 0 || strings.Contains(w.Body.String(), "PRIVATE_AUTH_FAILURE") {
				t.Fatalf("gate lost status: %d %s calls=%d", w.Code, w.Body, other.Load())
			}
		})
	}
}
