package application

import (
	"bytes"
	"github.com/santhosh-tekuri/jsonschema/v6"
	"os"
	"testing"
)

func compileSchema(t *testing.T, name string) *jsonschema.Schema {
	t.Helper()
	c := jsonschema.NewCompiler()
	for _, n := range []string{"screen", "action-request", "action-response"} {
		raw, err := os.ReadFile("../../contracts/" + n + ".schema.json")
		if err != nil {
			t.Fatal(err)
		}
		doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(raw))
		if err != nil {
			t.Fatal(err)
		}
		if err = c.AddResource("https://shopkmp.local/contracts/sdui/v1/"+n+".schema.json", doc); err != nil {
			t.Fatal(err)
		}
	}
	s, err := c.Compile("https://shopkmp.local/contracts/sdui/v1/" + name + ".schema.json")
	if err != nil {
		t.Fatal(err)
	}
	return s
}
func schemaValidate(t *testing.T, s *jsonschema.Schema, raw []byte) {
	t.Helper()
	v, err := jsonschema.UnmarshalJSON(bytes.NewReader(raw))
	if err != nil {
		t.Fatal(err)
	}
	if err = s.Validate(v); err != nil {
		t.Fatal(err)
	}
}
