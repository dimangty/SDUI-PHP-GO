package bff

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v6"
)

func compileSchema(t *testing.T, name string) *jsonschema.Schema {
	t.Helper()
	compiler := jsonschema.NewCompiler()
	for _, n := range []string{"screen", "action-request", "action-response"} {
		raw, err := os.ReadFile("../../contracts/" + n + ".schema.json")
		if err != nil {
			t.Fatal(err)
		}
		doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(raw))
		if err != nil {
			t.Fatal(err)
		}
		if err = compiler.AddResource("https://shopkmp.local/contracts/sdui/v1/"+n+".schema.json", doc); err != nil {
			t.Fatal(err)
		}
	}
	schema, err := compiler.Compile("https://shopkmp.local/contracts/sdui/v1/" + name + ".schema.json")
	if err != nil {
		t.Fatal(err)
	}
	return schema
}
func schemaValidate(t *testing.T, schema *jsonschema.Schema, raw []byte) {
	t.Helper()
	value, err := jsonschema.UnmarshalJSON(bytes.NewReader(raw))
	if err != nil {
		t.Fatal(err)
	}
	if err = schema.Validate(value); err != nil {
		t.Fatal(err)
	}
}
func TestPublishedFixturesMatchActualBuildersAndIndependentSchema(t *testing.T) {
	schema := compileSchema(t, "screen")
	h := newTestHandler(t, fixtureUpstream)
	for _, tc := range []struct{ name, path string }{{"home", "home?deviceId=test-device"}, {"catalog", "catalog"}, {"product", "product/" + pid}, {"cart", "cart"}, {"orders", "orders"}, {"order", "order/" + oid}, {"profile", "profile"}, {"devices", "devices"}} {
		t.Run(tc.name, func(t *testing.T) {
			w := request(h, "GET", "/api/v1/screens/"+tc.path, "", "valid")
			if w.Code != 200 {
				t.Fatalf("%d %s", w.Code, w.Body)
			}
			schemaValidate(t, schema, w.Body.Bytes())
			path := filepath.Join("../../contracts/fixtures", tc.name+".json")
			var formatted bytes.Buffer
			if err := json.Indent(&formatted, w.Body.Bytes(), "", "  "); err != nil {
				t.Fatal(err)
			}
			formatted.WriteByte('\n')
			if os.Getenv("UPDATE_FIXTURES") == "1" {
				if err := os.WriteFile(path, formatted.Bytes(), 0644); err != nil {
					t.Fatal(err)
				}
			}
			published, err := os.ReadFile(path)
			if err != nil {
				t.Fatal(err)
			}
			schemaValidate(t, schema, published)
			if !bytes.Equal(published, formatted.Bytes()) {
				t.Fatal("published fixture drift; review wire change before explicitly regenerating")
			}
		})
	}
}
func TestIndependentSchemaRejectsUnsupportedAndExecutableWire(t *testing.T) {
	schema := compileSchema(t, "screen")
	h := newTestHandler(t, fixtureUpstream)
	w := request(h, "GET", "/api/v1/screens/product/"+pid, "", "valid")
	for _, mutate := range []func(map[string]any){
		func(d map[string]any) { d["schemaVersion"] = 2 },
		func(d map[string]any) { d["components"].([]any)[0].(map[string]any)["type"] = "RemoteCode" },
		func(d map[string]any) { d["actions"].([]any)[0].(map[string]any)["url"] = "http://evil.test" },
		func(d map[string]any) { d["state"].(map[string]any)["fields"].(map[string]any)["password"] = "secret" },
		func(d map[string]any) {
			d["components"].([]any)[0].(map[string]any)["props"].(map[string]any)["padding"] = "999999dp"
		},
	} {
		var d map[string]any
		json.Unmarshal(w.Body.Bytes(), &d)
		mutate(d)
		if err := schema.Validate(d); err == nil {
			t.Fatal("independent schema accepted invalid document")
		}
	}
}
func TestCommandSchemaMatchesActualAdapter(t *testing.T) {
	requestSchema := compileSchema(t, "action-request")
	responseSchema := compileSchema(t, "action-response")
	raw := []byte(`{"command":"AddToCart","input":{"productId":"` + pid + `","quantity":1}}`)
	schemaValidate(t, requestSchema, raw)
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "POST" {
			fmt.Fprint(w, cartJSON)
			return
		}
		fixtureUpstream(w, r)
	})
	response := postCommand(h, string(raw), "contract-key")
	if response.Code != 200 {
		t.Fatalf("%d %s", response.Code, response.Body)
	}
	schemaValidate(t, responseSchema, response.Body.Bytes())
	for _, raw := range []string{`{"command":"Checkout","input":{}}`, `{"command":"CancelOrder","input":{"orderId":"` + oid + `","confirmed":false}}`, `{"command":"UpdateQuantity","input":{"productId":"` + pid + `"}}`} {
		var d any
		json.Unmarshal([]byte(raw), &d)
		if err := requestSchema.Validate(d); err == nil {
			t.Fatalf("accepted %s", raw)
		}
	}
}
