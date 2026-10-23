package observe

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"log/slog"
	"sync"
	"time"
)

type Event struct {
	Kind, Operation string
	Status          int
	Duration        time.Duration
	Error           string
	Retries, Reused int
}

type key struct{ kind, operation string }
type metric struct {
	count, errors, timeouts, retries, reused uint64
	classes                                  [6]uint64
	buckets                                  [8]uint64
	seconds                                  float64
	lastStatus                               int
}

type Recorder struct {
	logger *slog.Logger
	mu     sync.RWMutex
	values map[key]metric
	logKey []byte
}

func New(logger *slog.Logger) *Recorder {
	return &Recorder{logger: logger, values: make(map[key]metric), logKey: []byte(rand.Text())}
}

func bounds() [8]float64 { return [8]float64{.001, .005, .01, .025, .05, .1, .5, 2} }

// Record is safe for concurrent use. All labels have finite allowlists; unknown
// operation/error strings collapse to "other" before either logging or storage.
// A nil recorder is the zero-cost default for library/test consumers.
func (r *Recorder) Record(ctx context.Context, e Event) {
	if r == nil {
		return
	}
	k := normalize(e.Kind, e.Operation)
	e.Error = safeError(e.Error)
	if e.Status < 100 || e.Status > 599 {
		e.Status = 0
	}
	seconds := max(0, e.Duration.Seconds())
	r.mu.Lock()
	m := r.values[k]
	m.count++
	if e.Error != "" || e.Status >= 400 {
		m.errors++
	}
	if e.Error == "timeout" || e.Status == 504 {
		m.timeouts++
	}
	m.retries += uint64(max(0, e.Retries))
	m.reused += uint64(max(0, e.Reused))
	m.classes[e.Status/100]++
	m.seconds += seconds
	m.lastStatus = e.Status
	for i, bound := range bounds() {
		if seconds <= bound {
			m.buckets[i]++
		}
	}
	r.values[k] = m
	r.mu.Unlock()
	if r.logger != nil {
		c, _ := ctx.Value(correlationKey{}).(correlation)
		r.logger.InfoContext(ctx, "sdui_operation", "kind", k.kind, "operation", k.operation,
			"request_id_hash", r.pseudonym(c.requestID), "trace_id_hash", r.pseudonym(c.traceID), "status", e.Status,
			"duration_ms", seconds*1000, "error_kind", e.Error,
			"retries", max(0, e.Retries), "reused_connections", max(0, e.Reused))
	}
}

// Caller-controlled correlation can contain credentials or personal identifiers
// even when syntactically valid. A process-local HMAC preserves within-process
// correlation without retaining the source or enabling dictionary recovery.
// Wire request/trace IDs are forwarded unchanged for API compatibility.
func (r *Recorder) pseudonym(value string) string {
	if value == "" {
		return ""
	}
	mac := hmac.New(sha256.New, r.logKey)
	_, _ = mac.Write([]byte(value))
	return hex.EncodeToString(mac.Sum(nil)[:16])
}

func normalize(kind, operation string) key {
	switch kind {
	case "http":
		switch operation {
		case "home", "catalog", "product", "cart", "orders", "order", "profile", "devices", "actions", "not_found":
			return key{kind, operation}
		}
	case "render":
		switch operation {
		case "home", "catalog", "product", "cart", "orders", "order", "profile", "devices":
			return key{kind, operation}
		}
	case "upstream":
		switch operation {
		case "user", "categories", "popular", "products", "product", "cart", "addresses", "session", "orders", "order", "devices", "ready", "add_cart", "update_cart", "remove_cart", "cancel_order", "update_profile", "revoke_device":
			return key{kind, operation}
		}
	case "probe":
		if operation == "live" || operation == "ready" {
			return key{kind, operation}
		}
	default:
		return key{"other", "other"}
	}
	return key{kind, "other"}
}

func safeError(kind string) string {
	switch kind {
	case "", "network", "body", "body_limit", "redirect", "invalid_error", "invalid_json", "invalid_user", "resource_mismatch", "retry_budget", "canceled", "timeout", "business", "invalid_document", "http":
		return kind
	default:
		return "other"
	}
}
