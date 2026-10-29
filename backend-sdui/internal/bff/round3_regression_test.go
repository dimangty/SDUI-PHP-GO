package bff

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"testing"
	"unicode/utf8"
)

// round3CardWire is a test-only PHP wire serializer. It deliberately does not
// call Go's preview algorithm: PHP sends 257 raw code points without ellipsis.
func round3CardWire(p map[string]any) map[string]any {
	c := make(map[string]any, len(p))
	for k, v := range p {
		c[k] = v
	}
	r := []rune(p["description"].(string))
	c["descriptionSnippet"] = string(r[:min(257, len(r))])
	c["descriptionComplete"] = len(r) <= 257
	c["imageUrl"] = nil
	switch images := p["imageUrls"].(type) {
	case []string:
		if len(images) > 0 {
			c["imageUrl"] = images[0]
		}
	case []any:
		if len(images) > 0 {
			c["imageUrl"] = images[0]
		}
	}
	delete(c, "description")
	delete(c, "imageUrls")
	return c
}

func TestRound3F1LargeCatalogHTTPProjection(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, count := range []int{1, 20, 50, 100} {
		t.Run(fmt.Sprint(count), func(t *testing.T) {
			full := make([]any, count)
			cards := make([]any, count)
			for i := range full {
				p := reviewProduct(t, strings.Repeat("😀", 10000))
				p["id"] = fmt.Sprintf("%08x-2222-4222-8222-222222222222", i+1)
				p["price"] = "50.01"
				// Both URLs meet PHP's 2048-byte enrollment limit. The 100-item
				// page exceeds the body budget because of valid descriptions.
				p["imageUrls"] = []string{"https://media.example/" + strings.Repeat("a", 2022) + ".png", "https://media.example/" + strings.Repeat("b", 2022) + ".png"}
				full[i], cards[i] = p, round3CardWire(p)
			}
			projectedCalls := 0
			up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				switch r.URL.Path {
				case "/api/v1/products":
					items := full
					response := map[string]any{"items": items, "page": 0, "size": count, "totalItems": count, "totalPages": 1}
					if r.URL.Query().Get("projection") == "sdui-card-v1" {
						projectedCalls++
						response["items"], response["projection"] = cards, "sdui-card-v1"
						compact, err := json.Marshal(response)
						if err != nil {
							t.Error(err)
						}
						if len(compact)+1 > 2<<20 {
							t.Error("projected upstream body exceeds unchanged default limit")
						}
						t.Logf("projected upstream products=%d bytes=%d", count, len(compact)+1)
					}
					json.NewEncoder(w).Encode(response)
				case "/api/v1/products/00000001-2222-4222-8222-222222222222":
					if r.URL.RawQuery != "" {
						t.Error("detail must remain unprojected")
					}
					json.NewEncoder(w).Encode(full[0])
				default:
					fixtureUpstream(w, r)
				}
			}))
			defer up.Close()
			// Original public list/detail retain full descriptions and media.
			for _, path := range []string{"/api/v1/products", "/api/v1/products/00000001-2222-4222-8222-222222222222"} {
				res, err := http.Get(up.URL + path)
				if err != nil {
					t.Fatal(err)
				}
				raw, err := io.ReadAll(res.Body)
				res.Body.Close()
				if err != nil || !strings.Contains(string(raw), strings.Repeat("😀", 10000)) {
					t.Fatal("public full-data fixture lost its full description")
				}
				if path == "/api/v1/products" && count == 100 && len(raw) <= 2<<20 {
					t.Fatal("valid 100-item page must reproduce original body-budget failure")
				}
				t.Logf("full %s products=%d bytes=%d", path, count, len(raw))
			}
			h := round3Handler(t, up.URL)
			w := request(h, "GET", fmt.Sprintf("/api/v1/screens/catalog?size=%d", count), "", "")
			if w.Code != 200 {
				t.Fatalf("bounded projection must render %d products: HTTP %d %s", count, w.Code, w.Body)
			}
			if projectedCalls != 1 {
				t.Fatalf("projection not negotiated: calls=%d", projectedCalls)
			}
			schemaValidate(t, schema, w.Body.Bytes())
			_, nodes := reviewNodes(t, w.Body.Bytes())
			seen := map[string]bool{}
			for _, n := range nodes {
				if n.Type == "ProductCard" {
					id := n.Props["productId"].(string)
					if seen[id] {
						t.Fatal("duplicate product")
					}
					seen[id] = true
					if n.Props["description"] != strings.Repeat("😀", 255)+"…" || n.Props["descriptionTruncated"] != true {
						t.Fatal("preview changed")
					}
				}
			}
			if len(seen) != count {
				t.Fatalf("lost products: %d", len(seen))
			}
			t.Logf("compact SDUI products=%d bytes=%d", count, w.Body.Len())
		})
	}
}

func TestRound3F2LegacyDevicesHTTP200(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, kind := range []string{"pin", "biometric"} {
		for _, compact := range []bool{false, true} {
			t.Run(fmt.Sprintf("%s/compact=%v", kind, compact), func(t *testing.T) {
				h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
					if r.URL.Path == "/api/v1/auth/"+kind+"/devices" {
						ids := []string{"ordinary.Phone", ".", ".."}
						if compact {
							for i := 0; i < 100; i++ {
								ids = append(ids, fmt.Sprintf("ordinary-%d", i))
							}
						}
						devices := []map[string]any{}
						for _, id := range ids {
							devices = append(devices, map[string]any{"deviceId": id, "deviceName": "Record " + id, "keyAlgorithm": "ES256", "createdAt": "2026-10-18T10:00:00Z", "lastUsedAt": "2026-10-18T10:00:00Z", "lockedUntil": nil})
						}
						json.NewEncoder(w).Encode(devices)
						return
					}
					fixtureUpstream(w, r)
				})
				w := request(h, "GET", "/api/v1/screens/devices", "", "valid")
				if w.Code != 200 {
					t.Fatalf("legacy records must remain visible: HTTP %d %s", w.Code, w.Body)
				}
				schemaValidate(t, schema, w.Body.Bytes())
				d, nodes := reviewNodes(t, w.Body.Bytes())
				joined := ""
				for _, n := range nodes {
					if n.Type == "Empty" {
						t.Fatal("false empty")
					}
					if s, ok := n.Props["text"].(string); ok {
						joined += s + "\n"
					}
				}
				for _, value := range []string{"Record .\n.\n" + kind, "Record ..\n..\n" + kind, "устаревший идентификатор", "отзыв", "аккаунт", "API", "оператор"} {
					if !strings.Contains(joined, value) {
						t.Errorf("missing legacy explanation/record %q", value)
					}
				}
				ordinary := false
				for _, a := range d.Actions {
					if a["type"] == "RevokeDevice" {
						if a["deviceId"] == "." || a["deviceId"] == ".." {
							t.Fatal("invalid CMP device action")
						}
						// Independent CMP v1 addressability check, separate from the
						// Go domain predicate and JSON Schema compiler.
						if !regexp.MustCompile(`^[A-Za-z0-9._-]{1,255}$`).MatchString(a["deviceId"].(string)) {
							t.Fatal("consumer cannot address device")
						}
						if a["deviceId"] == "ordinary.Phone" && a["deviceKind"] == kind {
							ordinary = true
						}
					}
				}
				if !ordinary {
					t.Fatal("ordinary revocation lost")
				}
				if !utf8.Valid(w.Body.Bytes()) {
					t.Fatal("invalid consumer UTF8")
				}
			})
		}
	}
}

func TestRound3F2OtherInvalidDevicesFailClosed(t *testing.T) {
	for _, id := range []string{"", "../phone", "phone/name", "a b", "телефон", strings.Repeat("a", 256)} {
		t.Run(id, func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/auth/pin/devices" {
					json.NewEncoder(w).Encode([]any{map[string]any{"deviceId": id, "deviceName": "Invalid record", "createdAt": "now", "lastUsedAt": "now", "lockedUntil": nil}})
					return
				}
				fixtureUpstream(w, r)
			})
			w := request(h, "GET", "/api/v1/screens/devices", "", "valid")
			if w.Code != 502 || strings.Contains(w.Body.String(), "Invalid record") {
				t.Fatalf("invalid upstream identifier published: %d %s", w.Code, w.Body)
			}
		})
	}
}

func TestRound3F1MissingMarkerIsNotEmptyCatalogOrPopular(t *testing.T) {
	for _, screen := range []string{"catalog", "home"} {
		t.Run(screen, func(t *testing.T) {
			up := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/products" || r.URL.Path == "/api/v1/products/popular" {
					json.NewEncoder(w).Encode(map[string]any{"items": []any{}, "page": 0, "size": 20, "totalPages": 0, "totalItems": 0})
					return
				}
				fixtureUpstream(w, r)
			}))
			defer up.Close()
			w := request(round3Handler(t, up.URL), "GET", "/api/v1/screens/"+screen, "", "")
			if screen == "catalog" {
				if w.Code != 502 {
					t.Fatalf("missing marker accepted: %d", w.Code)
				}
			} else {
				if w.Code != 200 || !strings.Contains(w.Body.String(), `"popular_unavailable"`) || !strings.Contains(w.Body.String(), `"partial":true`) || strings.Contains(w.Body.String(), "home.popular.empty") {
					t.Fatalf("missing marker became empty/hidden: %d %s", w.Code, w.Body)
				}
				schemaValidate(t, compileSchema(t, "screen"), w.Body.Bytes())
			}
		})
	}
}
