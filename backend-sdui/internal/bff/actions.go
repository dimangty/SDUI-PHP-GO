package bff

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"mime"
	"net/http"
	"reflect"
	"regexp"
	"strings"
	"unicode"
	"unicode/utf8"

	"shopkmp/backend-sdui/internal/sdui"
	"shopkmp/backend-sdui/internal/yii"
)

const MaxActionBody = 32 << 10

type CommandRequest struct {
	Command string          `json:"command"`
	Input   json.RawMessage `json:"input"`
}
type CartCommand struct {
	ProductID string `json:"productId"`
	Quantity  *int   `json:"quantity"`
}
type ProductCommand struct {
	ProductID string `json:"productId"`
}
type CancelCommand struct {
	OrderID   string `json:"orderId"`
	Confirmed bool   `json:"confirmed"`
}
type RevokeCommand struct {
	DeviceID   string `json:"deviceId"`
	DeviceKind string `json:"deviceKind"`
	Confirmed  bool   `json:"confirmed"`
}
type CommandResponse struct {
	SchemaVersion int              `json:"schemaVersion"`
	Command       string           `json:"command"`
	Outcome       string           `json:"outcome"`
	Refresh       sdui.Destination `json:"refresh"`
}

// checkJSON rejects duplicate keys and excessive nesting before typed decoding.
// encoding/json's default last-key-wins behavior is inappropriate for commands.
func checkJSON(d *json.Decoder, depth int) error {
	if depth > 16 {
		return errors.New("JSON too deep")
	}
	token, err := d.Token()
	if err != nil {
		return err
	}
	delim, ok := token.(json.Delim)
	if !ok {
		return nil
	}
	switch delim {
	case '{':
		seen := map[string]bool{}
		for d.More() {
			k, err := d.Token()
			if err != nil {
				return err
			}
			key, ok := k.(string)
			if !ok || seen[key] {
				return errors.New("duplicate JSON key")
			}
			seen[key] = true
			if err := checkJSON(d, depth+1); err != nil {
				return err
			}
		}
	case '[':
		for d.More() {
			if err := checkJSON(d, depth+1); err != nil {
				return err
			}
		}
	default:
		return errors.New("unexpected delimiter")
	}
	_, err = d.Token()
	return err
}
func strictJSON(raw []byte, out any) error {
	if !utf8.Valid(raw) || len(bytes.TrimSpace(raw)) == 0 || bytes.TrimSpace(raw)[0] != '{' {
		return bad("Expected a JSON object")
	}
	scan := json.NewDecoder(bytes.NewReader(raw))
	scan.UseNumber()
	if err := checkJSON(scan, 0); err != nil {
		return bad("Invalid, duplicate or deeply nested JSON")
	}
	if _, err := scan.Token(); err != io.EOF {
		return bad("Expected exactly one JSON object")
	}
	// encoding/json otherwise matches struct fields case-insensitively, allowing
	// aliases such as quantity/Quantity to bypass the exact published contract.
	var members map[string]json.RawMessage
	if err := json.Unmarshal(raw, &members); err != nil {
		return bad("Invalid command object")
	}
	fields := reflect.TypeOf(out).Elem()
	allowed := make(map[string]bool, fields.NumField())
	for i := 0; i < fields.NumField(); i++ {
		tag := strings.Split(fields.Field(i).Tag.Get("json"), ",")[0]
		allowed[tag] = true
	}
	for key := range members {
		if !allowed[key] {
			return bad("Unknown command field")
		}
	}
	d := json.NewDecoder(bytes.NewReader(raw))
	d.DisallowUnknownFields()
	if err := d.Decode(out); err != nil {
		return bad("Invalid command fields")
	}
	return nil
}

var phonePattern = regexp.MustCompile(`^\+[1-9][0-9]{7,14}$`)

func validProfile(p yii.ProfileInput) bool {
	n := strings.TrimSpace(p.FullName)
	if n == "" || utf8.RuneCountInString(n) > 255 || !phonePattern.MatchString(p.Phone) {
		return false
	}
	for _, c := range n {
		if unicode.IsControl(c) {
			return false
		}
	}
	return true
}
func (s *Service) handleAction(w http.ResponseWriter, r *http.Request, id yii.Identity) {
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
	raw, err := io.ReadAll(http.MaxBytesReader(w, r.Body, MaxActionBody))
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
	if err := strictJSON(raw, &command); err != nil {
		fail(err)
		return
	}
	key := r.Header.Get("Idempotency-Key")
	if len(r.Header.Values("Idempotency-Key")) > 1 || !yii.ValidKey(key) {
		fail(bad("Invalid Idempotency-Key"))
		return
	}
	var cart CartCommand
	var product ProductCommand
	var cancel CancelCommand
	var revoke RevokeCommand
	var profile yii.ProfileInput
	switch command.Command {
	case "AddToCart", "UpdateQuantity":
		err = strictJSON(command.Input, &cart)
		if err == nil && (!yii.ValidID(cart.ProductID) || cart.Quantity == nil || *cart.Quantity < 0 || *cart.Quantity > 1000000 || (command.Command == "AddToCart" && (*cart.Quantity == 0 || key == ""))) {
			err = bad("Valid productId, quantity and AddToCart idempotency key are required")
		}
	case "RemoveFromCart":
		err = strictJSON(command.Input, &product)
		if err == nil && !yii.ValidID(product.ProductID) {
			err = bad("Invalid productId")
		}
	case "CancelOrder":
		err = strictJSON(command.Input, &cancel)
		if err == nil && (!yii.ValidID(cancel.OrderID) || !cancel.Confirmed) {
			err = bad("Cancellation requires orderId and explicit confirmation")
		}
	case "UpdateProfile":
		err = strictJSON(command.Input, &profile)
		if err == nil && !validProfile(profile) {
			err = bad("Valid fullName and phone are required")
		}
	case "RevokeDevice":
		err = strictJSON(command.Input, &revoke)
		if err == nil && (!yii.ValidDevice(revoke.DeviceID) || (revoke.DeviceKind != "pin" && revoke.DeviceKind != "biometric") || !revoke.Confirmed) {
			err = bad("Valid device and explicit confirmation are required")
		}
	default:
		err = bad("Unsupported command")
	}
	if err != nil {
		fail(err)
		return
	}
	// Reauthenticate on every command; never use accountId from a screen, client
	// claims, cookies, or an earlier successful request as authority.
	user, err := s.up.User(r.Context(), id)
	if err != nil {
		fail(err)
		return
	}
	if !yii.ValidID(user.ID) {
		fail(&yii.TransportError{Kind: "invalid_user"})
		return
	}
	dest := sdui.Destination{Screen: "cart"}
	switch command.Command {
	case "AddToCart":
		var result yii.Cart
		result, err = s.up.AddCart(r.Context(), id, yii.AddCartInput{ProductID: cart.ProductID, Quantity: *cart.Quantity}, key)
		if err == nil {
			err = ownership(result.UserID == user.ID)
		}
	case "UpdateQuantity":
		var result yii.Cart
		result, err = s.up.UpdateCart(r.Context(), id, cart.ProductID, *cart.Quantity)
		if err == nil {
			err = ownership(result.UserID == user.ID)
		}
	case "RemoveFromCart":
		var result yii.Cart
		result, err = s.up.RemoveCart(r.Context(), id, product.ProductID)
		if err == nil {
			err = ownership(result.UserID == user.ID)
		}
	case "CancelOrder":
		var existing yii.Order
		existing, err = s.up.Order(r.Context(), id, cancel.OrderID)
		if err == nil {
			err = ownership(existing.UserID == user.ID && existing.ID == cancel.OrderID)
		}
		if err == nil {
			var result yii.Order
			result, err = s.up.CancelOrder(r.Context(), id, cancel.OrderID)
			if err == nil {
				err = ownership(result.UserID == user.ID && result.ID == cancel.OrderID)
			}
		}
		dest = sdui.Destination{Screen: "order", ID: cancel.OrderID}
	case "UpdateProfile":
		var result yii.User
		result, err = s.up.UpdateProfile(r.Context(), id, profile)
		if err == nil {
			err = ownership(result.ID == user.ID)
		}
		dest = sdui.Destination{Screen: "profile"}
	case "RevokeDevice":
		err = s.up.RevokeDevice(r.Context(), id, revoke.DeviceKind, revoke.DeviceID)
		dest = sdui.Destination{Screen: "devices"}
	}
	if err != nil {
		fail(err)
		return
	}
	// Acknowledge the successful write without a second business read that could
	// fail and hide a committed mutation. The adapter refreshes separately.
	response := CommandResponse{SchemaVersion: 1, Command: command.Command, Outcome: "applied", Refresh: dest}
	body, _ := json.Marshal(response)
	w.WriteHeader(200)
	_, _ = w.Write(body)
}
