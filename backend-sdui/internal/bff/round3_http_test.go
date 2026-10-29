package bff

import (
	"net/http"
	"shopkmp/backend-sdui/internal/yii"
	"testing"
	"time"
)

func round3Handler(t *testing.T, origin string) http.Handler {
	t.Helper()
	c, err := yii.New(yii.Config{Origin: origin, Timeout: 2 * time.Second})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(c.CloseIdleConnections)
	return New(c, Config{RequestTimeout: 3 * time.Second})
}
