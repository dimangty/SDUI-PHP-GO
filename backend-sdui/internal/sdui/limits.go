package sdui

import (
	"fmt"
	"reflect"
	"unicode/utf8"
)

const (
	// MaxClientInt is the largest value of the unchanged CMP v1 Kotlin Int.
	MaxClientInt          = 1<<31 - 1
	MaxBody               = 1 << 20
	MaxNodes              = 500
	MaxDepth              = 16
	MaxText               = 8192
	MaxActions            = 500
	MaxDescriptionPreview = 256
	MaxCategoryPageSize   = 50
	MaxCartPageSize       = 100
)

func clientInt(value int) bool { return value >= 0 && value <= MaxClientInt }
func (q CollectionQuery) Validate(maxSize int) error {
	if q.Page < 0 || q.Page > 1000000 || q.Size < 1 || q.Size > maxSize {
		return fmt.Errorf("invalid collection page")
	}
	return nil
}
func validPagination(p Pagination) bool {
	return clientInt(p.Page) && p.Size >= 1 && p.Size <= 100 && clientInt(p.TotalPages) && clientInt(p.TotalItems)
}
func boundedStrings(v reflect.Value, depth int) error {
	if depth > 128 {
		return fmt.Errorf("value too deep")
	}
	if !v.IsValid() {
		return nil
	}
	switch v.Kind() {
	case reflect.Pointer, reflect.Interface:
		if !v.IsNil() {
			return boundedStrings(v.Elem(), depth+1)
		}
	case reflect.String:
		if !utf8.ValidString(v.String()) || utf8.RuneCountInString(v.String()) > MaxText {
			return fmt.Errorf("text limit")
		}
	case reflect.Struct:
		for i := 0; i < v.NumField(); i++ {
			if err := boundedStrings(v.Field(i), depth+1); err != nil {
				return err
			}
		}
	case reflect.Slice, reflect.Array:
		for i := 0; i < v.Len(); i++ {
			if err := boundedStrings(v.Index(i), depth+1); err != nil {
				return err
			}
		}
	case reflect.Map:
		iter := v.MapRange()
		for iter.Next() {
			if err := boundedStrings(iter.Key(), depth+1); err != nil {
				return err
			}
			if err := boundedStrings(iter.Value(), depth+1); err != nil {
				return err
			}
		}
	}
	return nil
}
