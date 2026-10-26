package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"testing"
	"unicode/utf8"
)

func followupDevices(count int, rich bool) []map[string]any {
	items := make([]map[string]any, count)
	for i := range items {
		name := fmt.Sprintf("Phone %03d", i)
		if rich {
			name += strings.Repeat("Ж🌟<&\"\\", 40)
		}
		items[i] = map[string]any{"deviceId": fmt.Sprintf("Phone-%03d", i), "deviceName": name, "keyAlgorithm": "ES256", "createdAt": "2026-10-18T10:00:00Z", "lastUsedAt": "2026-10-19T10:00:00Z", "lockedUntil": nil}
		if i < 2 {
			items[i]["deviceId"] = []string{"Phone-AbC", "phone-abc"}[i]
		}
	}
	return items
}

func followupAddresses(count int, rich bool) []map[string]any {
	items := make([]map[string]any, count)
	for i := range items {
		city, street := fmt.Sprintf("City %04d", i), "Street"
		if rich {
			city += strings.Repeat("Ж🌟", 80)
			street = strings.Repeat("<&\"\\", 20)
		}
		items[i] = map[string]any{"id": reviewItemID(i), "userId": uid, "city": city, "street": street, "house": "1", "apartment": "2", "comment": nil}
	}
	return items
}

func TestFollowupN2DevicesPreserveEveryControlAndLabel(t *testing.T) {
	schema := compileSchema(t, "screen")
	stableIDs := map[string][2]string{}
	for _, count := range []int{0, 1, 99, 100, 150, 249, 250} {
		for _, rich := range []bool{false, true} {
			t.Run(fmt.Sprintf("%d/rich=%v", count, rich), func(t *testing.T) {
				// Overlap IDs across kinds intentionally; the tuple is the identity.
				pins, bio := followupDevices((count+1)/2, rich), followupDevices(count/2, rich)
				h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
					switch r.URL.Path {
					case "/api/v1/auth/pin/devices":
						_ = json.NewEncoder(w).Encode(pins)
					case "/api/v1/auth/biometric/devices":
						_ = json.NewEncoder(w).Encode(bio)
					default:
						fixtureUpstream(w, r)
					}
				})
				w := request(h, "GET", "/api/v1/screens/devices", "", "valid")
				if count == 250 {
					if w.Code != 502 || !strings.Contains(w.Body.String(), "BFF_INVALID_DOCUMENT") || strings.Contains(w.Body.String(), "Phone-") {
						t.Fatalf("node overflow not atomic: %d %s", w.Code, w.Body)
					}
					return
				}
				if w.Code != 200 || w.Header().Get("Cache-Control") != "no-store" {
					t.Fatalf("devices: %d %s", w.Code, w.Body)
				}
				schemaValidate(t, schema, w.Body.Bytes())
				d, nodes := reviewNodes(t, w.Body.Bytes())
				actions := map[string]map[string]any{}
				buttons := map[string]int{}
				type entry struct {
					button reviewNode
					info   string
				}
				entries := []entry{}
				var labels strings.Builder
				empty := 0
				for _, a := range d.Actions {
					if a["type"] == "RevokeDevice" {
						key := a["deviceKind"].(string) + "/" + a["deviceId"].(string)
						if actions[key] != nil {
							t.Fatal("duplicate revoke action")
						}
						actions[key] = a
					}
				}
				for index, n := range nodes {
					if n.Type == "Button" {
						buttons[n.Props["actionId"].(string)]++
						if n.Props["enabled"] != true || n.Props["label"] != "Отозвать" {
							t.Fatal("revoke disabled or mislabeled")
						}
						info := ""
						textCount := 1
						if count <= 99 {
							textCount = 3
						}
						for _, before := range nodes[index-textCount : index] {
							if before.Type != "Text" {
								t.Fatal("device information detached from button")
							}
							info += before.Props["text"].(string) + "\n"
						}
						entries = append(entries, entry{button: n, info: info})
					}
					if n.Type == "Empty" {
						empty++
					}
					if n.Type == "Text" && strings.HasPrefix(n.ID, "device.") {
						value := n.Props["text"].(string)
						if !utf8.ValidString(value) || utf8.RuneCountInString(value) > 8192 {
							t.Fatal("unsafe device label")
						}
						labels.WriteString(value + "\n")
					}
				}
				var want strings.Builder
				entryIndex := 0
				for _, group := range []struct {
					kind  string
					items []map[string]any
				}{{"pin", pins}, {"biometric", bio}} {
					for _, v := range group.items {
						info := fmt.Sprintf("%s\n%s\n%s · %s\n", v["deviceName"], v["deviceId"], group.kind, v["lastUsedAt"])
						want.WriteString(info)
						a := actions[group.kind+"/"+v["deviceId"].(string)]
						if a == nil || buttons[fmt.Sprint(a["id"])] != 1 {
							t.Fatal("missing individually accessible revoke")
						}
						if entryIndex >= len(entries) || entries[entryIndex].info != info || entries[entryIndex].button.Props["actionId"] != a["id"] {
							t.Fatal("device/button association or order changed")
						}
						ids := [2]string{a["id"].(string), entries[entryIndex].button.ID}
						key := fmt.Sprintf("%v/%s/%s", rich, group.kind, v["deviceId"])
						if previous, ok := stableIDs[key]; ok && ids != previous {
							t.Fatal("revoke action/button identity changed across compact transition")
						}
						stableIDs[key] = ids
						entryIndex++
						confirmation, ok := a["confirmation"].(map[string]any)
						if !ok || confirmation["message"] != v["deviceName"] || confirmation["title"] != "Отозвать устройство?" || confirmation["confirmLabel"] != "Отозвать" {
							t.Fatal("wrong confirmation")
						}
					}
				}
				if labels.String() != want.String() || len(actions) != count || (count == 0) != (empty == 1) {
					t.Fatalf("lost/reordered device data: actions=%d empty=%d", len(actions), empty)
				}
				t.Logf("devices=%d rich=%v nodes=%d actions=%d bytes=%d", count, rich, len(nodes), len(d.Actions), w.Body.Len())
			})
		}
	}
}

func TestFollowupN2ProfilePreservesAllAddressesInOrder(t *testing.T) {
	schema := compileSchema(t, "screen")
	for _, count := range []int{0, 1, 487, 488, 1000} {
		for _, rich := range []bool{false, true} {
			t.Run(fmt.Sprintf("%d/rich=%v", count, rich), func(t *testing.T) {
				items := followupAddresses(count, rich)
				h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
					if r.URL.Path == "/api/v1/addresses" {
						_ = json.NewEncoder(w).Encode(items)
						return
					}
					fixtureUpstream(w, r)
				})
				w := request(h, "GET", "/api/v1/screens/profile", "", "valid")
				if w.Code != 200 || w.Header().Get("Cache-Control") != "no-store" {
					t.Fatalf("profile: %d %s", w.Code, w.Body)
				}
				schemaValidate(t, schema, w.Body.Bytes())
				d, nodes := reviewNodes(t, w.Body.Bytes())
				var got, want strings.Builder
				for _, n := range nodes {
					if n.Type == "Text" && strings.HasPrefix(n.ID, "address.") {
						v := n.Props["text"].(string)
						if !utf8.ValidString(v) || utf8.RuneCountInString(v) > 8192 {
							t.Fatal("unsafe address block")
						}
						got.WriteString(v + "\n")
					}
				}
				for _, a := range items {
					fmt.Fprintf(&want, "%s, %s, %s, %s\n", a["city"], a["street"], a["house"], a["apartment"])
				}
				if got.String() != want.String() {
					t.Fatal("address data lost, changed or reordered")
				}
				t.Logf("addresses=%d rich=%v nodes=%d actions=%d bytes=%d", count, rich, len(nodes), len(d.Actions), w.Body.Len())
			})
		}
	}
}

func TestFollowupN2UnrepresentableCollectionsFailAtomically(t *testing.T) {
	for _, tc := range []struct{ name, screen string }{{"escaped-body", "profile"}, {"text", "devices"}, {"ownership", "profile"}} {
		t.Run(tc.name, func(t *testing.T) {
			h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/api/v1/addresses" {
					items := followupAddresses(1000, false)
					for _, a := range items {
						a["city"], a["street"] = strings.Repeat("&", 255), strings.Repeat("&", 255)
						if tc.name == "ownership" {
							a["userId"] = pid
						}
					}
					encoder := json.NewEncoder(w)
					encoder.SetEscapeHTML(false) // Upstream <2 MiB; final escaped SDUI >1 MiB.
					_ = encoder.Encode(items)
					return
				}
				if r.URL.Path == "/api/v1/auth/pin/devices" {
					items := followupDevices(100, false)
					items[99]["deviceName"] = strings.Repeat("🌟", 8193)
					_ = json.NewEncoder(w).Encode(items)
					return
				}
				fixtureUpstream(w, r)
			})
			w := request(h, "GET", "/api/v1/screens/"+tc.screen, "", "valid")
			code := "BFF_INVALID_DOCUMENT"
			if tc.name == "ownership" {
				code = "BFF_UPSTREAM_ACCOUNT_MISMATCH"
			}
			if w.Code != 502 || !strings.Contains(w.Body.String(), code) || strings.Contains(w.Body.String(), `"components"`) || w.Header().Get("Cache-Control") != "no-store" {
				t.Fatalf("unsafe partial document: %d %s", w.Code, w.Body)
			}
		})
	}
}
