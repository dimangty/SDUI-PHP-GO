package yii

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"

	"shopkmp/backend-sdui/internal/domain"
	"shopkmp/backend-sdui/internal/observe"
)

type Config struct {
	Origin   string
	Timeout  time.Duration
	MaxBody  int64
	Backoff  time.Duration
	Observer *observe.Recorder
}
type Client struct {
	origin    string
	http      *http.Client
	mutations *http.Client
	timeout   time.Duration
	maxBody   int64
	backoff   time.Duration
	observer  *observe.Recorder
}

// Identity carries only request-scoped credentials, never an asserted user/role.
type Identity struct {
	Bearer    string
	RequestID string
}
type UpstreamError struct {
	Status int
	Body   json.RawMessage
	Code   string
	// Only a validated, single Retry-After value from a 429/503 response.
	RetryAfter string
}

func (e *UpstreamError) Error() string   { return fmt.Sprintf("Yii HTTP %d (%s)", e.Status, e.Code) }
func (e *UpstreamError) HTTPStatus() int { return e.Status }

type TransportError struct {
	Kind  string
	Cause error
	// Status is retained as soon as response headers arrive, independently of
	// body size, decoding or read failures. A known 401/403 must fail closed.
	Status int
}

func (e *TransportError) Error() string   { return "Yii " + e.Kind }
func (e *TransportError) Unwrap() error   { return e.Cause }
func (e *TransportError) HTTPStatus() int { return e.Status }

func HTTPStatus(err error) int {
	var response interface{ HTTPStatus() int }
	if errors.As(err, &response) {
		return response.HTTPStatus()
	}
	return 0
}

var tracePattern = regexp.MustCompile(`^[A-Za-z0-9._-]{1,128}$`)
var bearerPattern = regexp.MustCompile(`^[A-Za-z0-9._~+/-]+=*$`)

func ValidID(s string) bool     { return domain.ValidID(s) }
func ValidDevice(s string) bool { return domain.ValidDevice(s) }
func ValidBearer(s string) bool { return len(s) > 0 && len(s) <= 8192 && bearerPattern.MatchString(s) }
func RequestID(s string) string {
	if tracePattern.MatchString(s) {
		return s
	}
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b[:])
}
func ValidKey(s string) bool {
	if len(s) > 255 {
		return false
	}
	for _, c := range s {
		if c < 33 || c > 126 {
			return false
		}
	}
	return true
}

func New(cfg Config) (*Client, error) {
	u, err := url.Parse(cfg.Origin)
	if err != nil || u == nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" || u.User != nil || u.RawQuery != "" || u.ForceQuery || u.Fragment != "" || (u.Path != "" && u.Path != "/") || u.Opaque != "" {
		return nil, errors.New("YII_ORIGIN must be a fixed HTTP(S) origin without credentials/path/query/fragment")
	}
	if cfg.Timeout <= 0 {
		return nil, errors.New("positive upstream timeout required")
	}
	if cfg.MaxBody == 0 {
		cfg.MaxBody = 2 << 20
	}
	if cfg.MaxBody < 1 || cfg.MaxBody > 8<<20 {
		return nil, errors.New("upstream body limit must be 1..8388608")
	}
	if cfg.Backoff == 0 {
		cfg.Backoff = 50 * time.Millisecond
	}
	if cfg.Backoff < 0 || cfg.Backoff > time.Second {
		return nil, errors.New("invalid backoff")
	}
	// Reads reuse bounded connections. Mutations deliberately do not: even an
	// empty POST or an Idempotency-Key must never trigger a transparent replay.
	return &Client{origin: strings.TrimSuffix(cfg.Origin, "/"), http: newHTTPClient(cfg.Timeout, true), mutations: newHTTPClient(cfg.Timeout, false), timeout: cfg.Timeout, maxBody: cfg.MaxBody, backoff: cfg.Backoff, observer: cfg.Observer}, nil
}

// call is deliberately private. Public methods fix every path and method.
func (c *Client) call(ctx context.Context, id Identity, method, path string, q url.Values, input any, key string, success successContract, out any) (err error) {
	if id.Bearer != "" && !ValidBearer(id.Bearer) {
		return errors.New("invalid bearer")
	}
	if !ValidKey(key) {
		return errors.New("invalid idempotency key")
	}
	ctx, cancel := context.WithTimeout(ctx, c.timeout)
	defer cancel()
	client := c.mutations
	var budget readBudget
	if method == http.MethodGet {
		client = c.http
		var stop context.CancelCauseFunc
		ctx, stop = budget.context(ctx)
		defer stop(nil)
	}
	id.RequestID = RequestID(id.RequestID)
	ctx = observe.WithCorrelation(ctx, id.RequestID, observe.TraceParent(ctx))
	// One event per logical call: status is the final HTTP attempt's response
	// status, or zero if that attempt received no response. Cancellation while
	// waiting to retry retains the last received status (no new attempt began).
	status := 0
	if c.observer != nil {
		start := time.Now()
		defer func() {
			c.observer.Record(ctx, observe.Event{Kind: "upstream", Operation: operation(method, path), Status: status,
				Duration: time.Since(start), Error: errorKind(err), Retries: max(0, min(2, int(budget.connections.Load()))-1), Reused: int(budget.reused.Load())})
		}()
	}
	var payload []byte
	if input != nil {
		payload, err = json.Marshal(input)
		if err != nil {
			return err
		}
	}
	target := c.origin + path
	if len(q) > 0 {
		target += "?" + q.Encode()
	}
	for attempt := 0; attempt < 2; attempt++ {
		req, requestErr := http.NewRequestWithContext(ctx, method, target, bytes.NewReader(payload))
		if requestErr != nil {
			return requestErr
		}
		req.GetBody = nil // explicitly prohibit transport replays of mutation bodies
		req.Header.Set("Accept", "application/json")
		req.Header.Set("X-Request-Id", id.RequestID)
		if parent := observe.TraceParent(ctx); parent != "" {
			req.Header.Set("Traceparent", parent)
		}
		if id.Bearer != "" {
			req.Header.Set("Authorization", "Bearer "+id.Bearer)
		}
		if input != nil {
			req.Header.Set("Content-Type", "application/json")
		}
		if key != "" {
			req.Header.Set("Idempotency-Key", key)
		}
		if ctx.Err() != nil {
			return &TransportError{Kind: "canceled", Cause: ctx.Err()}
		}
		status = 0
		res, doErr := client.Do(req)
		retry := false
		if doErr != nil {
			if errors.Is(context.Cause(ctx), errReadBudget) {
				return &TransportError{Kind: "retry_budget", Cause: errReadBudget}
			}
			err = &TransportError{Kind: "network", Cause: doErr}
			var n net.Error
			retry = ctx.Err() == nil && (errors.Is(doErr, io.EOF) || errors.Is(doErr, io.ErrUnexpectedEOF) || errors.As(doErr, &n) && n.Timeout())
		} else {
			status = res.StatusCode
			retry, err = c.decodeResponse(res, success, out)
			if err == nil {
				return nil
			}
		}
		if method != http.MethodGet || attempt == 1 || !retry || budget.connections.Load() >= 2 {
			return err
		}
		delay := c.backoff
		var upstream *UpstreamError
		if errors.As(err, &upstream) && upstream.RetryAfter != "" {
			delay = max(delay, retryAfterDelay(upstream.RetryAfter, time.Now()))
		}
		if deadline, ok := ctx.Deadline(); ok && delay >= time.Until(deadline) {
			// Preserve the received error and retry metadata if the server's
			// minimum delay cannot fit the shared request deadline.
			return err
		}
		timer := time.NewTimer(delay)
		select {
		case <-ctx.Done():
			timer.Stop()
			return &TransportError{Kind: "canceled", Cause: ctx.Err()}
		case <-timer.C:
		}
	}
	return err
}

func get[T any](c *Client, ctx context.Context, id Identity, path string, q url.Values) (T, error) {
	var out T
	err := c.call(ctx, id, http.MethodGet, path, q, nil, "", json200, &out)
	return out, err
}
func (c *Client) User(ctx context.Context, id Identity) (User, error) {
	return get[User](c, ctx, id, "/api/v1/users/me", nil)
}
func (c *Client) Categories(ctx context.Context, id Identity) ([]Category, error) {
	return get[[]Category](c, ctx, id, "/api/v1/categories", nil)
}
func (c *Client) Popular(ctx context.Context, id Identity) ([]Product, error) {
	return get[[]Product](c, ctx, id, "/api/v1/products/popular", url.Values{"limit": {"10"}})
}
func (c *Client) Product(ctx context.Context, id Identity, pid string) (Product, error) {
	if !ValidID(pid) {
		return Product{}, errors.New("invalid product ID")
	}
	return get[Product](c, ctx, id, "/api/v1/products/"+pid, nil)
}
func (c *Client) Cart(ctx context.Context, id Identity) (Cart, error) {
	return get[Cart](c, ctx, id, "/api/v1/cart", nil)
}
func (c *Client) Addresses(ctx context.Context, id Identity) ([]Address, error) {
	return get[[]Address](c, ctx, id, "/api/v1/addresses", nil)
}
func (c *Client) Session(ctx context.Context, id Identity, device string) (Session, error) {
	if !ValidDevice(device) {
		return Session{}, errors.New("invalid device ID")
	}
	return get[Session](c, ctx, id, "/api/v1/auth/session", url.Values{"deviceId": {device}})
}
func (c *Client) Order(ctx context.Context, id Identity, oid string) (Order, error) {
	if !ValidID(oid) {
		return Order{}, errors.New("invalid order ID")
	}
	return get[Order](c, ctx, id, "/api/v1/orders/"+oid, nil)
}
func (c *Client) Devices(ctx context.Context, id Identity, kind string) ([]Device, error) {
	if kind != "pin" && kind != "biometric" {
		return nil, errors.New("invalid device kind")
	}
	contract := json200
	if kind == "biometric" {
		contract = biometric200
	}
	var out []Device
	err := c.call(ctx, id, http.MethodGet, "/api/v1/auth/"+kind+"/devices", nil, nil, "", contract, &out)
	return out, err
}
func (c *Client) Ready(ctx context.Context) error {
	var out struct {
		Status string `json:"status"`
	}
	if err := c.call(ctx, Identity{}, "GET", "/health/ready", nil, nil, "", json200, &out); err != nil {
		return err
	}
	if out.Status != "ok" {
		return errors.New("yii not ready")
	}
	return nil
}
func (c *Client) AddCart(ctx context.Context, id Identity, in AddCartInput, key string) (Cart, error) {
	var out Cart
	if !ValidID(in.ProductID) || in.Quantity < 1 || in.Quantity > 1000000 {
		return out, errors.New("invalid cart input")
	}
	err := c.call(ctx, id, "POST", "/api/v1/cart/items", nil, in, key, json200, &out)
	return out, err
}
func (c *Client) UpdateCart(ctx context.Context, id Identity, pid string, quantity int) (Cart, error) {
	var out Cart
	if !ValidID(pid) || quantity < 0 || quantity > 1000000 {
		return out, errors.New("invalid cart input")
	}
	err := c.call(ctx, id, "PATCH", "/api/v1/cart/items/"+pid, nil, struct {
		Quantity int `json:"quantity"`
	}{quantity}, "", json200, &out)
	return out, err
}
func (c *Client) RemoveCart(ctx context.Context, id Identity, pid string) (Cart, error) {
	var out Cart
	if !ValidID(pid) {
		return out, errors.New("invalid product ID")
	}
	err := c.call(ctx, id, "DELETE", "/api/v1/cart/items/"+pid, nil, nil, "", json200, &out)
	return out, err
}
func (c *Client) CancelOrder(ctx context.Context, id Identity, oid string) (Order, error) {
	var out Order
	if !ValidID(oid) {
		return out, errors.New("invalid order ID")
	}
	err := c.call(ctx, id, "POST", "/api/v1/orders/"+oid+"/cancel", nil, nil, "", json200, &out)
	return out, err
}
func (c *Client) UpdateProfile(ctx context.Context, id Identity, in ProfileInput) (User, error) {
	var out User
	err := c.call(ctx, id, "PUT", "/api/v1/users/me", nil, in, "", json200, &out)
	return out, err
}
func (c *Client) RevokeDevice(ctx context.Context, id Identity, kind, device string) error {
	if (kind != "pin" && kind != "biometric") || !ValidDevice(device) {
		return errors.New("invalid device")
	}
	return c.call(ctx, id, "DELETE", "/api/v1/auth/"+kind+"/devices/"+device, nil, nil, "", empty204, nil)
}
