package yii

import (
	"net/http"
	"strconv"
	"strings"
	"time"
)

func validRetryAfter(status int, values []string) string {
	if (status != 429 && status != 503) || len(values) != 1 || len(values[0]) > 128 {
		return ""
	}
	value := strings.Trim(values[0], " \t")
	if value == "" {
		return ""
	}
	if delaySeconds(value) {
		return value
	}
	if date, err := http.ParseTime(value); err == nil {
		return date.UTC().Format(http.TimeFormat)
	}
	return ""
}

func delaySeconds(value string) bool {
	for _, c := range value {
		if c < '0' || c > '9' {
			return false
		}
	}
	return value != ""
}

func retryAfterDelay(value string, now time.Time) time.Duration {
	if delaySeconds(value) {
		const largest = time.Duration(1<<63 - 1)
		seconds, err := strconv.ParseUint(value, 10, 64)
		if err != nil || seconds > uint64(largest/time.Second) {
			// Syntactically valid but too large to wait: preserve metadata and
			// make the deadline check reject a retry, without overflow.
			return largest
		}
		return time.Duration(seconds) * time.Second
	}
	date, err := http.ParseTime(value)
	if err != nil {
		return 0
	}
	return max(0, date.Sub(now))
}
