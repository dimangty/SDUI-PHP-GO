# Common SDUI v1 examples

The eight screen JSON files are deterministic documents emitted by the real builders
against representative canonical HTTP DTOs. They are shared input for Kotlin
decoding, renderer and action tests, not business-data fixtures for live E2E.
`primitives.json` adds layout and Empty/Loading/Error examples. Together the nine
documents exercise all 26 published component types.
Four more fixtures complete the reviewed v1 handoff: `cart-continuation.json`,
`home-continuation.json`, `catalog-continuation.json`, and
`product-long-description.json`. They demonstrate finite collection query/state/
destination mapping, both pagers, a selected category pinned outside the visible
window, and a full10k description with bounded text chunks. Total: **13 fixtures**.

`TestPublishedFixturesMatchActualBuildersAndIndependentSchema` validates them
with an independent Draft 2020-12 validator and detects builder/fixture drift.
Regenerate only after reviewing a deliberate wire change:

```sh
UPDATE_FIXTURES=1 go test ./internal/bff -run TestPublished
```

IDs and money are strings. Field values are controlled by `state.fields`.
Read `../../../docs/SDUI_CONTRACT.md` before implementing adapters. The live
test captures separate, random-account HTTP evidence under `tests/evidence`.
