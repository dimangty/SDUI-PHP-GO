package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestHealthyUsesConfiguredEndpoint(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Path != "/health/ready" {
			t.Fatalf("request path = %q, want /health/ready", request.URL.Path)
		}
		writer.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(server.Close)
	t.Setenv("HEALTHCHECK_URL", server.URL+"/health/ready")

	if !healthy() {
		t.Fatal("healthy() rejected a successful configured endpoint")
	}
}

func TestHealthyRejectsNonSuccessAndInvalidURL(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		writer.WriteHeader(http.StatusServiceUnavailable)
	}))
	t.Cleanup(server.Close)

	t.Setenv("HEALTHCHECK_URL", server.URL)
	if healthy() {
		t.Fatal("healthy() accepted a non-200 response")
	}

	t.Setenv("HEALTHCHECK_URL", "://invalid")
	if healthy() {
		t.Fatal("healthy() accepted an invalid URL")
	}
}
