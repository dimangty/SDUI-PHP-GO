package bff

import "shopkmp/backend-sdui/internal/application"

// Compatibility facade; implementation and contracts live in the application.
type Application = application.Application
type Backend = application.Backend
type CommandRequest = application.CommandRequest
type CommandResponse = application.CommandResponse

func NewApplication(up Backend) *Application { return application.New(up, up, up, up, up) }
