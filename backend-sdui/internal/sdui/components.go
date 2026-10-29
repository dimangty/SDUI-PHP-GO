package sdui

import "shopkmp/backend-sdui/internal/domain"

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
