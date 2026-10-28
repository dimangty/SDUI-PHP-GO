# Expanded installed visual evidence — checkpoint status

**PENDING: the expanded ten-state/13-image installed run has not completed.**
This file records the true interruption checkpoint; it must not be interpreted
as a passing visual acceptance result.

The shared production UI implementation and the preceding installed CARD flows
have passing evidence in TASK1–3 reports and `CHECKPOINT.md`. The screenshot
extension adds real six-digit PIN login, native readonly-editor/back checks and a
finite privacy-safe export set. Existing four commerce screenshots/summaries are
valid for their recorded earlier runs, not the expanded source.

## Resume serially on disposable local devices

Use JDK 17 at
`/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home`.

```sh
python3 scripts/run_ios_installed_journey.py --udid 2E4889AB-D521-4B8C-A796-AD80A7536D00 --backend-url http://127.0.0.1:58196 --tmp-root /var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode
python3 scripts/run_android_installed_journey.py --serial emulator-5558 --backend-url http://10.0.2.2:58196
```

Do not run both commerce journeys concurrently: their exact stock checks use the
same disposable SKU. Require an actual passing test, full-journey milestone and
13/13 named screenshots on each platform. Preserve all failures/receipt IDs and
privacy restrictions; no browser/capability, plaintext credential or raw hierarchy
export. After capture, visually compare all ten app states with the reference,
including the real paid-order card, native editor and compact profile.

The honest photo-upload/notification limitations and the distinct unresolved A1
backend release gate remain in force.
