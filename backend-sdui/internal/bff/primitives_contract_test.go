package bff

import (
	"bytes"
	"encoding/json"
	"os"
	"testing"

	"shopkmp/backend-sdui/internal/sdui"
)

func TestPublishedPrimitiveAndStateExamples(t *testing.T) {
	d := screenBase("home", "Примитивы UIKit", nil)
	retry := action(&d, sdui.Action{ID: "example.retry", Type: "Retry", Destination: &sdui.Destination{Screen: "home"}})
	input := field(&d, "catalog.priceMin", "Цена от", "50.01", "Text", false, 13, nil)
	input.Type = "TextField"
	root(&d,
		sdui.N("example.column", "Column", sdui.LayoutProps{Spacing: "m", Padding: "none", Align: "Start"},
			sdui.N("example.row", "Row", sdui.LayoutProps{Spacing: "s", Padding: "none", Align: "Center"},
				sdui.N("example.icon", "Icon", sdui.IconProps{Name: "Search", Description: "Поиск"}), text("example.text", "Каталог", "Title")),
			sdui.N("example.box", "Box", sdui.LayoutProps{Spacing: "none", Padding: "s", Align: "Center"}, input),
			sdui.N("example.lazyrow", "LazyRow", sdui.LayoutProps{Spacing: "s", Padding: "none", Align: "Start"}, text("example.lazytext", "Горизонтальный список", "Secondary"))),
		sdui.N("example.empty", "Empty", sdui.StatusProps{Title: "Нет данных", Message: "Измените фильтр"}),
		sdui.N("example.loading", "Loading", sdui.StatusProps{Title: "Загрузка", Message: "Подождите"}),
		sdui.N("example.error", "Error", sdui.StatusProps{Title: "Ошибка", Message: "Обновите экран", RetryAction: retry}),
		sdui.N("example.spacer", "Spacer", sdui.SpacerProps{Size: "l"}),
		sdui.N("example.divider", "Divider", sdui.DividerProps{}))
	raw, err := d.Encode()
	if err != nil {
		t.Fatal(err)
	}
	schemaValidate(t, compileSchema(t, "screen"), raw)
	var formatted bytes.Buffer
	json.Indent(&formatted, raw, "", "  ")
	formatted.WriteByte('\n')
	path := "../../contracts/fixtures/primitives.json"
	if os.Getenv("UPDATE_FIXTURES") == "1" {
		if err := os.WriteFile(path, formatted.Bytes(), 0644); err != nil {
			t.Fatal(err)
		}
	}
	fixture, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(fixture, formatted.Bytes()) {
		t.Fatal("primitive fixture drift")
	}
	schemaValidate(t, compileSchema(t, "screen"), fixture)
}
