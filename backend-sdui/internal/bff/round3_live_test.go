package bff

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"regexp"
	"strings"
	"testing"
	"time"
	"unicode/utf8"
)

// This test calls the deployed edge, never an httptest server or a business DB.
// The PHP fixture is the sole writer and owns exactly one fresh run's records.
type round3LiveSession struct {
	Access  string `json:"accessToken"`
	Refresh string `json:"refreshToken"`
}
type round3LiveFixture struct {
	Run, Owner, Foreign, Category, OrdinaryDevice string
	Products                                      []string
	OwnerSession, ForeignSession                  round3LiveSession
	Linked                                        []struct {
		Kind, Device, CredentialID string
		Families                   []round3LiveSession
	}
}
type round3EdgeReceipt struct {
	Name, Method, Path, SHA256 string
	Status, BodyBytes          int
	ElapsedMS                  float64
}
type round3EdgeRun struct {
	origin, evidence string
	client           *http.Client
	steps            []round3EdgeReceipt
}

func round3FixtureCommand(action, run string, variant ...string) ([]byte, error) {
	source, err := os.ReadFile("../../../backend-yii2/tests/round3_edge_fixture.php")
	if err != nil {
		return nil, err
	}
	project := os.Getenv("ROUND3_DISPOSABLE")
	args := []string{"exec", "-i", "-e", "ROUND3_DISPOSABLE=" + project, project + "-yii2-1", "php", "/dev/stdin", action, run}
	args = append(args, variant...)
	cmd := exec.Command("docker", args...)
	cmd.Stdin = bytes.NewReader(source)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	out, err := cmd.Output()
	if err != nil {
		// stderr is intentionally not echoed: PDO and auth diagnostics may be sensitive.
		return nil, fmt.Errorf("PHP fixture %s failed (%T); no credentials logged", action, err)
	}
	return out, nil
}

func (r *round3EdgeRun) call(t *testing.T, name, method, path, token string, input any, want int, save bool) []byte {
	t.Helper()
	var body []byte
	if input != nil {
		var err error
		body, err = json.Marshal(input)
		if err != nil {
			t.Fatal(err)
		}
	}
	req, err := http.NewRequest(method, r.origin+path, bytes.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Accept-Encoding", "identity")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	if input != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	start := time.Now()
	res, err := r.client.Do(req)
	if err != nil {
		t.Fatalf("%s: HTTP request failed", name)
	}
	defer res.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(res.Body, (32<<20)+1))
	if err != nil || len(raw) > 32<<20 {
		t.Fatalf("%s: response read failed/over test capture budget", name)
	}
	elapsed := float64(time.Since(start).Nanoseconds()) / 1e6
	if res.Header.Get("Content-Encoding") != "" {
		t.Fatalf("%s: unexpected compressed response", name)
	}
	if res.ContentLength >= 0 && res.ContentLength != int64(len(raw)) {
		t.Fatalf("%s: incomplete HTTP body", name)
	}
	step := round3EdgeReceipt{Name: name, Method: method, Path: path, Status: res.StatusCode, BodyBytes: len(raw), ElapsedMS: elapsed}
	// Auth responses are neither saved nor hashed, including errors.
	if !strings.HasPrefix(path, "/api/v1/auth/") {
		sum := sha256.Sum256(raw)
		step.SHA256 = hex.EncodeToString(sum[:])
	}
	r.steps = append(r.steps, step)
	t.Logf("%s HTTP=%d body_bytes=%d elapsed_ms=%.3f", name, res.StatusCode, len(raw), elapsed)
	if save && r.evidence != "" && !strings.HasPrefix(path, "/api/v1/auth/") {
		if err := os.WriteFile(filepath.Join(r.evidence, "edge-"+name+".json"), raw, 0644); err != nil {
			t.Fatal(err)
		}
	}
	if res.StatusCode != want {
		var failure struct{ Error struct{ Code string } }
		_ = json.Unmarshal(raw, &failure)
		t.Fatalf("%s: HTTP %d, expected %d (code=%s)", name, res.StatusCode, want, failure.Error.Code)
	}
	return raw
}

// Independent consumer checks: no production validators/preview functions used.
func round3EdgeConsumer(t *testing.T, raw []byte) (reviewDocument, []reviewNode) {
	t.Helper()
	schemaValidate(t, compileSchema(t, "screen"), raw)
	d, nodes := reviewNodes(t, raw)
	if !utf8.Valid(raw) {
		t.Fatal("CMP payload must be valid UTF-8")
	}
	refs := map[string]bool{}
	deviceRE := regexp.MustCompile(`^[A-Za-z0-9._-]{1,255}$`)
	mediaRE := regexp.MustCompile(`^/(images/products|icons|uploads/users)/[A-Za-z0-9/_.-]+$`)
	for _, a := range d.Actions {
		refs[a["id"].(string)] = true
		if a["type"] == "RevokeDevice" {
			id := a["deviceId"].(string)
			if id == "." || id == ".." || !deviceRE.MatchString(id) {
				t.Fatal("CMP-incompatible RevokeDevice")
			}
		}
	}
	for _, n := range nodes {
		for _, key := range []string{"actionId", "openAction"} {
			if ref, ok := n.Props[key].(string); ok && !refs[ref] {
				t.Fatalf("dangling CMP action reference in %s", n.ID)
			}
		}
		if text, ok := n.Props["text"].(string); ok && utf8.RuneCountInString(text) > 8192 {
			t.Fatal("CMP text code-point budget exceeded")
		}
		if n.Type == "ProductCard" {
			if utf8.RuneCountInString(n.Props["description"].(string)) > 256 {
				t.Fatal("CMP preview budget exceeded")
			}
			if image, ok := n.Props["image"].(string); ok && (len(image) > 2048 || !mediaRE.MatchString(image) || strings.Contains(image, "..") || strings.Contains(image, "//")) {
				t.Fatal("CMP-incompatible business image")
			}
		}
	}
	t.Logf("independent_schema=PASS CMP_wire_constraints=PASS bytes=%d nodes=%d actions=%d", len(raw), len(nodes), len(d.Actions))
	return d, nodes
}

func TestRound3LiveEdge(t *testing.T) {
	origin := os.Getenv("ROUND3_LIVE_ORIGIN")
	if origin == "" {
		t.Skip("set ROUND3_LIVE_ORIGIN=http://127.0.0.1:58221 and ROUND3_DISPOSABLE=shopkmp-round3-final-e2e for the disposable real edge")
	}
	project := os.Getenv("ROUND3_DISPOSABLE")
	parsed, parseErr := url.Parse(origin)
	if parseErr != nil || parsed.Scheme != "http" || parsed.Hostname() != "127.0.0.1" || parsed.Port() == "" || parsed.User != nil || parsed.RawQuery != "" || parsed.Fragment != "" || parsed.Path != "" || !regexp.MustCompile(`^shopkmp-[a-z0-9-]+-e2e$`).MatchString(project) {
		t.Fatal("live fixture requires an explicitly opted-in disposable development project and loopback HTTP origin")
	}
	label, err := exec.Command("docker", "inspect", "--format", `{{index .Config.Labels "com.docker.compose.project"}}`, project+"-yii2-1").Output()
	if err != nil || strings.TrimSpace(string(label)) != project {
		t.Fatal("disposable project container identity check failed")
	}
	ports, err := exec.Command("docker", "inspect", "--format", `{{json .NetworkSettings.Ports}}`, project+"-edge-1").Output()
	var bindings map[string][]struct{ HostIP, HostPort string }
	if err != nil || json.Unmarshal(ports, &bindings) != nil {
		t.Fatal("disposable edge binding check failed")
	}
	matched := false
	for _, binding := range bindings["8080/tcp"] {
		matched = matched || binding.HostIP == "127.0.0.1" && binding.HostPort == parsed.Port()
	}
	if !matched {
		t.Fatal("origin does not address this disposable project's loopback edge binding")
	}
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		t.Fatal(err)
	}
	b[6] = b[6]&15 | 64
	b[8] = b[8]&63 | 128
	h := hex.EncodeToString(b[:])
	run := h[:8] + "-" + h[8:12] + "-" + h[12:16] + "-" + h[16:20] + "-" + h[20:]
	r := &round3EdgeRun{origin: origin, evidence: os.Getenv("ROUND3_EDGE_EVIDENCE"), client: &http.Client{Timeout: 30 * time.Second, Transport: &http.Transport{DisableCompression: true}, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}}
	if r.evidence != "" {
		if err := os.MkdirAll(r.evidence, 0755); err != nil {
			t.Fatal(err)
		}
	}
	// Both cleanup and manifest writing happen even when a subtest fails.
	t.Cleanup(func() {
		raw, err := round3FixtureCommand("cleanup", run)
		if err != nil {
			t.Error(err)
		} else {
			t.Logf("addressed fixture cleanup: %s", bytes.TrimSpace(raw))
		}
		if r.evidence != "" {
			manifest, _ := json.MarshalIndent(map[string]any{"run": run, "origin": origin, "bodyByteMeaning": "complete identity-encoded HTTP entity bodies; excludes headers/framing", "steps": r.steps, "cleanupOK": err == nil}, "", "  ")
			if err := os.WriteFile(filepath.Join(r.evidence, "edge-manifest.json"), manifest, 0644); err != nil {
				t.Error(err)
			}
		}
	})
	seed, err := round3FixtureCommand("seed", run)
	if err != nil {
		t.Fatal(err)
	}
	var f round3LiveFixture
	if err := json.Unmarshal(seed, &f); err != nil || len(f.Products) != 100 || f.OwnerSession.Access == "" {
		t.Fatal("invalid private fixture manifest")
	}
	clear(seed) // Never log or persist the private seed manifest.
	pageCase := func(t *testing.T, name, sort string, size, page int, priceMin string, wantIDs []string, total int, description string) {
		q := url.Values{"categoryId": {f.Category}, "size": {fmt.Sprint(size)}, "page": {fmt.Sprint(page)}, "sort": {sort}, "priceMin": {priceMin}, "priceMax": {"50.01"}}
		fullRaw := r.call(t, name+"-full", "GET", "/api/v1/products?"+q.Encode(), f.OwnerSession.Access, nil, 200, true)
		q.Set("projection", "sdui-card-v1")
		cardRaw := r.call(t, name+"-card", "GET", "/api/v1/products?"+q.Encode(), f.OwnerSession.Access, nil, 200, true)
		var full, card struct {
			Projection                         string
			Page, Size, TotalItems, TotalPages int
			Items                              []map[string]any
		}
		if json.Unmarshal(fullRaw, &full) != nil || json.Unmarshal(cardRaw, &card) != nil {
			t.Fatal("invalid catalog JSON")
		}
		pages := (total + size - 1) / size
		if full.TotalItems != total || card.TotalItems != total || full.TotalPages != pages || card.TotalPages != pages || card.Projection != "sdui-card-v1" || full.Page != page || card.Page != page || full.Size != size || card.Size != size {
			t.Fatal("catalog pagination/projection changed")
		}
		if len(full.Items) != len(wantIDs) || len(card.Items) != len(wantIDs) {
			t.Fatal("catalog dropped/added products")
		}
		for i, id := range wantIDs {
			p, c := full.Items[i], card.Items[i]
			if p["id"] != id || c["id"] != id || p["price"] != "50.01" || c["price"] != "50.01" || p["description"] != description {
				t.Fatal("full/card identity/order/money/full description mismatch")
			}
			runes := []rune(description)
			if c["descriptionSnippet"] != string(runes[:min(257, len(runes))]) || c["descriptionComplete"] != (len(runes) <= 257) {
				t.Fatal("raw card Unicode boundary/completeness changed")
			}
			images := p["imageUrls"].([]any)
			if len(images) != 1 || c["imageUrl"] != images[0] || len(images[0].(string)) > 2048 {
				t.Fatal("first/full image preservation failed")
			}
		}
		if len(cardRaw) > 2<<20 {
			t.Fatal("projected body exceeds unchanged upstream 2MiB default")
		}
		q.Del("projection")
		raw := r.call(t, name+"-sdui", "GET", "/api/v1/screens/catalog?"+q.Encode(), f.OwnerSession.Access, nil, 200, true)
		d, nodes := round3EdgeConsumer(t, raw)
		var doc struct {
			State struct {
				Pagination struct{ Page, Size, TotalItems, TotalPages int }
			}
		}
		_ = json.Unmarshal(raw, &doc)
		if doc.State.Pagination.TotalItems != total || doc.State.Pagination.TotalPages != pages || doc.State.Pagination.Page != page {
			t.Fatal("SDUI pagination totals/page changed")
		}
		_ = d
		seen := []string{}
		for _, n := range nodes {
			if n.Type != "ProductCard" {
				continue
			}
			seen = append(seen, n.Props["productId"].(string))
			runes := []rune(description)
			preview := description
			if len(runes) > 256 {
				preview = string(runes[:255]) + "…"
			}
			if n.Props["description"] != preview || n.Props["descriptionTruncated"] != (len(runes) > 256) || n.Props["price"] != "50.01" {
				t.Fatal("SDUI money/Unicode preview mismatch")
			}
			if n.Props["image"] != card.Items[len(seen)-1]["imageUrl"] {
				t.Fatal("CMP-safe first image was dropped/changed in SDUI")
			}
		}
		if len(seen) != len(wantIDs) || (len(seen) > 0 && !reflect.DeepEqual(seen, wantIDs)) {
			t.Fatal("SDUI missing/duplicate/reordered products")
		}
		t.Logf("F1 %s total=%d visible=%d full_http_body=%d projected_http_body=%d sdui_body=%d", name, total, len(seen), len(fullRaw), len(cardRaw), len(raw))
	}
	for _, sort := range []string{"price_asc", "price_desc"} {
		for _, size := range []int{1, 20, 50, 100} {
			t.Run(fmt.Sprintf("ordinary-%s-%d", sort, size), func(t *testing.T) {
				pageCase(t, fmt.Sprintf("ordinary-%s-%d", sort, size), sort, size, 0, "50.01", f.Products[:size], 100, strings.Repeat("😀", 10000))
			})
		}
	}
	t.Run("last-page", func(t *testing.T) {
		pageCase(t, "last-page", "price_desc", 33, 3, "50.01", f.Products[99:], 100, strings.Repeat("😀", 10000))
	})
	t.Run("past-end", func(t *testing.T) {
		pageCase(t, "past-end", "price_asc", 20, 5, "50.01", nil, 100, strings.Repeat("😀", 10000))
	})
	t.Run("empty-filter", func(t *testing.T) {
		q := url.Values{"categoryId": {f.Category}, "priceMin": {"50.02"}, "size": {"20"}}
		full := r.call(t, "empty-filter-full", "GET", "/api/v1/products?"+q.Encode(), f.OwnerSession.Access, nil, 200, true)
		q.Set("projection", "sdui-card-v1")
		card := r.call(t, "empty-filter-card", "GET", "/api/v1/products?"+q.Encode(), f.OwnerSession.Access, nil, 200, true)
		for _, raw := range [][]byte{full, card} {
			var p struct {
				Items                  []any
				TotalItems, TotalPages int
			}
			if json.Unmarshal(raw, &p) != nil || len(p.Items) != 0 || p.TotalItems != 0 || p.TotalPages != 0 {
				t.Fatal("empty filter lost totals")
			}
		}
		q.Del("projection")
		raw := r.call(t, "empty-filter-sdui", "GET", "/api/v1/screens/catalog?"+q.Encode(), f.OwnerSession.Access, nil, 200, true)
		_, nodes := round3EdgeConsumer(t, raw)
		var emptyDoc struct {
			State struct {
				Pagination struct{ TotalItems, TotalPages int }
			}
		}
		if json.Unmarshal(raw, &emptyDoc) != nil || emptyDoc.State.Pagination.TotalItems != 0 || emptyDoc.State.Pagination.TotalPages != 0 {
			t.Fatal("SDUI empty filter totals changed")
		}
		for _, n := range nodes {
			if n.Type == "ProductCard" {
				t.Fatal("false nonempty filtered page")
			}
		}
	})
	t.Run("full-detail", func(t *testing.T) {
		raw := r.call(t, "full-detail-business", "GET", "/api/v1/products/"+f.Products[0], f.OwnerSession.Access, nil, 200, true)
		var p struct {
			Description string
			ImageURLs   []string
		}
		if json.Unmarshal(raw, &p) != nil || p.Description != strings.Repeat("😀", 10000) || len(p.ImageURLs) != 1 {
			t.Fatal("full public detail changed")
		}
		raw = r.call(t, "full-detail-sdui", "GET", "/api/v1/screens/product/"+f.Products[0], f.OwnerSession.Access, nil, 200, true)
		_, nodes := round3EdgeConsumer(t, raw)
		description := ""
		for _, n := range nodes {
			if strings.HasPrefix(n.ID, "product.description.") {
				description += n.Props["text"].(string)
			}
		}
		if description != p.Description {
			t.Fatal("SDUI full detail dropped description code points")
		}
	})
	for _, v := range []struct{ name, description string }{{"ascii256", strings.Repeat("a", 256)}, {"ascii257", strings.Repeat("a", 257)}, {"cyrillic257", strings.Repeat("я", 257)}, {"emoji257", strings.Repeat("😀", 257)}, {"emoji10000", strings.Repeat("😀", 10000)}} {
		t.Run("unicode-"+v.name, func(t *testing.T) {
			if _, err := round3FixtureCommand("description", run, v.name); err != nil {
				t.Fatal(err)
			}
			pageCase(t, "unicode-"+v.name, "price_asc", 1, 0, "50.01", f.Products[:1], 100, v.description)
		})
	}
	// Separate valid maximum-length image fixture; never inflate invalid URLs.
	t.Run("long-valid-image", func(t *testing.T) {
		if _, err := round3FixtureCommand("description", run, "emoji10000"); err != nil {
			t.Fatal(err)
		}
		if _, err := round3FixtureCommand("long-image", run); err != nil {
			t.Fatal(err)
		}
		pageCase(t, "long-valid-image", "price_desc", 100, 0, "50.01", f.Products, 100, strings.Repeat("😀", 10000))
	})
	t.Run("mixed-legacy-devices", func(t *testing.T) {
		raw := r.call(t, "mixed-legacy-devices", "GET", "/api/v1/screens/devices", f.OwnerSession.Access, nil, 200, true)
		d, nodes := round3EdgeConsumer(t, raw)
		joined := ""
		for _, n := range nodes {
			if text, ok := n.Props["text"].(string); ok {
				joined += text + "\n"
			}
		}
		for _, kind := range []string{"pin", "biometric"} {
			for _, device := range []string{".", ".."} {
				if !strings.Contains(joined, "Round3 "+kind+" "+device+"\n"+device+"\n"+kind) {
					t.Errorf("legacy row missing kind=%s device=%s", kind, device)
				}
			}
		}
		for _, text := range []string{"устаревший идентификатор", "отзыв", "аккаунт", "API", "оператор"} {
			if !strings.Contains(joined, text) {
				t.Errorf("missing explanation %s", text)
			}
		}
		ordinary := map[string]bool{}
		for _, a := range d.Actions {
			if a["type"] == "RevokeDevice" && a["deviceId"] == f.OrdinaryDevice {
				ordinary[a["deviceKind"].(string)] = true
			}
		}
		if !ordinary["pin"] || !ordinary["biometric"] {
			t.Fatal("ordinary revoke actions lost")
		}
	})
	for _, linked := range f.Linked {
		t.Run("revoke-"+linked.Kind+"-"+fmt.Sprint(len(linked.Device)), func(t *testing.T) {
			path := "/api/v1/auth/" + linked.Kind + "?" + url.Values{"deviceId": {linked.Device}}.Encode()
			name := fmt.Sprintf("revoke-%s-%d", linked.Kind, len(linked.Device))
			r.call(t, name+"-foreign", "DELETE", path, f.ForeignSession.Access, nil, 404, false)
			// A foreign attempt must leave both connected families live.
			for i, s := range linked.Families {
				r.call(t, fmt.Sprintf("%s-still-live-%d", name, i), "GET", "/api/v1/users/me", s.Access, nil, 200, false)
			}
			r.call(t, name+"-owner", "DELETE", path, f.OwnerSession.Access, nil, 204, false)
			for i, s := range linked.Families {
				raw := r.call(t, fmt.Sprintf("%s-refresh-rejected-%d", name, i), "POST", "/api/v1/auth/refresh", "", map[string]string{"refreshToken": s.Refresh}, 401, false)
				var failure struct{ Error struct{ Code string } }
				if json.Unmarshal(raw, &failure) != nil || failure.Error.Code != "INVALID_REFRESH_TOKEN" {
					t.Fatal("revoked family refresh error mismatch")
				}
				r.call(t, fmt.Sprintf("%s-access-rejected-%d", name, i), "GET", "/api/v1/users/me", s.Access, nil, 401, false)
			}
			t.Log("F2 owner-only query revoke and two rejected refresh families PASS; sequential E2E does not prove locking")
		})
	}
	t.Run("unrelated-session-survives", func(t *testing.T) {
		r.call(t, "unrelated-owner-session", "GET", "/api/v1/users/me", f.OwnerSession.Access, nil, 200, false)
		r.call(t, "unrelated-foreign-session", "GET", "/api/v1/users/me", f.ForeignSession.Access, nil, 200, false)
	})
}
