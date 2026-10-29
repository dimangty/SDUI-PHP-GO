package bff

import (
	"encoding/json"
	"errors"
	"io"
	"mime"
	"net/http"

	"shopkmp/backend-sdui/internal/application"
	"shopkmp/backend-sdui/internal/application/model"
)

const MaxActionBody = 32 << 10

func (s *Service) handleAction(w http.ResponseWriter, r *http.Request, id model.Identity) {
	fail := func(err error) { s.fail(w, id.RequestID, err) }
	if id.Bearer == "" {
		fail(&Error{401, "BFF_UNAUTHORIZED", "Sign in to perform this command"})
		return
	}
	if r.URL.RawQuery != "" {
		fail(bad("Commands do not accept query parameters"))
		return
	}
	contentType, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if err != nil || contentType != "application/json" {
		fail(&Error{415, "BFF_UNSUPPORTED_MEDIA_TYPE", "Use application/json"})
		return
	}
	raw, err := io.ReadAll(http.MaxBytesReader(bodyLimitWriter(w), r.Body, MaxActionBody))
	if err != nil {
		var large *http.MaxBytesError
		if errors.As(err, &large) {
			fail(&Error{413, "BFF_REQUEST_TOO_LARGE", "Command body exceeds 32768 bytes"})
		} else {
			fail(bad("Cannot read command body"))
		}
		return
	}
	var command CommandRequest
	if err := application.StrictJSON(raw, &command); err != nil {
		fail(err)
		return
	}
	key := r.Header.Get("Idempotency-Key")
	if len(r.Header.Values("Idempotency-Key")) > 1 || !model.ValidKey(key) {
		fail(bad("Invalid Idempotency-Key"))
		return
	}
	response, err := s.app.Execute(r.Context(), id, command, key)
	if err != nil {
		fail(err)
		return
	}
	body, err := json.Marshal(response)
	if err != nil {
		fail(&Error{502, "BFF_INVALID_DOCUMENT", "Upstream data cannot form a safe screen"})
		return
	}
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(body)
}
