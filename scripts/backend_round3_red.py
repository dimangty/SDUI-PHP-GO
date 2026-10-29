#!/usr/bin/env python3
"""Replay correct-behavior F1/F2 regressions against immutable task-baseline Go.

Uses a disposable source tree, the module's original native toolchain and only
new test files. Never changes the checkout, toolchain or production artifacts.
The expected outcome is a failing test command (exit 1), not a green suite.
"""
import io
from pathlib import Path
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
BASE = '30a882952f0e92e369fd7f31f192aecbbffd6b62'


def main():
    archive = subprocess.check_output(['git', 'archive', BASE, 'backend-sdui'], cwd=ROOT)
    with tempfile.TemporaryDirectory(prefix='round3-native-red-') as directory:
        with tarfile.open(fileobj=io.BytesIO(archive)) as source:
            source.extractall(directory, filter='data')
        module = Path(directory) / 'backend-sdui'
        for name in ['round3_regression_test.go', 'round3_http_test.go']:
            path = Path('internal/bff') / name
            (module / path).write_bytes((ROOT / 'backend-sdui' / path).read_bytes())
        subprocess.run(['go', 'version'], cwd=module, check=True)
        print(f'Original production source: {BASE}; only the two new tests overlaid', flush=True)
        return subprocess.run(['go', 'test', './internal/bff', '-count=1', '-run',
                               '^TestRound3(F1LargeCatalogHTTPProjection|F2LegacyDevicesHTTP200)$', '-v'],
                              cwd=module).returncode


if __name__ == '__main__':
    raise SystemExit(main())
