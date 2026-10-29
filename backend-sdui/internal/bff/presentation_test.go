package bff

import (
	"encoding/json"
	"fmt"
	"net/http"
	"testing"
)

func presentationScreen(t *testing.T, path string) (reviewDocument, []reviewNode) {
	t.Helper()
	w := request(newTestHandler(t, fixtureUpstream), "GET", "/api/v1/screens/"+path, "", "valid")
	if w.Code != 200 {
		t.Fatalf("HTTP %d: %s", w.Code, w.Body)
	}
	schemaValidate(t, compileSchema(t, "screen"), w.Body.Bytes())
	return reviewNodes(t, w.Body.Bytes())
}

func presentationNode(t *testing.T, nodes []reviewNode, id string) reviewNode {
	t.Helper()
	for _, n := range nodes {
		if n.ID == id {
			return n
		}
	}
	t.Fatalf("missing real node %s", id)
	return reviewNode{}
}

func TestPresentationHomeShoppingPrecedesAncillaryNavigation(t *testing.T) {
	d, nodes := presentationScreen(t, "home?deviceId=test-device")
	positions := map[string]int{}
	for i, n := range nodes {
		positions[n.ID] = i
		if n.Type == "ProfileHeader" {
			t.Error("home still has oversized identity block")
		}
	}
	if presentationNode(t, nodes, "home.title").Props["text"] != "ShopKMP" {
		t.Error("missing brand heading")
	}
	if positions["catalog.q"] >= positions["catalog.categories"] || positions["catalog.categories"] >= positions["catalog.products"] || positions["catalog.products"] >= positions["home.catalog"] {
		t.Error("shopping hierarchy interrupted by ancillary links")
	}
	if d.State.CartItemsCount != 2 {
		t.Error("lost validated session count")
	}
}

func TestPresentationProductImagesThenActualBodyTitleAndFullCTA(t *testing.T) {
	d, nodes := presentationScreen(t, "product/"+pid)
	if nodes[1].Type != "Image" {
		t.Errorf("first body node = %s, want image", nodes[1].Type)
	}
	title := presentationNode(t, nodes, "product.title")
	if title.Props["text"] != "Real lamp" || title.Props["style"] != "Title" {
		t.Errorf("actual body title lost: %+v", title)
	}
	if presentationNode(t, nodes, "product.quantity.label").Props["text"] != "Количество" {
		t.Error("quantity label")
	}
	if presentationNode(t, nodes, "product.buy").Props["label"] != "Добавить в корзину" {
		t.Error("incomplete CTA")
	}
	if d.State.Fields["product.quantity"] != "1" {
		t.Error("lost controlled quantity")
	}
	for _, a := range d.Actions {
		if a["id"] == "product.add" && a["type"] == "AddToCart" {
			return
		}
	}
	t.Fatal("lost original purchase action")
}

func TestPresentationOrdersLabelsAreLocalizedButCanonicalValuesStay(t *testing.T) {
	_, nodes := presentationScreen(t, "orders")
	status := presentationNode(t, nodes, "orders.status")
	options := status.Props["options"].([]any)
	want := map[string]string{"CREATED": "Создан", "PAID": "Оплачен", "ASSEMBLING": "Собирается", "DELIVERING": "Доставляется", "COMPLETED": "Выполнен", "CANCELLED": "Отменён"}
	for _, o := range options {
		v := o.(map[string]any)
		if label, ok := want[v["value"].(string)]; ok && v["label"] != label {
			t.Errorf("%s label = %s", v["value"], v["label"])
		}
	}
	if presentationNode(t, nodes, "order."+oid).Props["status"] != "CREATED" {
		t.Error("canonical status changed")
	}
}

func TestPresentationOrderPaymentLabelIsDisplayOnly(t *testing.T) {
	_, nodes := presentationScreen(t, "order/"+oid)
	if presentationNode(t, nodes, "order.payment").Props["text"] != "При получении" {
		t.Error("raw payment enum leaked into display text")
	}
	if presentationNode(t, nodes, "order."+oid).Props["paymentMethod"] != "ON_DELIVERY" {
		t.Error("canonical payment method changed")
	}
}

func TestPresentationProfileOverviewRetainsAllAddressesAndNativeActions(t *testing.T) {
	h := newTestHandler(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/addresses" {
			fmt.Fprintf(w, `[{"id":%q,"userId":%q,"city":"Other","street":"First","house":"9","apartment":"","comment":null},{"id":%q,"userId":%q,"city":"Moscow","street":"Light","house":"1","apartment":"2","comment":null}]`, oid, uid, aid, uid)
			return
		}
		fixtureUpstream(w, r)
	})
	w := request(h, "GET", "/api/v1/screens/profile", "", "valid")
	if w.Code != 200 {
		t.Fatalf("HTTP %d: %s", w.Code, w.Body)
	}
	d, nodes := reviewNodes(t, w.Body.Bytes())
	for _, n := range nodes {
		if n.ID == "profile.form" {
			t.Error("new overview still contains inline editor")
		}
	}
	if presentationNode(t, nodes, "profile.address.default").Props["text"] != "Moscow, Light, 1, 2" {
		t.Error("not the actual default address")
	}
	presentationNode(t, nodes, "address."+oid)
	for _, kind := range []string{"EditProfile", "ChangePin", "ManageBiometric", "Logout"} {
		found := false
		for _, a := range d.Actions {
			if a["type"] == kind {
				found = true
			}
		}
		if !found {
			t.Errorf("lost %s", kind)
		}
	}
	var envelope map[string]any
	json.Unmarshal(w.Body.Bytes(), &envelope)
	if envelope["metadata"].(map[string]any)["accountId"] != uid {
		t.Error("lost owner partition")
	}
}
