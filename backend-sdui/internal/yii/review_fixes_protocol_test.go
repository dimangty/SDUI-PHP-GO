package yii

import (
	"context"
	"fmt"
	"net/http"
	"sync/atomic"
	"testing"
)

func TestReviewFixR2TypedEndpointsRejectIncompleteSuccess(t *testing.T) {
	for _, body := range []string{`{}`, `{"userId":"u"}`, `{"id":"u","fullName":"Buyer","phone":"+79991234567"}`, `{"items":[],"totalAmount":"0.00"}`, `{"items":null,"totalAmount":"0.00"}`} {
		for _, mutation := range []bool{false, true} {
			t.Run(fmt.Sprintf("%v/%s", mutation, body), func(t *testing.T) {
				var calls atomic.Int32
				c := clientFor(t, func(w http.ResponseWriter, r *http.Request) {
					calls.Add(1)
					fmt.Fprint(w, body)
				}, 4096)
				var err error
				if mutation {
					_, err = c.AddCart(context.Background(), Identity{}, AddCartInput{ProductID: productID, Quantity: 1}, "key")
				} else {
					_, err = c.Cart(context.Background(), Identity{})
				}
				if err == nil || calls.Load() != 1 {
					t.Fatalf("incomplete success accepted/retried: %v calls=%d", err, calls.Load())
				}
			})
		}
	}
}
