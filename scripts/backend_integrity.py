#!/usr/bin/env python3
"""Compare frozen client/contracts and pre-existing migration bytes to an explicit base."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys
from compose import ROOT


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    protected = ['core', 'feature', 'composeApp', 'iosApp', 'build-logic', 'gradle',
        'build.gradle.kts', 'settings.gradle.kts', 'gradle.properties', 'gradlew', 'gradlew.bat',
        'backend-yii2/contracts', 'backend-sdui/contracts']
    result = subprocess.run(['git', 'diff', '--exit-code', args.base, '--', *protected], cwd=ROOT, capture_output=True)
    paths = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', args.base, '--',
                                    'tools/ops/migrations', 'backend-yii2/migrations'], cwd=ROOT, text=True).splitlines()
    migrations = {}
    for path in paths:
        original = subprocess.check_output(['git', 'show', args.base + ':' + path], cwd=ROOT)
        current = (ROOT / path).read_bytes()
        migrations[path] = {'baseSha256': hashlib.sha256(original).hexdigest(),
                             'currentSha256': hashlib.sha256(current).hexdigest(), 'equal': original == current}
    contracts = {}
    contract_paths = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', args.base, '--',
        'backend-yii2/contracts', 'backend-sdui/contracts'], cwd=ROOT, text=True).splitlines()
    for path in contract_paths:
        original = subprocess.check_output(['git', 'show', args.base + ':' + path], cwd=ROOT)
        current = (ROOT / path).read_bytes()
        contracts[path] = {'baseSha256': hashlib.sha256(original).hexdigest(),
                           'currentSha256': hashlib.sha256(current).hexdigest(), 'equal': original == current}
    ci_path = '.github/workflows/ci.yml'
    original_ci = subprocess.check_output(['git', 'show', args.base + ':' + ci_path], cwd=ROOT)
    current_ci = (ROOT / ci_path).read_bytes()
    mobile_jobs_unchanged = original_ci.split(b'\n  android:', 1)[1] == current_ci.split(b'\n  android:', 1)[1]
    ok = (result.returncode == 0 and mobile_jobs_unchanged
          and all(row['equal'] for row in [*migrations.values(), *contracts.values()]))
    args.output.write_text(json.dumps({'base': args.base, 'status': 'PASSED' if ok else 'FAILED',
        'frozenClientAndContractsUnchanged': result.returncode == 0, 'mobileCIJobsUnchanged': mobile_jobs_unchanged,
        'contracts': contracts, 'migrations': migrations}, indent=2) + '\n')
    print(f'{"PASSED" if ok else "FAILED"}: frozen client/contracts, {len(migrations)} original migration files; {args.output}')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
