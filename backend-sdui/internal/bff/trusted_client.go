package bff

import (
	"net/http"
	"net/netip"
)

func (s *Service) originalClient(r *http.Request) (netip.Addr, error) {
	peer, err := netip.ParseAddrPort(r.RemoteAddr)
	if err != nil || peer.Addr().Zone() != "" {
		return netip.Addr{}, bad("Invalid transport peer")
	}
	address := peer.Addr().Unmap()
	if !s.trustedEdges[address] {
		return address, nil // all caller forwarding headers are ignored
	}
	values := r.Header.Values("X-Real-IP")
	if len(values) != 1 {
		return netip.Addr{}, bad("Trusted edge must supply one original client IP")
	}
	original, err := netip.ParseAddr(values[0])
	if err != nil || original.Zone() != "" || original.Unmap().IsUnspecified() || original.IsMulticast() {
		return netip.Addr{}, bad("Invalid original client IP from trusted edge")
	}
	return original.Unmap(), nil
}
