// Package bff composes business HTTP results; Yii remains the authorization and
// business-rule owner. All mutable data is request scoped.
package bff

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"shopkmp/backend-sdui/internal/sdui"
	"shopkmp/backend-sdui/internal/yii"
)

type Config struct{ RequestTimeout time.Duration }
type Service struct {
	up      *yii.Client
	timeout time.Duration
}

func New(up *yii.Client, cfg Config) *Service {
	if cfg.RequestTimeout <= 0 {
		cfg.RequestTimeout = 8 * time.Second
	}
	return &Service{up: up, timeout: cfg.RequestTimeout}
}

type Error struct {
	Status  int
	Code    string
	Message string
}

func (e *Error) Error() string { return e.Code }
func bad(message string) error {
	return &Error{Status: 400, Code: "BFF_INVALID_REQUEST", Message: message}
}
func ownership(ok bool) error {
	if !ok {
		return &Error{Status: 502, Code: "BFF_UPSTREAM_ACCOUNT_MISMATCH", Message: "Upstream account result is inconsistent"}
	}
	return nil
}
func (s *Service) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	trace := yii.RequestID(r.Header.Get("X-Request-Id"))
	w.Header().Set("X-Request-Id", trace)
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Cache-Control", "no-store")
	ctx, cancel := context.WithTimeout(r.Context(), s.timeout)
	defer cancel()
	r = r.WithContext(ctx)
	id := yii.Identity{RequestID: trace}
	headers := r.Header.Values("Authorization")
	if len(headers) > 1 {
		s.fail(w, trace, bad("Only one Authorization header is allowed"))
		return
	}
	if len(headers) == 1 {
		if !strings.HasPrefix(headers[0], "Bearer ") || !yii.ValidBearer(strings.TrimPrefix(headers[0], "Bearer ")) {
			s.fail(w, trace, &Error{401, "BFF_UNAUTHORIZED", "A valid Bearer header is required"})
			return
		}
		id.Bearer = strings.TrimPrefix(headers[0], "Bearer ")
	}
	if r.URL.Path == "/api/v1/sdui/actions" {
		if r.Method != "POST" {
			w.Header().Set("Allow", "POST")
			s.fail(w, trace, &Error{405, "BFF_METHOD_NOT_ALLOWED", "Use POST"})
			return
		}
		s.handleAction(w, r, id)
		return
	}
	screen, resource, err := screenRoute(r.URL.Path)
	if err != nil {
		s.fail(w, trace, err)
		return
	}
	if r.Method != "GET" {
		w.Header().Set("Allow", "GET")
		s.fail(w, trace, &Error{405, "BFF_METHOD_NOT_ALLOWED", "Use GET"})
		return
	}
	q, err := parseQuery(r.URL.RawQuery)
	if err != nil {
		s.fail(w, trace, err)
		return
	}
	if err = validateKeys(screen, q); err != nil {
		s.fail(w, trace, err)
		return
	}
	var user *yii.User
	if id.Bearer == "" {
		if screen != "home" && screen != "catalog" && screen != "product" {
			s.fail(w, trace, &Error{401, "BFF_UNAUTHORIZED", "Sign in to view this screen"})
			return
		}
	} else {
		u, authErr := s.up.User(ctx, id)
		if authErr != nil {
			s.fail(w, trace, authErr)
			return
		}
		if !yii.ValidID(u.ID) {
			s.fail(w, trace, &yii.TransportError{Kind: "invalid_user"})
			return
		}
		user = &u
	}
	doc, err := s.build(ctx, id, user, screen, resource, q)
	if err != nil {
		s.fail(w, trace, err)
		return
	}
	body, err := doc.Encode()
	if err != nil {
		s.fail(w, trace, &Error{502, "BFF_INVALID_DOCUMENT", "Upstream data cannot form a safe screen"})
		return
	}
	// Public responses are also revalidated; no shared cache of request data.
	if user == nil {
		w.Header().Set("Cache-Control", "public, max-age=0, must-revalidate")
	}
	w.WriteHeader(200)
	_, _ = w.Write(body)
}
func screenRoute(path string) (string, string, error) {
	const prefix = "/api/v1/screens/"
	if !strings.HasPrefix(path, prefix) {
		return "", "", &Error{404, "BFF_NOT_FOUND", "Screen not found"}
	}
	parts := strings.Split(strings.TrimPrefix(path, prefix), "/")
	if len(parts) == 1 {
		switch parts[0] {
		case "home", "catalog", "cart", "orders", "profile", "devices":
			return parts[0], "", nil
		}
	}
	if len(parts) == 2 && (parts[0] == "product" || parts[0] == "order") {
		if !yii.ValidID(parts[1]) {
			return "", "", bad("Invalid resource ID")
		}
		return parts[0], parts[1], nil
	}
	return "", "", &Error{404, "BFF_NOT_FOUND", "Screen not found"}
}
func parseQuery(raw string) (url.Values, error) {
	q, err := url.ParseQuery(raw)
	if err != nil || len(raw) > 4096 {
		return nil, bad("Invalid or oversized query")
	}
	for _, v := range q {
		if len(v) != 1 {
			return nil, bad("Duplicate query parameter")
		}
	}
	return q, nil
}
func validateKeys(screen string, q url.Values) error {
	allowed := map[string]bool{}
	switch screen {
	case "home":
		allowed["deviceId"] = true
		allowed["categoryPage"], allowed["categorySize"] = true, true
	case "catalog":
		for _, k := range []string{"q", "categoryId", "sort", "priceMin", "priceMax", "page", "size", "categoryPage", "categorySize"} {
			allowed[k] = true
		}
	case "orders":
		for _, k := range []string{"status", "page", "size"} {
			allowed[k] = true
		}
	case "cart":
		allowed["page"], allowed["size"] = true, true
	}
	for k := range q {
		if !allowed[k] {
			return bad("Unsupported query parameter: " + k)
		}
	}
	return nil
}
func pageValues(q url.Values) (int, int, error) {
	page, size := 0, 20
	var err error
	if q.Has("page") {
		page, err = strconv.Atoi(q.Get("page"))
		if err != nil {
			return 0, 0, bad("Invalid page")
		}
	}
	if q.Has("size") {
		size, err = strconv.Atoi(q.Get("size"))
		if err != nil {
			return 0, 0, bad("Invalid size")
		}
	}
	return page, size, nil
}
func catalogQuery(q url.Values) (yii.CatalogQuery, error) {
	page, size, err := pageValues(q)
	if err != nil {
		return yii.CatalogQuery{}, err
	}
	v := yii.CatalogQuery{Query: q.Get("q"), CategoryID: q.Get("categoryId"), Sort: q.Get("sort"), PriceMin: q.Get("priceMin"), PriceMax: q.Get("priceMax"), Page: page, Size: size}
	if err := v.Validate(); err != nil {
		return v, bad(err.Error())
	}
	return v, nil
}
func orderQuery(q url.Values) (yii.OrderQuery, error) {
	page, size, err := pageValues(q)
	if err != nil {
		return yii.OrderQuery{}, err
	}
	v := yii.OrderQuery{Status: q.Get("status"), Page: page, Size: size}
	if err := v.Validate(); err != nil {
		return v, bad(err.Error())
	}
	return v, nil
}
func (s *Service) fail(w http.ResponseWriter, trace string, err error) {
	var up *yii.UpstreamError
	if errors.As(err, &up) {
		w.WriteHeader(up.Status)
		_, _ = w.Write(up.Body)
		return
	}
	status, code, message := 502, "BFF_UPSTREAM_TRANSPORT", "Business service is unavailable"
	var e *Error
	if securityError(err) {
		status, code, message = yii.HTTPStatus(err), "BFF_UPSTREAM_SECURITY", "Business service rejected authorization"
	} else if errors.As(err, &e) {
		status, code, message = e.Status, e.Code, e.Message
	} else if errors.Is(err, context.DeadlineExceeded) {
		status, code, message = 504, "BFF_UPSTREAM_TIMEOUT", "Business service deadline exceeded"
	} else if errors.Is(err, context.Canceled) {
		status, code, message = 408, "BFF_REQUEST_CANCELED", "Request was canceled"
	}
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(struct {
		Error struct {
			Code    string `json:"code"`
			Message string `json:"message"`
			TraceID string `json:"traceId"`
		} `json:"error"`
	}{Error: struct {
		Code    string `json:"code"`
		Message string `json:"message"`
		TraceID string `json:"traceId"`
	}{code, message, trace}})
}

func screenBase(name, title string, user *yii.User) sdui.Screen {
	d := sdui.Screen{SchemaVersion: 1, ScreenID: name, Components: []sdui.Node{}, Actions: []sdui.Action{}, State: sdui.State{Fields: map[string]string{}}, Metadata: sdui.Metadata{Title: title, Locale: "ru-RU"}}
	if user != nil {
		d.State.Authenticated = true
		d.Metadata.AccountID = user.ID
	}
	return d
}
