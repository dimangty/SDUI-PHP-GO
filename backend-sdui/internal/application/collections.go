package application

import (
	"fmt"
	"net/url"
	"strconv"

	"shopkmp/backend-sdui/internal/application/model"
	"shopkmp/backend-sdui/internal/domain"
	"shopkmp/backend-sdui/internal/sdui"
)

// Disjoint collection caps reserve complete screen chrome, both pagers and
// native checkout. At most 100 cart rows*3 actions+16 chrome=316 actions, or
// 100 products+50 categories+16 chrome=166. Worst-case canonical names/media and
// 256-code-point previews also fit 1MiB; Encode validates the complete result.
const defaultCollectionSize = 50

func ParseCollectionQuery(q url.Values, pageKey, sizeKey string, maxSize int) (sdui.CollectionQuery, error) {
	out := sdui.CollectionQuery{Size: defaultCollectionSize}
	var err error
	if q.Has(pageKey) {
		out.Page, err = strconv.Atoi(q.Get(pageKey))
		if err != nil {
			return out, bad("Invalid collection page")
		}
	}
	if q.Has(sizeKey) {
		out.Size, err = strconv.Atoi(q.Get(sizeKey))
		if err != nil {
			return out, bad("Invalid collection size")
		}
	}
	if err := out.Validate(maxSize); err != nil {
		return out, bad(err.Error())
	}
	return out, nil
}
func collectionWindow[T any](items []T, q sdui.CollectionQuery) ([]T, sdui.Pagination) {
	total := len(items)
	pages := (total + q.Size - 1) / q.Size
	page := 0
	if pages > 0 {
		page = min(q.Page, pages-1)
	}
	start := page * q.Size
	end := min(start+q.Size, total)
	return items[start:end], sdui.Pagination{Page: page, Size: q.Size, TotalPages: pages, TotalItems: total}
}
func collectionControls(d *sdui.Screen, key string, p sdui.Pagination, dest sdui.Destination) []sdui.Node {
	if d.State.Collections == nil {
		d.State.Collections = map[string]sdui.Pagination{}
	}
	d.State.Collections[key] = p
	label := "Категории"
	if key == "cart" {
		label = "Товары в корзине"
	}
	start, end := 0, 0
	if p.TotalItems > 0 {
		start = p.Page*p.Size + 1
		end = min((p.Page+1)*p.Size, p.TotalItems)
	}
	nodes := []sdui.Node{text("collection."+key+".range", fmt.Sprintf("%s: %d–%d из %d", label, start, end, p.TotalItems), "Secondary")}
	for _, v := range []struct {
		name, label string
		page        int
	}{{"previous", "Предыдущие", p.Page - 1}, {"next", "Следующие", p.Page + 1}} {
		if v.page < 0 || v.page >= p.TotalPages {
			continue
		}
		next := dest
		query := sdui.CollectionQuery{Page: v.page, Size: p.Size}
		if key == "cart" {
			next.Cart = &query
		} else {
			next.Categories = &query
		}
		id := "collection." + key + "." + v.name
		ref := action(d, sdui.Action{ID: id, Type: "Navigate", Destination: &next})
		nodes = append(nodes, button(id+".button", v.label, ref, true))
	}
	return nodes
}
func collectionBudget(primary, perItem, categoryCount int) error {
	const chromeActions = 16
	if primary*perItem+categoryCount+chromeActions > sdui.MaxActions {
		return &Error{InvalidDocument, "BFF_INVALID_DOCUMENT", "Upstream collection exceeds the presentation budget"}
	}
	return nil
}
func categoryOptions(all, visible []model.Category, selected string) []sdui.Option {
	options := []sdui.Option{{Value: "", Label: "Все категории"}}
	present := selected == ""
	for _, c := range visible {
		value := c.ID
		if domain.EqualID(c.ID, selected) {
			// CMP selects options literally against controlled field state.
			value = selected
			present = true
		}
		options = append(options, sdui.Option{Value: value, Label: c.Name})
	}
	if !present {
		label := "Выбранная категория"
		for _, c := range all {
			if domain.EqualID(c.ID, selected) {
				label = c.Name
				break
			}
		}
		options = append(options, sdui.Option{Value: selected, Label: label})
	}
	return options
}
