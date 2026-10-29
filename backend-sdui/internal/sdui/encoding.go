package sdui

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
)

func (s *Screen) Encode() ([]byte, error) {
	if err := s.Validate(); err != nil {
		return nil, err
	}
	// No clock, correlation IDs or token data participate in content revisions.
	s.Revision = ""
	raw, err := json.Marshal(s)
	if err != nil {
		return nil, err
	}
	sum := sha256.Sum256(raw)
	s.Revision = hex.EncodeToString(sum[:])
	raw, err = json.Marshal(s)
	if len(raw) > MaxBody {
		return nil, fmt.Errorf("document exceeds %d bytes", MaxBody)
	}
	return raw, err
}
