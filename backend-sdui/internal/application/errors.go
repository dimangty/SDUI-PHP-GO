package application

type ErrorKind string

const (
	InvalidRequest  ErrorKind = "invalid_request"
	Unauthorized    ErrorKind = "unauthorized"
	InvalidDocument ErrorKind = "invalid_document"
	AccountMismatch ErrorKind = "account_mismatch"
)

// Error is transport neutral. HTTP status mapping belongs to bff.
type Error struct {
	Kind    ErrorKind
	Code    string
	Message string
}

func (e *Error) Error() string { return e.Code }
func bad(message string) error { return &Error{InvalidRequest, "BFF_INVALID_REQUEST", message} }
func ownership(ok bool) error {
	if !ok {
		return &Error{AccountMismatch, "BFF_UPSTREAM_ACCOUNT_MISMATCH", "Upstream account result is inconsistent"}
	}
	return nil
}
