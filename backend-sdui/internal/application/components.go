package application

import (
	"fmt"
	"strconv"

	"shopkmp/backend-sdui/internal/application/model"
	"shopkmp/backend-sdui/internal/domain"
	"shopkmp/backend-sdui/internal/sdui"
)

func action(d *sdui.Screen, a sdui.Action) string { d.Actions = append(d.Actions, a); return a.ID }
func text(id, value, style string) sdui.Node {
	return sdui.N(id, "Text", sdui.TextProps{Text: value, Style: style})
}
func button(id, label, ref string, enabled bool) sdui.Node {
	return sdui.N(id, "Button", sdui.ButtonProps{Label: label, ActionID: ref, Style: "Primary", Enabled: enabled})
}
func nav(d *sdui.Screen, id, label, screen string) sdui.Node {
	ref := action(d, sdui.Action{ID: id + ".navigate", Type: "Navigate", Destination: &sdui.Destination{Screen: screen}})
	return button(id, label, ref, true)
}
func root(d *sdui.Screen, nodes ...sdui.Node) {
	d.Components = []sdui.Node{sdui.N("screen.content", "LazyColumn", sdui.LayoutProps{Spacing: "m", Padding: "m", Align: "Start"}, nodes...)}
}
func refresh(d *sdui.Screen, dest sdui.Destination) {
	action(d, sdui.Action{ID: "screen.refresh", Type: "Refresh", Destination: &dest})
}
func field(d *sdui.Screen, id, label, value, input string, required bool, limit int, options []sdui.Option) sdui.Node {
	d.State.Fields[id] = value
	ref := action(d, sdui.Action{ID: "field." + id, Type: "UpdateField", FieldID: id})
	kind := "FormField"
	if id == "catalog.q" {
		kind = "SearchField"
	}
	props := sdui.FieldProps{FieldID: id, Label: label, Input: input, Required: required, MaxLength: limit, Options: options, ChangeAction: ref}
	if id == "catalog.q" {
		props.MaxUTF8Bytes = 200
	}
	return sdui.N(id, kind, props)
}
func categories(d *sdui.Screen, items []model.Category, query model.CatalogQuery, collection sdui.CollectionQuery) sdui.Node {
	props := sdui.CategoriesProps{Items: []sdui.CategoryOption{}}
	for _, c := range items {
		q := query
		q.CategoryID = c.ID
		q.Page = 0
		ref := action(d, sdui.Action{ID: "category." + c.ID, Type: "Navigate", Destination: &sdui.Destination{Screen: "catalog", Catalog: &q, Categories: &collection}})
		props.Items = append(props.Items, sdui.CategoryOption{ID: c.ID, Label: c.Name, Image: sdui.Media(c.IconURL), ActionID: ref, Selected: domain.EqualID(query.CategoryID, c.ID)})
	}
	return sdui.N("catalog.categories", "CategoryList", props)
}
func productCard(d *sdui.Screen, p model.Product) sdui.Node {
	ref := action(d, sdui.Action{ID: "product." + p.ID + ".open", Type: "OpenProduct", ProductID: p.ID})
	var image *string
	if len(p.ImageURLs) > 0 {
		image = sdui.Media(p.ImageURLs[0])
	}
	preview, truncated := descriptionPreview(p.Description)
	return sdui.N("product."+p.ID, "ProductCard", sdui.ProductProps{ProductID: p.ID, Name: p.Name, Description: preview, DescriptionTruncated: truncated, Price: p.Price, DiscountPrice: p.DiscountPrice, Image: image, Stock: p.Stock, Rating: p.Rating, ReviewsCount: p.ReviewsCount, OpenAction: ref})
}
func productGrid(d *sdui.Screen, products []model.Product) sdui.Node {
	nodes := []sdui.Node{}
	for _, p := range products {
		nodes = append(nodes, productCard(d, p))
	}
	return sdui.N("catalog.products", "ProductGrid", sdui.GridProps{Columns: 2}, nodes...)
}
func empty(id, title, message string) sdui.Node {
	return sdui.N(id, "Empty", sdui.StatusProps{Title: title, Message: message})
}
func partial(d *sdui.Screen, section string, dest sdui.Destination) sdui.Node {
	d.Metadata.Partial = true
	d.Metadata.Warnings = append(d.Metadata.Warnings, section+"_unavailable")
	ref := action(d, sdui.Action{ID: section + ".retry", Type: "Retry", Destination: &dest})
	return sdui.N(section+".error", "Error", sdui.StatusProps{Title: "Раздел недоступен", Message: "Попробуйте обновить экран", RetryAction: ref})
}
func orderCard(d *sdui.Screen, o model.OrderSummary) sdui.Node {
	ref := action(d, sdui.Action{ID: "order." + o.ID + ".open", Type: "OpenOrder", OrderID: o.ID})
	return sdui.N("order."+o.ID, "OrderCard", sdui.OrderProps{OrderID: o.ID, Number: o.Number, Status: o.Status, Total: o.TotalAmount, DeliveryAddress: o.DeliveryAddress, PaymentMethod: o.PaymentMethod, CreatedAt: o.CreatedAt, OpenAction: ref})
}

// Labels are presentation only. Query values and OrderProps retain canonical enums.
func orderStatusLabel(status string) string {
	return map[string]string{"CREATED": "Создан", "PAID": "Оплачен", "ASSEMBLING": "Собирается", "DELIVERING": "Доставляется", "COMPLETED": "Выполнен", "CANCELLED": "Отменён"}[status]
}

func paymentLabel(method string) string {
	return map[string]string{"CARD": "Карта", "SBP": "СБП", "ON_DELIVERY": "При получении"}[method]
}
func price(id string, m model.Money) sdui.Node {
	return sdui.N(id, "Price", sdui.PriceProps{Amount: m, Currency: "RUB"})
}
func pagination[T any](d *sdui.Screen, p model.Page[T], dest sdui.Destination) []sdui.Node {
	d.State.Pagination = &sdui.Pagination{Page: p.Page, Size: p.Size, TotalPages: p.TotalPages, TotalItems: p.TotalItems}
	nodes := []sdui.Node{}
	for _, v := range []struct {
		id, label string
		page      int
	}{{"previous", "Назад", p.Page - 1}, {"next", "Далее", p.Page + 1}} {
		if v.page < 0 || v.page >= p.TotalPages {
			continue
		}
		next := dest
		if dest.Catalog != nil {
			q := *dest.Catalog
			q.Page = v.page
			if q.Validate() != nil {
				continue
			}
			next.Catalog = &q
		}
		if dest.Orders != nil {
			q := *dest.Orders
			q.Page = v.page
			next.Orders = &q
		}
		ref := action(d, sdui.Action{ID: "page." + v.id, Type: "Navigate", Destination: &next})
		nodes = append(nodes, button("page."+v.id, v.label, ref, true))
	}
	return nodes
}
func checkoutForm(d *sdui.Screen, addresses []model.Address, def *string) sdui.Node {
	chosen := model.Address{}
	if len(addresses) > 0 {
		chosen = addresses[0]
	}
	for _, a := range addresses {
		if def != nil && domain.EqualID(a.ID, *def) {
			chosen = a
		}
	}
	ref := action(d, sdui.Action{ID: "checkout.submit", Type: "Checkout", FormID: "checkout"})
	children := []sdui.Node{
		field(d, "checkout.city", "Город", chosen.City, "Text", true, 255, nil),
		field(d, "checkout.street", "Улица", chosen.Street, "Text", true, 255, nil),
		field(d, "checkout.house", "Дом", chosen.House, "Text", true, 64, nil),
		field(d, "checkout.apartment", "Квартира", chosen.Apartment, "Text", false, 64, nil),
		field(d, "checkout.paymentMethod", "Способ оплаты", "CARD", "Select", true, 20, []sdui.Option{{Value: "CARD", Label: "Карта"}, {Value: "SBP", Label: "СБП"}, {Value: "ON_DELIVERY", Label: "При получении"}}),
		button("checkout.button", "Оформить заказ", ref, true),
	}
	return sdui.N("checkout.form", "Form", sdui.FormProps{FormID: "checkout", Title: "Доставка и оплата", SubmitAction: ref}, children...)
}
func quantityField(q int) string { return strconv.Itoa(q) }
func deviceNodes(d *sdui.Screen, kind string, devices []model.Device, compact bool) []sdui.Node {
	nodes := []sdui.Node{}
	for _, v := range devices {
		id := "device." + kind + "." + v.DeviceID
		// Device IDs can be 255 bytes; use a stable short hash for view identity.
		id = shortID(id)
		if v.DeviceID == "." || v.DeviceID == ".." {
			// These historical values cannot be expressed by CMP's RevokeDevice
			// contract. Preserve the record and explain the supported remedy.
			nodes = append(nodes, text(id+".info", fmt.Sprintf("%s\n%s\n%s · %s\nУстройство использует устаревший идентификатор; требуется отзыв или исправление через аккаунт, API или оператора.", v.DeviceName, v.DeviceID, kind, v.LastUsedAt), "Body"))
			continue
		}
		ref := action(d, sdui.Action{ID: id + ".revoke", Type: "RevokeDevice", DeviceID: v.DeviceID, DeviceKind: kind, Confirmation: &sdui.Confirmation{Title: "Отозвать устройство?", Message: v.DeviceName, ConfirmLabel: "Отозвать"}})
		if compact {
			// Flat siblings remain independently reachable in CMP's LazyColumn.
			// Each label stays next to its own unchanged button and confirmation.
			nodes = append(nodes, text(id+".info", fmt.Sprintf("%s\n%s\n%s · %s", v.DeviceName, v.DeviceID, kind, v.LastUsedAt), "Body"), button(id+".button", "Отозвать", ref, true))
			continue
		}
		nodes = append(nodes, sdui.N(id, "Column", sdui.LayoutProps{Spacing: "s", Padding: "s", Align: "Start"}, text(id+".name", v.DeviceName, "Title"), text(id+".id", v.DeviceID, "Secondary"), text(id+".last", fmt.Sprintf("%s · %s", kind, v.LastUsedAt), "Secondary"), button(id+".button", "Отозвать", ref, true)))
	}
	return nodes
}
