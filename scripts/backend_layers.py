#!/usr/bin/env python3
"""Small, explicit application/adapter and pure-mapper dependency policy."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def violations(root):
    findings = []
    for path in (root / 'backend-sdui/internal/application').rglob('*.go'):
        text = path.read_text()
        imports = re.findall(r'^import\s*\((.*?)\)|^import[ \t]+([^\n]+)', text, re.M | re.S)
        dependencies = re.findall(r'"([^"\n]+)"', '\n'.join(a + b for a, b in imports))
        if any(name == 'database/sql' or name.startswith(('github.com/jackc/',
                'shopkmp/backend-sdui/internal/yii', 'shopkmp/backend-sdui/internal/bff'))
                for name in dependencies):
            findings.append(str(path.relative_to(root)) + ': application imports an adapter')
    for path in (root / 'backend-yii2').rglob('*DtoMapper.php'):
        if 'vendor' in path.parts or 'dev-tools' in path.parts:
            continue
        text = path.read_text()
        if re.search(r'\bDatabase\b|\b(?:SELECT|INSERT|UPDATE|DELETE)\s+(?:\w|\*)', text):
            findings.append(str(path.relative_to(root)) + ': DTO mapper contains database work')
    return findings


if __name__ == '__main__':
    failures = violations(ROOT)
    for failure in failures:
        print(failure)
    print(f'Backend layer policy: {len(failures)} findings')
    raise SystemExit(bool(failures))
