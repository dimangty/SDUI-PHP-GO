package yii

import (
	"context"
	"crypto/tls"
	"errors"
	"net"
	"net/http"
	"net/http/httptrace"
	"sync/atomic"
	"time"
)

func newHTTPClient(timeout time.Duration, pooled bool) *http.Client {
	dialer := &net.Dialer{Timeout: timeout, KeepAlive: 30 * time.Second}
	protocols := new(http.Protocols)
	protocols.SetHTTP1(true) // GotConn budget enforcement requires exclusive HTTP/1 connections
	tr := &http.Transport{
		Proxy: nil,
		DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
			// net/http detaches dial cancellation, but retains context values.
			if b, ok := ctx.Value(readBudgetKey{}).(*readBudget); ok && b.connections.Load() > 2 {
				return nil, errReadBudget
			}
			return dialer.DialContext(ctx, network, address)
		},
		Protocols:              protocols,
		TLSClientConfig:        &tls.Config{MinVersion: tls.VersionTLS12},
		TLSHandshakeTimeout:    timeout,
		ResponseHeaderTimeout:  timeout,
		ExpectContinueTimeout:  time.Second,
		MaxResponseHeaderBytes: 32 << 10,
		MaxIdleConns:           64,
		MaxIdleConnsPerHost:    16,
		MaxConnsPerHost:        64,
		IdleConnTimeout:        90 * time.Second,
		DisableKeepAlives:      !pooled,
	}
	return &http.Client{
		Transport: tr,
		Timeout:   timeout,
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
}

var errReadBudget = errors.New("upstream read attempt budget exhausted")

// net/http can transparently retry GET on stale pooled connections. Count its
// connection acquisitions as well as explicit retries, under one shared budget.
// GetConn is called before acquisition/write, including transparent retries.
type readBudget struct {
	connections atomic.Int32
	reused      atomic.Int32
}

type readBudgetKey struct{}

func (b *readBudget) context(ctx context.Context) (context.Context, context.CancelCauseFunc) {
	ctx, cancel := context.WithCancelCause(ctx)
	ctx = context.WithValue(ctx, readBudgetKey{}, b)
	return httptrace.WithClientTrace(ctx, &httptrace.ClientTrace{
		GetConn: func(string) {
			if b.connections.Add(1) > 2 {
				cancel(errReadBudget)
			}
		},
		GotConn: func(info httptrace.GotConnInfo) {
			// Cancellation can race with delivery of an already idle connection.
			// Close synchronously before the transport's write loop can use it.
			if b.connections.Load() > 2 {
				_ = info.Conn.Close()
				return
			}
			if info.Reused {
				b.reused.Add(1)
			}
		},
	}), cancel
}

func (c *Client) CloseIdleConnections() {
	c.http.CloseIdleConnections()
	c.mutations.CloseIdleConnections()
}
