package runtime

import "testing"

func TestClientContextConfigRejectsBroadOrAmbiguousEdgeTrust(t *testing.T) {
	for _, value := range []string{"0.0.0.0/0", "::/0", "172.30.95.0/24", "127.0.0.1/32", "0.0.0.0", "::", "::ffff:0.0.0.0", "224.0.0.1", "::ffff:224.0.0.1", "edge", "fe80::1%eth0", "::ffff:172.30.95.2%eth0", "172.30.95.2,invalid", "172.30.95.2,"} {
		t.Run(value, func(t *testing.T) {
			t.Setenv("TRUSTED_EDGE_IPS", value)
			if _, err := Load(); err == nil {
				t.Fatal("unsafe edge peer allowlist accepted")
			}
		})
	}
}
