package bff

import (
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"slices"
	"sync/atomic"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/observe"
	"shopkmp/backend-sdui/internal/yii"
)

func BenchmarkHomeHTTP(b *testing.B) {
	benchmarkHomeHTTP(b, false)
}

func BenchmarkHomeHTTPObserved(b *testing.B) {
	benchmarkHomeHTTP(b, true)
}

func benchmarkHomeHTTP(b *testing.B, observed bool) {
	var connections, calls atomic.Int64
	upstream := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		fixtureUpstream(w, r)
	}))
	upstream.Config.ConnState = func(_ net.Conn, state http.ConnState) {
		if state == http.StateNew {
			connections.Add(1)
		}
	}
	upstream.Start()
	defer upstream.Close()
	var recorder *observe.Recorder
	if observed {
		recorder = observe.New(slog.New(slog.NewJSONHandler(io.Discard, nil)))
	}
	up, err := yii.New(yii.Config{Origin: upstream.URL, Timeout: time.Second, Observer: recorder})
	if err != nil {
		b.Fatal(err)
	}
	defer up.CloseIdleConnections()
	server := httptest.NewServer(New(up, Config{Observer: recorder}))
	defer server.Close()
	client := server.Client()
	latency := make([]time.Duration, 0, b.N)
	b.ReportAllocs()
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		start := time.Now()
		r, err := client.Get(server.URL + "/api/v1/screens/home")
		if err != nil {
			b.Fatal(err)
		}
		_, err = io.Copy(io.Discard, r.Body)
		r.Body.Close()
		if err != nil || r.StatusCode != 200 {
			b.Fatalf("HTTP %d: %v", r.StatusCode, err)
		}
		latency = append(latency, time.Since(start))
	}
	b.StopTimer()
	slices.Sort(latency)
	for _, q := range []int{50, 95, 99} {
		b.ReportMetric(float64(latency[(len(latency)-1)*q/100].Nanoseconds())/1000, fmt.Sprintf("p%d-us", q))
	}
	b.ReportMetric(float64(connections.Load())/float64(b.N), "connections/op")
	b.ReportMetric(float64(calls.Load())/float64(b.N), "upstream/op")
}
