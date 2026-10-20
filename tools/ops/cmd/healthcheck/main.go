// Команда healthcheck проверяет внутренний liveness endpoint контейнера без
// зависимости от wget, curl или других утилит runtime-образа.
package main

import (
	"context"
	"io"
	"net/http"
	"os"
	"strings"
	"time"
)

const defaultHealthcheckURL = "http://127.0.0.1:9091/health/live"

func main() {
	if !healthy() {
		os.Exit(1)
	}
}

func healthy() bool {
	target := strings.TrimSpace(os.Getenv("HEALTHCHECK_URL"))
	if target == "" {
		target = defaultHealthcheckURL
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	request, err := http.NewRequestWithContext(
		ctx,
		http.MethodGet,
		target,
		nil,
	)
	if err != nil {
		return false
	}
	client := &http.Client{Timeout: 2 * time.Second}
	response, err := client.Do(request)
	if err != nil {
		return false
	}
	_, readErr := io.Copy(io.Discard, io.LimitReader(response.Body, 4<<10))
	closeErr := response.Body.Close()
	return response.StatusCode == http.StatusOK && readErr == nil && closeErr == nil
}
