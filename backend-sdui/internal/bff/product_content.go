package bff

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"shopkmp/backend-sdui/internal/sdui"
)

func descriptionPreview(description string) (string, bool) {
	runes := []rune(description)
	if len(runes) <= sdui.MaxDescriptionPreview {
		return description, false
	}
	return string(runes[:sdui.MaxDescriptionPreview-1]) + "…", true
}
func descriptionNodes(description string) []sdui.Node {
	// Business descriptions allow 10k code points. Preserve every code point;
	// split presentation into modest blocks rather than weakening wire limits.
	const chunk = 4096
	runes := []rune(description)
	nodes := []sdui.Node{}
	for offset := 0; offset < len(runes); offset += chunk {
		end := min(offset+chunk, len(runes))
		nodes = append(nodes, text(fmt.Sprintf("product.description.%d", offset), string(runes[offset:end]), "Body"))
	}
	return nodes
}
func imageID(source string) string {
	sum := sha256.Sum256([]byte(source))
	return "product.image." + hex.EncodeToString(sum[:16])
}
