package application

import (
	"context"
	"net/url"

	"shopkmp/backend-sdui/internal/application/model"
	"shopkmp/backend-sdui/internal/sdui"
)

// Ports are small, consumer-owned contracts. Implementations own I/O; builders
// below receive typed data and only produce the existing SDUI contract.
type Auth interface {
	User(context.Context, model.Identity) (model.User, error)
}

type CatalogReads interface {
	Categories(context.Context, model.Identity) ([]model.Category, error)
	Popular(context.Context, model.Identity) ([]model.Product, error)
	Products(context.Context, model.Identity, model.CatalogQuery) (model.Page[model.Product], error)
	Product(context.Context, model.Identity, string) (model.Product, error)
}
type AccountReads interface {
	Cart(context.Context, model.Identity) (model.Cart, error)
	Addresses(context.Context, model.Identity) ([]model.Address, error)
	Session(context.Context, model.Identity, string) (model.Session, error)
	Orders(context.Context, model.Identity, model.OrderQuery) (model.Page[model.Order], error)
	Order(context.Context, model.Identity, string) (model.Order, error)
	Devices(context.Context, model.Identity, string) ([]model.Device, error)
}
type CartCommands interface {
	AddCart(context.Context, model.Identity, model.AddCartInput, string) (model.Cart, error)
	UpdateCart(context.Context, model.Identity, string, int) (model.Cart, error)
	RemoveCart(context.Context, model.Identity, string) (model.Cart, error)
}
type AccountCommands interface {
	CancelOrder(context.Context, model.Identity, string) (model.Order, error)
	UpdateProfile(context.Context, model.Identity, model.ProfileInput) (model.User, error)
	RevokeDevice(context.Context, model.Identity, string, string) error
}

type Backend interface {
	Auth
	CatalogReads
	AccountReads
	CartCommands
	AccountCommands
}

// Application owns request-scoped composition and command orchestration. It has
// no ResponseWriter, HTTP request, routing or serialization responsibility.
type Application struct {
	auth            Auth
	catalogReads    CatalogReads
	accountReads    AccountReads
	cartCommands    CartCommands
	accountCommands AccountCommands
}

func New(auth Auth, catalog CatalogReads, account AccountReads, cart CartCommands, commands AccountCommands) *Application {
	return &Application{auth: auth, catalogReads: catalog, accountReads: account, cartCommands: cart, accountCommands: commands}
}

func (s *Application) authenticate(ctx context.Context, id model.Identity) (model.User, error) {
	u, err := s.auth.User(ctx, id)
	if err != nil {
		return u, err
	}
	if !model.ValidID(u.ID) {
		return u, &model.Failure{Kind: "invalid_user"}
	}
	return u, nil
}

func (s *Application) Screen(ctx context.Context, id model.Identity, name, resource string, q url.Values) (sdui.Screen, error) {
	var user *model.User
	if id.Bearer == "" {
		if name != "home" && name != "catalog" && name != "product" {
			return sdui.Screen{}, &Error{Unauthorized, "BFF_UNAUTHORIZED", "Sign in to view this screen"}
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
