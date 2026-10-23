// Package sdui defines the finite, versioned wire contract. No wire value can
// supply code, an HTTP method, an endpoint, or an external media URL.
package sdui

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"reflect"
	"regexp"
	"strings"
	"unicode/utf8"

	"shopkmp/backend-sdui/internal/domain"
)

const (
	MaxBody               = 1 << 20
	MaxNodes              = 500
	MaxDepth              = 16
	MaxText               = 8192
	MaxActions            = 500
	MaxDescriptionPreview = 256
	MaxCategoryPageSize   = 50
	MaxCartPageSize       = 100
)

type Screen struct {
	SchemaVersion int      `json:"schemaVersion"`
	ScreenID      string   `json:"screenId"`
	Revision      string   `json:"revision"`
	Components    []Node   `json:"components"`
	Actions       []Action `json:"actions"`
	State         State    `json:"state"`
	Metadata      Metadata `json:"metadata"`
}
type State struct {
	Authenticated  bool                  `json:"authenticated"`
	Fields         map[string]string     `json:"fields"`
	CartItemsCount int                   `json:"cartItemsCount"`
	Pagination     *Pagination           `json:"pagination,omitempty"`
	Collections    map[string]Pagination `json:"collections,omitempty"`
}
type Pagination struct {
	Page       int `json:"page"`
	Size       int `json:"size"`
	TotalPages int `json:"totalPages"`
	TotalItems int `json:"totalItems"`
}
type Metadata struct {
	Title     string   `json:"title"`
	Locale    string   `json:"locale"`
	AccountID string   `json:"accountId,omitempty"`
	Partial   bool     `json:"partial"`
	Warnings  []string `json:"warnings,omitempty"`
}
type Node struct {
	ID       string `json:"id"`
	Type     string `json:"type"`
	Props    Props  `json:"props"`
	Children []Node `json:"children,omitempty"`
}

// Sealed props: builders cannot accidentally publish an upstream/raw JSON blob.
type Props interface{ sduiProps() }

func N(id, kind string, props Props, children ...Node) Node {
	return Node{ID: id, Type: kind, Props: props, Children: children}
}

type TextProps struct {
	Text  string `json:"text"`
	Style string `json:"style"`
}
type ImageProps struct {
	Source      string `json:"source"`
	Description string `json:"description"`
	Aspect      string `json:"aspect"`
}
type IconProps struct {
	Name        string `json:"name"`
	Description string `json:"description"`
}
type LayoutProps struct {
	Spacing string `json:"spacing"`
	Padding string `json:"padding"`
	Align   string `json:"align"`
}
type ButtonProps struct {
	Label    string `json:"label"`
	ActionID string `json:"actionId"`
	Style    string `json:"style"`
	Enabled  bool   `json:"enabled"`
}
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}
type FieldProps struct {
	FieldID      string   `json:"fieldId"`
	Label        string   `json:"label"`
	Input        string   `json:"input"`
	Required     bool     `json:"required"`
	ReadOnly     bool     `json:"readOnly"`
	MaxLength    int      `json:"maxLength"`
	MaxUTF8Bytes int      `json:"maxUtf8Bytes,omitempty"`
	Options      []Option `json:"options,omitempty"`
	ChangeAction string   `json:"changeAction"`
}
type ProductProps struct {
	ProductID            string        `json:"productId"`
	Name                 string        `json:"name"`
	Description          string        `json:"description"`
	DescriptionTruncated bool          `json:"descriptionTruncated"`
	Price                domain.Money  `json:"price"`
	DiscountPrice        *domain.Money `json:"discountPrice"`
	Image                *string       `json:"image"`
	Stock                int           `json:"stock"`
	Rating               *float64      `json:"rating"`
	ReviewsCount         int           `json:"reviewsCount"`
	OpenAction           string        `json:"openAction"`
	AddAction            string        `json:"addAction,omitempty"`
}
type GridProps struct {
	Columns int `json:"columns"`
}
type CategoryOption struct {
	ID       string  `json:"id"`
	Label    string  `json:"label"`
	Image    *string `json:"image"`
	ActionID string  `json:"actionId"`
	Selected bool    `json:"selected"`
}
type CategoriesProps struct {
	Items []CategoryOption `json:"items"`
}
type PriceProps struct {
	Amount   domain.Money `json:"amount"`
	Currency string       `json:"currency"`
}
type StepperProps struct {
	FieldID  string `json:"fieldId"`
	Value    int    `json:"value"`
	Min      int    `json:"min"`
	Max      int    `json:"max"`
	ActionID string `json:"actionId"`
	Enabled  bool   `json:"enabled"`
}
type CartItemProps struct {
	ProductID      string       `json:"productId"`
	Name           string       `json:"name"`
	Quantity       int          `json:"quantity"`
	Price          domain.Money `json:"price"`
	Image          *string      `json:"image"`
	Available      bool         `json:"available"`
	Stock          int          `json:"stock"`
	OpenAction     string       `json:"openAction"`
	QuantityAction string       `json:"quantityAction"`
	RemoveAction   string       `json:"removeAction"`
}
type OrderProps struct {
	OrderID         string       `json:"orderId"`
	Number          int          `json:"number"`
	Status          string       `json:"status"`
	Total           domain.Money `json:"total"`
	DeliveryAddress string       `json:"deliveryAddress"`
	PaymentMethod   string       `json:"paymentMethod"`
	CreatedAt       string       `json:"createdAt"`
	OpenAction      string       `json:"openAction"`
}
type ProfileProps struct {
	FullName string  `json:"fullName"`
	Email    string  `json:"email"`
	Phone    string  `json:"phone"`
	Avatar   *string `json:"avatar"`
}
type StatusProps struct {
	Title       string `json:"title"`
	Message     string `json:"message"`
	RetryAction string `json:"retryAction,omitempty"`
}
type SpacerProps struct {
	Size string `json:"size"`
}
type DividerProps struct{}
type FormProps struct {
	FormID       string `json:"formId"`
	Title        string `json:"title"`
	SubmitAction string `json:"submitAction"`
}

func (TextProps) sduiProps()       {}
func (ImageProps) sduiProps()      {}
func (IconProps) sduiProps()       {}
func (LayoutProps) sduiProps()     {}
func (ButtonProps) sduiProps()     {}
func (FieldProps) sduiProps()      {}
func (ProductProps) sduiProps()    {}
func (GridProps) sduiProps()       {}
func (CategoriesProps) sduiProps() {}
func (PriceProps) sduiProps()      {}
func (StepperProps) sduiProps()    {}
func (CartItemProps) sduiProps()   {}
func (OrderProps) sduiProps()      {}
func (ProfileProps) sduiProps()    {}
func (StatusProps) sduiProps()     {}
func (SpacerProps) sduiProps()     {}
func (DividerProps) sduiProps()    {}
func (FormProps) sduiProps()       {}

type Destination struct {
	Screen     string               `json:"screen"`
	ID         string               `json:"id,omitempty"`
	Catalog    *domain.CatalogQuery `json:"catalog,omitempty"`
	Orders     *domain.OrderQuery   `json:"orders,omitempty"`
	Categories *CollectionQuery     `json:"categories,omitempty"`
	Cart       *CollectionQuery     `json:"cart,omitempty"`
}

// CollectionQuery is presentation-only. Never forward it to business endpoints
// that return complete collections (categories/cart); page their bounded DTOs.
type CollectionQuery struct {
	Page int `json:"page"`
	Size int `json:"size"`
}

func (q CollectionQuery) Validate(maxSize int) error {
	if q.Page < 0 || q.Page > 1000000 || q.Size < 1 || q.Size > maxSize {
		return fmt.Errorf("invalid collection page")
	}
	return nil
}

type Confirmation struct {
	Title        string `json:"title"`
	Message      string `json:"message"`
	ConfirmLabel string `json:"confirmLabel"`
}
type Action struct {
	ID            string        `json:"id"`
	Type          string        `json:"type"`
	Destination   *Destination  `json:"destination,omitempty"`
	ProductID     string        `json:"productId,omitempty"`
	OrderID       string        `json:"orderId,omitempty"`
	DeviceID      string        `json:"deviceId,omitempty"`
	DeviceKind    string        `json:"deviceKind,omitempty"`
	FormID        string        `json:"formId,omitempty"`
	FieldID       string        `json:"fieldId,omitempty"`
	QuantityField string        `json:"quantityField,omitempty"`
	Confirmation  *Confirmation `json:"confirmation,omitempty"`
}

var safeID = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$`)
var mediaPattern = regexp.MustCompile(`^/(images/products|icons|uploads/users)/[A-Za-z0-9/_.-]+$`)

func Media(s string) *string {
	if !mediaPattern.MatchString(s) || strings.Contains(s, "..") || strings.Contains(s, "//") {
		return nil
	}
	return &s
}
func AllowedField(s string) bool {
	switch s {
	case "catalog.q", "catalog.categoryId", "catalog.sort", "catalog.priceMin", "catalog.priceMax", "catalog.page", "catalog.size", "orders.status", "orders.page", "orders.size", "product.quantity", "checkout.city", "checkout.street", "checkout.house", "checkout.apartment", "checkout.paymentMethod", "profile.fullName", "profile.phone":
		return true
	}
	return false
}
func oneOf(value string, values ...string) bool {
	for _, v := range values {
		if v == value {
			return true
		}
	}
	return false
}

func (s *Screen) Encode() ([]byte, error) {
	if err := s.Validate(); err != nil {
		return nil, err
	}
	// No clock, correlation IDs or token data participate in content revisions.
	s.Revision = ""
	raw, err := json.Marshal(s)
	if err != nil {
		return nil, err
	}
	sum := sha256.Sum256(raw)
	s.Revision = hex.EncodeToString(sum[:])
	raw, err = json.Marshal(s)
	if len(raw) > MaxBody {
		return nil, fmt.Errorf("document exceeds %d bytes", MaxBody)
	}
	return raw, err
}
func (s Screen) Validate() error {
	if s.SchemaVersion != 1 || !oneOf(s.ScreenID, "home", "catalog", "product", "cart", "orders", "order", "profile", "devices") || s.Components == nil || s.Actions == nil || s.State.Fields == nil || len(s.Actions) > MaxActions {
		return fmt.Errorf("invalid screen envelope")
	}
	if !oneOf(s.Metadata.Locale, "ru-RU") || s.Metadata.Title == "" || s.State.Authenticated != (s.Metadata.AccountID != "") || s.Metadata.AccountID != "" && !domain.ValidID(s.Metadata.AccountID) {
		return fmt.Errorf("invalid metadata")
	}
	for k := range s.State.Fields {
		if !AllowedField(k) {
			return fmt.Errorf("invalid field %s", k)
		}
	}
	if s.State.CartItemsCount < 0 {
		return fmt.Errorf("negative cart count")
	}
	if p := s.State.Pagination; p != nil && (p.Page < 0 || p.Size < 1 || p.Size > 100 || p.TotalPages < 0 || p.TotalItems < 0) {
		return fmt.Errorf("invalid pagination")
	}
	for key, p := range s.State.Collections {
		maxSize := MaxCartPageSize
		switch key {
		case "categories":
			if !oneOf(s.ScreenID, "home", "catalog") {
				return fmt.Errorf("categories on wrong screen")
			}
			maxSize = MaxCategoryPageSize
		case "cart":
			if s.ScreenID != "cart" {
				return fmt.Errorf("cart collection on wrong screen")
			}
		default:
			return fmt.Errorf("unknown collection")
		}
		if err := (CollectionQuery{Page: p.Page, Size: p.Size}).Validate(maxSize); err != nil {
			return err
		}
		if p.TotalPages < 0 || p.TotalItems < 0 || p.TotalPages != (p.TotalItems+p.Size-1)/p.Size || (p.TotalPages == 0 && p.Page != 0) || (p.TotalPages > 0 && p.Page >= p.TotalPages) {
			return fmt.Errorf("inconsistent collection pagination")
		}
	}
	actions := map[string]bool{}
	for _, a := range s.Actions {
		if !safeID.MatchString(a.ID) || actions[a.ID] {
			return fmt.Errorf("duplicate/invalid action ID")
		}
		actions[a.ID] = true
		if err := a.Validate(); err != nil {
			return err
		}
		if a.Type == "UpdateField" {
			if _, ok := s.State.Fields[a.FieldID]; !ok {
				return fmt.Errorf("missing controlled field state")
			}
		}
	}
	ids := map[string]bool{}
	count := 0
	var visit func(Node, int) error
	visit = func(n Node, depth int) error {
		count++
		if depth > MaxDepth || count > MaxNodes || !safeID.MatchString(n.ID) || ids[n.ID] {
			return fmt.Errorf("node depth/count/ID limit")
		}
		ids[n.ID] = true
		if err := validateProps(n); err != nil {
			return err
		}
		if p, ok := n.Props.(FieldProps); ok {
			value, present := s.State.Fields[p.FieldID]
			if !present || utf8.RuneCountInString(value) > p.MaxLength || p.MaxUTF8Bytes > 0 && len(value) > p.MaxUTF8Bytes {
				return fmt.Errorf("invalid controlled field state")
			}
		}
		if p, ok := n.Props.(StepperProps); ok {
			if _, present := s.State.Fields[p.FieldID]; !present {
				return fmt.Errorf("missing stepper state")
			}
		}
		if len(n.Children) > 0 && !oneOf(n.Type, "Column", "Row", "Box", "LazyColumn", "LazyRow", "ProductGrid", "Form") {
			return fmt.Errorf("leaf has children")
		}
		for _, ref := range references(n.Props) {
			if ref != "" && !actions[ref] {
				return fmt.Errorf("missing action %s", ref)
			}
		}
		for _, c := range n.Children {
			if err := visit(c, depth+1); err != nil {
				return err
			}
		}
		return nil
	}
	for _, n := range s.Components {
		if err := visit(n, 1); err != nil {
			return err
		}
	}
	return boundedStrings(reflect.ValueOf(s), 0)
}
func boundedStrings(v reflect.Value, depth int) error {
	if depth > 128 {
		return fmt.Errorf("value too deep")
	}
	if !v.IsValid() {
		return nil
	}
	switch v.Kind() {
	case reflect.Pointer, reflect.Interface:
		if !v.IsNil() {
			return boundedStrings(v.Elem(), depth+1)
		}
	case reflect.String:
		if !utf8.ValidString(v.String()) || utf8.RuneCountInString(v.String()) > MaxText {
			return fmt.Errorf("text limit")
		}
	case reflect.Struct:
		for i := 0; i < v.NumField(); i++ {
			if err := boundedStrings(v.Field(i), depth+1); err != nil {
				return err
			}
		}
	case reflect.Slice, reflect.Array:
		for i := 0; i < v.Len(); i++ {
			if err := boundedStrings(v.Index(i), depth+1); err != nil {
				return err
			}
		}
	case reflect.Map:
		iter := v.MapRange()
		for iter.Next() {
			if err := boundedStrings(iter.Key(), depth+1); err != nil {
				return err
			}
			if err := boundedStrings(iter.Value(), depth+1); err != nil {
				return err
			}
		}
	}
	return nil
}
func validateProps(n Node) error {
	valid := false
	switch p := n.Props.(type) {
	case TextProps:
		valid = n.Type == "Text" && oneOf(p.Style, "Body", "Secondary", "Title", "SectionTitle", "ScreenTitle", "HeroTitle", "Price", "Error", "Avatar")
	case ImageProps:
		valid = n.Type == "Image" && Media(p.Source) != nil && oneOf(p.Aspect, "Product", "Thumbnail", "Avatar")
	case IconProps:
		valid = n.Type == "Icon" && oneOf(p.Name, "Search", "Cart", "Profile", "Back", "Check", "Device", "Warning")
	case LayoutProps:
		valid = oneOf(n.Type, "Column", "Row", "Box", "LazyColumn", "LazyRow") && oneOf(p.Spacing, "none", "xs", "s", "m", "l", "xl") && oneOf(p.Padding, "none", "xs", "s", "m", "l", "xl") && oneOf(p.Align, "Start", "Center", "End")
	case ButtonProps:
		valid = n.Type == "Button" && p.ActionID != "" && oneOf(p.Style, "Primary", "Secondary", "Text")
	case FieldProps:
		valid = oneOf(n.Type, "TextField", "SearchField", "FormField") && AllowedField(p.FieldID) && oneOf(p.Input, "Text", "Number", "Phone", "Select") && p.MaxLength >= 1 && p.MaxLength <= 2000 && p.ChangeAction != "" && len(p.Options) <= 500
		valid = valid && ((p.FieldID == "catalog.q" && p.MaxUTF8Bytes == 200) || (p.FieldID != "catalog.q" && p.MaxUTF8Bytes == 0))
	case ProductProps:
		valid = n.Type == "ProductCard" && domain.ValidID(p.ProductID) && domain.ValidMoney(string(p.Price)) && (p.DiscountPrice == nil || domain.ValidMoney(string(*p.DiscountPrice))) && (p.Image == nil || Media(*p.Image) != nil) && p.OpenAction != "" && p.Stock >= 0 && p.ReviewsCount >= 0 && (p.Rating == nil || *p.Rating >= 1 && *p.Rating <= 5)
		valid = valid && utf8.RuneCountInString(p.Description) <= MaxDescriptionPreview
	case GridProps:
		valid = n.Type == "ProductGrid" && p.Columns == 2
	case CategoriesProps:
		valid = n.Type == "CategoryList" && len(p.Items) <= MaxCategoryPageSize
		for _, c := range p.Items {
			valid = valid && domain.ValidID(c.ID) && c.ActionID != "" && (c.Image == nil || Media(*c.Image) != nil)
		}
	case PriceProps:
		valid = n.Type == "Price" && domain.ValidMoney(string(p.Amount)) && p.Currency == "RUB"
	case StepperProps:
		valid = n.Type == "QuantityStepper" && AllowedField(p.FieldID) && p.Min >= 0 && p.Max >= p.Min && p.Max <= 1000000 && p.Value >= p.Min && p.Value <= p.Max && p.ActionID != ""
	case CartItemProps:
		valid = n.Type == "CartItem" && domain.ValidID(p.ProductID) && domain.ValidMoney(string(p.Price)) && p.Quantity >= 1 && p.Quantity <= 1000000 && p.Stock >= 0 && (p.Image == nil || Media(*p.Image) != nil) && p.OpenAction != "" && p.QuantityAction != "" && p.RemoveAction != ""
	case OrderProps:
		valid = n.Type == "OrderCard" && domain.ValidID(p.OrderID) && domain.ValidMoney(string(p.Total)) && p.Number >= 1 && validStatus(p.Status) && oneOf(p.PaymentMethod, "CARD", "SBP", "ON_DELIVERY") && p.OpenAction != ""
	case ProfileProps:
		valid = n.Type == "ProfileHeader" && (p.Avatar == nil || Media(*p.Avatar) != nil)
	case StatusProps:
		valid = oneOf(n.Type, "Empty", "Loading", "Error")
	case SpacerProps:
		valid = n.Type == "Spacer" && oneOf(p.Size, "xs", "s", "m", "l", "xl")
	case DividerProps:
		valid = n.Type == "Divider"
	case FormProps:
		valid = n.Type == "Form" && oneOf(p.FormID, "catalog", "orders", "checkout", "profile") && p.SubmitAction != ""
	}
	if !valid {
		return fmt.Errorf("invalid %s props", n.Type)
	}
	return nil
}
func validStatus(s string) bool {
	return oneOf(s, "CREATED", "PAID", "ASSEMBLING", "DELIVERING", "COMPLETED", "CANCELLED")
}
func references(p Props) []string {
	switch v := p.(type) {
	case ButtonProps:
		return []string{v.ActionID}
	case FieldProps:
		return []string{v.ChangeAction}
	case ProductProps:
		return []string{v.OpenAction, v.AddAction}
	case CategoriesProps:
		r := []string{}
		for _, c := range v.Items {
			r = append(r, c.ActionID)
		}
		return r
	case StepperProps:
		return []string{v.ActionID}
	case CartItemProps:
		return []string{v.OpenAction, v.QuantityAction, v.RemoveAction}
	case OrderProps:
		return []string{v.OpenAction}
	case StatusProps:
		return []string{v.RetryAction}
	case FormProps:
		return []string{v.SubmitAction}
	}
	return nil
}

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
