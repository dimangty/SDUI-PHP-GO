package domain

import "testing"

func TestEqualIDRequiresTwoStrictUUIDs(t *testing.T) {
	const lower = "abcdefab-cdef-4abc-8def-abcdefabcdef"
	for _, tc := range []struct {
		a, b string
		want bool
	}{
		{lower, lower, true},
		{lower, "ABCDEFAB-CDEF-4ABC-8DEF-ABCDEFABCDEF", true},
		{lower, "AbCdEfAb-cDeF-4aBc-8DeF-aBcDeFaBcDeF", true},
		{lower, "bbcdefab-cdef-4abc-8def-abcdefabcdef", false},
		{"", "", false},
		{"not-a-uuid", "not-a-uuid", false},
		{lower, lower + "\n", false},
		{lower + "x", lower + "x", false},
		{"ａｂcdefab-cdef-4abc-8def-abcdefabcdef", lower, false},
	} {
		if EqualID(tc.a, tc.b) != tc.want || EqualID(tc.b, tc.a) != tc.want {
			t.Errorf("EqualID(%q, %q) want %v", tc.a, tc.b, tc.want)
		}
	}
}
