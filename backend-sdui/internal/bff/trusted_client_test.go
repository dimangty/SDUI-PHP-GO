package bff

import (
	"net/http"
	"net/http/httptest"
	"net/netip"
	"strings"
	"sync"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/yii"
)

// Removing transport-derived client context must break this actual BFF -> HTTP
// adapter test; caller-supplied forwarding/identity headers must never survive.
func TestClientContextUntrustedPeerCannotAssertOriginalIP(t *testing.T) {
	for _, peer := range []string{"192.0.2.44:1234", "[2001:db8:44::1]:1234", "[::ffff:192.0.2.44]:1234"} {
		t.Run(peer, func(t *testing.T) {
			want := "192.0.2.44"
			if peer == "[2001:db8:44::1]:1234" {
				want = "2001:db8:44::1"
			}
			var mu sync.Mutex
			paths := map[string]bool{}
			up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if got := r.Header.Values("X-Real-IP"); len(got) != 1 || got[0] != want {
					t.Errorf("original client = %v, want transport peer %s", got, want)
				}
				for _, name := range []string{"X-Forwarded-For", "Forwarded", "X-Shop-Rate-Client-IP", "X-User-Id", "X-Admin", "Cookie"} {
					if r.Header.Get(name) != "" {
						t.Errorf("untrusted header %s crossed HTTP hop", name)
					}
				}
				mu.Lock()
				paths[r.URL.Path] = true
				mu.Unlock()
				fixtureUpstream(w, r)
			}))
			defer up.Close()
			client, err := yii.New(yii.Config{Origin: up.URL, Timeout: time.Second})
			if err != nil {
				t.Fatal(err)
			}
			defer client.CloseIdleConnections()
			h := New(client, Config{})
			r := httptest.NewRequest("GET", "/api/v1/screens/home?deviceId=test-device", nil)
			r.RemoteAddr = peer
			r.Header.Set("Authorization", "Bearer valid")
			for _, name := range []string{"X-Real-IP", "X-Forwarded-For", "Forwarded", "X-Shop-Rate-Client-IP", "X-User-Id", "X-Admin", "Cookie"} {
				r.Header.Set(name, "203.0.113.99")
			}
			w := httptest.NewRecorder()
			h.ServeHTTP(w, r)
			if w.Code != 200 {
				t.Fatalf("%d %s", w.Code, w.Body)
			}
			if len(paths) != 4 { // authentication + all three concurrent home reads
				t.Fatalf("multi-upstream reads = %v", paths)
			}
		})
	}
}

func TestClientContextOnlyExactTrustedEdgeCanForwardOneIP(t *testing.T) {
	for _, tc := range []struct {
		name, peer, original, want string
		duplicate                  bool
		status                     int
	}{
		{"trusted IPv4", "172.30.95.2:4000", "198.51.100.9", "198.51.100.9", false, 200},
		{"trusted IPv6", "[fd00:95::2]:4000", "2001:db8:9::1", "2001:db8:9::1", false, 200},
		{"mapped client", "172.30.95.2:4000", "::ffff:198.51.100.9", "198.51.100.9", false, 200},
		{"neighbor untrusted", "172.30.95.3:4000", "198.51.100.9", "172.30.95.3", false, 200},
		{"duplicate", "172.30.95.2:4000", "198.51.100.9", "", true, 400},
		{"chain", "172.30.95.2:4000", "198.51.100.9, 203.0.113.1", "", false, 400},
		{"missing", "172.30.95.2:4000", "", "", false, 400},
		{"port", "172.30.95.2:4000", "198.51.100.9:80", "", false, 400},
		{"zone", "172.30.95.2:4000", "fe80::1%eth0", "", false, 400},
		{"mapped unspecified", "172.30.95.2:4000", "::ffff:0.0.0.0", "", false, 400},
		{"mapped zone", "172.30.95.2:4000", "::ffff:198.51.100.9%eth0", "", false, 400},
	} {
		t.Run(tc.name, func(t *testing.T) {
			var mu sync.Mutex
			calls := 0
			up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				mu.Lock()
				calls++
				mu.Unlock()
				if r.Header.Get("X-Real-IP") != tc.want {
					t.Errorf("client = %q, want %q", r.Header.Get("X-Real-IP"), tc.want)
				}
				if r.Header.Get("Authorization") != "Bearer valid" {
					t.Error("client context replaced independent bearer authentication")
				}
				fixtureUpstream(w, r)
			}))
			defer up.Close()
			client, err := yii.New(yii.Config{Origin: up.URL, Timeout: time.Second})
			if err != nil {
				t.Fatal(err)
			}
			defer client.CloseIdleConnections()
			h := New(client, Config{TrustedEdgeIPs: []netip.Addr{netip.MustParseAddr("172.30.95.2"), netip.MustParseAddr("fd00:95::2")}})
			r := httptest.NewRequest("GET", "/api/v1/screens/home?deviceId=test-device", nil)
			r.RemoteAddr = tc.peer
			r.Header.Set("Authorization", "Bearer valid")
			if tc.original != "" {
				r.Header.Set("X-Real-IP", tc.original)
			}
			if tc.duplicate {
				r.Header.Add("X-Real-IP", "203.0.113.1")
			}
			r.Header.Set("X-Forwarded-For", "203.0.113.2")
			w := httptest.NewRecorder()
			h.ServeHTTP(w, r)
			if w.Code != tc.status {
				t.Fatalf("status %d, want %d: %s", w.Code, tc.status, w.Body)
			}
			if tc.status == 400 && (calls != 0 || !strings.Contains(w.Body.String(), "BFF_INVALID_REQUEST")) {
				t.Fatalf("malformed trusted context reached business API: calls=%d %s", calls, w.Body)
			}
		})
	}
}
