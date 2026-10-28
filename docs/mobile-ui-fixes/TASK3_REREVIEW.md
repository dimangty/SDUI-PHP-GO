# Task 3 M1 — scoped re-review, fix round 1

Date: 2026-10-27.

## Verdict

**PASS for the scoped correction. Task 3 M1 is closed.** The address disclosure
now remains open after the last required value or error is resolved, and a fresh
valid prefill still starts compact. No new Critical, High or Medium regression
was found in this fix.

Reviewed the current `SduiForms.kt`, the two added UI regressions and
`TASK3_REPORT.md:313–406` against the original finding in `TASK3_REVIEW.md`.
This closes that source finding; it does not establish installed-journey or
reference-UI acceptance. Task 2 M1 was closed separately and is not revisited.

## Source verification

Location:
`core/sdui-renderer/src/commonMain/kotlin/com/shopkmp/sdui/renderer/SduiForms.kt:55–74`.

- **Initial missing address:** `editAddress` is initialized from the actual
  `invalidAddress` predicate at lines 58–59. Automatic exposure therefore starts
  with the remembered open state set, rather than relying only on transient
  validation visibility.
- **Later errors or missing values:** the SideEffect at lines 61–63 latches
  `editAddress=true` after automatic exposure. Clearing the final error or typing
  the first house character does not set it back to false. The visibility guard
  at line 70 also keeps invalid controls exposed immediately.
- **Explicit collapse:** lines 67–69 toggle only when the address is valid.
  Invalid/error exposure cannot be hidden by that click. Once valid, an explicit
  collapse remains collapsed because the SideEffect no longer sets the flag.
- **Valid prefill:** false initial validation leaves the flag false; there is no
  unconditional expansion. The new error-transition test checks this starting
  compact state before injecting an error.
- **Values, errors and requests:** the correction writes only the local Boolean.
  It does not write the host-controlled field/error maps, replace field nodes or
  selectors, dispatch SDUI events, submit checkout, or change payment handling.
  The existing render callbacks and explicit submit path remain intact.
- **Account/form state:** `rememberSaveable(node.id)` retains the same identity
  and surrounding account/destination saveable ownership reviewed previously.
  Values and errors are not added to its key, so ordinary edits do not reset the
  latch. The fix introduces no new account state, ownership lookup or persistence
  boundary.

A read-only predicate/state trace corroborated five transitions: valid prefill
stays closed; blank-to-valid stays open; a later error followed by resolution
stays open; explicit valid collapse stays closed; and an invalid section cannot
be collapsed. This was source-level evaluation, not a fresh Compose execution.

## Regression coverage and recorded evidence

Test file:
`core/sdui-renderer/src/iosTest/kotlin/com/shopkmp/sdui/renderer/ShoppingPresentationUiTest.kt`.

1. **Lines 122–154 — blank address / multi-character house / apartment.** Starts
   with all four address values blank and never clicks Edit to open. It enters
   house `5` and `1` separately, asserts the input remains displayed and focused
   after `5`, then enters apartment `24`. Explicit collapse/reopen preserves
   `51` and `24`. The exact event list contains only the five expected field
   edits, and payment remains canonical `CARD`.
2. **Lines 156–189 — last error cleared.** Starts compact with a valid prefill,
   injects a street error, and lets the field callback update the controlled
   value and remove its error. Street remains displayed after resolution;
   apartment remains editable. Collapse/reopen preserves the draft and resolved
   errors. The exact event list contains only the two actual field edits.

These cases exercise the original failure paths through actual tagged inputs;
they do not open Edit first or weaken assertions to bypass the defect. Their
exact event assertions also exclude an accidental checkout Activate or a
disclosure-generated event. Existing disclosure/error tests remain present.

`TASK3_REPORT.md:361–400` records the intended two semantic RED failures, then
23/23 renderer iOS GREEN, followed by renderer/main verification totaling
49 JVM and 67 iOS target executions plus affected platform compiles. Those run
claims are attributed to the implementation report. This re-review independently
read the existing renderer iOS XML: **23 tests, zero failures/errors/skips**, with
both named new cases present and passing. The historical RED artifacts were not
re-executed or independently reconstructed.

No test/build/device/backend/payment command was run for this re-review. Only
this re-review report was authored; no source edits, subagents, commits or pushes
were made. The coordinator's installed/live/visual gates remain as documented.
