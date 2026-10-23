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
	return get[Page[Product]](c, ctx, id, path, q.Values())
}

func (c *Client) Orders(ctx context.Context, id Identity, q OrderQuery) (Page[Order], error) {
	if err := q.Validate(); err != nil {
		return Page[Order]{}, err
	}
	v := url.Values{"page": {strconv.Itoa(q.Page)}, "size": {strconv.Itoa(q.Size)}}
	if q.Status != "" {
		v.Set("status", q.Status)
	}
	return get[Page[Order]](c, ctx, id, "/api/v1/orders", v)
}
