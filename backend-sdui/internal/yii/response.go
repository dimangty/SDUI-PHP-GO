package yii

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
)

// decodeResponse always closes the body, and retains security status even when
// reading, size validation or decoding fails. Only valid business envelopes pass.
func (c *Client) decodeResponse(res *http.Response, out any) (retry bool, err error) {
	defer res.Body.Close()
	body, readErr := io.ReadAll(io.LimitReader(res.Body, c.maxBody+1))
	switch {
	case readErr != nil:
		return false, &TransportError{Kind: "body", Cause: readErr, Status: res.StatusCode}
	case int64(len(body)) > c.maxBody:
		return false, &TransportError{Kind: "body_limit", Status: res.StatusCode}
	case res.StatusCode >= 300 && res.StatusCode < 400:
		return false, &TransportError{Kind: "redirect", Status: res.StatusCode}
	case res.StatusCode < 200 || res.StatusCode >= 300:
		var envelope struct {
			Error struct {
				Code    string `json:"code"`
				Message string `json:"message"`
				TraceID string `json:"traceId"`
			} `json:"error"`
		}
		if json.Unmarshal(body, &envelope) != nil || envelope.Error.Code == "" || envelope.Error.Message == "" || envelope.Error.TraceID == "" {
			return false, &TransportError{Kind: "invalid_error", Status: res.StatusCode}
		}
		return res.StatusCode == 502 || res.StatusCode == 503 || res.StatusCode == 504,
			&UpstreamError{Status: res.StatusCode, Body: body, Code: envelope.Error.Code}
	case out == nil || res.StatusCode == http.StatusNoContent:
		return false, nil
	default:
		if len(body) == 0 || bytes.Equal(bytes.TrimSpace(body), []byte("null")) {
			return false, &TransportError{Kind: "invalid_json"}
		}
		if err := json.Unmarshal(body, out); err != nil {
			return false, &TransportError{Kind: "invalid_json", Cause: err}
		}
		return false, nil
	}
}
