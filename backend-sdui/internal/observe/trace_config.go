package observe

import (
	"errors"
	"math"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

type TraceConfig struct {
	Enabled         bool
	Endpoint        string
	SampleRatio     float64
	QueueSize       int
	BatchSize       int
	BatchTimeout    time.Duration
	ExportTimeout   time.Duration
	FlushTimeout    time.Duration
	ShutdownTimeout time.Duration
}

func TraceDefaults() TraceConfig {
	return TraceConfig{SampleRatio: .1, QueueSize: 256, BatchSize: 64, BatchTimeout: time.Second, ExportTimeout: time.Second, FlushTimeout: time.Second, ShutdownTimeout: 2 * time.Second}
}

func (c TraceConfig) Validate() error {
	if c.SampleRatio < 0 || c.SampleRatio > 1 || math.IsNaN(c.SampleRatio) || math.IsInf(c.SampleRatio, 0) || c.QueueSize < 1 || c.QueueSize > 8192 || c.BatchSize < 1 || c.BatchSize > 512 || c.BatchSize > c.QueueSize {
		return errors.New("invalid SDUI trace sampling or queue bounds")
	}
	for _, d := range []time.Duration{c.BatchTimeout, c.ExportTimeout, c.FlushTimeout, c.ShutdownTimeout} {
		if d <= 0 || d > 30*time.Second {
			return errors.New("SDUI trace timeouts must be positive and at most 30s")
		}
	}
	if c.Endpoint != "" || c.Enabled {
		u, err := url.Parse(c.Endpoint)
		if err != nil || u == nil || (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() == "" || u.Opaque != "" || u.User != nil || u.RawQuery != "" || u.ForceQuery || strings.Contains(c.Endpoint, "#") || strings.ContainsAny(c.Endpoint, " \t\r\n") {
			return errors.New("SDUI_TRACE_ENDPOINT requires an absolute HTTP(S) URL without credentials/query/fragment")
		}
		// WithEndpointURL retains only URL.Path, then reconstructs the URL.
		// It cannot preserve encoded separators or even canonical escapes whose
		// RawPath is empty (e.g. %20 and %25). Reject before SDK construction.
		if u.RawPath != "" || strings.Contains(u.EscapedPath(), "%") {
			return errors.New("SDUI_TRACE_ENDPOINT requires an unescaped path")
		}
		if port := u.Port(); port != "" {
			n, err := strconv.Atoi(port)
			if err != nil || n < 1 || n > 65535 {
				return errors.New("invalid SDUI_TRACE_ENDPOINT port")
			}
		}
	}
	if c.Enabled {
		// The upstream SDK/exporter parses ambient OTEL_* settings BEFORE its
		// explicit options and can print raw malformed URLs/certificate paths to
		// stderr. Reject these settings before invoking it; never mutate process
		// environment or global log/error handlers to hide the side effect.
		for _, entry := range os.Environ() {
			key, value, _ := strings.Cut(entry, "=")
			if strings.HasPrefix(key, "OTEL_") && value != "" {
				return errors.New("enabled SDUI tracing requires dedicated SDUI_TRACE_* configuration; unset ambient OTEL_* settings")
			}
		}
	}
	return nil
}
