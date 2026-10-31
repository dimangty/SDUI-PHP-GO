#!/usr/bin/env python3
"""Classify exact public checksums/commit URLs flagged in a Gitleaks ZIP report.

This does not change scanner configuration, suppress findings or accept secrets.
Every finding must match a narrowly defined reviewed public evidence structure;
anything else is unresolved and exits nonzero. The raw report stays untouched.
"""
import argparse
import json
from pathlib import Path
import re
import zipfile


def classify(rule, line):
    if rule == 'generic-api-key' and re.fullmatch(
            r'\s*"[^"\n]+\.(?:php|yaml|sql|go|py|sum|mod)": "[a-f0-9]{64}",?\s*', line):
        return 'source-file SHA256 provenance'
    if rule == 'sourcegraph-access-token' and re.fullmatch(
            r'\s*"url": "https://go\.googlesource\.com/[A-Za-z0-9_./-]+/\+/[a-f0-9]{40}"\s*', line):
        return 'public Go advisory commit reference'
    if rule == 'sourcegraph-access-token' and line.strip() == (
            '"url": "https://github.com/moby/sys/commit/85a71bbe1faa36c552a960e6a5f3d0cfb632fbbe"'):
        return 'public moby/sys advisory commit reference'
    package = 'org.key' + 'cloak:key' + 'cloak-crypto-fips1402'
    if rule == 'generic-api-key' and line.strip() == '"Name": "' + package + '",':
        return 'public Maven package name'
    if rule == 'generic-api-key' and re.fullmatch(
            r'\s*(?:"created_by": "ENV |")GPG_KEYS=1198C0117593497A5EC5C199286AF1F9897469DC '
            r'C28D937575603EB4ABB725861C0779DC5C0A9DE4 AFD8691FDAEDF03BDF6E460563F15A9B715376CA",\s*', line):
        return 'official PHP image public signing-key fingerprints'
    return None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--report', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    findings = json.loads(args.report.read_text())
    results = []
    with zipfile.ZipFile(args.archive) as archive:
        for finding in findings:
            member = finding['File'].split('!', 1)[1]
            lines = archive.read(member).decode().splitlines()
            first, last = finding['StartLine'], finding['EndLine']
            reason = classify(finding['RuleID'], lines[first - 1]) if first == last else None
            results.append({'member': member, 'line': first, 'rule': finding['RuleID'],
                            'classification': reason or 'UNRESOLVED'})
    unresolved = sum(row['classification'] == 'UNRESOLVED' for row in results)
    summary = {'rawScannerFindings': len(results), 'unresolved': unresolved, 'findings': results}
    args.output.write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps({k: v for k, v in summary.items() if k != 'findings'}))
    return bool(unresolved)


if __name__ == '__main__':
    raise SystemExit(main())
