package runtime

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/yii"
)

func TestReadinessRejectsBudgetsSmallerThanPHPAllocation(t *testing.T) {
	for _, value := range []string{"0s", "600ms", "1999ms", "4s", "invalid"} {
		t.Run(value, func(t *testing.T) {
			t.Setenv("READINESS_TIMEOUT", value)
			if _, err := Load(); err == nil {
				t.Fatal("accepted readiness budget incompatible with core allocation or upstream timeout")
			}
		})
	}
}

func TestReadinessConcurrencyDoesNotOccupyUnboundedPHPWorkers(t *testing.T) {
	entered := make(chan struct{}, 16)
	release := make(chan struct{})
	up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		entered <- struct{}{}
		select {
		case <-release:
			w.Write([]byte(`{"status":"ok","capabilities":["catalog-cards-v1"]}`))
		case <-r.Context().Done():
		}
	}))
	defer up.Close()
	defer close(release)
	c, err := yii.New(yii.Config{Origin: up.URL, Timeout: 3 * time.Second})
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	h := Health(c)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var workers sync.WaitGroup
	for range 2 {
		workers.Go(func() {
			h.ServeHTTP(httptest.NewRecorder(), httptest.NewRequest("GET", "/health/ready", nil).WithContext(ctx))
		})
	}
	for range 2 {
		select {
		case <-entered:
		case <-time.After(time.Second):
			t.Fatal("mandatory probes did not start")
		}
	}
	w := httptest.NewRecorder()
	thirdDone := make(chan struct{})
	go func() {
		h.ServeHTTP(w, httptest.NewRequest("GET", "/health/ready", nil).WithContext(ctx))
		close(thirdDone)
	}()
	select {
	case <-thirdDone:
		if w.Code != http.StatusServiceUnavailable {
			t.Fatalf("saturated readiness returned %d", w.Code)
		}
	case <-time.After(150 * time.Millisecond):
		cancel()
		<-thirdDone
		workers.Wait()
		t.Fatal("saturated probes queued more upstream work")
	}
	// Cancellation must release occupied slots and their HTTP connections.
	cancel()
	workers.Wait()
	live := httptest.NewRecorder()
	h.ServeHTTP(live, httptest.NewRequest("GET", "/health/live", nil))
	if live.Code != 200 || len(entered) != 0 {
		t.Fatal("liveness performed upstream I/O or probe exceeded concurrency limit")
	}
}
