package sdui

import "shopkmp/backend-sdui/internal/domain"

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
