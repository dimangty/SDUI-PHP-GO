package yii

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"runtime"
	"slices"
	"sync/atomic"
	"testing"
	"time"
)

// Same serial loopback workload before/after; includes real HTTP decode. This is
// not a production load model. Connections and calls are measured server-side.
func BenchmarkClientRead(b *testing.B) {
	var connections, calls atomic.Int64
	s := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		fmt.Fprint(w, `{"id":"cart","userId":"u","items":[],"totalAmount":"0.00"}`)
	}))
	s.Config.ConnState = func(_ net.Conn, state http.ConnState) {
		if state == http.StateNew {
			connections.Add(1)
		}
	}
	s.Start()
	defer s.Close()
	c, err := New(Config{Origin: s.URL, Timeout: time.Second})
	if err != nil {
		b.Fatal(err)
	}
	defer c.CloseIdleConnections()
	latency := make([]time.Duration, 0, b.N)
	var before, after runtime.MemStats
	runtime.ReadMemStats(&before)
	b.ReportAllocs()
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		start := time.Now()
		if _, err := c.Cart(context.Background(), Identity{}); err != nil {
			b.Fatal(err)
		}
		latency = append(latency, time.Since(start))
	}
	b.StopTimer()
	runtime.ReadMemStats(&after)
	slices.Sort(latency)
	for _, q := range []int{50, 95, 99} {
		b.ReportMetric(float64(latency[(len(latency)-1)*q/100].Nanoseconds())/1000, fmt.Sprintf("p%d-us", q))
	}
	b.ReportMetric(float64(connections.Load())/float64(b.N), "connections/op")
	b.ReportMetric(float64(calls.Load())/float64(b.N), "upstream/op")
	b.ReportMetric(float64(after.TotalAlloc-before.TotalAlloc)/float64(b.N), "totalalloc-B/op")
	b.ReportMetric(float64(after.HeapInuse), "heapinuse-B")
}
