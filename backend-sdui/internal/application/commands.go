package application

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"reflect"
	"regexp"
	"strings"
	"unicode"
	"unicode/utf8"

	"shopkmp/backend-sdui/internal/application/model"
	"shopkmp/backend-sdui/internal/domain"
	"shopkmp/backend-sdui/internal/sdui"
)

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
func StrictJSON(raw []byte, out any) error {
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

func validProfile(p model.ProfileInput) bool {
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

type commandInput struct {
	cart    CartCommand
	product ProductCommand
	cancel  CancelCommand
	revoke  RevokeCommand
	profile model.ProfileInput
}

func prepareCommand(command CommandRequest, key string) (commandInput, error) {
	var in commandInput
	if !model.ValidKey(key) {
		return in, bad("Invalid Idempotency-Key")
	}
	var err error
	cart, product, cancel, revoke, profile := &in.cart, &in.product, &in.cancel, &in.revoke, &in.profile
	switch command.Command {
	case "AddToCart", "UpdateQuantity":
		err = StrictJSON(command.Input, cart)
		if err == nil && (!model.ValidID(cart.ProductID) || cart.Quantity == nil || *cart.Quantity < 0 || *cart.Quantity > 1000000 || (command.Command == "AddToCart" && (*cart.Quantity == 0 || key == ""))) {
			err = bad("Valid productId, quantity and AddToCart idempotency key are required")
		}
	case "RemoveFromCart":
		err = StrictJSON(command.Input, product)
		if err == nil && !model.ValidID(product.ProductID) {
			err = bad("Invalid productId")
		}
	case "CancelOrder":
		err = StrictJSON(command.Input, cancel)
		if err == nil && (!model.ValidID(cancel.OrderID) || !cancel.Confirmed) {
			err = bad("Cancellation requires orderId and explicit confirmation")
		}
	case "UpdateProfile":
		err = StrictJSON(command.Input, profile)
		if err == nil && !validProfile(*profile) {
			err = bad("Valid fullName and phone are required")
		}
	case "RevokeDevice":
		err = StrictJSON(command.Input, revoke)
		if err == nil && (!model.ValidDevice(revoke.DeviceID) || (revoke.DeviceKind != "pin" && revoke.DeviceKind != "biometric") || !revoke.Confirmed) {
			err = bad("Valid device and explicit confirmation are required")
		}
	default:
		err = bad("Unsupported command")
	}
	return in, err
}

func (s *Application) Execute(ctx context.Context, id model.Identity, command CommandRequest, key string) (CommandResponse, error) {
	if id.Bearer == "" {
		return CommandResponse{}, &Error{Unauthorized, "BFF_UNAUTHORIZED", "Sign in to perform this command"}
	}
	in, err := prepareCommand(command, key)
	if err != nil {
		return CommandResponse{}, err
	}
	cart, product, cancel, revoke, profile := in.cart, in.product, in.cancel, in.revoke, in.profile
	// Reauthenticate on every command; never use accountId from a screen, client
	// claims, cookies, or an earlier successful request as authority.
	user, err := s.authenticate(ctx, id)
	if err != nil {
		return CommandResponse{}, err
	}
	dest := sdui.Destination{Screen: "cart"}
	switch command.Command {
	case "AddToCart":
		var result model.Cart
		result, err = s.cartCommands.AddCart(ctx, id, model.AddCartInput{ProductID: cart.ProductID, Quantity: *cart.Quantity}, key)
		if err == nil {
			err = ownership(domain.EqualID(result.UserID, user.ID))
		}
	case "UpdateQuantity":
		var result model.Cart
		result, err = s.cartCommands.UpdateCart(ctx, id, cart.ProductID, *cart.Quantity)
		if err == nil {
			err = ownership(domain.EqualID(result.UserID, user.ID))
		}
	case "RemoveFromCart":
		var result model.Cart
		result, err = s.cartCommands.RemoveCart(ctx, id, product.ProductID)
		if err == nil {
			err = ownership(domain.EqualID(result.UserID, user.ID))
		}
	case "CancelOrder":
		var existing model.Order
		existing, err = s.accountReads.Order(ctx, id, cancel.OrderID)
		if err == nil {
			err = ownership(domain.EqualID(existing.UserID, user.ID) && domain.EqualID(existing.ID, cancel.OrderID))
		}
		if err == nil {
			var result model.Order
			result, err = s.accountCommands.CancelOrder(ctx, id, cancel.OrderID)
			if err == nil {
				err = ownership(domain.EqualID(result.UserID, user.ID) && domain.EqualID(result.ID, cancel.OrderID))
			}
		}
		dest = sdui.Destination{Screen: "order", ID: cancel.OrderID}
	case "UpdateProfile":
		var result model.User
		result, err = s.accountCommands.UpdateProfile(ctx, id, profile)
		if err == nil {
			err = ownership(domain.EqualID(result.ID, user.ID))
		}
		dest = sdui.Destination{Screen: "profile"}
	case "RevokeDevice":
		err = s.accountCommands.RevokeDevice(ctx, id, revoke.DeviceKind, revoke.DeviceID)
		dest = sdui.Destination{Screen: "devices"}
	}
	if err != nil {
		return CommandResponse{}, err
	}
	// Acknowledge the successful write without a second business read that could
	// fail and hide a committed mutation. The adapter refreshes separately.
	return CommandResponse{SchemaVersion: 1, Command: command.Command, Outcome: "applied", Refresh: dest}, nil
}
