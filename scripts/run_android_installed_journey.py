#!/usr/bin/env python3
"""Build/install/run Task5 on the actual 58095 edge, retaining nonsecret evidence.

Clears com.shopkmp test-account app data. No API fixtures, auth injection or browser
URL/hierarchy capture. adb's process status alone does not indicate test success.
"""
import argparse
from datetime import datetime, timezone
import os
from pathlib import Path
import subprocess
import sys


ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / "docs/evidence/android"
SCREENSHOTS = (
    "product.png", "checkout-confirmation.png", "orders-created.png",
    "orders-paid.png", "order-detail.png",
)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--serial", default=os.environ.get("ANDROID_SERIAL", "emulator-5554"))
    args = parser.parse_args()
    env = os.environ.copy()
    home = Path.home()
    env.setdefault("ANDROID_HOME", str(home / "Library/Android/sdk"))
    env.setdefault("JAVA_HOME", str(home / "Library/Java/JavaVirtualMachines/jbr-17.0.9/Contents/Home"))
    adb = [str(Path(env["ANDROID_HOME"]) / "platform-tools/adb"), "-s", args.serial]

    def run(command, **kwargs):
        return subprocess.run(command, cwd=ROOT, env=env, check=True, **kwargs)

    EVIDENCE.mkdir(parents=True, exist_ok=True)
    # Only declared outputs of this helper; prevent old successful captures looking current.
    for name in SCREENSHOTS:
        (EVIDENCE / name).unlink(missing_ok=True)
    started = datetime.now(timezone.utc).isoformat()
    identity = f"Started UTC: {started}\nDevice: {args.serial}\n"
    summary = EVIDENCE / "instrumentation.txt"
    summary.write_text(identity + "RUNNING: preflight; no journey verdict yet\n")
    phase = "build"
    try:
        run([
            str(ROOT / "gradlew"), ":composeApp:assembleDebug", ":composeApp:assembleDebugAndroidTest",
            "-PSHOPKMP_DEBUG_SERVER_URL=http://10.0.2.2:58095", "--console=plain", "--quiet",
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
    result = subprocess.run(adb + [
        "shell", "am", "instrument", "-w", "-r", "-e", "class",
        "com.shopkmp.InstalledJourneyTest#realCardJourney",
        "com.shopkmp.test/androidx.test.runner.AndroidJUnitRunner",
    ], cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    output = result.stdout
    summary.write_text(identity + output)
    print(output, end="")
    for name in SCREENSHOTS:
        remote = f"/sdcard/Android/data/com.shopkmp/files/android-e2e/{name}"
        exists = subprocess.run(adb + ["shell", "test", "-f", remote], env=env,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if exists.returncode == 0:
            run(adb + ["pull", remote, str(EVIDENCE / name)])
    passed = result.returncode == 0 and "OK (1 test)" in output and "FAILURES!!!" not in output
    print(f"{'PASS' if passed else 'FAIL'} installed Android journey; evidence: docs/evidence/android/")
    return 0 if passed else 1


if __name__ == "__main__":
    sys.exit(main())
