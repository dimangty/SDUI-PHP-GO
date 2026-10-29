package application

import (
	"net/url"
	"shopkmp/backend-sdui/internal/application/model"
	"shopkmp/backend-sdui/internal/sdui"
	"strconv"
)

func pageValues(q url.Values) (int, int, error) {
	page, size := 0, 20
	var err error
	if q.Has("page") {
		page, err = strconv.Atoi(q.Get("page"))
		if err != nil {
			return 0, 0, bad("Invalid page")
		}
	}
	if q.Has("size") {
		size, err = strconv.Atoi(q.Get("size"))
		if err != nil {
			return 0, 0, bad("Invalid size")
		}
	}
	return page, size, nil
}
func ParseCatalogQuery(q url.Values) (model.CatalogQuery, error) {
	page, size, err := pageValues(q)
	if err != nil {
		return model.CatalogQuery{}, err
	}
	v := model.CatalogQuery{Query: q.Get("q"), CategoryID: q.Get("categoryId"), Sort: q.Get("sort"), PriceMin: q.Get("priceMin"), PriceMax: q.Get("priceMax"), Page: page, Size: size}
	if err := v.Validate(); err != nil {
		return v, bad(err.Error())
	}
	return v, nil
}
func orderQuery(q url.Values) (model.OrderQuery, error) {
	page, size, err := pageValues(q)
	if err != nil {
		return model.OrderQuery{}, err
	}
	v := model.OrderQuery{Status: q.Get("status"), Page: page, Size: size}
	if err := v.Validate(); err != nil {
		return v, bad(err.Error())
	}
	return v, nil
}
func screenBase(name, title string, user *model.User) sdui.Screen {
	d := sdui.Screen{SchemaVersion: 1, ScreenID: name, Components: []sdui.Node{}, Actions: []sdui.Action{}, State: sdui.State{Fields: map[string]string{}}, Metadata: sdui.Metadata{Title: title, Locale: "ru-RU"}}
	if user != nil {
		d.State.Authenticated = true
		d.Metadata.AccountID = user.ID
	}
	return d
}
