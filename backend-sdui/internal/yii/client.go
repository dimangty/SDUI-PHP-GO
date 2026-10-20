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
)

type Config struct {
	Origin  string
	Timeout time.Duration
	MaxBody int64
	Backoff time.Duration
}
type Client struct {
	origin  string
	http    *http.Client
	timeout time.Duration
	maxBody int64
	backoff time.Duration
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

var uuidPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
var devicePattern = regexp.MustCompile(`^[A-Za-z0-9._-]{1,255}$`)
var tracePattern = regexp.MustCompile(`^[A-Za-z0-9._-]{1,128}$`)
var bearerPattern = regexp.MustCompile(`^[A-Za-z0-9._~+/-]+=*$`)

func ValidID(s string) bool     { return uuidPattern.MatchString(s) }
func ValidDevice(s string) bool { return s != "." && s != ".." && devicePattern.MatchString(s) }
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
	// No proxy environment, cookie jar or redirect forwarding. No reused connection
	// means net/http cannot silently replay a request on a stale connection outside
	// our explicit one-retry budget (including Idempotency-Key POSTs).
	tr := &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: cfg.Timeout, KeepAlive: 30 * time.Second}).DialContext, TLSHandshakeTimeout: cfg.Timeout, ResponseHeaderTimeout: cfg.Timeout, DisableKeepAlives: true, MaxResponseHeaderBytes: 32 << 10}
	return &Client{origin: strings.TrimSuffix(cfg.Origin, "/"), http: &http.Client{Transport: tr, Timeout: cfg.Timeout, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}, timeout: cfg.Timeout, maxBody: cfg.MaxBody, backoff: cfg.Backoff}, nil
}

// call is deliberately private. Public methods fix every path and method.
func (c *Client) call(ctx context.Context, id Identity, method, path string, q url.Values, input any, key string, out any) error {
	if id.Bearer != "" && !ValidBearer(id.Bearer) {
		return errors.New("invalid bearer")
	}
	if !ValidKey(key) {
		return errors.New("invalid idempotency key")
	}
	ctx, cancel := context.WithTimeout(ctx, c.timeout)
	defer cancel()
	var payload []byte
	var err error
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
	id.RequestID = RequestID(id.RequestID)
	for attempt := 0; attempt < 2; attempt++ {
		req, err := http.NewRequestWithContext(ctx, method, target, bytes.NewReader(payload))
		if err != nil {
			return err
		}
		req.GetBody = nil // explicitly prohibit transport replays of mutation bodies
		req.Header.Set("Accept", "application/json")
		req.Header.Set("X-Request-Id", id.RequestID)
		if id.Bearer != "" {
			req.Header.Set("Authorization", "Bearer "+id.Bearer)
		}
		if input != nil {
			req.Header.Set("Content-Type", "application/json")
		}
		if key != "" {
			req.Header.Set("Idempotency-Key", key)
		}
		res, doErr := c.http.Do(req)
		retry := false
		if doErr != nil {
			err = &TransportError{Kind: "network", Cause: doErr}
			var n net.Error
			retry = ctx.Err() == nil && (errors.Is(doErr, io.EOF) || errors.Is(doErr, io.ErrUnexpectedEOF) || errors.As(doErr, &n) && (n.Timeout() || n.Temporary()))
		} else {
			body, readErr := io.ReadAll(io.LimitReader(res.Body, c.maxBody+1))
			res.Body.Close()
			switch {
			case readErr != nil:
				err = &TransportError{Kind: "body", Cause: readErr, Status: res.StatusCode}
			case int64(len(body)) > c.maxBody:
				err = &TransportError{Kind: "body_limit", Status: res.StatusCode}
			case res.StatusCode >= 300 && res.StatusCode < 400:
				err = &TransportError{Kind: "redirect", Status: res.StatusCode}
			case res.StatusCode < 200 || res.StatusCode >= 300:
				var envelope struct {
					Error struct {
						Code    string `json:"code"`
						Message string `json:"message"`
						TraceID string `json:"traceId"`
					} `json:"error"`
				}
				if json.Unmarshal(body, &envelope) != nil || envelope.Error.Code == "" || envelope.Error.Message == "" || envelope.Error.TraceID == "" {
					err = &TransportError{Kind: "invalid_error", Status: res.StatusCode}
				} else {
					err = &UpstreamError{Status: res.StatusCode, Body: body, Code: envelope.Error.Code}
					retry = res.StatusCode == 502 || res.StatusCode == 503 || res.StatusCode == 504
				}
			case out == nil || res.StatusCode == 204:
				return nil
			default:
				if len(body) == 0 || bytes.Equal(bytes.TrimSpace(body), []byte("null")) {
					return &TransportError{Kind: "invalid_json"}
				}
				if decodeErr := json.Unmarshal(body, out); decodeErr != nil {
					return &TransportError{Kind: "invalid_json", Cause: decodeErr}
				}
				return nil
			}
		}
		if method != http.MethodGet || attempt == 1 || !retry {
			return err
		}
		timer := time.NewTimer(c.backoff)
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
	err := c.call(ctx, id, http.MethodGet, path, q, nil, "", &out)
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
	return get[[]Device](c, ctx, id, "/api/v1/auth/"+kind+"/devices", nil)
}
func (c *Client) Ready(ctx context.Context) error {
	var out struct {
		Status string `json:"status"`
	}
	if err := c.call(ctx, Identity{}, "GET", "/health/ready", nil, nil, "", &out); err != nil {
		return err
	}
	if out.Status != "ok" {
		return errors.New("Yii not ready")
	}
	return nil
}
func (c *Client) AddCart(ctx context.Context, id Identity, in AddCartInput, key string) (Cart, error) {
	var out Cart
	if !ValidID(in.ProductID) || in.Quantity < 1 || in.Quantity > 1000000 {
		return out, errors.New("invalid cart input")
	}
	err := c.call(ctx, id, "POST", "/api/v1/cart/items", nil, in, key, &out)
	return out, err
}
func (c *Client) UpdateCart(ctx context.Context, id Identity, pid string, quantity int) (Cart, error) {
	var out Cart
	if !ValidID(pid) || quantity < 0 || quantity > 1000000 {
		return out, errors.New("invalid cart input")
	}
	err := c.call(ctx, id, "PATCH", "/api/v1/cart/items/"+pid, nil, struct {
		Quantity int `json:"quantity"`
	}{quantity}, "", &out)
	return out, err
}
func (c *Client) RemoveCart(ctx context.Context, id Identity, pid string) (Cart, error) {
	var out Cart
	if !ValidID(pid) {
		return out, errors.New("invalid product ID")
	}
	err := c.call(ctx, id, "DELETE", "/api/v1/cart/items/"+pid, nil, nil, "", &out)
	return out, err
}
func (c *Client) CancelOrder(ctx context.Context, id Identity, oid string) (Order, error) {
	var out Order
	if !ValidID(oid) {
		return out, errors.New("invalid order ID")
	}
	err := c.call(ctx, id, "POST", "/api/v1/orders/"+oid+"/cancel", nil, nil, "", &out)
	return out, err
}
func (c *Client) UpdateProfile(ctx context.Context, id Identity, in ProfileInput) (User, error) {
	var out User
	err := c.call(ctx, id, "PUT", "/api/v1/users/me", nil, in, "", &out)
	return out, err
}
func (c *Client) RevokeDevice(ctx context.Context, id Identity, kind, device string) error {
	if (kind != "pin" && kind != "biometric") || !ValidDevice(device) {
		return errors.New("invalid device")
	}
	return c.call(ctx, id, "DELETE", "/api/v1/auth/"+kind+"/devices/"+device, nil, nil, "", nil)
}
