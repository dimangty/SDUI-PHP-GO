package domain

import (
	"crypto/rand"
	"encoding/hex"
	"regexp"
)

var tracePattern = regexp.MustCompile(`^[A-Za-z0-9._-]{1,128}$`)
var bearerPattern = regexp.MustCompile(`^[A-Za-z0-9._~+/-]+=*$`)

func ValidBearer(s string) bool { return len(s) > 0 && len(s) <= 8192 && bearerPattern.MatchString(s) }
func RequestID(s string) string {
	if tracePattern.MatchString(s) {
		return s
	}
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(err)
	}
	return hex.EncodeToString(b[:])
}
func ValidKey(s string) bool {
	if len(s) > 255 {
		return false
	}
	for _, c := range s {
		if c < 33 || c > 126 {
			return false
		}
	}
	return true
}
