package bff

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/url"
	"sort"
	"sync"

	"shopkmp/backend-sdui/internal/domain"
	"shopkmp/backend-sdui/internal/sdui"
	"shopkmp/backend-sdui/internal/yii"
)

func shortID(value string) string {
	h := sha256.Sum256([]byte(value))
	return "device." + hex.EncodeToString(h[:12])
}
func securityError(err error) bool {
	status := yii.HTTPStatus(err)
	return status == 401 || status == 403
}
func (s *Application) build(ctx context.Context, id yii.Identity, user *yii.User, name, resource string, q url.Values) (sdui.Screen, error) {
	switch name {
	case "home":
		return s.home(ctx, id, user, q)
	case "catalog":
		return s.catalog(ctx, id, user, q)
	case "product":
		return s.product(ctx, id, user, resource)
	case "cart":
		return s.cart(ctx, id, user, q)
	case "orders":
		return s.orders(ctx, id, user, q)
	case "order":
		return s.order(ctx, id, user, resource)
	case "profile":
		return s.profile(ctx, id, user)
	case "devices":
		return s.devices(ctx, id, user)
	}
	return sdui.Screen{}, bad("Unknown screen")
}

func (s *Application) home(ctx context.Context, id yii.Identity, u *yii.User, q url.Values) (sdui.Screen, error) {
	d := screenBase("home", "ShopKMP", u)
	categoryQuery, err := collectionQuery(q, "categoryPage", "categorySize", sdui.MaxCategoryPageSize)
	if err != nil {
		return d, err
	}
	dest := sdui.Destination{Screen: "home", Categories: &categoryQuery}
	refresh(&d, dest)
	if u != nil && !yii.ValidDevice(q.Get("deviceId")) {
		return d, bad("Personalized home requires stable deviceId")
	}
	aggregateCtx, cancelAggregate := context.WithCancel(ctx)
	defer cancelAggregate()
	cancelOnSecurity := func(err error) {
		if securityError(err) {
			cancelAggregate()
		}
	}
	var cats []yii.Category
	var popular []yii.Product
	var session yii.Session
	var ce, pe, se error
	var wg sync.WaitGroup
	wg.Add(2)
	go func() { defer wg.Done(); cats, ce = s.up.Categories(aggregateCtx, id); cancelOnSecurity(ce) }()
	go func() { defer wg.Done(); popular, pe = s.up.Popular(aggregateCtx, id); cancelOnSecurity(pe) }()
	if u != nil {
		wg.Add(1)
		go func() {
			defer wg.Done()
			session, se = s.up.Session(aggregateCtx, id, q.Get("deviceId"))
			cancelOnSecurity(se)
		}()
	}
	wg.Wait()
	for _, err := range []error{ce, pe, se} {
		if securityError(err) {
			return d, err
		}
	}
	if ctx.Err() != nil {
		return d, ctx.Err()
	}
	nodes := []sdui.Node{text("home.title", "ShopKMP", "ScreenTitle")}
	ancillary := []sdui.Node{nav(&d, "home.catalog", "Каталог", "catalog")}
	search := action(&d, sdui.Action{ID: "home.search.submit", Type: "SubmitForm", FormID: "catalog"})
	nodes = append(nodes, sdui.N("home.search.form", "Form", sdui.FormProps{FormID: "catalog", Title: "Поиск", SubmitAction: search}, field(&d, "catalog.q", "Поиск товаров", "", "Text", false, 200, nil), button("home.search.button", "Найти", search, true)))
	d.State.Fields["catalog.page"] = "0"
	d.State.Fields["catalog.size"] = "20"
	if u != nil {
		ancillary = append(ancillary, nav(&d, "home.profile", "Профиль", "profile"))
		if se == nil {
			if err := ownership(domain.EqualID(session.User.ID, u.ID)); err != nil {
				return d, err
			}
			d.State.CartItemsCount = session.CartItemsCount
			ancillary = append(ancillary, nav(&d, "home.cart", fmt.Sprintf("Корзина (%d)", session.CartItemsCount), "cart"))
		} else {
			ancillary = append(ancillary, partial(&d, "session", dest))
		}
	} else {
		ref := action(&d, sdui.Action{ID: "home.login.action", Type: "Login"})
		ancillary = append(ancillary, button("home.login", "Войти", ref, true))
	}
	if ce != nil {
		nodes = append(nodes, partial(&d, "categories", dest))
	} else {
		visible, meta := collectionWindow(cats, categoryQuery)
		categoryQuery.Page = meta.Page
		popularCount := 0
		if pe == nil {
			popularCount = len(popular)
		}
		if err := collectionBudget(popularCount, 1, len(visible)); err != nil {
			return d, err
		}
		nodes = append(nodes, text("home.categories.title", "Категории", "SectionTitle"), categories(&d, visible, yii.CatalogQuery{Size: 20}, categoryQuery))
		nodes = append(nodes, collectionControls(&d, "categories", meta, dest)...)
	}
	if pe != nil {
		nodes = append(nodes, partial(&d, "popular", dest))
	} else {
		nodes = append(nodes, text("home.popular.title", "Популярные товары", "SectionTitle"))
		if len(popular) == 0 {
			nodes = append(nodes, empty("home.popular.empty", "Пока нет популярных товаров", "Посмотрите весь каталог"))
		} else {
			nodes = append(nodes, productGrid(&d, popular))
		}
	}
	nodes = append(nodes, ancillary...)
	root(&d, nodes...)
	return d, nil
}
func mediaPointer(s *string) *string {
	if s == nil {
		return nil
	}
	return sdui.Media(*s)
}

func (s *Application) catalog(ctx context.Context, id yii.Identity, u *yii.User, q url.Values) (sdui.Screen, error) {
	d := screenBase("catalog", "Каталог", u)
	query, err := catalogQuery(q)
	if err != nil {
		return d, err
	}
	categoryQuery, err := collectionQuery(q, "categoryPage", "categorySize", sdui.MaxCategoryPageSize)
	if err != nil {
		return d, err
	}
	products, err := s.up.Products(ctx, id, query)
	if err != nil {
		return d, err
	}
	cats, err := s.up.Categories(ctx, id)
	if err != nil {
		return d, err
	}
	visibleCats, categoryMeta := collectionWindow(cats, categoryQuery)
	categoryQuery.Page = categoryMeta.Page
	if err := collectionBudget(len(products.Items), 1, len(visibleCats)); err != nil {
		return d, err
	}
	dest := sdui.Destination{Screen: "catalog", Catalog: &query, Categories: &categoryQuery}
	refresh(&d, dest)
	submit := action(&d, sdui.Action{ID: "catalog.submit", Type: "SubmitForm", FormID: "catalog"})
	catOptions := categoryOptions(cats, visibleCats, query.CategoryID)
	form := sdui.N("catalog.form", "Form", sdui.FormProps{FormID: "catalog", Title: "Поиск и фильтры", SubmitAction: submit},
		field(&d, "catalog.q", "Поиск", query.Query, "Text", false, 200, nil),
		field(&d, "catalog.categoryId", "Категория", query.CategoryID, "Select", false, 36, catOptions),
		field(&d, "catalog.sort", "Сортировка", query.Sort, "Select", false, 20, []sdui.Option{{Value: "", Label: "По умолчанию"}, {Value: "price_asc", Label: "Дешевле"}, {Value: "price_desc", Label: "Дороже"}, {Value: "rating", Label: "Рейтинг"}, {Value: "new", Label: "Новинки"}}),
		field(&d, "catalog.priceMin", "Цена от", query.PriceMin, "Text", false, 13, nil), field(&d, "catalog.priceMax", "Цена до", query.PriceMax, "Text", false, 13, nil), button("catalog.search", "Найти", submit, true))
	d.State.Fields["catalog.page"] = quantityField(query.Page)
	d.State.Fields["catalog.size"] = quantityField(query.Size)
	nodes := []sdui.Node{text("catalog.title", "Каталог", "ScreenTitle"), form, categories(&d, visibleCats, query, categoryQuery)}
	nodes = append(nodes, collectionControls(&d, "categories", categoryMeta, dest)...)
	if len(products.Items) == 0 {
		nodes = append(nodes, empty("catalog.empty", "Товары не найдены", "Измените поиск или фильтры"))
	} else {
		nodes = append(nodes, productGrid(&d, products.Items))
	}
	nodes = append(nodes, pagination(&d, products, dest)...)
	root(&d, nodes...)
	return d, nil
}
func (s *Application) product(ctx context.Context, id yii.Identity, u *yii.User, pid string) (sdui.Screen, error) {
	d := screenBase("product", "Товар", u)
	p, err := s.up.Product(ctx, id, pid)
	if err != nil {
		return d, err
	}
	if !domain.EqualID(p.ID, pid) {
		return d, &yii.TransportError{Kind: "resource_mismatch"}
	}
	refresh(&d, sdui.Destination{Screen: "product", ID: pid})
	nodes := []sdui.Node{}
	images := map[string]bool{}
	for _, img := range p.ImageURLs {
		if source := sdui.Media(img); source != nil {
			if images[*source] {
				continue
			}
			images[*source] = true
			nodes = append(nodes, sdui.N(imageID(*source), "Image", sdui.ImageProps{Source: *source, Description: p.Name, Aspect: "Product"}))
		}
	}
	sale := p.Price
	if p.DiscountPrice != nil {
		sale = *p.DiscountPrice
	}
	nodes = append(nodes, text("product.title", p.Name, "Title"))
	rating := "Нет оценок"
	if p.Rating != nil {
		rating = fmt.Sprintf("Рейтинг: %.1f", *p.Rating)
	}
	nodes = append(nodes, text("product.rating", fmt.Sprintf("%s · Отзывов: %d", rating, p.ReviewsCount), "Secondary"), price("product.price", sale))
	nodes = append(nodes, descriptionNodes(p.Description)...)
	nodes = append(nodes, text("product.stock", fmt.Sprintf("В наличии: %d", p.Stock), "Secondary"), text("product.quantity.label", "Количество", "SectionTitle"))
	d.State.Fields["product.quantity"] = "1"
	quantity := action(&d, sdui.Action{ID: "product.quantity.change", Type: "UpdateField", FieldID: "product.quantity"})
	maxStock := min(1000000, max(1, p.Stock))
	nodes = append(nodes, sdui.N("product.quantity", "QuantityStepper", sdui.StepperProps{FieldID: "product.quantity", Value: 1, Min: 1, Max: maxStock, ActionID: quantity, Enabled: p.IsActive && p.Stock > 0}))
	a := sdui.Action{ID: "product.add", Type: "AddToCart", ProductID: p.ID, QuantityField: "product.quantity"}
	if u == nil {
		a = sdui.Action{ID: "product.add", Type: "Login"}
	}
	ref := action(&d, a)
	nodes = append(nodes, button("product.buy", "Добавить в корзину", ref, p.IsActive && p.Stock > 0))
	root(&d, nodes...)
	return d, nil
}
func (s *Application) cart(ctx context.Context, id yii.Identity, u *yii.User, q url.Values) (sdui.Screen, error) {
	d := screenBase("cart", "Корзина", u)
	query, err := collectionQuery(q, "page", "size", sdui.MaxCartPageSize)
	if err != nil {
		return d, err
	}
	cart, err := s.up.Cart(ctx, id)
	if err != nil {
		return d, err
	}
	if err = ownership(domain.EqualID(cart.UserID, u.ID)); err != nil {
		return d, err
	}
	sort.Slice(cart.Items, func(i, j int) bool { return cart.Items[i].ProductID < cart.Items[j].ProductID })
	visible, meta := collectionWindow(cart.Items, query)
	query.Page = meta.Page
	if err := collectionBudget(len(visible), 3, 0); err != nil {
		return d, err
	}
	dest := sdui.Destination{Screen: "cart", Cart: &query}
	refresh(&d, dest)
	nodes := []sdui.Node{text("cart.title", "Корзина", "ScreenTitle")}
	for _, item := range cart.Items {
		// Check before adding, including rows outside the visible window. Never
		// overflow Go int or publish a count CMP's Kotlin Int cannot represent.
		if item.Quantity < 0 || item.Quantity > sdui.MaxClientInt-d.State.CartItemsCount {
			return d, &Error{502, "BFF_INVALID_DOCUMENT", "Upstream data cannot form a safe screen"}
		}
		d.State.CartItemsCount += item.Quantity
	}
	for _, item := range visible {
		base := "cart." + item.ProductID
		open := action(&d, sdui.Action{ID: base + ".open", Type: "OpenProduct", ProductID: item.ProductID})
		update := action(&d, sdui.Action{ID: base + ".quantity", Type: "UpdateQuantity", ProductID: item.ProductID})
		remove := action(&d, sdui.Action{ID: base + ".remove", Type: "RemoveFromCart", ProductID: item.ProductID})
		nodes = append(nodes, sdui.N(base, "CartItem", sdui.CartItemProps{ProductID: item.ProductID, Name: item.ProductName, Quantity: item.Quantity, Price: item.PriceSnapshot, Image: mediaPointer(item.ImageURL), Available: item.IsAvailable, Stock: item.Stock, OpenAction: open, QuantityAction: update, RemoveAction: remove}))
	}
	nodes = append(nodes, collectionControls(&d, "cart", meta, dest)...)
	if len(cart.Items) == 0 {
		nodes = append(nodes, empty("cart.empty", "Корзина пуста", "Добавьте товары из каталога"), nav(&d, "cart.catalog", "В каталог", "catalog"))
	} else {
		addresses, err := s.up.Addresses(ctx, id)
		if err != nil {
			return d, err
		}
		for _, a := range addresses {
			if err := ownership(domain.EqualID(a.UserID, u.ID)); err != nil {
				return d, err
			}
		}
		nodes = append(nodes, sdui.N("cart.divider", "Divider", sdui.DividerProps{}), price("cart.total", cart.TotalAmount), checkoutForm(&d, addresses, u.DefaultAddressID))
	}
	root(&d, nodes...)
	return d, nil
}
func (s *Application) orders(ctx context.Context, id yii.Identity, u *yii.User, q url.Values) (sdui.Screen, error) {
	d := screenBase("orders", "Заказы", u)
	query, err := orderQuery(q)
	if err != nil {
		return d, err
	}
	page, err := s.up.Orders(ctx, id, query)
	if err != nil {
		return d, err
	}
	dest := sdui.Destination{Screen: "orders", Orders: &query}
	refresh(&d, dest)
	submit := action(&d, sdui.Action{ID: "orders.submit", Type: "SubmitForm", FormID: "orders"})
	options := []sdui.Option{{Value: "", Label: "Все"}}
	for _, st := range []string{"CREATED", "PAID", "ASSEMBLING", "DELIVERING", "COMPLETED", "CANCELLED"} {
		options = append(options, sdui.Option{Value: st, Label: orderStatusLabel(st)})
	}
	nodes := []sdui.Node{text("orders.title", "История заказов", "ScreenTitle"), sdui.N("orders.form", "Form", sdui.FormProps{FormID: "orders", Title: "Фильтр", SubmitAction: submit}, field(&d, "orders.status", "Статус", query.Status, "Select", false, 20, options), button("orders.filter", "Применить", submit, true))}
	d.State.Fields["orders.page"] = quantityField(query.Page)
	d.State.Fields["orders.size"] = quantityField(query.Size)
	for _, o := range page.Items {
		if err := ownership(domain.EqualID(o.UserID, u.ID)); err != nil {
			return d, err
		}
		nodes = append(nodes, orderCard(&d, o))
	}
	if len(page.Items) == 0 {
		nodes = append(nodes, empty("orders.empty", "Заказов пока нет", "Выберите товары в каталоге"))
	}
	nodes = append(nodes, pagination(&d, page, dest)...)
	root(&d, nodes...)
	return d, nil
}
func (s *Application) order(ctx context.Context, id yii.Identity, u *yii.User, oid string) (sdui.Screen, error) {
	d := screenBase("order", "Заказ", u)
	o, err := s.up.Order(ctx, id, oid)
	if err != nil {
		return d, err
	}
	if err = ownership(domain.EqualID(o.UserID, u.ID)); err != nil {
		return d, err
	}
	if !domain.EqualID(o.ID, oid) {
		return d, &yii.TransportError{Kind: "resource_mismatch"}
	}
	refresh(&d, sdui.Destination{Screen: "order", ID: oid})
	nodes := []sdui.Node{text("order.title", fmt.Sprintf("Заказ №%d", o.Number), "ScreenTitle"), orderCard(&d, o), text("order.address", o.DeliveryAddress, "Body"), text("order.payment", paymentLabel(o.PaymentMethod), "Secondary")}
	items, err := orderItemNodes(o.Items)
	if err != nil {
		return d, err
	}
	nodes = append(nodes, items...)
	nodes = append(nodes, price("order.total", o.TotalAmount))
	if o.Comment != nil {
		nodes = append(nodes, text("order.comment", *o.Comment, "Secondary"))
	}
	if o.Status == "CREATED" || o.Status == "PAID" {
		ref := action(&d, sdui.Action{ID: "order.cancel", Type: "CancelOrder", OrderID: oid, Confirmation: &sdui.Confirmation{Title: "Отменить заказ?", Message: "Заказ будет отменён. Возврат обрабатывает магазин.", ConfirmLabel: "Отменить заказ"}})
		nodes = append(nodes, button("order.cancel.button", "Отменить заказ", ref, true))
	}
	root(&d, nodes...)
	return d, nil
}
func (s *Application) profile(ctx context.Context, id yii.Identity, u *yii.User) (sdui.Screen, error) {
	d := screenBase("profile", "Профиль", u)
	addresses, err := s.up.Addresses(ctx, id)
	if err != nil {
		return d, err
	}
	refresh(&d, sdui.Destination{Screen: "profile"})
	nodes := []sdui.Node{text("profile.title", "Профиль", "ScreenTitle"), sdui.N("profile.header", "ProfileHeader", sdui.ProfileProps{FullName: u.FullName, Email: u.Email, Phone: u.Phone, Avatar: mediaPointer(u.AvatarURL)})}
	for _, a := range addresses {
		if err := ownership(domain.EqualID(a.UserID, u.ID)); err != nil {
			return d, err
		}
	}
	addressContent, err := addressNodes(addresses)
	if err != nil {
		return d, err
	}
	nodes = append(nodes, text("profile.address.default", defaultAddressSummary(addresses, u.DefaultAddressID), "Body"))
	nodes = append(nodes, addressContent...)
	nodes = append(nodes, nav(&d, "profile.orders", "Мои заказы", "orders"), nav(&d, "profile.devices", "Мои устройства", "devices"))
	for _, v := range []struct{ id, label, kind string }{{"edit", "Редактировать профиль", "EditProfile"}, {"pin", "Изменить PIN", "ChangePin"}, {"biometric", "Биометрия", "ManageBiometric"}, {"logout", "Выйти", "Logout"}} {
		ref := action(&d, sdui.Action{ID: "profile." + v.id, Type: v.kind})
		nodes = append(nodes, button("profile."+v.id+".button", v.label, ref, true))
	}
	root(&d, nodes...)
	return d, nil
}
func (s *Application) devices(ctx context.Context, id yii.Identity, u *yii.User) (sdui.Screen, error) {
	d := screenBase("devices", "Устройства", u)
	pins, err := s.up.Devices(ctx, id, "pin")
	if err != nil {
		return d, err
	}
	bio, err := s.up.Devices(ctx, id, "biometric")
	if err != nil {
		return d, err
	}
	refresh(&d, sdui.Destination{Screen: "devices"})
	nodes := []sdui.Node{text("devices.title", "Устройства быстрого входа", "ScreenTitle")}
	// Preserve small-screen IDs and layout. Compact rows need two nodes each,
	// plus the root/title; Encode enforces the full node/action/body budgets.
	compact := len(pins)+len(bio) > (sdui.MaxNodes-2)/5
	nodes = append(nodes, deviceNodes(&d, "pin", pins, compact)...)
	nodes = append(nodes, deviceNodes(&d, "biometric", bio, compact)...)
	if len(pins)+len(bio) == 0 {
		nodes = append(nodes, empty("devices.empty", "Нет устройств", "Настройте PIN или биометрию на устройстве"))
	}
	root(&d, nodes...)
	return d, nil
}
