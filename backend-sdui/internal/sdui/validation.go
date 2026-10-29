package sdui

import (
	"fmt"
	"reflect"
	"regexp"
	"shopkmp/backend-sdui/internal/domain"
	"unicode/utf8"
)

var safeID = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$`)

// Validate checks document structure, cross-references and controlled state.
// Component and action semantics remain typed and require no schema loader.
func (s Screen) Validate() error {
	if s.SchemaVersion != 1 || !oneOf(s.ScreenID, "home", "catalog", "product", "cart", "orders", "order", "profile", "devices") || s.Components == nil || s.Actions == nil || s.State.Fields == nil || len(s.Actions) > MaxActions {
		return fmt.Errorf("invalid screen envelope")
	}
	if !oneOf(s.Metadata.Locale, "ru-RU") || s.Metadata.Title == "" || s.State.Authenticated != (s.Metadata.AccountID != "") || s.Metadata.AccountID != "" && !domain.ValidID(s.Metadata.AccountID) {
		return fmt.Errorf("invalid metadata")
	}
	for k := range s.State.Fields {
		if !AllowedField(k) {
			return fmt.Errorf("invalid field %s", k)
		}
	}
	if !clientInt(s.State.CartItemsCount) {
		return fmt.Errorf("cart count outside client Int range")
	}
	if p := s.State.Pagination; p != nil && !validPagination(*p) {
		return fmt.Errorf("invalid pagination")
	}
	for key, p := range s.State.Collections {
		if !validPagination(p) {
			return fmt.Errorf("invalid collection pagination")
		}
		maxSize := MaxCartPageSize
		switch key {
		case "categories":
			if !oneOf(s.ScreenID, "home", "catalog") {
				return fmt.Errorf("categories on wrong screen")
			}
			maxSize = MaxCategoryPageSize
		case "cart":
			if s.ScreenID != "cart" {
				return fmt.Errorf("cart collection on wrong screen")
			}
		default:
			return fmt.Errorf("unknown collection")
		}
		if err := (CollectionQuery{Page: p.Page, Size: p.Size}).Validate(maxSize); err != nil {
			return err
		}
		pages := 0
		if p.TotalItems > 0 {
			pages = 1 + (p.TotalItems-1)/p.Size
		}
		if p.TotalPages != pages || (p.TotalPages == 0 && p.Page != 0) || (p.TotalPages > 0 && p.Page >= p.TotalPages) {
			return fmt.Errorf("inconsistent collection pagination")
		}
	}
	actions := map[string]bool{}
	for _, a := range s.Actions {
		if !safeID.MatchString(a.ID) || actions[a.ID] {
			return fmt.Errorf("duplicate/invalid action ID")
		}
		actions[a.ID] = true
		if err := a.Validate(); err != nil {
			return err
		}
		if a.Type == "UpdateField" {
			if _, ok := s.State.Fields[a.FieldID]; !ok {
				return fmt.Errorf("missing controlled field state")
			}
		}
	}
	ids := map[string]bool{}
	count := 0
	var visit func(Node, int) error
	visit = func(n Node, depth int) error {
		count++
		if depth > MaxDepth || count > MaxNodes || !safeID.MatchString(n.ID) || ids[n.ID] {
			return fmt.Errorf("node depth/count/ID limit")
		}
		ids[n.ID] = true
		if err := validateProps(n); err != nil {
			return err
		}
		if p, ok := n.Props.(FieldProps); ok {
			value, present := s.State.Fields[p.FieldID]
			if !present || utf8.RuneCountInString(value) > p.MaxLength || p.MaxUTF8Bytes > 0 && len(value) > p.MaxUTF8Bytes {
				return fmt.Errorf("invalid controlled field state")
			}
		}
		if p, ok := n.Props.(StepperProps); ok {
			if _, present := s.State.Fields[p.FieldID]; !present {
				return fmt.Errorf("missing stepper state")
			}
		}
		if len(n.Children) > 0 && !oneOf(n.Type, "Column", "Row", "Box", "LazyColumn", "LazyRow", "ProductGrid", "Form") {
			return fmt.Errorf("leaf has children")
		}
		for _, ref := range references(n.Props) {
			if ref != "" && !actions[ref] {
				return fmt.Errorf("missing action %s", ref)
			}
		}
		for _, c := range n.Children {
			if err := visit(c, depth+1); err != nil {
				return err
			}
		}
		return nil
	}
	for _, n := range s.Components {
		if err := visit(n, 1); err != nil {
			return err
		}
	}
	return boundedStrings(reflect.ValueOf(s), 0)
}
