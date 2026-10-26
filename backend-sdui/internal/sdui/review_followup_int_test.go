package sdui

import (
	"fmt"
	"math"
	"testing"
)

func TestFollowupN3FinalWireIntBounds(t *testing.T) {
	const id = "abcdefab-cdef-4abc-8def-abcdefabcdef"
	for name, set := range map[string]func(*Screen, int){
		"cart count":  func(s *Screen, v int) { s.State.CartItemsCount = v },
		"page":        func(s *Screen, v int) { s.State.Pagination = &Pagination{Page: v, Size: 20} },
		"total pages": func(s *Screen, v int) { s.State.Pagination = &Pagination{Size: 20, TotalPages: v} },
		"total items": func(s *Screen, v int) { s.State.Pagination = &Pagination{Size: 20, TotalItems: v} },
		"collection total items": func(s *Screen, v int) {
			s.ScreenID = "cart"
			s.State.Collections = map[string]Pagination{"cart": {Size: 1, TotalPages: v, TotalItems: v}}
		},
		"stock": func(s *Screen, v int) {
			s.Actions = []Action{{ID: "open", Type: "OpenProduct", ProductID: id}}
			s.Components = []Node{N("product", "ProductCard", ProductProps{ProductID: id, Price: "0.01", Stock: v, OpenAction: "open"})}
		},
		"reviews": func(s *Screen, v int) {
			s.Actions = []Action{{ID: "open", Type: "OpenProduct", ProductID: id}}
			s.Components = []Node{N("product", "ProductCard", ProductProps{ProductID: id, Price: "0.01", ReviewsCount: v, OpenAction: "open"})}
		},
		"cart stock": func(s *Screen, v int) {
			s.Actions = []Action{{ID: "open", Type: "OpenProduct", ProductID: id}, {ID: "quantity", Type: "UpdateQuantity", ProductID: id}, {ID: "remove", Type: "RemoveFromCart", ProductID: id}}
			s.Components = []Node{N("item", "CartItem", CartItemProps{ProductID: id, Quantity: 1, Price: "0.01", Stock: v, OpenAction: "open", QuantityAction: "quantity", RemoveAction: "remove"})}
		},
		"order number": func(s *Screen, v int) {
			s.Actions = []Action{{ID: "open", Type: "OpenOrder", OrderID: id}}
			s.Components = []Node{N("order", "OrderCard", OrderProps{OrderID: id, Number: v, Status: "CREATED", Total: "0.01", PaymentMethod: "CARD", OpenAction: "open"})}
		},
	} {
		for _, v := range []int{-1, 0, 42, math.MaxInt32, math.MaxInt32 + 1, math.MaxInt} {
			t.Run(fmt.Sprintf("%s/%d", name, v), func(t *testing.T) {
				s := document()
				set(&s, v)
				_, err := s.Encode()
				want := v >= 0 && v <= math.MaxInt32 && (name != "order number" || v > 0)
				if (err == nil) != want {
					t.Fatalf("wire Int value=%d accepted=%v want=%v: %v", v, err == nil, want, err)
				}
			})
		}
	}
}
