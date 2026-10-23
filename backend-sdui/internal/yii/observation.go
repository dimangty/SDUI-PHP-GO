package yii

import (
	"context"
	"errors"
	"net/http"
	"strings"
)

// Never put resource IDs or raw URLs in operation labels or logs.
func operation(method, path string) string {
	switch path {
	case "/api/v1/users/me":
		if method == http.MethodPut {
			return "update_profile"
		}
		return "user"
	case "/api/v1/categories":
		return "categories"
	case "/api/v1/products/popular":
		return "popular"
	case "/api/v1/products", "/api/v1/products/search":
		return "products"
	case "/api/v1/cart":
		return "cart"
	case "/api/v1/cart/items":
		return "add_cart"
	case "/api/v1/addresses":
		return "addresses"
	case "/api/v1/auth/session":
		return "session"
	case "/api/v1/orders":
		return "orders"
	case "/api/v1/auth/pin/devices", "/api/v1/auth/biometric/devices":
		return "devices"
	case "/health/ready":
		return "ready"
	}
	switch {
	case strings.HasPrefix(path, "/api/v1/products/"):
		return "product"
	case strings.HasPrefix(path, "/api/v1/cart/items/"):
		if method == http.MethodPatch {
			return "update_cart"
		}
		return "remove_cart"
	case strings.HasPrefix(path, "/api/v1/orders/"):
		if method == http.MethodPost {
			return "cancel_order"
		}
		return "order"
	case strings.HasPrefix(path, "/api/v1/auth/"):
		return "revoke_device"
	default:
		return "other"
	}
}

func errorKind(err error) string {
	if err == nil {
		return ""
	}
	if errors.Is(err, context.DeadlineExceeded) {
		return "timeout"
	}
	if errors.Is(err, context.Canceled) {
		return "canceled"
	}
	var transport *TransportError
	if errors.As(err, &transport) {
		return transport.Kind
	}
	var upstream *UpstreamError
	if errors.As(err, &upstream) {
		return "business"
	}
	return "other"
}
