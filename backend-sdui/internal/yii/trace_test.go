package yii

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/codes"
	"go.opentelemetry.io/otel/sdk/resource"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	"go.opentelemetry.io/otel/sdk/trace/tracetest"
	"go.opentelemetry.io/otel/trace"

	"shopkmp/backend-sdui/internal/observe"
)

func TestCardValidationFinalizesLogicalTelemetry(t *testing.T) {
	for _, route := range []string{"catalog", "search", "popular"} {
		for _, invalid := range []string{"", "marker", "completeness"} {
			t.Run(route+"/"+invalid, func(t *testing.T) {
				exporter := tracetest.NewInMemoryExporter()
				provider := sdktrace.NewTracerProvider(sdktrace.WithSyncer(exporter), sdktrace.WithResource(resource.Empty()))
				defer provider.Shutdown(context.Background())
				var logs bytes.Buffer
				recorder := observe.New(slog.New(slog.NewJSONHandler(&logs, nil)))
				var calls atomic.Int32
				s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
					calls.Add(1)
					marker, complete := "sdui-card-v1", true
					if invalid == "marker" {
						marker = "SECRET-unsupported-marker"
					}
					if invalid == "completeness" {
						complete = false // A short incomplete snippet is invalid.
					}
					_ = json.NewEncoder(w).Encode(map[string]any{"projection": marker, "items": []any{round3WireCard("SECRET-short-snippet", complete)}, "page": 0, "size": 20, "totalPages": 1, "totalItems": 1})
				}))
				defer s.Close()
				c, err := New(Config{Origin: s.URL, Timeout: time.Second, Observer: recorder, Tracing: observe.NewTracing(provider)})
				if err != nil {
					t.Fatal(err)
				}
				defer c.CloseIdleConnections()
				op := "products"
				if route == "popular" {
					op = "popular"
					_, err = c.Popular(context.Background(), Identity{})
				} else {
					q := CatalogQuery{Size: 20}
					if route == "search" {
						q.Query = "SECRET-query"
					}
					_, err = c.Products(context.Background(), Identity{}, q)
				}
				wantKind, wantCode := "", codes.Unset
				if invalid != "" {
					wantKind, wantCode = "protocol", codes.Error
					var failure *TransportError
					if !errors.As(err, &failure) || failure.Kind != "protocol" || failure.Status != 200 {
						t.Errorf("card protocol failure must retain HTTP 200: %v", err)
					}
				} else if err != nil {
					t.Fatal(err)
				}
				spans := exporter.GetSpans()
				if len(spans) != 1 || calls.Load() != 1 {
					t.Fatalf("want one logical span and no retry: spans=%d calls=%d", len(spans), calls.Load())
				}
				span := spans[0]
				attrs := attribute.NewSet(span.Attributes...)
				kind, _ := attrs.Value("error.type")
				status, _ := attrs.Value("http.response.status_code")
				if span.Name != "sdui.upstream."+op || span.SpanKind != trace.SpanKindClient || span.Status.Code != wantCode || span.Status.Description != "" || kind.AsString() != wantKind || status.AsInt64() != 200 || len(span.Events) != 0 {
					t.Errorf("incorrect final client span: %+v", span)
				}
				var event map[string]any
				if err := json.Unmarshal(logs.Bytes(), &event); err != nil {
					t.Fatal(err)
				}
				if event["error_kind"] != wantKind || event["status"] != float64(200) || event["retries"] != float64(0) {
					t.Errorf("incorrect final observer outcome: %s", &logs)
				}
				metrics := httptest.NewRecorder()
				recorder.ServeHTTP(metrics, httptest.NewRequest("GET", "/metrics", nil))
				wantErrors := 0
				if invalid != "" {
					wantErrors = 1
				}
				if !strings.Contains(metrics.Body.String(), fmt.Sprintf("sdui_errors_total{kind=\"upstream\",operation=\"%s\"} %d\n", op, wantErrors)) {
					t.Errorf("incorrect error metric: %s", metrics.Body)
				}
				if strings.Contains(fmt.Sprint(span), "SECRET") || strings.Contains(logs.String(), "SECRET") {
					t.Fatal("raw protocol data escaped telemetry boundary")
				}
			})
		}
	}
}

func TestTracingPreservesReadQuotaPoolingAndMutationNoReplay(t *testing.T) {
	exporter := tracetest.NewInMemoryExporter()
	provider := sdktrace.NewTracerProvider(sdktrace.WithSyncer(exporter), sdktrace.WithResource(resource.Empty()))
	defer provider.Shutdown(context.Background())
	tracing := observe.NewTracing(provider)
	var phase atomic.Int32
	var connections, warmed, failedReads, mutations atomic.Int32
	allWarm := make(chan struct{})
	s := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if len(r.Header.Get("Traceparent")) != 55 {
			t.Error("missing local client traceparent")
		}
		if r.Method != "GET" {
			mutations.Add(1)
		} else {
			switch phase.Load() {
			case 0:
				fmt.Fprint(w, emptyCartJSON)
				return
			case 1:
				if warmed.Add(1) == 3 {
					close(allWarm)
				}
				<-allWarm
				fmt.Fprint(w, emptyCartJSON)
				return
			default:
				failedReads.Add(1)
			}
		}
		conn, _, err := w.(http.Hijacker).Hijack()
		if err != nil {
			t.Error(err)
			return
		}
		_ = conn.Close() // committed mutation or stale read with lost response
	}))
	s.Config.ConnState = func(_ net.Conn, state http.ConnState) {
		if state == http.StateNew {
			connections.Add(1)
		}
	}
	s.Start()
	defer s.Close()
	c, err := New(Config{Origin: s.URL, Timeout: time.Second, Backoff: time.Millisecond, Tracing: tracing})
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	for range 5 {
		if _, err := c.Cart(context.Background(), Identity{}); err != nil {
			t.Fatal(err)
		}
	}
	if connections.Load() != 1 {
		t.Fatalf("tracing disabled reuse: %d connections", connections.Load())
	}
	phase.Store(1)
	var wg sync.WaitGroup
	for range 3 {
		wg.Go(func() {
			if _, err := c.Cart(context.Background(), Identity{}); err != nil {
				t.Error(err)
			}
		})
	}
	wg.Wait()
	phase.Store(2)
	_, err = c.Cart(context.Background(), Identity{})
	var failure *TransportError
	if !errors.As(err, &failure) || failure.Kind != "retry_budget" || failedReads.Load() != 2 {
		t.Fatalf("tracing exceeded two-connection read quota: calls=%d err=%v", failedReads.Load(), err)
	}
	for i, mutate := range []func() error{
		func() error {
			_, err := c.AddCart(context.Background(), Identity{}, AddCartInput{ProductID: productID, Quantity: 1}, "mutation-key")
			return err
		},
		func() error { _, err := c.CancelOrder(context.Background(), Identity{}, productID); return err },
		func() error { _, err := c.UpdateCart(context.Background(), Identity{}, productID, 1); return err },
		func() error { _, err := c.RemoveCart(context.Background(), Identity{}, productID); return err },
		func() error {
			_, err := c.UpdateProfile(context.Background(), Identity{}, ProfileInput{FullName: "Buyer", Phone: "+79991234567"})
			return err
		},
		func() error { return c.RevokeDevice(context.Background(), Identity{}, "pin", "device") },
	} {
		if err := mutate(); err == nil || mutations.Load() != int32(i+1) {
			t.Fatalf("mutation replayed/lost: n=%d err=%v writes=%d", i, err, mutations.Load())
		}
	}
	if n := len(exporter.GetSpans()); n != 15 {
		t.Fatalf("want one span per logical call, not per attempt: %d", n)
	}
}
