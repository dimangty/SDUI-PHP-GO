#!/usr/bin/env python3
"""Fail-closed policy over unmodified Trivy JSON; raw findings are never erased."""
import argparse
from collections import Counter
import datetime as dt
import hashlib
import json
from pathlib import Path
import sys

BLOCKING = {'CRITICAL', 'HIGH', 'UNKNOWN'}
SEVERITIES = BLOCKING | {'MEDIUM', 'LOW'}


def utc(value):
    parsed = dt.datetime.fromisoformat(value.replace('Z', '+00:00'))
    if parsed.tzinfo is None:
        raise ValueError('timestamp must include timezone')
    return parsed


def fresh_database(metadata, now, max_age):
    updated, next_update = utc(metadata['UpdatedAt']), utc(metadata['NextUpdate'])
    if updated > now + dt.timedelta(minutes=5) or now - updated > max_age or next_update <= now:
        raise ValueError('required scanner database is stale or has an invalid timestamp')


def evaluate(report, artifact, platform, dispositions, now=None):
    now = now or dt.datetime.now(dt.timezone.utc)
    if (report.get('SchemaVersion') != 2 or report.get('ArtifactType') != 'container_image'
            or not report.get('ArtifactName') or report.get('Metadata', {}).get('ImageID') != artifact
            or not isinstance(report.get('Results'), list)):
        raise ValueError('missing, unsupported or artifact-mismatched Trivy report')
    if not isinstance(dispositions, list):
        raise ValueError('dispositions must be a list')
    config = report.get('Metadata', {}).get('ImageConfig', {})
    if config.get('os', '') + '/' + config.get('architecture', '') != platform:
        raise ValueError('Trivy report platform does not match the required artifact platform')
    counts, blockers, accepted = Counter(), [], []
    for result in report['Results']:
        vulnerabilities = result.get('Vulnerabilities') or []
        if not isinstance(vulnerabilities, list):
            raise ValueError('invalid vulnerability array')
        for finding in vulnerabilities:
            severity = finding.get('Severity')
            if severity not in SEVERITIES:
                raise ValueError('missing or unrecognized finding severity')
            key = {'id': finding['VulnerabilityID'], 'package': finding['PkgName'],
                   'version': finding['InstalledVersion'], 'target': result['Target'],
                   'artifact': artifact, 'platform': platform, 'packagePath': finding.get('PkgPath', '')}
            counts[severity] += 1
            matched = [item for item in dispositions if all(item.get(k) == v for k, v in key.items())]
            if len(matched) > 1:
                raise ValueError('ambiguous disposition')
            if matched:
                item = matched[0]
                required = ('owner', 'approvedBy', 'approval', 'evidence', 'evidenceSha256')
                if (any(not isinstance(item.get(k), str) or not item[k].strip() for k in required)
                        or item.get('status') not in {'NotAffected', 'Mitigated', 'AcceptedRisk'}
                        or utc(item['expires']) <= now
                        or hashlib.sha256(Path(item['evidence']).read_bytes()).hexdigest() != item['evidenceSha256']):
                    raise ValueError('unapproved, expired or unsupported disposition evidence')
                accepted.append(key | {k: item[k] for k in (*required, 'status', 'expires')})
            elif severity in BLOCKING:
                blockers.append(key | {'severity': severity})
    return {'status': 'FAILED' if blockers else ('PASSED_WITH_DISPOSITIONS' if accepted else 'PASSED'),
            'rawCounts': dict(counts), 'blockers': blockers, 'dispositions': accepted,
            'policy': {'blockingSeverities': sorted(BLOCKING)}}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--report', type=Path, required=True)
    parser.add_argument('--artifact-id', required=True)
    parser.add_argument('--platform', required=True)
    parser.add_argument('--dispositions', type=Path)
    parser.add_argument('--scanner-exit', type=int, default=0)
    args = parser.parse_args()
    try:
        if args.scanner_exit != 0:
            raise ValueError('scanner execution failed')
        report = json.loads(args.report.read_text())
        dispositions = json.loads(args.dispositions.read_text()) if args.dispositions else []
        result = evaluate(report, args.artifact_id, args.platform, dispositions)
        print(json.dumps(result, indent=2))
        return 1 if result['blockers'] else 0
    except (ValueError, KeyError, TypeError, OSError) as error:
        print(json.dumps({'status': 'FAILED', 'error': str(error)}), file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
