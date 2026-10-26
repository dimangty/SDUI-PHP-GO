package bff

import (
	"fmt"
	"net/http"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestReviewFixR3RetryMetadataIsAllowlisted(t *testing.T) {
	date := time.Now().Add(time.Hour).UTC().Format(http.TimeFormat)
	for _, tc := range []struct {
		status int
		values []string
		want   string
	}{{429, []string{"37"}, "37"}, {503, []string{"37"}, "37"}, {503, []string{date}, date},
		{503, []string{"999999999999999999999999"}, "999999999999999999999999"},
		{429, nil, ""}, {429, []string{"-1"}, ""}, {429, []string{"bad"}, ""},
		{429, []string{"1", "2"}, ""}, {429, []string{"1, 2"}, ""}, {429, []string{strings.Repeat("1", 129)}, ""},
		{403, []string{"37"}, ""}} {
		t.Run(fmt.Sprintf("%d/%v", tc.status, tc.values), func(t *testing.T) {
			body := `{"error":{"code":"RATE_LIMITED","message":"wait","traceId":"yii-retry"}}`
			var writes atomic.Int32
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.Method == "GET" {
					fixtureUpstream(w, r)
					return
				}
				writes.Add(1)
				for _, v := range tc.values {
					w.Header().Add("Retry-After", v)
				}
				w.Header().Set("Set-Cookie", "secret=yes")
				w.Header().Set("Authorization", "Bearer secret")
				w.Header().Set("Cache-Control", "public, max-age=9999")
				w.Header().Set("X-Upstream-Secret", "secret")
				w.WriteHeader(tc.status)
				fmt.Fprint(w, body)
			})
			w := postCommand(h, `{"command":"RevokeDevice","input":{"deviceId":"phone","deviceKind":"pin","confirmed":true}}`, "")
			if w.Code != tc.status || w.Body.String() != body || w.Header().Get("Retry-After") != tc.want || writes.Load() != 1 || w.Header().Get("Cache-Control") != "no-store" {
				t.Fatalf("metadata/status/body/replay: %d %v %s writes=%d", w.Code, w.Header(), w.Body, writes.Load())
			}
			for _, name := range []string{"Set-Cookie", "Authorization", "X-Upstream-Secret"} {
				if w.Header().Get(name) != "" {
					t.Fatalf("untrusted header leaked: %s", name)
				}
			}
		})
	}
}
