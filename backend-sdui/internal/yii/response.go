package yii

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"reflect"
	"strings"
	"unicode/utf8"
)

// Endpoint methods choose a contract from routes.php / OpenAPI, never infer
// success from the entire 2xx class or the caller's output pointer.
type successContract int

const (
	json200 successContract = iota
	empty204
	biometric200
)

// decodeResponse always closes the body, and retains security status even when
// reading, size validation or decoding fails. Only valid business envelopes pass.
func (c *Client) decodeResponse(res *http.Response, success successContract, out any) (retry bool, err error) {
	defer res.Body.Close()
	expectedStatus := http.StatusOK
	if success == empty204 {
		expectedStatus = http.StatusNoContent
	}
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
			&UpstreamError{Status: res.StatusCode, Body: body, Code: envelope.Error.Code, RetryAfter: validRetryAfter(res.StatusCode, res.Header.Values("Retry-After"))}
	case res.StatusCode != expectedStatus:
		return false, &TransportError{Kind: "protocol", Status: res.StatusCode}
	case success == empty204:
		if len(body) != 0 {
			return false, &TransportError{Kind: "protocol", Status: res.StatusCode}
		}
		return false, nil
	default:
		if out == nil || !utf8.Valid(body) || !validResponseShape(body, reflect.TypeOf(out).Elem(), success == biometric200) {
			return false, &TransportError{Kind: "protocol", Status: res.StatusCode}
		}
		if err := json.Unmarshal(body, out); err != nil {
			return false, &TransportError{Kind: "protocol", Cause: err, Status: res.StatusCode}
		}
		// These opt-in wire envelopes have semantic requirements beyond JSON
		// shape. Check them before call() finalizes its span and observer event.
		switch cards := out.(type) {
		case *cardPageWire:
			if !validCards(cards.Projection, cards.Items) {
				return false, &TransportError{Kind: "protocol", Status: res.StatusCode}
			}
		case *popularWire:
			if !validCards(cards.Projection, cards.Items) {
				return false, &TransportError{Kind: "protocol", Status: res.StatusCode}
			}
		}
		return false, nil
	}
}

// Check presence and nullability before decoding: encoding/json otherwise
// silently turns missing/null required members and null list entries into zero
// values. Requiredness comes from OpenAPI, separately from Go nullability.
// Only the two documented endpoint-specific optional fields can be absent.
// Unknown upstream members remain forward compatible, except case-fold aliases
// that encoding/json would otherwise use to overwrite canonical fields.
func validResponseShape(raw []byte, typ reflect.Type, biometric bool) bool {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 {
		return false
	}
	if typ.Kind() == reflect.Pointer {
		return bytes.Equal(raw, []byte("null")) || validResponseShape(raw, typ.Elem(), biometric)
	}
	if bytes.Equal(raw, []byte("null")) {
		return false
	}
	switch typ.Kind() {
	case reflect.Struct:
		var fields map[string]json.RawMessage
		if raw[0] != '{' || json.Unmarshal(raw, &fields) != nil {
			return false
		}
		for i := 0; i < typ.NumField(); i++ {
			field := typ.Field(i)
			name, _, _ := strings.Cut(field.Tag.Get("json"), ",")
			for key := range fields {
				if key != name && strings.EqualFold(key, name) {
					return false
				}
			}
			value, present := fields[name]
			optional := typ == reflect.TypeFor[deviceWire]() && field.Name == "KeyAlgorithm" && !biometric || typ == reflect.TypeOf(sessionWire{}.Device) && field.Name == "BiometricCredentialID"
			if !present && optional {
				continue
			}
			if !validResponseShape(value, field.Type, biometric) {
				return false
			}
		}
	case reflect.Slice:
		var items []json.RawMessage
		if raw[0] != '[' || json.Unmarshal(raw, &items) != nil {
			return false
		}
		for _, item := range items {
			if !validResponseShape(item, typ.Elem(), biometric) {
				return false
			}
		}
	}
	return true // Scalar types and decimal strings are checked by typed decoding.
}
