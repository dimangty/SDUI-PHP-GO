#!/usr/bin/env python3
"""Run linked, test-entitled Kotlin/Native security tests on the booted simulator.

Run from the repository root after linkDebugTestIosSimulatorArm64. Parse SIMPLE
results explicitly: simctl's exit status alone does not indicate test success.
Only generated test namespaces are used; no application data is accessed.
"""
import argparse
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[4]


def run(module, udid):
    binary = ROOT / f"core/{module}/build/bin/iosSimulatorArm64/debugTest/test.kexe"
    result = subprocess.run([
        "xcrun", "simctl", "spawn", udid, str(binary), "--ktest_logger=SIMPLE",
    ], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=120)
    print(result.stdout, end="")
    passed = sum(line.startswith("Passed:") for line in result.stdout.splitlines())
    failed = sum(line.startswith("Failed:") for line in result.stdout.splitlines())
    success = result.returncode == 0 and "Testing finished" in result.stdout and passed > 0 and failed == 0
    print(f"Native {module}: passed={passed}; failed={failed}; simctl={result.returncode}; "
          f"{'PASS' if success else 'FAIL'}", flush=True)
    return success


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--udid", default="16B71172-52E2-47E6-A951-EFAB9627917B")
    parser.add_argument("--module", choices=("settings", "common", "both"), default="both")
    args = parser.parse_args()
    results = [run(module, args.udid) for module in
               (("settings", "common") if args.module == "both" else (args.module,))]
    return 0 if all(results) else 1


if __name__ == "__main__":
    raise SystemExit(main())
