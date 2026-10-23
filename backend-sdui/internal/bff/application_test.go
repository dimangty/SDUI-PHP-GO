package bff

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"sync/atomic"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/yii"
)

func TestApplicationAuthenticatesScreensWithoutHTTPTransport(t *testing.T) {
	s := httptest.NewServer(http.HandlerFunc(fixtureUpstream))
	defer s.Close()
	up, err := yii.New(yii.Config{Origin: s.URL, Timeout: time.Second})
	if err != nil {
		t.Fatal(err)
	}
	app := NewApplication(up)
	if _, err := app.Screen(context.Background(), yii.Identity{}, "cart", "", url.Values{}); err == nil {
		t.Fatal("protected application screen accepted anonymous identity")
	}
	doc, err := app.Screen(context.Background(), yii.Identity{Bearer: "valid"}, "profile", "", url.Values{})
	if err != nil {
		t.Fatal(err)
	}
	if !doc.State.Authenticated || doc.Metadata.AccountID != uid || doc.Validate() != nil {
		t.Fatal("application failed to authenticate/compose valid profile")
	}
}

func TestApplicationCommandValidatesBeforeAuthenticationAndWritesOnce(t *testing.T) {
	var reads, writes atomic.Int32
	s := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodGet {
			reads.Add(1)
			fixtureUpstream(w, r)
			return
		}
		writes.Add(1)
		if r.Method != "POST" || r.URL.Path != "/api/v1/cart/items" || r.Header.Get("Idempotency-Key") != "app-key" {
			t.Error("wrong application write boundary")
		}
		fmt.Fprint(w, cartJSON)
	}))
	defer s.Close()
	up, err := yii.New(yii.Config{Origin: s.URL, Timeout: time.Second})
	if err != nil {
		t.Fatal(err)
	}
	app := NewApplication(up)
	identity := yii.Identity{Bearer: "valid"}
	command := CommandRequest{Command: "AddToCart", Input: json.RawMessage(`{"productId":"` + pid + `","quantity":1}`)}
	if _, err := app.Execute(context.Background(), identity, command, ""); err == nil || reads.Load() != 0 || writes.Load() != 0 {
		t.Fatal("invalid command reached upstream")
	}
	response, err := app.Execute(context.Background(), identity, command, "app-key")
	if err != nil || response.Outcome != "applied" || response.Refresh.Screen != "cart" || reads.Load() != 1 || writes.Load() != 1 {
		t.Fatalf("application execution: %+v, %v, reads=%d writes=%d", response, err, reads.Load(), writes.Load())
	}
}
