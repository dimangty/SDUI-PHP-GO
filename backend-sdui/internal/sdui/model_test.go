package sdui

import (
	"encoding/json"
	"fmt"
	"strings"
	"testing"
)

func document() Screen {
	return Screen{SchemaVersion: 1, ScreenID: "home", Components: []Node{N("title", "Text", TextProps{Text: "Shop", Style: "ScreenTitle"})}, Actions: []Action{}, State: State{Fields: map[string]string{}}, Metadata: Metadata{Title: "Shop", Locale: "ru-RU"}}
}
func TestRevisionDeterministicAndChangesWithActualContent(t *testing.T) {
	a := document()
	b := document()
	x, err := a.Encode()
	if err != nil {
		t.Fatal(err)
	}
	y, err := b.Encode()
	if err != nil || string(x) != string(y) {
		t.Fatal("nondeterministic document")
	}
	b.Components[0] = N("title", "Text", TextProps{Text: "New catalog", Style: "ScreenTitle"})
	if _, err = b.Encode(); err != nil {
		t.Fatal(err)
	}
	if a.Revision == b.Revision || len(a.Revision) != 64 {
		t.Fatal("revision ignores content")
	}
	var raw map[string]json.RawMessage
	if err = json.Unmarshal(x, &raw); err != nil {
		t.Fatal(err)
	}
	for _, key := range []string{"schemaVersion", "screenId", "revision", "components", "actions", "state", "metadata"} {
		if _, ok := raw[key]; !ok {
			t.Errorf("missing %s", key)
		}
	}
}
func TestModelRejectsUnsafeStructureAndActionReferences(t *testing.T) {
	tests := map[string]func(*Screen){
		"too many field options": func(s *Screen) {
			s.State.Fields["catalog.q"] = ""
			s.Actions = []Action{{ID: "field.change", Type: "UpdateField", FieldID: "catalog.q"}}
			s.Components = []Node{N("field", "SearchField", FieldProps{FieldID: "catalog.q", Label: "Search", Input: "Select", MaxLength: 200, ChangeAction: "field.change", Options: make([]Option, 501)})}
		},
		"unknown component":   func(s *Screen) { s.Components[0].Type = "RemoteWebView" },
		"mismatched props":    func(s *Screen) { s.Components[0].Type = "Price" },
		"duplicate IDs":       func(s *Screen) { s.Components = append(s.Components, s.Components[0]) },
		"negative cart count": func(s *Screen) { s.State.CartItemsCount = -1 },
		"invalid pagination":  func(s *Screen) { s.State.Pagination = &Pagination{Page: 0, Size: 0, TotalPages: 0, TotalItems: 0} },
		"missing controlled state": func(s *Screen) {
			s.Actions = []Action{{ID: "field.change", Type: "UpdateField", FieldID: "catalog.q"}}
			s.Components = []Node{N("field", "SearchField", FieldProps{FieldID: "catalog.q", Label: "Search", Input: "Text", MaxLength: 200, ChangeAction: "field.change"})}
		},
		"invalid rating": func(s *Screen) {
			r := 6.0
			s.Actions = []Action{{ID: "open", Type: "OpenProduct", ProductID: "11111111-1111-4111-8111-111111111111"}}
			s.Components = []Node{N("p", "ProductCard", ProductProps{ProductID: "11111111-1111-4111-8111-111111111111", Name: "X", Price: "1.00", Rating: &r, OpenAction: "open"})}
		},
		"long text": func(s *Screen) {
			s.Components[0] = N("title", "Text", TextProps{Text: strings.Repeat("a", MaxText+1), Style: "Body"})
		},
		"unregistered style": func(s *Screen) { s.Components[0] = N("title", "Text", TextProps{Text: "X", Style: "css:script"}) },
		"arbitrary media": func(s *Screen) {
			s.Components[0] = N("img", "Image", ImageProps{Source: "http://evil.test/track", Description: "x", Aspect: "Product"})
		},
		"missing action": func(s *Screen) {
			s.Components[0] = N("button", "Button", ButtonProps{Label: "Buy", ActionID: "missing", Style: "Primary", Enabled: true})
		},
		"unknown action": func(s *Screen) { s.Actions = []Action{{ID: "exec", Type: "Execute"}} },
		"secret field":   func(s *Screen) { s.State.Fields["password"] = "secret" },
		"deep": func(s *Screen) {
			n := s.Components[0]
			for i := 0; i < MaxDepth; i++ {
				n = N(strings.Repeat("c", i+1), "Column", LayoutProps{Spacing: "s", Padding: "s", Align: "Start"}, n)
			}
			s.Components = []Node{n}
		},
		"too many": func(s *Screen) {
			for i := 0; i < MaxNodes; i++ {
				s.Components = append(s.Components, N("x", "Divider", DividerProps{}))
			}
		},
	}
	for name, mutate := range tests {
		t.Run(name, func(t *testing.T) {
			s := document()
			mutate(&s)
			if _, err := s.Encode(); err == nil {
				t.Fatal("unsafe document accepted")
			}
		})
	}
}

func TestBodyLimitIsEnforcedEvenWhenIndividualStringsAndNodeCountAreValid(t *testing.T) {
	s := document()
	s.Components = []Node{}
	for i := 0; i < 200; i++ {
		s.Components = append(s.Components, N(fmt.Sprintf("text.%d", i), "Text", TextProps{Text: strings.Repeat("a", MaxText), Style: "Body"}))
	}
	if _, err := s.Encode(); err == nil || !strings.Contains(err.Error(), "bytes") {
		t.Fatalf("body limit ignored: %v", err)
	}
}
