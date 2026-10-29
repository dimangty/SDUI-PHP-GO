package model

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"shopkmp/backend-sdui/internal/domain"
)

// Identity is request scoped. ClientIP is rate context, never authority.
type Identity struct {
	Bearer    string
	RequestID string
	ClientIP  netip.Addr
}

// RemoteError retains an opaque, validated business-service rejection. Only the
// transport maps this metadata to a public HTTP response.
type RemoteError struct {
	Status     int
	Body       json.RawMessage
	Code       string
	RetryAfter string
}

func (e *RemoteError) Error() string   { return fmt.Sprintf("Yii HTTP %d (%s)", e.Status, e.Code) }
func (e *RemoteError) HTTPStatus() int { return e.Status }

// Failure retains known authorization rejection even if the response body was
// malformed. Application orchestration needs that fact to cancel aggregation.
type Failure struct {
	Kind   string
	Cause  error
	Status int
}

func (e *Failure) Error() string   { return "Yii " + e.Kind }
func (e *Failure) Unwrap() error   { return e.Cause }
func (e *Failure) HTTPStatus() int { return e.Status }
func HTTPStatus(err error) int {
	var response interface{ HTTPStatus() int }
	if errors.As(err, &response) {
		return response.HTTPStatus()
	}
	return 0
}
func SecurityError(err error) bool { s := HTTPStatus(err); return s == 401 || s == 403 }
func ValidID(s string) bool        { return domain.ValidID(s) }
func ValidDevice(s string) bool    { return domain.ValidDevice(s) }
func ValidKey(s string) bool {
	return domain.ValidKey(s)
}

type CatalogQuery = domain.CatalogQuery
type OrderQuery = domain.OrderQuery
