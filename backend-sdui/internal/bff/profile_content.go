package bff

import (
	"fmt"
	"strings"

	"shopkmp/backend-sdui/internal/domain"
	"shopkmp/backend-sdui/internal/sdui"
	"shopkmp/backend-sdui/internal/yii"
)

func defaultAddressSummary(addresses []yii.Address, def *string) string {
	if len(addresses) == 0 {
		return "Адрес не указан"
	}
	chosen := addresses[0]
	for _, a := range addresses {
		if def != nil && domain.EqualID(a.ID, *def) {
			chosen = a
			break
		}
	}
	return fmt.Sprintf("%s, %s, %s, %s", chosen.City, chosen.Street, chosen.House, chosen.Apartment)
}

func addressNodes(addresses []yii.Address) ([]sdui.Node, error) {
	// Keep address IDs/order and the conservative legacy thirteen-node reserve.
	compact := len(addresses) > sdui.MaxNodes-13
	nodes := []sdui.Node{}
	var block strings.Builder
	flush := func() {
		if block.Len() > 0 {
			nodes = append(nodes, text(fmt.Sprintf("address.block.%d", len(nodes)), block.String(), "Body"))
			block.Reset()
		}
	}
	for _, a := range addresses {
		line := fmt.Sprintf("%s, %s, %s, %s", a.City, a.Street, a.House, a.Apartment)
		if !compact {
			nodes = append(nodes, text("address."+a.ID, line, "Body"))
			continue
		}
		// Whole lines, bounded in UTF-8 bytes (stricter than the code-point cap).
		// Encode still checks the complete document, including JSON escaping.
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
	return nodes, nil
}
