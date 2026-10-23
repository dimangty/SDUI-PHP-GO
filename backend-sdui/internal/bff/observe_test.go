package bff

import (
	"bytes"
	"fmt"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/observe"
	"shopkmp/backend-sdui/internal/yii"
)

func TestHTTPObservabilityRedactsCredentialsQueriesAndResourceIDs(t *testing.T) {
	var logs bytes.Buffer
	recorder := observe.New(slog.New(slog.NewJSONHandler(&logs, nil)))
	s := httptest.NewServer(http.HandlerFunc(fixtureUpstream))
	defer s.Close()
	up, err := yii.New(yii.Config{Origin: s.URL, Timeout: time.Second, Observer: recorder})
	if err != nil {
		t.Fatal(err)
	}
	defer up.CloseIdleConnections()
	h := New(up, Config{Observer: recorder})
	w := request(h, "GET", "/api/v1/screens/product/"+pid, "", "private-token")
	if w.Code != 200 {
		t.Fatalf("%d %s", w.Code, w.Body)
	}
	w = request(h, "GET", "/api/v1/screens/catalog?q=private-search", "", "")
	if w.Code != 200 {
		t.Fatalf("%d %s", w.Code, w.Body)
	}
	for _, secret := range []string{"private-token", "private-search", pid, "buyer@example.org", "+79991234567", uid} {
		if strings.Contains(logs.String(), secret) {
			t.Fatalf("telemetry leaked %s", secret)
		}
	}
	for _, kind := range []string{"http", "upstream", "render"} {
		if !strings.Contains(logs.String(), `"kind":"`+kind+`"`) {
			t.Fatalf("missing %s observation: %s", kind, &logs)
		}
	}
	if w := request(h, "GET", "/metrics", "", ""); w.Code != 404 {
		t.Fatal("metrics exposed publicly")
	}
}

func TestHomeOrdinaryFailureCannotHideLaterSiblingSecurityFailure(t *testing.T) {
	for _, status := range []int{401, 403} {
		t.Run(fmt.Sprint(status), func(t *testing.T) {
			categoryFailed := make(chan struct{})
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/categories" {
					w.WriteHeader(500)
					fmt.Fprint(w, `{"error":{"code":"DOWN","message":"down","traceId":"up"}}`)
					close(categoryFailed)
					return
				}
				<-categoryFailed
				w.WriteHeader(status)
				fmt.Fprint(w, "malformed authorization failure")
			})
			w := request(h, "GET", "/api/v1/screens/home", "", "")
			if w.Code != status || !strings.Contains(w.Body.String(), "BFF_UPSTREAM_SECURITY") {
				t.Fatalf("ordinary failure masked sibling security error: %d %s", w.Code, w.Body)
			}
		})
	}
}

func TestClientCorrelationCannotInjectSensitiveValuesIntoLogs(t *testing.T) {
	var logs bytes.Buffer
	recorder := observe.New(slog.New(slog.NewJSONHandler(&logs, nil)))
	h := New(nil, Config{Observer: recorder}) // missing route never calls the backend
	r := httptest.NewRequest("GET", "/missing", nil)
	r.Header.Set("X-Request-Id", "app_access_example-secret")
	r.Header.Set("Traceparent", "00-11111111111111111111111111111111-2222222222222222-01")
	w := httptest.NewRecorder()
	h.ServeHTTP(w, r)
	if w.Code != 404 || w.Header().Get("X-Request-Id") != "app_access_example-secret" {
		t.Fatal("wire correlation contract changed")
	}
	if strings.Contains(logs.String(), "app_access_example-secret") || strings.Contains(logs.String(), "11111111111111111111111111111111") {
		t.Fatalf("caller-controlled correlation retained sensitive data: %s", &logs)
	}
	if !strings.Contains(logs.String(), `"request_id_hash":`) || !strings.Contains(logs.String(), `"trace_id_hash":`) {
		t.Fatal("safe correlation missing from logs")
	}
}
