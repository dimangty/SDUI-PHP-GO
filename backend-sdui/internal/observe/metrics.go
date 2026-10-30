package observe

import (
	"bytes"
	"cmp"
	"fmt"
	"net/http"
	"slices"
)

// ServeHTTP is mounted only on the internal operational listener. Snapshotting
// releases the recorder lock before serialization or a slow metrics consumer.
func (r *Recorder) ServeHTTP(w http.ResponseWriter, _ *http.Request) {
	r.mu.RLock()
	tracing := r.tracing
	snapshot := make(map[key]metric, len(r.values))
	keys := make([]key, 0, len(r.values))
	for k, m := range r.values {
		snapshot[k] = m
		keys = append(keys, k)
	}
	r.mu.RUnlock()
	slices.SortFunc(keys, func(a, b key) int {
		if a.kind != b.kind {
			return cmp.Compare(a.kind, b.kind)
		}
		return cmp.Compare(a.operation, b.operation)
	})
	var out bytes.Buffer
	fmt.Fprintf(&out, "# TYPE sdui_trace_export_failures_total counter\nsdui_trace_export_failures_total %d\n", tracing.ExportFailures())
	for _, k := range keys {
		m := snapshot[k]
		labels := fmt.Sprintf(`kind=%q,operation=%q`, k.kind, k.operation)
		for _, counter := range []struct {
			name string
			n    uint64
		}{{"events", m.count}, {"errors", m.errors}, {"timeouts", m.timeouts}, {"retries", m.retries}, {"reused_connections", m.reused}} {
			fmt.Fprintf(&out, "sdui_%s_total{%s} %d\n", counter.name, labels, counter.n)
		}
		for i, bound := range bounds() {
			fmt.Fprintf(&out, "sdui_duration_seconds_bucket{%s,le=%q} %d\n", labels, fmt.Sprint(bound), m.buckets[i])
		}
		fmt.Fprintf(&out, "sdui_duration_seconds_bucket{%s,le=\"+Inf\"} %d\n", labels, m.count)
		fmt.Fprintf(&out, "sdui_duration_seconds_sum{%s} %g\n", labels, m.seconds)
		fmt.Fprintf(&out, "sdui_duration_seconds_count{%s} %d\n", labels, m.count)
		for class, count := range m.classes {
			fmt.Fprintf(&out, "sdui_status_total{%s,class=\"%dxx\"} %d\n", labels, class, count)
		}
		if k.kind == "probe" {
			fmt.Fprintf(&out, "sdui_probe_status{%s} %d\n", labels, m.lastStatus)
		}
	}
	w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	_, _ = w.Write(out.Bytes())
}
