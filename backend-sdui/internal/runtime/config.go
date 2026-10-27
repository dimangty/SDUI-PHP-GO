package runtime

import (
	"fmt"
	"net"
	"net/netip"
	"os"
	"strconv"
	"strings"
	"time"

	"shopkmp/backend-sdui/internal/yii"
)

type Config struct {
	ListenAddr        string
	AdminAddr         string
	Yii               yii.Config
	RequestTimeout    time.Duration
	ReadHeaderTimeout time.Duration
	ReadTimeout       time.Duration
	WriteTimeout      time.Duration
	IdleTimeout       time.Duration
	ShutdownTimeout   time.Duration
	TrustedEdgeIPs    []netip.Addr
}

func Defaults() Config {
	return Config{ListenAddr: ":8080", AdminAddr: ":9090", Yii: yii.Config{Origin: "http://yii2-http:8080", Timeout: 3 * time.Second, MaxBody: 2 << 20, Backoff: 50 * time.Millisecond}, RequestTimeout: 8 * time.Second, ReadHeaderTimeout: 3 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 12 * time.Second, IdleTimeout: 30 * time.Second, ShutdownTimeout: 10 * time.Second}
}
func Load() (Config, error) {
	c := Defaults()
	envStrings := map[string]*string{"LISTEN_ADDR": &c.ListenAddr, "ADMIN_ADDR": &c.AdminAddr, "YII_ORIGIN": &c.Yii.Origin}
	for key, dest := range envStrings {
		if v, ok := os.LookupEnv(key); ok {
			*dest = v
		}
	}
	if raw := os.Getenv("TRUSTED_EDGE_IPS"); raw != "" {
		for _, value := range strings.Split(raw, ",") {
			peer, err := netip.ParseAddr(strings.TrimSpace(value))
			if err != nil || peer.Zone() != "" || peer.Unmap().IsUnspecified() || peer.IsMulticast() {
				return c, fmt.Errorf("TRUSTED_EDGE_IPS requires exact IP addresses, never CIDRs or hostnames")
			}
			c.TrustedEdgeIPs = append(c.TrustedEdgeIPs, peer.Unmap())
		}
	}
	durations := map[string]*time.Duration{"UPSTREAM_TIMEOUT": &c.Yii.Timeout, "UPSTREAM_RETRY_BACKOFF": &c.Yii.Backoff, "REQUEST_TIMEOUT": &c.RequestTimeout, "READ_HEADER_TIMEOUT": &c.ReadHeaderTimeout, "READ_TIMEOUT": &c.ReadTimeout, "WRITE_TIMEOUT": &c.WriteTimeout, "IDLE_TIMEOUT": &c.IdleTimeout, "SHUTDOWN_TIMEOUT": &c.ShutdownTimeout}
	for key, dest := range durations {
		if v, ok := os.LookupEnv(key); ok {
			parsed, err := time.ParseDuration(v)
			if err != nil || parsed <= 0 || parsed > 2*time.Minute {
				return c, fmt.Errorf("invalid %s", key)
			}
			*dest = parsed
		}
	}
	if v, ok := os.LookupEnv("MAX_UPSTREAM_BODY"); ok {
		n, err := strconv.ParseInt(v, 10, 64)
		if err != nil || n < 1 || n > 8<<20 {
			return c, fmt.Errorf("invalid MAX_UPSTREAM_BODY")
		}
		c.Yii.MaxBody = n
	}
	_, p1, e1 := net.SplitHostPort(c.ListenAddr)
	_, p2, e2 := net.SplitHostPort(c.AdminAddr)
	if e1 != nil || e2 != nil || p1 == "" || p2 == "" || p1 == p2 {
		return c, fmt.Errorf("public and admin listeners require different ports")
	}
	if c.Yii.Timeout >= c.RequestTimeout || c.RequestTimeout >= c.WriteTimeout || c.ReadHeaderTimeout > c.ReadTimeout {
		return c, fmt.Errorf("require upstream < request < write and read-header <= read timeouts")
	}
	if _, err := yii.New(c.Yii); err != nil {
		return c, err
	}
	return c, nil
}
