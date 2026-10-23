package bff

import "net/http"

type statusWriter struct {
	http.ResponseWriter
	status int
}

func (w *statusWriter) WriteHeader(status int) {
	w.status = status
	w.ResponseWriter.WriteHeader(status)
}

func (w *statusWriter) Unwrap() http.ResponseWriter { return w.ResponseWriter }

func routeLabel(path string) string {
	if path == "/api/v1/sdui/actions" {
		return "actions"
	}
	name, _, err := screenRoute(path)
	if err != nil {
		return "not_found"
	}
	return name
}

// MaxBytesReader uses a private net/http server-writer interface to mark a
// connection non-reusable on oversize bodies. Give it the actual server writer.
func bodyLimitWriter(w http.ResponseWriter) http.ResponseWriter {
	if recorded, ok := w.(*statusWriter); ok {
		return recorded.ResponseWriter
	}
	return w
}
