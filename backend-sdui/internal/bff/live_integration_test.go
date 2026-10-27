package bff

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"shopkmp/backend-sdui/internal/yii"
)

type liveStep struct {
	Name   string `json:"name"`
	Method string `json:"method"`
	Path   string `json:"path"`
	Status int    `json:"status"`
	Bytes  int    `json:"bytes"`
	SHA256 string `json:"sha256,omitempty"`
}
type liveRun struct {
	t               *testing.T
	client          *http.Client
	yii, bff, token string
	steps           []liveStep
	payloads        map[string][]byte
}

func randomSuffix() string {
	var b [8]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b[:])
}
func (l *liveRun) call(name, origin, method, path string, in any, key string, want int) []byte {
	l.t.Helper()
	var body []byte
	if in != nil {
		var err error
		body, err = json.Marshal(in)
		if err != nil {
			l.t.Fatal(err)
		}
	}
	req, err := http.NewRequest(method, origin+path, bytes.NewReader(body))
	if err != nil {
		l.t.Fatal(err)
	}
	if l.token != "" {
		req.Header.Set("Authorization", "Bearer "+l.token)
	}
	if in != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("X-Request-Id", "task2-live-"+strconv.Itoa(len(l.steps)))
	if key != "" {
		req.Header.Set("Idempotency-Key", key)
	}
	res, err := l.client.Do(req)
	if err != nil {
		l.t.Fatalf("%s: %v", name, err)
	}
	defer res.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(res.Body, (4<<20)+1))
	if err != nil || len(raw) > 4<<20 {
		l.t.Fatalf("%s: body error %v", name, err)
	}
	// Auth bodies are never recorded or hashed; only route/status metadata is kept.
	// Errors never print token-bearing DTOs.
	if res.StatusCode != want {
		if strings.HasPrefix(path, "/api/v1/auth/") {
			l.t.Fatalf("%s: HTTP %d, expected %d", name, res.StatusCode, want)
		}
		l.t.Fatalf("%s: HTTP %d, expected %d: %s", name, res.StatusCode, want, raw)
	}
	step := liveStep{Name: name, Method: method, Path: path, Status: res.StatusCode, Bytes: len(raw)}
	if !strings.HasPrefix(path, "/api/v1/auth/") {
		sum := sha256.Sum256(raw)
		step.SHA256 = hex.EncodeToString(sum[:])
	}
	l.steps = append(l.steps, step)
	if origin == l.bff && l.token != "" && res.Header.Get("Cache-Control") != "no-store" {
		l.t.Fatalf("%s: personalized response cacheable", name)
	}
	l.t.Logf("%s: %s %s -> %d (%d bytes)", name, method, path, res.StatusCode, len(raw))
	return raw
}
func decodeLive[T any](t *testing.T, raw []byte) T {
	t.Helper()
	var out T
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatal(err)
	}
	return out
}

type liveTokens struct {
	UserID  string `json:"userId"`
	Access  string `json:"accessToken"`
	Refresh string `json:"refreshToken"`
}

func (l *liveRun) registerLogin(suffix string) liveTokens {
	l.t.Helper()
	l.token = ""
	email := "sdui-" + suffix + "@example.org"
	password := "Sdui!7a" + randomSuffix()
	raw := l.call("PHP register", l.yii, "POST", "/api/v1/auth/register", map[string]string{"email": email, "password": password, "fullName": "SDUI Live Buyer", "phone": "+79991234567"}, "", 201)
	created := decodeLive[liveTokens](l.t, raw)
	raw = l.call("PHP login", l.yii, "POST", "/api/v1/auth/login", map[string]string{"email": email, "password": password}, "", 200)
	tokens := decodeLive[liveTokens](l.t, raw)
	if tokens.UserID != created.UserID || tokens.Access == "" || tokens.Refresh == "" {
		l.t.Fatal("registration/login subject mismatch")
	}
	l.token = tokens.Access
	return tokens
}
func TestLiveYiiEightScreensAndActions(t *testing.T) {
	origin := os.Getenv("YII_INTEGRATION_ORIGIN")
	if origin == "" {
		t.Skip("set YII_INTEGRATION_ORIGIN to the disposable Task1 HTTP stack")
	}
	up, err := yii.New(yii.Config{Origin: origin, Timeout: 5 * time.Second})
	if err != nil {
		t.Fatal(err)
	}
	bffOrigin := os.Getenv("BFF_INTEGRATION_ORIGIN")
	if bffOrigin == "" {
		server := httptest.NewServer(New(up, Config{RequestTimeout: 10 * time.Second}))
		defer server.Close()
		bffOrigin = server.URL
	}
	l := &liveRun{t: t, yii: strings.TrimSuffix(origin, "/"), bff: strings.TrimSuffix(bffOrigin, "/"), client: &http.Client{Timeout: 20 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}, payloads: map[string][]byte{}}
	schema := compileSchema(t, "screen")
	commandSchema := compileSchema(t, "action-request")
	responseSchema := compileSchema(t, "action-response")
	screen := func(name, path string) map[string]any {
		raw := l.call("screen "+name, l.bff, "GET", "/api/v1/screens/"+path, nil, "", 200)
		schemaValidate(t, schema, raw)
		l.payloads[name+".json"] = raw
		return decodeLive[map[string]any](t, raw)
	}
	command := func(name string, input any, key string) {
		body := map[string]any{"command": name, "input": input}
		raw, _ := json.Marshal(body)
		schemaValidate(t, commandSchema, raw)
		raw = l.call("command "+name, l.bff, "POST", "/api/v1/sdui/actions", body, key, 200)
		schemaValidate(t, responseSchema, raw)
		l.payloads["action-"+name+".json"] = raw
	}
	screen("anonymous-home", "home")
	screen("anonymous-catalog", "catalog?size=2")
	page := decodeLive[yii.Page[yii.Product]](t, l.call("SQL-backed product selection", l.yii, "GET", "/api/v1/products?size=100&sort=price_asc", nil, "", 200))
	var selected yii.Product
	for _, p := range page.Items {
		if p.IsActive && p.Stock >= 5 && p.Price != "0.00" {
			selected = p
			break
		}
	}
	if selected.ID == "" {
		t.Fatal("disposable catalog needs an active product with stock >= 5")
	}
	productPath := "/api/v1/products/" + selected.ID
	l.payloads["business-product-before.json"] = l.call("product before", l.yii, "GET", productPath, nil, "", 200)
	screen("anonymous-product", "product/"+selected.ID)
	suffix := randomSuffix()
	tokens := l.registerLogin(suffix)
	device := "sdui-" + suffix
	// Native credential adapters, never generic SDUI forms or commands.
	pin := "825496"
	l.call("native set PIN", l.yii, "POST", "/api/v1/auth/pin", map[string]string{"deviceId": device, "deviceName": "Task2 native test device", "pin": pin}, "", 201)
	native := decodeLive[liveTokens](t, l.call("native PIN login", l.yii, "POST", "/api/v1/auth/pin/login", map[string]string{"deviceId": device, "pin": pin}, "", 200))
	if !strings.HasPrefix(native.Access, "app_access_") || native.UserID != tokens.UserID {
		t.Fatal("opaque native session not issued")
	}
	l.token = native.Access
	publicKey, _, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	der, err := x509.MarshalPKIXPublicKey(publicKey)
	if err != nil {
		t.Fatal(err)
	}
	l.call("native enroll biometric", l.yii, "POST", "/api/v1/auth/biometric", map[string]string{"deviceId": device, "deviceName": "Task2 native biometric device", "publicKey": base64.StdEncoding.EncodeToString(der), "keyAlgorithm": "EdDSA"}, "", 201)
	screen("home", "home?deviceId="+device)
	screen("catalog", "catalog?size=2")
	query := url.Values{"q": {selected.Name}, "categoryId": {selected.CategoryID}, "priceMin": {string(selected.Price)}, "priceMax": {string(selected.Price)}, "size": {"2"}}
	searched := screen("catalog-search", "catalog?"+query.Encode())
	if searched["state"].(map[string]any)["pagination"].(map[string]any)["totalItems"].(float64) < 1 {
		t.Fatal("real OpenSearch did not find the selected SQL-backed product")
	}
	screen("catalog-page-1", "catalog?page=1&size=1")
	categoryRaw := l.call("SQL-backed category collection", l.yii, "GET", "/api/v1/categories", nil, "", 200)
	l.payloads["business-categories.json"] = categoryRaw
	allCategories := decodeLive[[]yii.Category](t, categoryRaw)
	categoryIDs := map[string]bool{}
	for categoryPage := 0; categoryPage < max(1, (len(allCategories)+49)/50); categoryPage++ {
		name := fmt.Sprintf("catalog-category-page-%d", categoryPage)
		screen(name, fmt.Sprintf("catalog?size=100&categoryPage=%d&categorySize=50", categoryPage))
		d, nodes := reviewNodes(t, l.payloads[name+".json"])
		if d.State.Collections["categories"].TotalItems != len(allCategories) {
			t.Fatal("live category continuation lost total")
		}
		for _, node := range nodes {
			if node.Type == "CategoryList" {
				for _, raw := range node.Props["items"].([]any) {
					id := raw.(map[string]any)["id"].(string)
					if categoryIDs[id] {
						t.Fatal("live category repeated across pages")
					}
					categoryIDs[id] = true
				}
			}
		}
	}
	if len(categoryIDs) != len(allCategories) {
		t.Fatal("live category paging silently lost rows")
	}
	for _, c := range allCategories {
		if !categoryIDs[c.ID] {
			t.Fatal("live category missing from continuation")
		}
	}
	if len(allCategories) > 1 {
		screen("home-continuation", "home?deviceId="+device+"&categoryPage=1&categorySize=1")
	}
	screen("product", "product/"+selected.ID)
	screen("empty-cart", "cart")
	key := "sdui-add-" + suffix
	command("AddToCart", map[string]any{"productId": selected.ID, "quantity": 2}, key)
	command("AddToCart", map[string]any{"productId": selected.ID, "quantity": 2}, key)
	cartRaw := l.call("idempotent add readback", l.yii, "GET", "/api/v1/cart", nil, "", 200)
	l.payloads["business-cart.json"] = cartRaw
	cart := decodeLive[yii.Cart](t, cartRaw)
	if len(cart.Items) != 1 || cart.Items[0].Quantity != 2 {
		t.Fatalf("same-key add incremented twice: %+v", cart.Items)
	}
	l.call("conflicting idempotency fingerprint", l.bff, "POST", "/api/v1/sdui/actions", map[string]any{"command": "AddToCart", "input": map[string]any{"productId": selected.ID, "quantity": 3}}, key, 409)
	command("UpdateQuantity", map[string]any{"productId": selected.ID, "quantity": 3}, "")
	cart = decodeLive[yii.Cart](t, l.call("quantity replacement readback", l.yii, "GET", "/api/v1/cart", nil, "", 200))
	if cart.Items[0].Quantity != 3 {
		t.Fatal("quantity replacement failed")
	}
	command("RemoveFromCart", map[string]any{"productId": selected.ID}, "")
	cart = decodeLive[yii.Cart](t, l.call("remove readback", l.yii, "GET", "/api/v1/cart", nil, "", 200))
	if len(cart.Items) != 0 {
		t.Fatal("remove failed")
	}
	command("AddToCart", map[string]any{"productId": selected.ID, "quantity": 2}, "sdui-final-"+suffix)
	command("UpdateProfile", map[string]any{"fullName": "SDUI Updated Buyer", "phone": "+79997654321"}, "")
	addressRaw := l.call("native address creation", l.yii, "POST", "/api/v1/addresses", map[string]string{"city": "Moscow", "street": "SDUI integration", "house": "18", "apartment": "2"}, "", 201)
	address := decodeLive[yii.Address](t, addressRaw)
	if address.UserID != tokens.UserID {
		t.Fatal("address owner mismatch")
	}
	l.payloads["business-address.json"] = addressRaw
	var extra yii.Product
	for _, p := range page.Items {
		if p.ID != selected.ID && p.IsActive && p.Stock > 0 {
			extra = p
			break
		}
	}
	if extra.ID == "" {
		t.Fatal("live collection regression requires two available products")
	}
	command("AddToCart", map[string]any{"productId": extra.ID, "quantity": 1}, "sdui-page-"+suffix)
	extraPage := 0
	if extra.ID > selected.ID {
		extraPage = 1
	}
	screen("cart-continuation", fmt.Sprintf("cart?page=%d&size=1", extraPage))
	pagedCart, pagedNodes := reviewNodes(t, l.payloads["cart-continuation.json"])
	if pagedCart.State.Collections["cart"].TotalItems != 2 || pagedCart.State.CartItemsCount != 3 {
		t.Fatal("live cart continuation lost aggregate totals")
	}
	extraVisible := false
	for _, n := range pagedNodes {
		if n.Type == "CartItem" && n.Props["productId"] == extra.ID {
			extraVisible = true
		}
	}
	if !extraVisible {
		t.Fatal("live extra row not reachable")
	}
	command("RemoveFromCart", map[string]any{"productId": extra.ID}, "")
	screen("cart-after-continuation-remove", "cart?page=1&size=1")
	normalizedCart, _ := reviewNodes(t, l.payloads["cart-after-continuation-remove.json"])
	if normalizedCart.State.Collections["cart"].Page != 0 || normalizedCart.State.Collections["cart"].TotalItems != 1 {
		t.Fatal("live removed last page not normalized")
	}
	screen("cart", "cart")
	profile := screen("profile", "profile")
	if !bytes.Contains(l.payloads["profile.json"], []byte("SDUI Updated Buyer")) {
		t.Fatal("profile update not persisted")
	}
	_ = profile
	screen("devices", "devices")
	command("RevokeDevice", map[string]any{"deviceId": device, "deviceKind": "pin", "confirmed": true}, "")
	// Deleting the issuing credential must revoke this opaque family immediately.
	l.call("deleted PIN family rejected", l.bff, "GET", "/api/v1/screens/profile", nil, "", 401)
	l.token = tokens.Access // Still-valid signed password JWT, not the revoked PIN bearer.
	command("RevokeDevice", map[string]any{"deviceId": device, "deviceKind": "biometric", "confirmed": true}, "")
	screen("empty-devices", "devices")
	// Exercise the remaining journey/logout with a genuinely new opaque family.
	logoutDevice := device + "-logout"
	l.call("native set logout PIN", l.yii, "POST", "/api/v1/auth/pin", map[string]string{"deviceId": logoutDevice, "deviceName": "Live logout device", "pin": pin}, "", 201)
	native = decodeLive[liveTokens](t, l.call("native logout PIN login", l.yii, "POST", "/api/v1/auth/pin/login", map[string]string{"deviceId": logoutDevice, "pin": pin}, "", 200))
	if !strings.HasPrefix(native.Access, "app_access_") || native.UserID != tokens.UserID {
		t.Fatal("fresh opaque logout session not issued")
	}
	l.token = native.Access
	// Existing CheckoutUseCase owns this canonical operation. The test drives the
	// same HTTP boundary without asking SDUI to execute a critical remote command.
	checkout := map[string]any{"deliveryAddressId": address.ID, "paymentMethod": "ON_DELIVERY", "comment": "Task2 native adapter HTTP verification"}
	orderRaw := l.call("native checkout", l.yii, "POST", "/api/v1/orders", checkout, "sdui-checkout-"+suffix, 201)
	order := decodeLive[yii.Order](t, orderRaw)
	l.payloads["business-order-created.json"] = orderRaw
	replay := decodeLive[yii.Order](t, l.call("native checkout idempotent replay", l.yii, "POST", "/api/v1/orders", checkout, "sdui-checkout-"+suffix, 201))
	if replay.ID != order.ID || order.UserID != tokens.UserID {
		t.Fatal("checkout replay/ownership failed")
	}
	price := selected.Price
	if selected.DiscountPrice != nil {
		price = *selected.DiscountPrice
	}
	cents, err := strconv.ParseInt(strings.ReplaceAll(string(price), ".", ""), 10, 64)
	if err != nil {
		t.Fatal(err)
	}
	expected := fmt.Sprintf("%d.%02d", cents*2/100, cents*2%100)
	if string(order.TotalAmount) != expected {
		t.Fatalf("money total %s != %s", order.TotalAmount, expected)
	}
	if order.PaymentID == nil {
		t.Fatal("payment missing")
	}
	l.payloads["business-payment.json"] = l.call("native payment confirmation", l.yii, "POST", "/api/v1/payments/"+*order.PaymentID+"/confirm", nil, "", 200)
	screen("orders", "orders")
	screen("order", "order/"+order.ID)
	after := decodeLive[yii.Product](t, l.call("reserved stock readback", l.yii, "GET", productPath, nil, "", 200))
	if after.Stock != selected.Stock-2 {
		t.Fatal("SQL reservation not reflected")
	}
	command("CancelOrder", map[string]any{"orderId": order.ID, "confirmed": true}, "")
	canceled := decodeLive[yii.Order](t, l.call("cancellation readback", l.yii, "GET", "/api/v1/orders/"+order.ID, nil, "", 200))
	if canceled.Status != "CANCELLED" {
		t.Fatal("SQL order not canceled")
	}
	after = decodeLive[yii.Product](t, l.call("restored stock readback", l.yii, "GET", productPath, nil, "", 200))
	if after.Stock != selected.Stock {
		t.Fatal("SQL stock not restored")
	}
	screen("cancelled-order", "order/"+order.ID)
	screen("orders-cancelled-filter", "orders?status=CANCELLED&page=0&size=1")
	screen("cart-after-checkout", "cart")
	originalToken := l.token
	l.registerLogin(randomSuffix())
	l.call("cross-account order rejected", l.bff, "GET", "/api/v1/screens/order/"+order.ID, nil, "", 404)
	l.call("cross-account cancellation rejected", l.bff, "POST", "/api/v1/sdui/actions", map[string]any{"command": "CancelOrder", "input": map[string]any{"orderId": order.ID, "confirmed": true}}, "", 404)
	l.token = originalToken
	l.call("critical remote checkout rejected", l.bff, "POST", "/api/v1/sdui/actions", map[string]any{"command": "Checkout", "input": checkout}, "", 400)
	l.call("native logout", l.yii, "POST", "/api/v1/auth/logout", map[string]string{"refreshToken": native.Refresh, "deviceId": logoutDevice}, "", 204)
	l.call("revoked opaque session rejected", l.bff, "GET", "/api/v1/screens/profile", nil, "", 401)
	manifest := struct {
		ExecutedAt string     `json:"executedAt"`
		YiiOrigin  string     `json:"yiiOrigin"`
		BFFOrigin  string     `json:"bffOrigin"`
		UserID     string     `json:"userId"`
		ProductID  string     `json:"productId"`
		OrderID    string     `json:"orderId"`
		UnitPrice  yii.Money  `json:"unitPrice"`
		OrderTotal yii.Money  `json:"orderTotal"`
		Steps      []liveStep `json:"steps"`
	}{time.Now().UTC().Format(time.RFC3339), l.yii, l.bff, tokens.UserID, selected.ID, order.ID, price, order.TotalAmount, l.steps}
	if dir := os.Getenv("SDUI_EVIDENCE_DIR"); dir != "" {
		if err := os.MkdirAll(dir, 0755); err != nil {
			t.Fatal(err)
		}
		raw, _ := json.MarshalIndent(manifest, "", "  ")
		if err = os.WriteFile(filepath.Join(dir, "manifest.json"), raw, 0644); err != nil {
			t.Fatal(err)
		}
		for name, raw := range l.payloads {
			var out bytes.Buffer
			if err := json.Indent(&out, raw, "", "  "); err != nil {
				t.Fatal(err)
			}
			out.WriteByte('\n')
			if err := os.WriteFile(filepath.Join(dir, name), out.Bytes(), 0644); err != nil {
				t.Fatal(err)
			}
		}
	}
	t.Logf("PASS real Yii SQL-backed eight screens, six commands, native checkout/payment/credentials/logout; user=%s product=%s order=%s total=%s recordedSteps=%d", tokens.UserID, selected.ID, order.ID, order.TotalAmount, len(l.steps))
}
