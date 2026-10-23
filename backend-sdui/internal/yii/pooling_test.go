package yii

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/http/httptrace"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/observe"
)

func TestReadsReuseConnectionsAndMutationsRemainSingleAttempt(t *testing.T) {
	var connections, mutations atomic.Int32
	s := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			mutations.Add(1)
			conn, _, err := w.(http.Hijacker).Hijack()
			if err != nil {
				t.Error(err)
				return
			}
			conn.Close() // simulate a committed write with a lost response
			return
		}
		fmt.Fprint(w, `{"items":[],"totalAmount":"0.00"}`)
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
		t.Fatal(err)
	}
	for i := 0; i < 5; i++ {
		if _, err := c.Cart(context.Background(), Identity{}); err != nil {
			t.Fatal(err)
		}
	}
	if got := connections.Load(); got != 1 {
		t.Fatalf("five reads used %d connections, want one reusable connection", got)
	}
	_, err = c.AddCart(context.Background(), Identity{}, AddCartInput{ProductID: productID, Quantity: 1}, "mutation-key")
	if err == nil || mutations.Load() != 1 {
		t.Fatalf("mutation response loss replayed write: err=%v writes=%d", err, mutations.Load())
	}
	_, err = c.CancelOrder(context.Background(), Identity{}, productID)
	if err == nil || mutations.Load() != 2 {
		t.Fatalf("bodyless POST response loss replayed write: err=%v writes=%d", err, mutations.Load())
	}
}

func TestReadBudgetWithMultipleIdleConnections(t *testing.T) {
	var warmed, targetCalls atomic.Int32
	var targetPhase atomic.Bool
	allWarm := make(chan struct{})
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !targetPhase.Load() {
			if warmed.Add(1) == 3 {
				close(allWarm)
			}
			<-allWarm // force three simultaneously occupied connections into the pool
			fmt.Fprint(w, `{"items":[],"totalAmount":"0.00"}`)
			return
		}
		targetCalls.Add(1)
		conn, _, err := w.(http.Hijacker).Hijack()
		if err != nil {
			t.Error(err)
			return
		}
		conn.Close()
	}))
	defer s.Close()
	recorder := observe.New(nil)
	c, err := New(Config{Origin: s.URL, Timeout: time.Second, Observer: recorder})
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	var wg sync.WaitGroup
	for i := 0; i < 3; i++ {
		wg.Go(func() {
			if _, err := c.Cart(context.Background(), Identity{}); err != nil {
				t.Error(err)
			}
		})
	}
	wg.Wait()
	targetPhase.Store(true)
	_, err = c.Cart(context.Background(), Identity{})
	var transport *TransportError
	if !errors.As(err, &transport) || transport.Kind != "retry_budget" || targetCalls.Load() != 2 {
		t.Fatalf("multi-idle read budget: calls=%d error=%v", targetCalls.Load(), err)
	}
	w := httptest.NewRecorder()
	recorder.ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
	if !strings.Contains(w.Body.String(), `sdui_retries_total{kind="upstream",operation="cart"} 1`) {
		t.Fatalf("blocked third dispatch was counted as another retry: %s", w.Body)
	}
}

func TestCloseIdleConnectionsForcesNextReadToReconnect(t *testing.T) {
	var connections atomic.Int32
	s := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprint(w, `{"items":[],"totalAmount":"0.00"}`)
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
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	for i := 0; i < 2; i++ {
		if _, err := c.Cart(context.Background(), Identity{}); err != nil {
			t.Fatal(err)
		}
	}
	if connections.Load() != 1 {
		t.Fatal("reads did not warm a single pooled connection")
	}
	c.CloseIdleConnections()
	if _, err := c.Cart(context.Background(), Identity{}); err != nil || connections.Load() != 2 {
		t.Fatalf("idle connection not closed: error=%v connections=%d", err, connections.Load())
	}
}

func TestReadBudgetClosesThirdAssignedConnectionBeforeAnyWrite(t *testing.T) {
	var budget readBudget
	ctx, cancel := budget.context(context.Background())
	defer cancel(nil)
	trace := httptrace.ContextClientTrace(ctx)
	trace.GetConn("fixed:80")
	trace.GetConn("fixed:80")
	trace.GetConn("fixed:80") // context cancellation alone cannot veto GotConn
	assigned, peer := net.Pipe()
	defer assigned.Close()
	defer peer.Close()
	trace.GotConn(httptrace.GotConnInfo{Conn: assigned, Reused: true})
	received := make(chan int, 1)
	go func() {
		var b [1]byte
		n, _ := io.ReadFull(peer, b[:])
		received <- n
	}()
	if n, err := assigned.Write([]byte("G")); err == nil || n != 0 {
		t.Fatalf("over-budget connection accepted request bytes after cancellation: n=%d err=%v", n, err)
	}
	if n := <-received; n != 0 {
		t.Fatal("third request reached peer")
	}
}

type temporaryOnlyError struct{}

func (temporaryOnlyError) Error() string   { return "unclassified temporary failure" }
func (temporaryOnlyError) Timeout() bool   { return false }
func (temporaryOnlyError) Temporary() bool { return true }

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }

func TestUnclassifiedTemporaryNetworkErrorDoesNotTriggerRetry(t *testing.T) {
	c, err := New(Config{Origin: "http://fixed.invalid", Timeout: time.Second, Backoff: time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	var calls atomic.Int32
	c.http.Transport = roundTripFunc(func(*http.Request) (*http.Response, error) {
		calls.Add(1)
		return nil, temporaryOnlyError{}
	})
	if _, err := c.Cart(context.Background(), Identity{}); err == nil || calls.Load() != 1 {
		t.Fatalf("unclassified Temporary triggered retry: %v calls=%d", err, calls.Load())
	}
}

func TestStaleReadRetrySharesBudgetWithExplicitRetry(t *testing.T) {
	var calls atomic.Int32
	c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		n := calls.Add(1)
		if n == 1 {
			fmt.Fprint(w, `{"items":[],"totalAmount":"0.00"}`)
			return
		}
		if n == 2 {
			conn, _, err := w.(http.Hijacker).Hijack()
			if err != nil {
				t.Error(err)
				return
			}
			conn.Close()
			return
		}
		w.WriteHeader(503)
		fmt.Fprint(w, `{"error":{"code":"DOWN","message":"down","traceId":"up"}}`)
	}, 4096)
	defer c.CloseIdleConnections()
	if _, err := c.Cart(context.Background(), Identity{}); err != nil {
		t.Fatal(err)
	}
	_, err := c.Cart(context.Background(), Identity{})
	var upstream *UpstreamError
	if !errors.As(err, &upstream) || upstream.Status != 503 || calls.Load() != 3 {
		t.Fatalf("transparent+explicit retry exceeded two attempts: calls=%d err=%v", calls.Load(), err)
	}
}

func TestTLSVerificationAndCookieIsolation(t *testing.T) {
	tlsServer := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Error("untrusted TLS server received credentials")
	}))
	defer tlsServer.Close()
	c, err := New(Config{Origin: tlsServer.URL, Timeout: time.Second})
	if err != nil {
		t.Fatal(err)
	}
	defer c.CloseIdleConnections()
	if _, err := c.User(context.Background(), Identity{Bearer: "secret"}); err == nil {
		t.Fatal("untrusted TLS accepted")
	}
	c = clientFor(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Cookie") != "" {
			t.Error("upstream cookies reused as identity")
		}
		w.Header().Set("Set-Cookie", "identity=admin")
		fmt.Fprint(w, `{"items":[],"totalAmount":"0.00"}`)
	}, 4096)
	defer c.CloseIdleConnections()
	for i := 0; i < 2; i++ {
		if _, err := c.Cart(context.Background(), Identity{}); err != nil {
			t.Fatal(err)
		}
	}
}
