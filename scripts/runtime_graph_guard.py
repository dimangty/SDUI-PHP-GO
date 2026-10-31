#!/usr/bin/env python3
"""Reject retired identity dependencies; only exact frozen evidence may be exempt.

Checks tracked and new non-ignored files, including application manifests/tests.
The JSON manifest is policy input, not an exception for executable source.
"""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = 'scripts/runtime_history_allowlist.json'
# Assemble spellings so this checker and its adversarial tests need no exemptions.
PROVIDER = 'key' + 'cloak'
FORBIDDEN = re.compile('|'.join((PROVIDER, r'\b' + 'oid' + r'c\b', r'\b' + 'jw' + r'ks\b', 'Oid' + 'cVerifier',
    'firebase/php-' + 'jwt', r'Firebase\\+JWT', 'signed_' + 'oid' + 'c',
    r'bearerFormat:\s*' + 'JWT', r'ADMIN_' + r'EMAILS\s*[:=]',
    r'ADMIN_' + r'BOOTSTRAP_TOKEN\s*[:=]')), re.IGNORECASE)
CURRENT_DOCS = {'docs/ARCHITECTURE.md', 'docs/DEPLOYMENT.md', 'docs/ROUTE_MATRIX.md',
    'docs/backend-fixes/SESSIONS.md', 'docs/backend-fixes/MIGRATION_ROLLBACK.md',
    'docs/backend-fixes/TRUST_AND_RATE_LIMITS.md'}
LIVE_INPUT = re.compile(r'\b(volumes?|env_file|configs|secrets|include|require|COPY|ADD|open|read_text|read_bytes|file_get_contents)\b', re.IGNORECASE)


def historical_path(path):
    """Eligibility alone grants nothing: every exclusion also needs an exact hash."""
    p = PurePosixPath(path)
    if p.is_absolute() or '..' in p.parts or str(p) != path:
        return False
    if path in CURRENT_DOCS or path.startswith('docs/backend-fixes/round-5-local-auth/'):
        return False
    if path.startswith('docs/backend-fixes/local-auth/') and not path.startswith('docs/backend-fixes/local-auth/history/'):
        return False
    if path == 'backend-yii2/IMPLEMENTATION_LEDGER.md':
        return True
    if path.startswith('docs/images/') and p.suffix in ('.jpg', '.png'):
        return True
    if path.startswith('docs/backend-fixes/local-auth/history/'):
        return p.suffix in ('.json', '.md') or p.name == 'SHA256SUMS'
    if path.startswith(('docs/', 'backend-sdui/docs/', 'deploy/evidence/')):
        return p.suffix in ('.md', '.json', '.log', '.txt', '.svg', '.drawio')
    return False


def source_paths(root):
    result = subprocess.check_output(['git', 'ls-files', '-z', '--cached', '--others',
        '--exclude-standard'], cwd=root)
    return sorted(set(result.decode().rstrip('\0').split('\0')) - {''})


def current_report_path(path):
    return path.startswith(('docs/backend-fixes/round-5-local-auth/',
        'docs/backend-fixes/local-auth/')) and PurePosixPath(path).suffix in ('.md', '.json')


def audit(root):
    problems = []
    allowed = set()
    document = json.loads((root / MANIFEST).read_text())
    if document.get('schemaVersion') != 1 or not isinstance(document.get('files'), list):
        raise ValueError('invalid historical manifest schema')
    for row in document['files']:
        path = row.get('path', '')
        if not isinstance(path, str) or not historical_path(path):
            problems.append(f'{path}: not a historical evidence path')
            continue
        if path in allowed:
            problems.append(f'{path}: duplicate historical entry')
            continue
        allowed.add(path)
        target = root / path
        if not isinstance(row.get('reason'), str) or not row['reason'].strip():
            problems.append(f'{path}: historical reason required')
        if target.is_symlink() or not target.is_file():
            problems.append(f'{path}: missing or symlinked historical evidence')
        elif hashlib.sha256(target.read_bytes()).hexdigest() != row.get('sha256'):
            problems.append(f'{path}: historical content hash changed')
    paths = source_paths(root)
    reports = {path for path in paths if current_report_path(path)}
    for path in paths:
        target = root / path
        if path == MANIFEST or path in allowed or not target.exists():
            continue
        if target.is_symlink():
            problems.append(f'{path}: source symlinks require explicit review')
            continue
        if FORBIDDEN.search(path):
            problems.append(f'{path}:1: retired identity dependency in source path')
        data = target.read_bytes()
        if b'\0' in data:  # Binary screenshots/archives cannot be executable text.
            continue
        text = data.decode('utf-8', errors='replace')
        # The current cutover specification and audit must describe retired trust
        # and removed packages accurately. They are reports, not dependency-graph
        # inputs. Executable/config files in these directories remain scanned;
        # historical exceptions still require their original content hashes.
        current_report = path in reports
        for number, line in enumerate(text.splitlines(), 1):
            if target.suffix != '.md' and LIVE_INPUT.search(line) and any(old in line for old in allowed | reports):
                problems.append(f'{path}:{number}: historical or current evidence used by live source')
            if not current_report and FORBIDDEN.search(line):
                # Never print source text: environment/fixture lines can contain secrets.
                problems.append(f'{path}:{number}: retired identity dependency or registration-admin config')
    return paths, allowed, problems


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    parser.add_argument('--historical-inventory', action='store_true',
                        help='Print eligible historical hashes for review; never writes/approves exceptions')
    args = parser.parse_args()
    try:
        if args.historical_inventory:
            root = args.root.resolve()
            rows = []
            for path in source_paths(root):
                target = root / path
                if not historical_path(path) or target.is_symlink() or not target.is_file():
                    continue
                data = target.read_bytes()
                if FORBIDDEN.search(path) or FORBIDDEN.search(data.decode('utf-8', errors='replace')) or target.name == 'SHA256SUMS':
                    rows.append({'path': path, 'sha256': hashlib.sha256(data).hexdigest(),
                                 'reason': 'Frozen pre-local-auth implementation, policy or execution evidence; not current runtime instructions'})
            print(json.dumps({'schemaVersion': 1, 'files': rows}, indent=2))
            return 0
        paths, allowed, problems = audit(args.root.resolve())
    except (OSError, ValueError, TypeError, AttributeError, subprocess.SubprocessError) as error:
        print(f'FAILED dependency graph: {type(error).__name__}; invalid/missing graph policy or source inventory')
        return 1
    for problem in problems:
        print(problem)
    print(f'{"FAILED" if problems else "PASSED"} dependency graph: {len(paths)} source files, '
          f'{len(allowed)} exact historical files, {len(problems)} findings')
    return 1 if problems else 0


if __name__ == '__main__':
    raise SystemExit(main())
