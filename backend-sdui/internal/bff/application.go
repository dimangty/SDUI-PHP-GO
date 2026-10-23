package bff

import (
	"context"
	"net/url"

	"shopkmp/backend-sdui/internal/sdui"
	"shopkmp/backend-sdui/internal/yii"
)

// Ports are owned by the application that consumes them. The adapter keeps its
// typed HTTP DTOs; no persistence abstraction or alternate business engine exists.
type Authenticator interface {
	User(context.Context, yii.Identity) (yii.User, error)
}

type ScreenReader interface {
	Categories(context.Context, yii.Identity) ([]yii.Category, error)
	Popular(context.Context, yii.Identity) ([]yii.Product, error)
	Products(context.Context, yii.Identity, yii.CatalogQuery) (yii.Page[yii.Product], error)
	Product(context.Context, yii.Identity, string) (yii.Product, error)
	Cart(context.Context, yii.Identity) (yii.Cart, error)
	Addresses(context.Context, yii.Identity) ([]yii.Address, error)
	Session(context.Context, yii.Identity, string) (yii.Session, error)
	Orders(context.Context, yii.Identity, yii.OrderQuery) (yii.Page[yii.Order], error)
	Order(context.Context, yii.Identity, string) (yii.Order, error)
	Devices(context.Context, yii.Identity, string) ([]yii.Device, error)
}

type CommandWriter interface {
	AddCart(context.Context, yii.Identity, yii.AddCartInput, string) (yii.Cart, error)
	UpdateCart(context.Context, yii.Identity, string, int) (yii.Cart, error)
	RemoveCart(context.Context, yii.Identity, string) (yii.Cart, error)
	CancelOrder(context.Context, yii.Identity, string) (yii.Order, error)
	UpdateProfile(context.Context, yii.Identity, yii.ProfileInput) (yii.User, error)
	RevokeDevice(context.Context, yii.Identity, string, string) error
}

type Backend interface {
	Authenticator
	ScreenReader
	CommandWriter
}

// Application owns request-scoped composition and command orchestration. It has
// no ResponseWriter, HTTP request, routing or serialization responsibility.
type Application struct{ up Backend }

func NewApplication(up Backend) *Application { return &Application{up: up} }

func (s *Application) authenticate(ctx context.Context, id yii.Identity) (yii.User, error) {
	u, err := s.up.User(ctx, id)
	if err != nil {
		return u, err
	}
	if !yii.ValidID(u.ID) {
		return u, &yii.TransportError{Kind: "invalid_user"}
	}
	return u, nil
}

func (s *Application) Screen(ctx context.Context, id yii.Identity, name, resource string, q url.Values) (sdui.Screen, error) {
	var user *yii.User
	if id.Bearer == "" {
		if name != "home" && name != "catalog" && name != "product" {
			return sdui.Screen{}, &Error{401, "BFF_UNAUTHORIZED", "Sign in to view this screen"}
		}
	} else {
		u, err := s.authenticate(ctx, id)
		if err != nil {
			return sdui.Screen{}, err
		}
		user = &u
	}
	return s.build(ctx, id, user, name, resource, q)
}
