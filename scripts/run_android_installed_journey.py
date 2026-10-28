#!/usr/bin/env python3
"""Build/install/run Task5 on a configured disposable edge, retaining nonsecret evidence.

Clears com.shopkmp test-account app data. No API fixtures, auth injection or browser
URL/hierarchy capture. adb's process status alone does not indicate test success.
"""
import argparse
from datetime import datetime, timezone
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / "docs/evidence/android"
TMP = Path("/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode")
SCREENSHOTS = (
    "login.png", "registration.png", "pin-create-partial-2.png", "pin-login-partial-3.png", "home.png",
    "product.png", "cart-checkout.png", "orders-paid.png", "profile.png", "edit-profile.png", "profile-actions.png",
    "checkout-confirmation.png", "order-detail.png", "orders-created.png",
)
REQUIRED_SCREENSHOTS = set(SCREENSHOTS) - {"orders-created.png"}


def safe_instrumentation_lines(output):
    """Export fixed milestones/flags and counters, never raw runner failures/UI."""
    uuid = r"[a-f0-9-]{36}"
    boolean = r"(?:true|false)"
    stage = r"(?:preserved|before_click|after_click|timeout)"
    patterns = (
        r"E2E: UI registration reached native PIN setup",
        r"E2E: Authenticated Home SDUI and persisted PIN device verified",
        r"E2E: UI six-digit PIN login and actual Profile identity verified",
        r"E2E: Partial PIN capture has released keys and count-only evidence",
        rf"E2E: PIN_VISUAL stage=(?:before_wait|after_wait) baselineUniform={boolean} currentUniform={boolean} boundsMatch={boolean} pixelsMatch={boolean}",
        r"E2E: UI logout and real password login returned to authenticated Home",
        r"E2E: Native editor readonly email, absent bottom nav and cancel without mutation verified",
        rf"E2E: VISUAL_BIOMETRIC checked={boolean} enabled={boolean}",
        r"E2E: Catalog search → Product → AddToCart → Cart; canonical quantity/total verified",
        rf"E2E: Native checkout confirmed: one CREATED CARD order, exact total, stock -1, empty cart; order={uuid}",
        r"E2E: System browser Complete payment → canonical CAPTURED / PAID",
        r"E2E: BLOCKED: Browser CARD POST rejected: CORS_ORIGIN_DENIED for configured disposable origin; canonical AUTHORIZED / CREATED; continuing app inspection",
        r"E2E: App Orders/(?:PAID|CREATED) → order detail → Profile → Devices verified",
        rf"E2E: Final logout stays at Login; order={uuid} product={uuid}; payment=(?:CAPTURED|BLOCKED)",
        r"E2E: PASS Android full installed CARD journey",
        rf"E2E: BROWSER stage={stage} chrome={boolean}",
        r"E2E: BROWSER_TAP bounds=-?\d+,-?\d+,\d+,\d+",
        rf"E2E: BROWSER_CONTROL stage={stage} code=(?:complete|completed|return|send_anyway|send_anyway_title|send|go_back|continue|no_thanks|got_it|cors|forbidden|url_bar|webview) exists={boolean} enabled={boolean} clickable={boolean} bounds=-?\d+,-?\d+,\d+,\d+",
        rf"E2E: BROWSER_CLICK stage={stage} resource=(?:web_control|com\.android\.chrome:id/[a-zA-Z0-9_]+) enabled={boolean} bounds=-?\d+,-?\d+,\d+,\d+",
    )
    lines = []
    for line in output.splitlines():
        candidate = line.removeprefix("INSTRUMENTATION_STATUS: stream=").strip()
        if any(re.fullmatch(pattern, candidate) for pattern in patterns) or re.fullmatch(
            r"(?:OK \(\d+ tests?\)|Time: \d+(?:\.\d+)?|Tests run: \d+,\s+Failures: \d+|FAILURES!!!)", candidate
        ):
            lines.append(candidate)
    for marker, code in (
        ("Timed out waiting for gateway completed state; no payment action retry", "gateway_completed_timeout_no_retry"),
        ("already registered", "uiautomation_already_registered"),
        ("Process crashed", "instrumentation_process_crashed"),
        ("Actual partial PIN count is exposed without digits", "partial_pin_count"),
        ("Email has no native set-text action", "native_editor_readonly_email"),
        ("Editor has no host navigation", "native_editor_host_navigation"),
        ("PIN key visuals match released baseline", "partial_pin_visual_release_timeout"),
    ):
        if marker in output:
            lines.append(f"Failure: {code}")
    return lines


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--serial", default=os.environ.get("ANDROID_SERIAL", "emulator-5554"))
    parser.add_argument("--backend-url", default="http://10.0.2.2:58196")
    parser.add_argument("--tmp-root", type=Path, default=TMP)
    args = parser.parse_args()
    origin = urlsplit(args.backend_url)
    if origin.scheme not in ("http", "https") or not origin.hostname or origin.username or origin.password or origin.path not in ("", "/") or origin.query or origin.fragment:
        parser.error("--backend-url must be an HTTP(S) origin")
    backend_url = args.backend_url.rstrip("/")
    if not args.tmp_root.is_dir():
        parser.error("--tmp-root must be an existing private temporary directory")
    env = os.environ.copy()
    home = Path.home()
    env.setdefault("ANDROID_HOME", str(home / "Library/Android/sdk"))
    env.setdefault("JAVA_HOME", "/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home")
    adb = [str(Path(env["ANDROID_HOME"]) / "platform-tools/adb"), "-s", args.serial]

    def run(command, **kwargs):
        return subprocess.run(command, cwd=ROOT, env=env, check=True, **kwargs)

    EVIDENCE.mkdir(parents=True, exist_ok=True)
    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    private_evidence = args.tmp_root / f"android-journey-evidence-{run_id}"
    private_evidence.mkdir(mode=0o700)
    # Only declared outputs of this helper; prevent old successful captures looking current.
    for name in SCREENSHOTS:
        (EVIDENCE / name).unlink(missing_ok=True)
    started = datetime.now(timezone.utc).isoformat()
    identity = f"Started UTC: {started}\nDevice: {args.serial}\nEvidence run: {run_id}\n"
    summary = EVIDENCE / "instrumentation.txt"
    summary.write_text(identity + "RUNNING: preflight; no journey verdict yet\n")
    phase = "build"
    try:
        run([
            str(ROOT / "gradlew"), ":composeApp:assembleDebug", ":composeApp:assembleDebugAndroidTest",
            f"-PSHOPKMP_DEBUG_SERVER_URL={backend_url}", "--console=plain", "--quiet",
        ])
        phase = "install"
        for apk in (
            "composeApp/build/outputs/apk/debug/composeApp-debug.apk",
            "composeApp/build/outputs/apk/androidTest/debug/composeApp-debug-androidTest.apk",
        ):
            run(adb + ["install", "-r", str(ROOT / apk)])
        phase = "clear test-account app data"
        run(adb + ["shell", "pm", "clear", "com.shopkmp"])
    except (subprocess.CalledProcessError, OSError):
        summary.write_text(identity + f"FAIL: {phase} preflight; instrumentation did not run\n")
        print(f"FAIL Android {phase} preflight; evidence: docs/evidence/android/")
        return 1
    test_started = time.monotonic()
    result = subprocess.run(adb + [
        "shell", "am", "instrument", "-w", "-r", "-e", "class",
        "com.shopkmp.InstalledJourneyTest#realCardJourney",
        "-e", "backendServerUrl", backend_url,
        "com.shopkmp.test/androidx.test.runner.AndroidJUnitRunner",
    ], cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    output = result.stdout
    test_seconds = time.monotonic() - test_started
    journey_passed = (result.returncode == 0 and "OK (1 test)" in output and "FAILURES!!!" not in output
                      and "PASS Android full installed CARD journey" in output)
    exported_names = set()
    for name in SCREENSHOTS:
        remote = f"/sdcard/Android/data/com.shopkmp/files/android-e2e/{name}"
        exists = subprocess.run(adb + ["shell", "test", "-f", remote], env=env,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if exists.returncode == 0:
            run(adb + ["pull", remote, str(EVIDENCE / name)])
            if (EVIDENCE / name).read_bytes().startswith(b"\x89PNG\r\n\x1a\n"):
                shutil.copyfile(EVIDENCE / name, private_evidence / name)
                exported_names.add(name)
    missing = sorted(REQUIRED_SCREENSHOTS - exported_names)
    passed = journey_passed and not missing
    safe = (identity + f"Instrumentation wall time: {test_seconds:.3f}s\n"
            + "\n".join(safe_instrumentation_lines(output)) + "\n"
            + f"adb instrumentation process exit: {result.returncode}\n"
            + f"Named app screenshot exports: {len(exported_names & REQUIRED_SCREENSHOTS)}/{len(REQUIRED_SCREENSHOTS)}\n"
            + ("Evidence missing: " + ", ".join(missing) + "\n" if missing else "")
            + f"{'PASS' if passed else 'FAIL'} installed Android full CARD journey\n")
    summary.write_text(safe)
    (private_evidence / "instrumentation.txt").write_text(safe)
    print(safe, end="")
    print(f"{'PASS' if passed else 'FAIL'} installed Android journey; evidence: docs/evidence/android/")
    return 0 if passed else 1


if __name__ == "__main__":
    sys.exit(main())
