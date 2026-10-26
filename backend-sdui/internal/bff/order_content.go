package bff

import (
	"fmt"
	"strings"
	"unicode/utf8"

	"shopkmp/backend-sdui/internal/sdui"
	"shopkmp/backend-sdui/internal/yii"
)

func orderItemNodes(items []yii.OrderItem) ([]sdui.Node, error) {
	// Eight non-item nodes cover a CREATED/PAID order with a comment. Keep
	// existing rows for small orders, including their published fixture IDs.
	compact := len(items) > (sdui.MaxNodes-8)/3
	nodes := []sdui.Node{}
	var block strings.Builder
	flush := func() {
		if block.Len() != 0 {
			nodes = append(nodes, text(fmt.Sprintf("order.items.%d", len(nodes)), block.String(), "Body"))
			block.Reset()
		}
	}
	for _, item := range items {
		if !yii.ValidID(item.ProductID) || !yii.ValidMoney(string(item.PriceSnapshot)) || item.Quantity < 1 || !utf8.ValidString(item.ProductName) {
			return nil, &yii.TransportError{Kind: "invalid_json"}
		}
		base := "order.item." + item.ProductID
		label := fmt.Sprintf("%s × %d", item.ProductName, item.Quantity)
		if !compact {
			nodes = append(nodes, sdui.N(base, "Row", sdui.LayoutProps{Spacing: "s", Padding: "none", Align: "Start"}, text(base+".name", label, "Body"), price(base+".price", item.PriceSnapshot)))
			continue
		}
		// Byte bounds are deliberately stricter than MaxText's code-point bound.
		// Preserve complete lines and decimal strings; never compute money here.
		line := fmt.Sprintf("%s · %s RUB", label, item.PriceSnapshot)
		if len(line) > sdui.MaxText {
			return nil, &Error{502, "BFF_INVALID_DOCUMENT", "Upstream data cannot form a safe screen"}
		}
		if block.Len()+1+len(line) > sdui.MaxText {
			flush()
		}
		if block.Len() > 0 {
			block.WriteByte('\n')
		}
		block.WriteString(line)
	}
	flush()
	// Screen.Encode still checks total nodes and escaped JSON bytes before the
	// HTTP response is written. An unrepresentable order fails atomically.
	return nodes, nil
}
