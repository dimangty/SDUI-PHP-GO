// Package domain contains shared wire values and input constraints, independent
// of HTTP adapters. Business decisions and persistence remain owned by Yii.
package domain

import (
	"encoding/json"
	"errors"
	"regexp"
	"strings"
)

// Money is the canonical decimal string, never a floating-point calculation.
type Money string

var moneyPattern = regexp.MustCompile(`^(0|[1-9][0-9]{0,9})\.[0-9]{2}$`)
var uuidPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
var devicePattern = regexp.MustCompile(`^[A-Za-z0-9._-]{1,255}$`)

func ValidMoney(s string) bool { return moneyPattern.MatchString(s) }
func ValidID(s string) bool    { return uuidPattern.MatchString(s) }

// EqualID compares strictly valid UUIDs by value without rewriting wire inputs.
func EqualID(a, b string) bool {
	return ValidID(a) && ValidID(b) && strings.EqualFold(a, b)
}

func ValidDevice(s string) bool {
	return s != "." && s != ".." && devicePattern.MatchString(s)
}

func (m *Money) UnmarshalJSON(b []byte) error {
	var s string
	if err := json.Unmarshal(b, &s); err != nil || !ValidMoney(s) {
		return errors.New("invalid decimal-string money")
	}
	*m = Money(s)
	return nil
}
