package observe

import (
	"context"

	"go.opentelemetry.io/otel/attribute"
	"go.opentelemetry.io/otel/codes"
	"go.opentelemetry.io/otel/trace"
)

// Tracing accepts only bounded operation names, methods, statuses and error
// kinds. It deliberately has no raw URL, header, error or event recording API.
// Providers are constructor-injected; no global OTel provider is installed.
type Tracing struct {
	tracer trace.Tracer
	owner  *traceOwner
}

// NewTracing instruments with a provider owned by the caller (e.g. an in-memory
// SDK exporter). A nil provider disables instrumentation and keeps legacy wire
// correlation. NewTraceProvider owns the production SDK/exporter lifecycle.
func NewTracing(provider trace.TracerProvider) *Tracing {
	if provider == nil {
		return nil
	}
	return &Tracing{tracer: provider.Tracer("shopkmp/backend-sdui")}
}

type Span struct{ span trace.Span }

func (t *Tracing) Server(ctx context.Context, method, operation string) (context.Context, Span) {
	return t.start(ctx, "http", method, operation, trace.SpanKindServer, true)
}

func (t *Tracing) Build(ctx context.Context, screen string) (context.Context, Span) {
	return t.start(ctx, "render", "", screen, trace.SpanKindInternal, false)
}

func (t *Tracing) Client(ctx context.Context, method, operation string) (context.Context, Span) {
	return t.start(ctx, "upstream", method, operation, trace.SpanKindClient, false)
}

func (t *Tracing) start(ctx context.Context, kind, method, operation string, spanKind trace.SpanKind, root bool) (context.Context, Span) {
	if t == nil {
		return ctx, Span{}
	}
	op := normalize(kind, operation).operation
	attrs := []attribute.KeyValue{attribute.String("sdui.operation", op)}
	if method != "" {
		switch method {
		case "GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS":
		default:
			method = "other"
		}
		attrs = append(attrs, attribute.String("http.request.method", method))
	}
	if kind == "http" {
		attrs = append(attrs, attribute.String("http.route", traceRoute(op)))
	}
	opts := []trace.SpanStartOption{trace.WithSpanKind(spanKind), trace.WithAttributes(attrs...)}
	if root {
		// Public traceparent/tracestate, including a context installed by another
		// middleware, cannot choose the root ID, sampling decision or links.
		opts = append(opts, trace.WithNewRoot())
	}
	ctx, span := t.tracer.Start(ctx, "sdui."+kind+"."+op, opts...)
	return ctx, Span{span: span}
}

func traceRoute(op string) string {
	switch op {
	case "home", "catalog", "cart", "orders", "profile", "devices":
		return "/api/v1/screens/" + op
	case "product", "order":
		return "/api/v1/screens/" + op + "/{id}"
	case "actions":
		return "/api/v1/sdui/actions"
	default:
		return "other"
	}
}

func (s Span) End(status int, errorKind string) {
	if s.span == nil {
		return
	}
	if status >= 100 && status <= 599 {
		s.span.SetAttributes(attribute.Int("http.response.status_code", status))
	}
	if kind := safeError(errorKind); kind != "" {
		s.span.SetAttributes(attribute.String("error.type", kind))
		s.span.SetStatus(codes.Error, "")
	} else if status >= 400 {
		s.span.SetStatus(codes.Error, "")
	}
	s.span.End()
}

// TraceParent emits only the local client span's W3C context while enabled.
// No baggage or tracestate is forwarded. Disabled mode preserves the old wire
// correlation behavior, even if an unrelated provider populated the context.
func (t *Tracing) TraceParent(ctx context.Context) string {
	if t == nil {
		return TraceParent(ctx)
	}
	sc := trace.SpanContextFromContext(ctx)
	if !sc.IsValid() {
		return ""
	}
	flags := "00"
	if sc.IsSampled() {
		flags = "01"
	}
	return "00-" + sc.TraceID().String() + "-" + sc.SpanID().String() + "-" + flags
}
