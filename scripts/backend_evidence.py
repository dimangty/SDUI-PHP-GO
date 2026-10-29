#!/usr/bin/env python3
"""Package portable backend text evidence, excluding caches/binaries/image archives.

The index binds every retained log/report by SHA256. This packages evidence; it
does not interpret failures as successes or grant build/publication acceptance.
"""
import argparse
import hashlib
import json
from pathlib import Path
import zipfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, required=True)
    args = parser.parse_args()
    root = args.directory.resolve(strict=True)
    entries = []
    paths = []
    for path in sorted(root.rglob('*')):
        relative = path.relative_to(root)
        if (not path.is_file() or path.is_symlink() or
                any(part in ('cache', 'tools', '__pycache__') for part in relative.parts) or
                path.name in ('INDEX.json',) or path.suffix not in ('.json', '.log', '.stdout', '.stderr')):
            continue
        data = path.read_bytes()
        entries.append({'path': str(relative), 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
        paths.append(path)
    index = {'meaning': 'Raw evidence; exits/status remain authoritative, including RED/FAIL/NOT_RUN', 'files': entries}
    (root / 'INDEX.json').write_text(json.dumps(index, indent=2) + '\n')
    archive = root / 'round3-evidence.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
        bundle.write(root / 'INDEX.json', 'INDEX.json')
        for path in paths:
            bundle.write(path, str(path.relative_to(root)))
    with zipfile.ZipFile(archive) as bundle:
        for entry in entries:
            if hashlib.sha256(bundle.read(entry['path'])).hexdigest() != entry['sha256']:
                raise ValueError('Evidence archive checksum mismatch')
    print(json.dumps({'archive': str(archive), 'files': len(entries), 'bytes': archive.stat().st_size,
                      'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'verification': 'PASSED'}))


if __name__ == '__main__':
    main()
