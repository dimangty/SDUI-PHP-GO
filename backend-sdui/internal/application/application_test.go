package application

import (
	"context"
	"encoding/json"
	"errors"
	"net/url"
	"shopkmp/backend-sdui/internal/application/model"
	"testing"
)

const unitUserID = "11111111-1111-4111-8111-111111111111"
const unitProductID = "22222222-2222-4222-8222-222222222222"

type authFunc func(context.Context, model.Identity) (model.User, error)

func (f authFunc) User(c context.Context, id model.Identity) (model.User, error) { return f(c, id) }

type accountStub struct {
	AccountReads
	addresses []model.Address
}

func (s accountStub) Addresses(context.Context, model.Identity) ([]model.Address, error) {
	return s.addresses, nil
}

type cartWriter struct {
	CartCommands
	add func(context.Context, model.Identity, model.AddCartInput, string) (model.Cart, error)
}

func (s cartWriter) AddCart(c context.Context, id model.Identity, in model.AddCartInput, key string) (model.Cart, error) {
	return s.add(c, id, in, key)
}

func TestApplicationProfileWithInMemoryPorts(t *testing.T) {
	auth := authFunc(func(_ context.Context, id model.Identity) (model.User, error) {
		if id.Bearer != "valid" {
			t.Fatal("lost bearer")
		}
		return model.User{ID: unitUserID, FullName: "Buyer", Phone: "+79991234567"}, nil
	})
	app := New(auth, nil, accountStub{addresses: []model.Address{}}, nil, nil)
	_, err := app.Screen(context.Background(), model.Identity{}, "profile", "", url.Values{})
	var appErr *Error
	if !errors.As(err, &appErr) || appErr.Kind != Unauthorized {
		t.Fatalf("anonymous protected screen: %v", err)
	}
	doc, err := app.Screen(context.Background(), model.Identity{Bearer: "valid"}, "profile", "", url.Values{})
	if err != nil {
		t.Fatal(err)
	}
	if !doc.State.Authenticated || doc.Metadata.AccountID != unitUserID || doc.Validate() != nil {
		t.Fatal("in-memory profile lost authenticated owner")
	}
}

func TestApplicationCommandValidationAuthenticationAndOwnership(t *testing.T) {
	reads, writes := 0, 0
	owner := unitUserID
	auth := authFunc(func(context.Context, model.Identity) (model.User, error) {
		reads++
		return model.User{ID: unitUserID}, nil
	})
	writer := cartWriter{add: func(_ context.Context, id model.Identity, in model.AddCartInput, key string) (model.Cart, error) {
		writes++
		if reads != writes || id.Bearer != "valid" || key != "key" || in.ProductID != unitProductID || in.Quantity != 2 {
			t.Fatal("command validation/authentication/arguments drift")
		}
		return model.Cart{UserID: owner}, nil
	}}
	app := New(auth, nil, nil, writer, nil)
	id := model.Identity{Bearer: "valid"}
	in := CommandRequest{Command: "AddToCart", Input: json.RawMessage(`{"productId":"` + unitProductID + `","quantity":2}`)}
	if _, err := app.Execute(context.Background(), id, in, ""); err == nil || reads != 0 || writes != 0 {
		t.Fatal("invalid command performed I/O")
	}
	out, err := app.Execute(context.Background(), id, in, "key")
	if err != nil || out.Outcome != "applied" || out.Refresh.Screen != "cart" || reads != 1 || writes != 1 {
		t.Fatalf("command result %+v %v reads=%d writes=%d", out, err, reads, writes)
	}
	owner = unitProductID
	_, err = app.Execute(context.Background(), id, in, "key")
	var appErr *Error
	if !errors.As(err, &appErr) || appErr.Kind != AccountMismatch || reads != 2 || writes != 2 {
		t.Fatalf("foreign command result accepted: %v", err)
	}
}
