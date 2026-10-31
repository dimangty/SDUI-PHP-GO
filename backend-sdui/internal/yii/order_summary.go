package yii

import (
	"context"
	"net/url"
	"strconv"

	"shopkmp/backend-sdui/internal/application/model"
)

const orderSummaryProjection = "sdui-order-summary-v1"

// Separate private wire types preserve full-order requiredness and nullability.
type orderSummaryWire struct {
	ID              string `json:"id"`
	Number          int    `json:"number"`
	UserID          string `json:"userId"`
	Status          string `json:"status"`
	TotalAmount     Money  `json:"totalAmount"`
	DeliveryAddress string `json:"deliveryAddress"`
	PaymentMethod   string `json:"paymentMethod"`
	CreatedAt       string `json:"createdAt"`
}

type orderSummaryPageWire struct {
	Projection string             `json:"projection"`
	Items      []orderSummaryWire `json:"items"`
	Page       int                `json:"page"`
	Size       int                `json:"size"`
	TotalPages int                `json:"totalPages"`
	TotalItems int                `json:"totalItems"`
}

func (c *Client) OrderSummaries(ctx context.Context, id Identity, q OrderQuery) (model.Page[model.OrderSummary], error) {
	if err := q.Validate(); err != nil {
		return model.Page[model.OrderSummary]{}, err
	}
	v := url.Values{"page": {strconv.Itoa(q.Page)}, "size": {strconv.Itoa(q.Size)}, "projection": {orderSummaryProjection}}
	if q.Status != "" {
		v.Set("status", q.Status)
	}
	w, err := get[orderSummaryPageWire](c, ctx, id, "/api/v1/orders", v)
	if err != nil {
		return model.Page[model.OrderSummary]{}, err
	}
	out := model.Page[model.OrderSummary]{Page: w.Page, Size: w.Size, TotalPages: w.TotalPages, TotalItems: w.TotalItems, Items: make([]model.OrderSummary, 0, len(w.Items))}
	for _, o := range w.Items {
		out.Items = append(out.Items, model.OrderSummary{ID: o.ID, Number: o.Number, UserID: o.UserID, Status: o.Status, TotalAmount: o.TotalAmount, DeliveryAddress: o.DeliveryAddress, PaymentMethod: o.PaymentMethod, CreatedAt: o.CreatedAt})
	}
	return out, nil
}
