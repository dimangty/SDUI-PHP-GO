# ADR 001: bounded order text composition

Keep the existing Row(Text, Price) UI for up to 164 items. With a comment and
cancellation button this uses exactly 500 nodes (8 fixed + 3 × 164). RED on the
unmodified Go 1.27.3 project confirms 165/200 items fail BFF_INVALID_DOCUMENT.

Above 164 items use existing multiline `Text(style=Body)` blocks, packing whole
lines `name × quantity · priceSnapshot RUB` up to 8192 **UTF-8 bytes** per block.
This is stricter than v1's 8192 code-point bound. All names, quantities and
original decimal strings survive. The total remains PHP's total, separately
displayed as Price. Comment and cancellation remain unchanged. CMP's existing
Text renderer accepts multiline content: `core/sdui-renderer/.../SduiScreen.kt`
maps Node.Text to EpsText, and `core/uikit/.../text/EpsText.kt` passes the full
string to Material Text without maxLines/ellipsis. These files were read-only.
No new component or navigation input is introduced. JSON encoding and the existing final Screen.Encode check retain
escaped-byte, node, action and depth limits before any response body is written.

Rejected: raising client limits; silent truncation; unsupported order paging;
using floats or computed totals. Per-item single Text still scales linearly in
nodes, so grouped Text provides substantially more headroom.

The contract is finite: compact blocks + 8 fixed nodes must fit 500 nodes and
the complete encoded document (including the 64-byte revision) must fit
1,048,576 bytes. A single packed line above 8192 bytes is rejected. The upstream
body remains limited to its configured bound (default 2 MiB). These are exact
representation conditions, not a promise that every possible order fits. The
700-item escaped-name regression demonstrates atomic rejection at MaxBody; no
partial order is returned. Legal 255-code-point names and 200 items fit.

Adjacent collection audit: profile has 13 fixed nodes + one per address, so
487 short addresses fit, 488 do not. Devices has 2 fixed nodes + 5 per device,
so 99 devices fit, 100 do not (across both kinds). Tests pin these boundaries.
Devices require individual revoke actions and v1 has no continuation input for
either screen; these known growth limits remain outside this targeted order
composition fix and must not be described as solved.
