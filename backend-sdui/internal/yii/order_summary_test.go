package yii

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/application/model"
	"shopkmp/backend-sdui/internal/observe"

	"go.opentelemetry.io/otel/codes"
	sdktrace "go.opentelemetry.io/otel/sdk/trace"
	"go.opentelemetry.io/otel/sdk/trace/tracetest"
)

const r9FullOrder = `{"id":"11111111-1111-4111-8111-111111111111","number":42,"userId":"11111111-1111-4111-8111-111111111111","status":"CREATED","items":[{"productId":"11111111-1111-4111-8111-111111111111","productName":"Historical 😀","quantity":200,"priceSnapshot":"50.01"}],"totalAmount":"9999999999.99","deliveryAddressId":"11111111-1111-4111-8111-111111111111","deliveryAddress":"Historical address","paymentId":null,"paymentMethod":"CARD","createdAt":"2026-10-30T00:00:00Z","comment":"Historical comment"}`

func TestR9FullOrderEndpointsRemainFullAndUnprojected(t *testing.T) {
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Has("projection") {
			t.Error("summary mode leaked into full method")
		}
		if r.URL.Path == "/api/v1/orders" {
			fmt.Fprint(w, `{"items":[`+r9FullOrder+`],"page":0,"size":20,"totalPages":1,"totalItems":1}`)
			return
		}
		fmt.Fprint(w, r9FullOrder)
	}, 0)
	var wg sync.WaitGroup
	for i := 0; i < 10; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for _, read := range []func() (Order, error){
				func() (Order, error) {
					p, err := c.Orders(context.Background(), Identity{}, OrderQuery{Size: 20})
					if err != nil {
						return Order{}, err
					}
					return p.Items[0], nil
				},
				func() (Order, error) { return c.Order(context.Background(), Identity{}, productID) },
				func() (Order, error) { return c.CancelOrder(context.Background(), Identity{}, productID) },
			} {
				o, err := read()
				if err != nil || len(o.Items) != 1 || o.Items[0].ProductName != "Historical 😀" || o.Comment == nil || *o.Comment != "Historical comment" || o.TotalAmount != "9999999999.99" {
					t.Errorf("full order changed: %+v %v", o, err)
				}
			}
		}()
	}
	wg.Wait()
}

func TestR9SummaryDoesNotWeakenFullRequiredFields(t *testing.T) {
	for _, field := range []string{"items", "deliveryAddressId", "paymentId", "comment"} {
		t.Run(field, func(t *testing.T) {
			var order map[string]any
			_ = json.Unmarshal([]byte(r9FullOrder), &order)
			delete(order, field)
			c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/orders" {
					_ = json.NewEncoder(w).Encode(map[string]any{"items": []any{order}, "page": 0, "size": 20, "totalPages": 1, "totalItems": 1})
					return
				}
				_ = json.NewEncoder(w).Encode(order)
			}, 0)
			for _, read := range []func() error{
				func() error { _, err := c.Orders(context.Background(), Identity{}, OrderQuery{Size: 20}); return err },
				func() error { _, err := c.Order(context.Background(), Identity{}, productID); return err },
				func() error { _, err := c.CancelOrder(context.Background(), Identity{}, productID); return err },
			} {
				var failure *model.Failure
				if err := read(); !errors.As(err, &failure) || failure.Kind != "protocol" {
					t.Fatalf("incomplete full DTO accepted: %v", err)
				}
			}
		})
	}
}

func TestR9OrderSummaryInvalidQueryHasNoIOAndSecurityStatusSurvives(t *testing.T) {
	var calls atomic.Int32
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		w.WriteHeader(403)
		fmt.Fprint(w, "PRIVATE")
	}, 0)
	for _, q := range []OrderQuery{{Size: 0}, {Size: 101}, {Size: 20, Page: -1}, {Size: 20, Status: "unknown"}} {
		if _, err := c.OrderSummaries(context.Background(), Identity{}, q); err == nil {
			t.Fatal("invalid query accepted")
		}
	}
	if calls.Load() != 0 {
		t.Fatal("invalid query reached I/O")
	}
	_, err := c.OrderSummaries(context.Background(), Identity{}, OrderQuery{Size: 20})
	var failure *model.Failure
	if !errors.As(err, &failure) || failure.HTTPStatus() != 403 || calls.Load() != 1 || strings.Contains(err.Error(), "PRIVATE") {
		t.Fatalf("lost security status/sanitization: %v", err)
	}
}

func TestR9OrderSummaryMarkerFailureFinalizesTelemetry(t *testing.T) {
	var logs bytes.Buffer
	exporter := tracetest.NewInMemoryExporter()
	provider := sdktrace.NewTracerProvider(sdktrace.WithSyncer(exporter))
	defer provider.Shutdown(context.Background())
	var calls atomic.Int32
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		fmt.Fprint(w, `{"projection":"PRIVATE-wrong-marker","items":[],"page":0,"size":20,"totalPages":0,"totalItems":0}`)
	}))
	defer s.Close()
	c, err := New(Config{Origin: s.URL, Timeout: time.Second, Observer: observe.New(slog.New(slog.NewJSONHandler(&logs, nil))), Tracing: observe.NewTracing(provider)})
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	_, err = c.OrderSummaries(context.Background(), Identity{}, OrderQuery{Size: 20})
	var failure *model.Failure
	if !errors.As(err, &failure) || failure.Kind != "protocol" || failure.Status != 200 || calls.Load() != 1 {
		t.Fatalf("marker failure lost: %v", err)
	}
	spans := exporter.GetSpans()
	if len(spans) != 1 || spans[0].Status.Code != codes.Error || spans[0].Name != "sdui.upstream.orders" {
		t.Fatalf("semantic failure finalized as success: %+v", spans)
	}
	var event map[string]any
	if err := json.Unmarshal(logs.Bytes(), &event); err != nil {
		t.Fatal(err)
	}
	if event["error_kind"] != "protocol" || event["status"] != float64(200) || event["retries"] != float64(0) || strings.Contains(logs.String(), "PRIVATE") {
		t.Fatalf("incorrect or sensitive telemetry: %s", &logs)
	}
}
