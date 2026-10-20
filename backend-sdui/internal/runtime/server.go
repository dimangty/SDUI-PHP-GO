package runtime

import (
	"context"
	"errors"
	"fmt"
	"log"
	"net"
	"net/http"
	"sync"
	"time"

	"shopkmp/backend-sdui/internal/bff"
	"shopkmp/backend-sdui/internal/yii"
)

func Health(up *yii.Client) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health/live", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		fmt.Fprint(w, `{"status":"ok"}`)
	})
	mux.HandleFunc("GET /health/ready", func(w http.ResponseWriter, r *http.Request) {
		ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
		defer cancel()
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		if err := up.Ready(ctx); err != nil {
			w.WriteHeader(503)
			fmt.Fprint(w, `{"status":"unavailable"}`)
			return
		}
		fmt.Fprint(w, `{"status":"ok"}`)
	})
	return mux
}
func Run(ctx context.Context, c Config) error {
	up, err := yii.New(c.Yii)
	if err != nil {
		return err
	}
	public, err := net.Listen("tcp", c.ListenAddr)
	if err != nil {
		return err
	}
	defer public.Close()
	admin, err := net.Listen("tcp", c.AdminAddr)
	if err != nil {
		return err
	}
	defer admin.Close()
	log.Printf("SDUI public=%s internal=%s", public.Addr(), admin.Addr())
	return Serve(ctx, c, public, admin, bff.New(up, bff.Config{RequestTimeout: c.RequestTimeout}), Health(up))
}
func Serve(ctx context.Context, c Config, public, admin net.Listener, handler, health http.Handler) error {
	makeServer := func(h http.Handler) *http.Server {
		return &http.Server{Handler: h, ReadHeaderTimeout: c.ReadHeaderTimeout, ReadTimeout: c.ReadTimeout, WriteTimeout: c.WriteTimeout, IdleTimeout: c.IdleTimeout, MaxHeaderBytes: 16 << 10}
	}
	app, ops := makeServer(handler), makeServer(health)
	results := make(chan error, 2)
	go func() { results <- app.Serve(public) }()
	go func() { results <- ops.Serve(admin) }()
	var serveErr error
	select {
	case <-ctx.Done():
	case serveErr = <-results:
	}
	shutdownCtx, cancel := context.WithTimeout(context.Background(), c.ShutdownTimeout)
	defer cancel()
	var wg sync.WaitGroup
	shutdownErrors := make(chan error, 2)
	for _, srv := range []*http.Server{app, ops} {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if err := srv.Shutdown(shutdownCtx); err != nil {
				srv.Close()
				shutdownErrors <- err
			}
		}()
	}
	wg.Wait()
	close(shutdownErrors)
	log.Print("SDUI shutdown complete")
	if serveErr != nil && !errors.Is(serveErr, http.ErrServerClosed) {
		return serveErr
	}
	for err := range shutdownErrors {
		if err != nil {
			return err
		}
	}
	return nil
}
