package runtime

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/yii"
)

func TestConfigRejectsUnsafeTimeoutsAndConflictingListeners(t *testing.T) {
	for _, tc := range []struct{ key, value string }{{"YII_ORIGIN", "http://user:password@upstream"}, {"REQUEST_TIMEOUT", "0s"}, {"UPSTREAM_TIMEOUT", "50s"}, {"ADMIN_ADDR", ":8080"}, {"READ_HEADER_TIMEOUT", "-1s"}, {"SHUTDOWN_TIMEOUT", "nonsense"}, {"MAX_UPSTREAM_BODY", "999999999"}, {"MAX_UPSTREAM_BODY", "0"}} {
		t.Run(tc.key, func(t *testing.T) {
			t.Setenv("YII_ORIGIN", "http://127.0.0.1:58088")
			t.Setenv(tc.key, tc.value)
			if _, err := Load(); err == nil {
				t.Fatal("unsafe configuration accepted")
			}
		})
	}
}
func TestHealthHasSeparateHandlerAndReadinessReflectsYii(t *testing.T) {
	up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/health/ready" {
			t.Errorf("wrong readiness probe %s", r.URL)
		}
		fmt.Fprint(w, `{"status":"ok"}`)
	}))
	client, err := yii.New(yii.Config{Origin: up.URL, Timeout: 100 * time.Millisecond, Backoff: time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	health := Health(client)
	for _, path := range []string{"/health/live", "/health/ready"} {
		r := httptest.NewRequest("GET", path, nil)
		w := httptest.NewRecorder()
		health.ServeHTTP(w, r)
		if w.Code != 200 {
			t.Fatalf("%s %d %s", path, w.Code, w.Body)
		}
	}
	up.Close()
	w := httptest.NewRecorder()
	health.ServeHTTP(w, httptest.NewRequest("GET", "/health/ready", nil))
	if w.Code != 503 {
		t.Fatalf("downstream down but ready: %d", w.Code)
	}
	w = httptest.NewRecorder()
	health.ServeHTTP(w, httptest.NewRequest("GET", "/health/live", nil))
	if w.Code != 200 {
		t.Fatal("liveness depends on Yii")
	}
	w = httptest.NewRecorder()
	health.ServeHTTP(w, httptest.NewRequest("GET", "/api/v1/screens/home", nil))
	if w.Code != 404 {
		t.Fatal("business route on internal listener")
	}
}
func TestRunGracefullyDrainsInflightRequestOnCancellation(t *testing.T) {
	public, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer public.Close()
	admin, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer admin.Close()
	started, release := make(chan struct{}), make(chan struct{})
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { close(started); <-release; fmt.Fprint(w, "drained") })
	cfg := Defaults()
	cfg.ShutdownTimeout = time.Second
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- Serve(ctx, cfg, public, admin, handler, http.NotFoundHandler()) }()
	response := make(chan error, 1)
	go func() {
		res, err := http.Get("http://" + public.Addr().String())
		if err == nil {
			res.Body.Close()
			if res.StatusCode != 200 {
				err = fmt.Errorf("status %d", res.StatusCode)
			}
		}
		response <- err
	}()
	select {
	case <-started:
	case <-time.After(time.Second):
		t.Fatal("server did not start")
	}
	cancel()
	select {
	case err := <-done:
		t.Fatalf("abandoned inflight request: %v", err)
	case <-time.After(20 * time.Millisecond):
	}
	close(release)
	if err := <-response; err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("shutdown did not finish")
	}
}
