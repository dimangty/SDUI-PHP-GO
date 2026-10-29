package sdui

import (
	"fmt"
	"shopkmp/backend-sdui/internal/domain"
)

func (d Destination) Validate() error {
	if !oneOf(d.Screen, "home", "catalog", "product", "cart", "orders", "order", "profile", "devices") {
		return fmt.Errorf("invalid destination")
	}
	if oneOf(d.Screen, "product", "order") {
		if !domain.ValidID(d.ID) {
			return fmt.Errorf("missing destination ID")
		}
	} else if d.ID != "" {
		return fmt.Errorf("unexpected destination ID")
	}
	if d.Catalog != nil {
		if d.Screen != "catalog" {
			return fmt.Errorf("catalog query on wrong screen")
		}
		if err := d.Catalog.Validate(); err != nil {
			return err
		}
	}
	if d.Orders != nil {
		if d.Screen != "orders" {
			return fmt.Errorf("order query on wrong screen")
		}
		if err := d.Orders.Validate(); err != nil {
			return err
		}
	}
	if d.Categories != nil {
		if !oneOf(d.Screen, "home", "catalog") {
			return fmt.Errorf("category continuation on wrong screen")
		}
		if err := d.Categories.Validate(MaxCategoryPageSize); err != nil {
			return err
		}
	}
	if d.Cart != nil {
		if d.Screen != "cart" {
			return fmt.Errorf("cart continuation on wrong screen")
		}
		if err := d.Cart.Validate(MaxCartPageSize); err != nil {
			return err
		}
	}
	return nil
}
func (a Action) Validate() error {
	// Forbid parameters belonging to another discriminator as well as unknown types.
	copy := a
	copy.ID = ""
	copy.Type = ""
	switch a.Type {
	case "Navigate", "Refresh", "Retry":
		if a.Destination == nil {
			return fmt.Errorf("missing destination")
		}
		if err := a.Destination.Validate(); err != nil {
			return err
		}
		copy.Destination = nil
	case "OpenProduct":
		if !domain.ValidID(a.ProductID) {
			return fmt.Errorf("invalid product action")
		}
		copy.ProductID = ""
	case "OpenOrder":
		if !domain.ValidID(a.OrderID) {
			return fmt.Errorf("invalid order action")
		}
		copy.OrderID = ""
	case "UpdateField":
		if !AllowedField(a.FieldID) {
			return fmt.Errorf("invalid update field")
		}
		copy.FieldID = ""
	case "SubmitForm":
		if !oneOf(a.FormID, "catalog", "orders", "profile") {
			return fmt.Errorf("invalid form")
		}
		copy.FormID = ""
	case "AddToCart":
		if !domain.ValidID(a.ProductID) || (a.QuantityField != "" && a.QuantityField != "product.quantity") {
			return fmt.Errorf("invalid add action")
		}
		copy.ProductID = ""
		copy.QuantityField = ""
	case "RemoveFromCart", "UpdateQuantity":
		if !domain.ValidID(a.ProductID) {
			return fmt.Errorf("invalid cart action")
		}
		copy.ProductID = ""
	case "CancelOrder":
		if !domain.ValidID(a.OrderID) || a.Confirmation == nil {
			return fmt.Errorf("missing order confirmation")
		}
		copy.OrderID = ""
		copy.Confirmation = nil
	case "RevokeDevice":
		if !domain.ValidDevice(a.DeviceID) || !oneOf(a.DeviceKind, "pin", "biometric") || a.Confirmation == nil {
			return fmt.Errorf("invalid device action")
		}
		copy.DeviceID = ""
		copy.DeviceKind = ""
		copy.Confirmation = nil
	case "Checkout":
		if a.FormID != "checkout" {
			return fmt.Errorf("invalid checkout form")
		}
		copy.FormID = ""
	case "EditProfile", "Logout", "ChangePin", "ManageBiometric", "Login":
	default:
		return fmt.Errorf("unsupported action %s", a.Type)
	}
	if copy != (Action{}) {
		return fmt.Errorf("unexpected parameters for %s", a.Type)
	}
	return nil
}
