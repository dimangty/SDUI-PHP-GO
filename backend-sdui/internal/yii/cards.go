package yii

import (
	"context"
	"net/url"
	"unicode/utf8"
)

const cardProjection = "sdui-card-v1"

// cardWire is the opt-in business read projection, never a public SDUI node.
// The extra raw code point lets Go alone retain its existing preview algorithm.
type cardWire struct {
	ID                  string   `json:"id"`
	CategoryID          string   `json:"categoryId"`
	Name                string   `json:"name"`
	Price               Money    `json:"price"`
	DiscountPrice       *Money   `json:"discountPrice"`
	Stock               int      `json:"stock"`
	Rating              *float64 `json:"rating"`
	IsActive            bool     `json:"isActive"`
	ReviewsCount        int      `json:"reviewsCount"`
	ImageURL            *string  `json:"imageUrl"`
	DescriptionSnippet  string   `json:"descriptionSnippet"`
	DescriptionComplete bool     `json:"descriptionComplete"`
}

type cardPageWire struct {
	Projection string     `json:"projection"`
	Items      []cardWire `json:"items"`
	Page       int        `json:"page"`
	Size       int        `json:"size"`
	TotalPages int        `json:"totalPages"`
	TotalItems int        `json:"totalItems"`
}

type popularWire struct {
	Projection string     `json:"projection"`
	Items      []cardWire `json:"items"`
}

func validCards(marker string, cards []cardWire) bool {
	if marker != cardProjection {
		return false
	}
	for _, c := range cards {
		n := utf8.RuneCountInString(c.DescriptionSnippet)
		if !utf8.ValidString(c.DescriptionSnippet) || n > 257 || !c.DescriptionComplete && n != 257 {
			return false
		}
	}
	return true
}

// The decoder validates the projection inside the logical call's telemetry
// scope. Conversion receives only validated cards and performs no I/O.
func convertCards(cards []cardWire) []Product {
	products := make([]Product, 0, len(cards))
	for _, c := range cards {
		p := Product{ID: c.ID, CategoryID: c.CategoryID, Name: c.Name, Description: c.DescriptionSnippet, Price: c.Price, DiscountPrice: c.DiscountPrice, Stock: c.Stock, Rating: c.Rating, IsActive: c.IsActive, ReviewsCount: c.ReviewsCount, ImageURLs: []string{}}
		if c.ImageURL != nil {
			p.ImageURLs = append(p.ImageURLs, *c.ImageURL)
		}
		products = append(products, p)
	}
	return products
}

func (c *Client) Popular(ctx context.Context, id Identity) ([]Product, error) {
	w, err := get[popularWire](c, ctx, id, "/api/v1/products/popular", url.Values{"limit": {"10"}, "projection": {cardProjection}})
	if err != nil {
		return nil, err
	}
	return convertCards(w.Items), nil
}
