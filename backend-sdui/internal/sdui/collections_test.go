package sdui

import (
	"fmt"
	"testing"
)

func TestCategoryViewportCannotExceedPublishedPageCap(t *testing.T) {
	d := document()
	props := CategoriesProps{Items: []CategoryOption{}}
	for i := 0; i < 51; i++ {
		id := fmt.Sprintf("%08x-1111-4111-8111-111111111111", i+1)
		ref := fmt.Sprintf("category.%d", i)
		props.Items = append(props.Items, CategoryOption{ID: id, Label: "Category", ActionID: ref})
		d.Actions = append(d.Actions, Action{ID: ref, Type: "Navigate", Destination: &Destination{Screen: "catalog"}})
	}
	d.Components = []Node{N("categories", "CategoryList", props)}
	d.State.Collections = map[string]Pagination{"categories": {Page: 0, Size: 50, TotalItems: 51, TotalPages: 2}}
	if _, err := d.Encode(); err == nil {
		t.Fatal("51 categories exceeded the published 50-item viewport cap")
	}
}
