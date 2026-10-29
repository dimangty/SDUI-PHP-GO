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
        'backend-yii2/contracts', 'backend-sdui/internal/sdui/schema']
    result = subprocess.run(['git', 'diff', '--exit-code', args.base, '--', *protected], cwd=ROOT, capture_output=True)
    paths = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', args.base, '--',
                                    'tools/ops/migrations', 'backend-yii2/migrations'], cwd=ROOT, text=True).splitlines()
    migrations = {}
    for path in paths:
        original = subprocess.check_output(['git', 'show', args.base + ':' + path], cwd=ROOT)
        current = (ROOT / path).read_bytes()
        migrations[path] = {'baseSha256': hashlib.sha256(original).hexdigest(),
                            'currentSha256': hashlib.sha256(current).hexdigest(), 'equal': original == current}
    ok = result.returncode == 0 and all(row['equal'] for row in migrations.values())
    args.output.write_text(json.dumps({'base': args.base, 'status': 'PASSED' if ok else 'FAILED',
        'frozenClientAndContractsUnchanged': result.returncode == 0, 'migrations': migrations}, indent=2) + '\n')
    print(f'{"PASSED" if ok else "FAILED"}: frozen client/contracts, {len(migrations)} original migration files; {args.output}')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
