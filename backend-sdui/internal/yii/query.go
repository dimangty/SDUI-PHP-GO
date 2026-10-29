package yii

import (
	"context"
	"net/url"
	"strconv"

	"shopkmp/backend-sdui/internal/domain"
)

type CatalogQuery = domain.CatalogQuery
type OrderQuery = domain.OrderQuery

func (c *Client) Products(ctx context.Context, id Identity, q CatalogQuery) (Page[Product], error) {
	if err := q.Validate(); err != nil {
		return Page[Product]{}, err
	}
	path := "/api/v1/products"
	if q.Query != "" {
		path += "/search"
	}
	v := q.Values()
	v.Set("projection", cardProjection)
	w, err := get[cardPageWire](c, ctx, id, path, v)
	if err != nil {
		return Page[Product]{}, err
	}
	items := convertCards(w.Items)
	return Page[Product]{Items: items, Page: w.Page, Size: w.Size, TotalPages: w.TotalPages, TotalItems: w.TotalItems}, nil
}

func (c *Client) Orders(ctx context.Context, id Identity, q OrderQuery) (Page[Order], error) {
	if err := q.Validate(); err != nil {
		return Page[Order]{}, err
	}
	v := url.Values{"page": {strconv.Itoa(q.Page)}, "size": {strconv.Itoa(q.Size)}}
	if q.Status != "" {
		v.Set("status", q.Status)
	}
	w, err := get[pageWire[orderWire]](c, ctx, id, "/api/v1/orders", v)
	if err != nil {
		return Page[Order]{}, err
	}
	out := Page[Order]{Page: w.Page, Size: w.Size, TotalPages: w.TotalPages, TotalItems: w.TotalItems, Items: make([]Order, 0, len(w.Items))}
	for _, o := range w.Items {
		out.Items = append(out.Items, convertOrder(o))
	}
	return out, nil
}
