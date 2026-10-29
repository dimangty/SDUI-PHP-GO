// Package sdui defines the finite, versioned wire contract. No wire value can
// supply code, an HTTP method, an endpoint, or an external media URL.
package sdui

type Screen struct {
	SchemaVersion int      `json:"schemaVersion"`
	ScreenID      string   `json:"screenId"`
	Revision      string   `json:"revision"`
	Components    []Node   `json:"components"`
	Actions       []Action `json:"actions"`
	State         State    `json:"state"`
	Metadata      Metadata `json:"metadata"`
}
type State struct {
	Authenticated  bool                  `json:"authenticated"`
	Fields         map[string]string     `json:"fields"`
	CartItemsCount int                   `json:"cartItemsCount"`
	Pagination     *Pagination           `json:"pagination,omitempty"`
	Collections    map[string]Pagination `json:"collections,omitempty"`
}
type Pagination struct {
	Page       int `json:"page"`
	Size       int `json:"size"`
	TotalPages int `json:"totalPages"`
	TotalItems int `json:"totalItems"`
}
type Metadata struct {
	Title     string   `json:"title"`
	Locale    string   `json:"locale"`
	AccountID string   `json:"accountId,omitempty"`
	Partial   bool     `json:"partial"`
	Warnings  []string `json:"warnings,omitempty"`
}
