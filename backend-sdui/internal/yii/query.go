package yii

import (
	"context"
	"errors"
	"net/url"
	"strconv"
	"unicode/utf8"
)

type CatalogQuery struct {
	Query      string `json:"q,omitempty"`
	CategoryID string `json:"categoryId,omitempty"`
	Sort       string `json:"sort,omitempty"`
	PriceMin   string `json:"priceMin,omitempty"`
	PriceMax   string `json:"priceMax,omitempty"`
	Page       int    `json:"page"`
	Size       int    `json:"size"`
}

func (q CatalogQuery) Validate() error {
	if len(q.Query) > 200 || !utf8.ValidString(q.Query) || q.CategoryID != "" && !ValidID(q.CategoryID) || q.Page < 0 || q.Page > 1000000 || q.Size < 1 || q.Size > 100 {
		return errors.New("invalid catalog query")
	}
	if q.Sort != "" && q.Sort != "price_asc" && q.Sort != "price_desc" && q.Sort != "rating" && q.Sort != "new" {
		return errors.New("invalid sort")
	}
	if q.Query != "" && q.Sort != "" {
		return errors.New("search uses relevance, not sort")
	}
	if q.PriceMin != "" && !ValidMoney(q.PriceMin) || q.PriceMax != "" && !ValidMoney(q.PriceMax) {
		return errors.New("invalid price filter")
	}
	if q.PriceMin != "" && q.PriceMax != "" {
		a, _ := strconv.ParseInt(removeDot(q.PriceMin), 10, 64)
		b, _ := strconv.ParseInt(removeDot(q.PriceMax), 10, 64)
		if a > b {
			return errors.New("priceMin exceeds priceMax")
		}
	}
	if q.Query != "" && (q.Page+1)*q.Size > 10000 {
		return errors.New("search window exceeded")
	}
	return nil
}
func removeDot(s string) string { return s[:len(s)-3] + s[len(s)-2:] }
func (q CatalogQuery) Values() url.Values {
	v := url.Values{"page": {strconv.Itoa(q.Page)}, "size": {strconv.Itoa(q.Size)}}
	for k, value := range map[string]string{"q": q.Query, "categoryId": q.CategoryID, "sort": q.Sort, "priceMin": q.PriceMin, "priceMax": q.PriceMax} {
		if value != "" {
			v.Set(k, value)
		}
	}
	return v
}
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

type OrderQuery struct {
	Status string `json:"status,omitempty"`
	Page   int    `json:"page"`
	Size   int    `json:"size"`
}

func (q OrderQuery) Validate() error {
	if q.Page < 0 || q.Page > 1000000 || q.Size < 1 || q.Size > 100 {
		return errors.New("invalid pagination")
	}
	switch q.Status {
	case "", "CREATED", "PAID", "ASSEMBLING", "DELIVERING", "COMPLETED", "CANCELLED":
		return nil
	default:
		return errors.New("invalid order status")
	}
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
