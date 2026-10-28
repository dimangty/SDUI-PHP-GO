#!/usr/bin/env python3
"""Run Task5's installed CMP iOS CARD journey with nonsecret output only.

XCTest activities can include typed credentials and browser capabilities. Raw
stdout/results stay in a private temporary directory and are deleted on exit.
Never print raw XCTest failures, JSON responses, hierarchy, or activity logs.
"""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
TMP = Path("/var/folders/zd/jkv0h0f16gg_fhxq7j8sq66h0000gp/T/opencode")
EVIDENCE = ROOT / "docs/evidence/ios"
MILESTONES = (
    "public_product_discovered", "registered_native_pin_visible", "pin_home_identity_verified",
    "six_digit_pin_login_verified", "password_login_home_verified", "native_editor_readonly_cancel_verified",
    "catalog_product_cart_verified", "confirmation_zero_orders_verified",
    "native_checkout_canonical_verified", "native_send_prompt_handled", "safari_captured_paid_verified",
    "native_open_prompt_handled", "paid_order_details_devices_verified", "logout_ui_login_verified",
    "logout_relaunch_login_verified", "full_journey_pass",
)
SCREENSHOTS = (
    "login", "registration", "pin-create-partial-2", "pin-login-partial-3", "home",
    "product", "cart-checkout", "orders-paid", "profile", "edit-profile", "profile-actions",
    "checkout-confirmation", "order-detail",
)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--udid", required=True)
    parser.add_argument("--backend-url", default="http://127.0.0.1:58196")
    parser.add_argument("--tmp-root", type=Path, default=TMP)
    args = parser.parse_args()
    origin = urlsplit(args.backend_url)
    if origin.scheme not in ("http", "https") or not origin.hostname or origin.username or origin.password or origin.path not in ("", "/") or origin.query or origin.fragment:
        parser.error("--backend-url must be an HTTP(S) origin")
    backend_url = args.backend_url.rstrip("/")
    if not args.tmp_root.is_dir():
        parser.error("--tmp-root must be an existing private temporary directory")
    env = os.environ.copy()
    env.setdefault("JAVA_HOME", "/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home")
    # xcodebuild's documented TEST_RUNNER_ prefix is stripped for the test process.
    env["TEST_RUNNER_JOURNEY_BACKEND_URL"] = backend_url
    env.setdefault("ANDROID_HOME", str(Path.home() / "Library/Android/sdk"))
    EVIDENCE.mkdir(parents=True, exist_ok=True)
    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    private_evidence = args.tmp_root / f"ios-journey-evidence-{run_id}"
    private_evidence.mkdir(mode=0o700)
    for name in SCREENSHOTS:
        (EVIDENCE / f"{name}.png").unlink(missing_ok=True)
    lines = [f"Started UTC: {datetime.now(timezone.utc).isoformat()}", f"Device: {args.udid}", f"Evidence run: {run_id}"]
    (EVIDENCE / "xctest-summary.txt").write_text("\n".join(lines) + "\nRUNNING; no verdict for this run yet\n")
    report = ROOT / "docs/MOBILE_E2E_REPORT.md"
    with report.open("a") as output:
        output.write(f"\n### iOS full journey run — {lines[0].removeprefix('Started UTC: ')}\n\n"
                     "Started actual xcodebuild installed test. Resume: "
                     f"`python3 scripts/run_ios_installed_journey.py --udid {args.udid} --backend-url {backend_url}`. "
                     "Result pending; do not infer PASS if interrupted.\n")
    with tempfile.TemporaryDirectory(prefix="ios-journey-", dir=args.tmp_root) as temporary:
        sdk = subprocess.check_output(["xcrun", "--sdk", "iphonesimulator", "--show-sdk-path"], text=True).strip()
        reset = Path(temporary) / "reset-installation-identity"
        subprocess.run([
            "xcrun", "swiftc", "-sdk", sdk, "-target", "arm64-apple-ios16.0-simulator",
            str(ROOT / "scripts/reset_ios_journey_identity.swift"), "-o", str(reset),
            "-Xlinker", "-sectcreate", "-Xlinker", "__TEXT", "-Xlinker", "__entitlements",
            "-Xlinker", str(ROOT / "iosApp/iosApp/SimulatorKeychain.entitlements"),
        ], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        reset_run = subprocess.run(["xcrun", "simctl", "spawn", args.udid, str(reset)],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if reset_run.returncode != 0:
            raise RuntimeError("Nonsecret installation identity reset failed (details withheld)")
        lines.append("Test isolation: reset only nonsecret backend_device_id; Keychain tokens preserved")
        # Clear only this test app's container; the scoped helper above is
        # necessary because this app's ordinary settings also use Keychain.
        subprocess.run(["xcrun", "simctl", "uninstall", args.udid, "com.shopkmp.ios"],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        result_path = Path(temporary) / "Journey.xcresult"
        command = [
            "xcodebuild", "test", "-project", "iosApp/iosApp.xcodeproj", "-scheme", "iosApp",
            "-configuration", "Debug", "-destination", f"platform=iOS Simulator,id={args.udid}",
            "-derivedDataPath", str(args.tmp_root / "ios-installed-derived"), "-resultBundlePath", str(result_path),
            "-parallel-testing-enabled", "NO", "-collect-test-diagnostics", "never",
            "-only-testing:iosAppUITests/InstalledJourneyTests/testRealCardJourney",
            f"BACKEND_SERVER_URL={backend_url}", "CODE_SIGNING_ALLOWED=NO",
            "-hideShellScriptEnvironment",
        ]
        print("Building/running installed iOS journey (raw XCTest output withheld).", flush=True)
        started = time.monotonic()
        run = subprocess.run(command, cwd=ROOT, env=env, stdout=subprocess.PIPE,
                             stderr=subprocess.STDOUT, text=True, timeout=900)
        lines.append(f"Build/test wall time: {time.monotonic() - started:.3f}s")
        # Compiler diagnostics occur before secrets exist; never print source context.
        for line in run.stdout.splitlines():
            if re.match(r".*\.swift:\d+:\d+: error:", line):
                lines.append(line)
        summary_run = subprocess.run([
            "xcrun", "xcresulttool", "get", "test-results", "summary", "--path", str(result_path),
        ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        tests_run = subprocess.run([
            "xcrun", "xcresulttool", "get", "test-results", "tests", "--path", str(result_path),
        ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if tests_run.returncode == 0:
            def durations(node):
                if isinstance(node, dict):
                    if node.get("nodeType") == "Test Case" and isinstance(node.get("durationInSeconds"), (int, float)):
                        yield node["durationInSeconds"]
                    for child in node.values():
                        yield from durations(child)
                elif isinstance(node, list):
                    for child in node:
                        yield from durations(child)
            for duration in durations(json.loads(tests_run.stdout)):
                lines.append(f"XCTest test duration: {duration:.3f}s")
        passed = False
        activities_run = subprocess.run([
            "xcrun", "xcresulttool", "get", "test-results", "activities", "--path", str(result_path),
            "--test-id", "InstalledJourneyTests/testRealCardJourney()",
        ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        milestones = set()
        diagnostics_text = run.stdout + "\n" + activities_run.stdout
        # Diagnose the observed startup failure without retaining arbitrary exception text.
        diagnostics = Path(temporary) / "diagnostics"
        subprocess.run(["xcrun", "xcresulttool", "export", "diagnostics", "--path", str(result_path), "--output-path", str(diagnostics)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        keychain_cast_failure = False
        for path in diagnostics.rglob("*"):
            if path.is_file() and path.suffix in (".txt", ".log"):
                data = path.read_text(errors="replace")
                diagnostics_text += "\n" + data
                if ("kotlin.collections.HashMap cannot be cast to class kotlinx.cinterop.CPointer" in data
                        and "SecurityKeychainSettings" in data):
                    keychain_cast_failure = True
        if keychain_cast_failure:
            lines.append("Startup blocker: SecurityKeychainSettings HashMap-to-CPointer ClassCastException before Login")
        for code in MILESTONES:
            if f"E2E-MILESTONE: {code}" in diagnostics_text:
                milestones.add(code)
                lines.append(f"Milestone: {code}")
        for match in re.finditer(r"E2E-TYPE: ([a-zA-Z.]+) native=(\d+)", diagnostics_text):
            message = f"XCUI field: {match.group(1)} nativeType={match.group(2)}"
            if message not in lines:
                lines.append(message)
        # Only fixed signup identifiers/stages and booleans/geometry are exported.
        # Never widen these to arbitrary values, labels, hierarchy or exceptions.
        safe_signup = r"signup\.(?:name|email|phone|password|passwordRepeat|terms|submit)"
        boolean = r"(?:true|false)"
        rect = r"-?\d+\.\d,-?\d+\.\d,\d+\.\d,\d+\.\d"
        for pattern in (
            rf"E2E-FIELD: {safe_signup} stage=(?:after_type|before_terms|after_terms) valueMatches={boolean} lengthMatches={boolean} masked={boolean} exists={boolean}",
            rf"E2E-TERMS: stage=(?:before|after) exists={boolean} selected={boolean} valueChecked={boolean} submitEnabled={boolean}",
            rf"E2E-FRAME: {safe_signup} stage=(?:before_tap|before_terms|after_terms) target={rect} viewport={rect} centerInside={boolean} frameInside={boolean} hittable={boolean} keyboard={boolean}",
            rf"E2E-PAYMENT-FRAME: target={rect} viewport={rect} centerInside={boolean} frameInside={boolean} hittable={boolean} enabled={boolean}",
            rf"E2E-BROWSER-UI: alerts=\d+ sheets=\d+ webviews=\d+",
            rf"E2E-SAFARI-PROMPT: owner=(?:safari|springboard) label=(?:Send|Send_Anyway|Отправить|Все_равно_отправить|Continue|Продолжить|Cancel|Отменить|Open|Открыть) exists={boolean} hittable={boolean} enabled={boolean} type=\d+",
            rf"E2E-VISUAL-BIOMETRIC: checked={boolean} enabled={boolean}",
        ):
            for match in re.finditer(pattern, diagnostics_text):
                if match.group(0) not in lines:
                    lines.append(match.group(0))
        for match in re.finditer(r"E2E-SELECT: card_type=\d+ selected=(?:true|false) value_one=(?:true|false)", diagnostics_text):
            if match.group(0) not in lines:
                lines.append(match.group(0))
        for match in re.finditer(r"E2E-BROWSER: (?:Payment_completed|CORS_ORIGIN_DENIED|Payment_failed|Return_to_ShopKMP|Complete_payment) exists=(?:true|false) type=\d+|E2E-PAYMENT: payment=(?:AUTHORIZED|CAPTURED|FAILED) order=(?:CREATED|PAID|CANCELLED)|E2E-PIN: (?:PIN_already_set|PIN_must_not_be_common|PIN_must_contain|deviceName) exists=(?:true|false)|E2E-PIN: persisted_count=\d+", diagnostics_text):
            if match.group(0) not in lines:
                lines.append(match.group(0))
        for match in re.finditer(r"E2E-PROMPT: (?:Open|Открыть|Send|Отправить|Cancel|Отменить) exists=(?:true|false) hittable=(?:true|false)", diagnostics_text):
            if match.group(0) not in lines:
                lines.append(match.group(0))
        for match in re.finditer(r"E2E-ORDER: [a-f0-9-]{36} product=[a-f0-9-]{36} total=\d+\.\d{2} payment=CAPTURED order=PAID", diagnostics_text):
            if match.group(0) not in lines:
                lines.append(match.group(0))
        if summary_run.returncode == 0:
            summary = json.loads(summary_run.stdout)
            lines.append(f"XCTest result: {summary['result']}; total={summary['totalTestCount']}; passed={summary['passedTests']}; failed={summary['failedTests']}")
            for failure in summary.get("testFailures", []):
                # Only our fixed assertion messages (no expression/actual values).
                text = failure.get("failureText", "")
                if "Installed Login exposes stable email selector" in text:
                    lines.append("Failure: Installed Login exposes stable email selector")
                elif match := re.search(r"E2E-CHECK: ([a-zA-Z0-9_.-]+)", text):
                    code = re.sub(r"pin\.digit\.\d", "pin_digit_redacted", match.group(1))
                    lines.append(f"Failure: {code}")
                else:
                    lines.append("Failure: XCTest interaction failed (raw details withheld)")
                    for marker in ("Failed to get matching snapshot", "Failed to launch", "not running",
                                   "Timed out", "No matches found", "Multiple matching elements",
                                   "Failed to establish communication", "Lost connection", "Crashed",
                                   "Failed to terminate", "Failed to activate"):
                        if marker.lower() in text.lower():
                            lines.append(f"Failure classification: {marker}")
            passed = (run.returncode == 0 and summary["totalTestCount"] == 1 and summary["passedTests"] == 1
                      and "full_journey_pass" in milestones)
        else:
            lines.append("No XCTest summary available; build/runner failed.")
        lines.append(f"xcodebuild exit: {run.returncode}")
        exported = Path(temporary) / "attachments"
        extraction = subprocess.run([
            "xcrun", "xcresulttool", "export", "attachments", "--path", str(result_path),
            "--output-path", str(exported),
        ], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        exported_names = set()
        if extraction.returncode == 0:
            for test in json.loads((exported / "manifest.json").read_text()):
                for attachment in test.get("attachments", []):
                    name = attachment["suggestedHumanReadableName"]
                    for allowed in SCREENSHOTS:
                        if re.fullmatch(rf"e2e-{re.escape(allowed)}(?:_[a-zA-Z0-9_-]+)?(?:\.png)?", name):
                            source = exported / attachment["exportedFileName"]
                            if source.is_file() and source.read_bytes().startswith(b"\x89PNG\r\n\x1a\n"):
                                shutil.copyfile(source, EVIDENCE / f"{allowed}.png")
                                shutil.copyfile(source, private_evidence / f"{allowed}.png")
                                exported_names.add(allowed)
                                lines.append(f"Screenshot: docs/evidence/ios/{allowed}.png")
        missing = sorted(set(SCREENSHOTS) - exported_names)
        lines.append(f"Named app screenshot exports: {len(exported_names)}/{len(SCREENSHOTS)}")
        if missing:
            lines.append("Evidence missing: " + ", ".join(missing))
        passed = passed and not missing
        lines.append(f"{'PASS' if passed else 'FAIL'} installed iOS full CARD journey")
    output = "\n".join(lines) + "\n"
    (EVIDENCE / "xctest-summary.txt").write_text(output)
    (private_evidence / "xctest-summary.txt").write_text(output)
    with report.open("a") as checkpoint:
        checkpoint.write("\n```text\n" + output + "```\n")
    print(output, end="")
    return 0 if passed else 1


if __name__ == "__main__":
    sys.exit(main())
