#!/usr/bin/env python3
"""Scan exact artifacts of a disposable, already tested backend Compose project.

No rebuild/pull of runtime tags occurs here. Scan immutable local image archives,
production Composer dependencies, Go source and the actual delivered Go binaries.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import signal
import time
import uuid

from backend_security_policy import evaluate, fresh_database, utc
from compose import command, ROOT
from backend_artifacts import inventory, source_snapshot, verify_receipt

TRIVY = 'aquasec/trivy:0.76.1@sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c'
GOVULNCHECK = 'golang.org/x/vuln/cmd/govulncheck@v1.9.0'
RUNTIMES = ('yii2', 'sdui', 'migrate', 'postgres', 'keycloak', 'opensearch', 'edge', 'yii2-http', 'keycloak-postgres')
BINARIES = {'sdui': ('/server', 'go1.27.3'), 'migrate': ('/migrate', 'go1.26.10'),
            'keycloak': ('/opt/keycloak/bin/shopkmp-healthcheck', 'go1.26.10'),
            'postgres': ('/usr/local/bin/gosu', 'go1.26.10')}


def sha(path):
    with Path(path).open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def json_stream(text):
    decoder, result = json.JSONDecoder(), []
    while text.strip():
        value, end = decoder.raw_decode(text.lstrip())
        result.append(value)
        text = text.lstrip()[end:]
    return result


def go_policy(text, expected_go):
    messages = json_stream(text)
    configs = [m['config'] for m in messages if 'config' in m]
    sboms = [m['SBOM'] for m in messages if 'SBOM' in m]
    if (len(configs) != 1 or configs[0].get('scanner_version') != 'v1.9.0'
            or len(sboms) != 1 or sboms[0].get('go_version') != expected_go
            or (configs[0].get('scan_mode') == 'source' and configs[0].get('go_version') != expected_go)):
        raise ValueError('missing/mismatched govulncheck configuration or Go toolchain')
    now = dt.datetime.now(dt.timezone.utc)
    modified = utc(configs[0]['db_last_modified'])
    if now - modified > dt.timedelta(hours=72) or modified > now + dt.timedelta(minutes=5):
        raise ValueError('Go vulnerability database is stale or invalid')
    findings = [m['finding'] for m in messages if 'finding' in m]
    # Conservative: even module/package-only detections require review, not an
    # automatic NotAffected claim. Trace reachability remains in the raw stream.
    return {'status': 'FAILED' if findings else 'PASSED', 'rawFindings': findings, 'config': configs[0]}


class Scanner:
    def __init__(self, output, seconds=900):
        self.output = output
        self.failures = []
        self.deadline = time.monotonic() + seconds
        self.write('gate.json', {'status': 'INCOMPLETE', 'timestamp': dt.datetime.now(dt.timezone.utc).isoformat()})

    def run(self, name, argv, cwd=ROOT, env=None, required=True):
        started = dt.datetime.now(dt.timezone.utc).isoformat()
        with (self.output / (name + '.stdout')).open('w') as out, (self.output / (name + '.stderr')).open('w') as err:
            try:
                remaining = min(600, self.deadline - time.monotonic())
                if remaining <= 0:
                    raise subprocess.TimeoutExpired(argv, 0)
                process = subprocess.Popen(argv, cwd=cwd, env=env, stdout=out, stderr=err, start_new_session=True)
                try:
                    code = process.wait(timeout=remaining)
                finally:
                    if process.poll() is None:
                        os.killpg(process.pid, signal.SIGKILL)
                        process.wait()
            except subprocess.TimeoutExpired:
                err.write('Scanner command/overall deadline exceeded\n')
                code = 124
            except OSError as error:
                err.write(str(error) + '\n')
                code = 127
        (self.output / (name + '.command.json')).write_text(json.dumps({'command': argv, 'cwd': str(cwd),
            'started': started, 'finished': dt.datetime.now(dt.timezone.utc).isoformat(), 'exitCode': code}, indent=2) + '\n')
        if required and code:
            raise RuntimeError(f'{name} execution failed (exit {code})')
        return code, (self.output / (name + '.stdout')).read_text()

    def attempt(self, label, work):
        try:
            work()
        except (OSError, ValueError, KeyError, TypeError, RuntimeError, subprocess.SubprocessError) as error:
            self.failures.append({'check': label, 'error': str(error)})
            print(f'FAILED {label}: {error}', flush=True)

    def trivy(self, name, *args):
        container = 'shopkmp-security-' + uuid.uuid4().hex
        try:
            return self.run(name, ['docker', 'run', '--rm', '--name', container, '-v', str(self.output) + ':/work',
                '-v', str(self.cache) + ':/cache', TRIVY,
                '--cache-dir', '/cache', '--config', '', '--timeout', '15m', *args])
        finally:
            # Killing the Docker CLI alone does not stop a daemon-side scanner.
            # Only this invocation's unique container may be removed.
            with (self.output / (name + '.cleanup.log')).open('w') as log:
                subprocess.run(['docker', 'rm', '-f', container], stdout=log, stderr=log, timeout=10)

    def write(self, name, value):
        (self.output / name).write_text(json.dumps(value, indent=2) + '\n')

    def finish(self, platform):
        self.write('gate.json', {'status': 'FAILED' if self.failures else 'PASSED', 'failures': self.failures,
            'timestamp': dt.datetime.now(dt.timezone.utc).isoformat(), 'platform': platform})
        return 1 if self.failures else 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env', default='.env.example')
    parser.add_argument('--project', required=True)
    parser.add_argument('--platform', choices=['linux/amd64', 'linux/arm64'], required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--cache', type=Path, help='Optional reusable scanner DB cache; always refreshed and checked')
    parser.add_argument('--receipt', type=Path, help='Build/test receipt from integration-up/integration-check')
    parser.add_argument('--deadline-seconds', type=int, default=900)
    args = parser.parse_args()
    if not args.project.endswith('-e2e'):
        parser.error('scan an explicitly named disposable *-e2e project')
    output = args.output.resolve()
    # Never reuse stale reports from a previous invocation.
    output.mkdir(parents=True, exist_ok=False)
    if not 30 <= args.deadline_seconds <= 900:
        parser.error('scan deadline must be 30..900 seconds')
    scan = Scanner(output, args.deadline_seconds)
    scan.cache = (args.cache or output / 'cache').resolve()
    scan.cache.mkdir(parents=True, exist_ok=True)
    compose = command(ROOT / args.env, args.project)
    artifacts = {}
    now = dt.datetime.now(dt.timezone.utc)
    dispositions = json.loads((ROOT / 'deploy/security/dispositions.json').read_text())
    database_content = {}
    _, commit = scan.run('commit', ['git', 'rev-parse', 'HEAD'])
    _, diff = scan.run('worktree', ['git', 'diff', '--binary', 'HEAD', '--', 'backend-yii2', 'backend-sdui', 'tools/ops', 'deploy', 'scripts', 'Makefile', '.github/workflows/ci.yml'])
    (output / 'worktree.stdout').unlink()
    _, paths = scan.run('source-files', ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--',
        'backend-yii2', 'backend-sdui', 'tools/ops', 'deploy', 'scripts', 'Makefile', '.github/workflows/ci.yml'])
    source_hashes = {p: sha(ROOT / p) for p in sorted(set(paths.splitlines()))
                     if not p.startswith('deploy/evidence/') and (ROOT / p).is_file()}
    scan.write('source-hashes.json', source_hashes)
    scan.write('provenance.json', {'commit': commit.strip(), 'worktreeDiffSha256': hashlib.sha256(diff.encode()).hexdigest(),
        'timestamp': now.isoformat(), 'platform': args.platform, 'trivy': TRIVY, 'govulncheck': GOVULNCHECK,
        'lockfiles': {p: sha(ROOT / p) for p in ('backend-yii2/composer.lock', 'backend-sdui/go.mod', 'backend-sdui/go.sum', 'tools/ops/go.mod', 'tools/ops/go.sum')}})

    def artifact_inventory():
        artifacts.update(inventory(compose, args.platform, scan.deadline))
        receipt = json.loads((args.receipt or ROOT / 'deploy/evidence' / (args.project + '-artifacts.json')).read_text())
        verify_receipt(receipt, source_snapshot(), artifacts)
        scan.write('build-test-receipt.json', receipt)
        scan.write('artifacts.json', artifacts)
    scan.attempt('artifact-inventory', artifact_inventory)

    def databases():
        scan.trivy('trivy-version', 'version', '--format', 'json')
        scan.trivy('trivy-db-download', 'image', '--download-db-only', '--db-repository', 'ghcr.io/aquasecurity/trivy-db:2')
        scan.trivy('trivy-java-db-download', 'image', '--download-java-db-only', '--java-db-repository', 'ghcr.io/aquasecurity/trivy-java-db:1')
        for directory, version, hours in (('db', 2, 48), ('java-db', 1, 96)):
            metadata = json.loads((scan.cache / directory / 'metadata.json').read_text())
            if metadata.get('Version') != version:
                raise ValueError('unsupported scanner database version')
            fresh_database(metadata, dt.datetime.now(dt.timezone.utc), dt.timedelta(hours=hours))
            scan.write(directory + '-database.json', metadata)
            database_content[directory] = {p.name: sha(p) for p in (scan.cache / directory).iterdir() if p.is_file()}
            scan.write(directory + '-database-content.json', database_content[directory])
        scan.trivy('trivy-database-version', 'version', '--format', 'json')
    scan.attempt('scanner-databases', databases)
    db_ok = not any(f['check'] == 'scanner-databases' for f in scan.failures)

    def composer():
        image = artifacts['yii2']['imageId']
        scan.run('composer-version', ['docker', 'run', '--rm', '--network', 'none', image, 'composer', '--version'])
        code, text = scan.run('composer-audit', ['docker', 'run', '--rm', image, 'composer', 'audit', '--locked', '--no-dev', '--format=json'], required=False)
        report = json.loads(text)
        scan.write('composer-audit.json', report)
        if code or report.get('advisories') or report.get('abandoned') or 'advisories' not in report:
            raise ValueError('Composer audit rejected production dependencies or report missing')
    scan.attempt('composer-production', composer)

    tools = output / 'tools'
    tools.mkdir()
    go_env = os.environ | {'GOBIN': str(tools), 'GOTOOLCHAIN': 'go1.27.3'}
    scan.attempt('govulncheck-install', lambda: scan.run('govulncheck-install', ['go', 'install', GOVULNCHECK], env=go_env))
    govuln = str(tools / 'govulncheck')
    for module, version in (('backend-sdui', 'go1.27.3'), ('tools/ops', 'go1.26.10')):
        def source(module=module, version=version):
            label = 'go-source-' + module.replace('/', '-')
            _, text = scan.run(label, [govuln, '-json', './...'], cwd=ROOT / module,
                               env=os.environ | {'GOTOOLCHAIN': version})
            result = go_policy(text, version)
            scan.write(label + '.policy.json', result)
            if result['status'] != 'PASSED':
                raise ValueError('Go raw findings require review')
        scan.attempt('source-' + module, source)

    for service in RUNTIMES:
        def image_scan(service=service):
            artifact = artifacts[service]
            # Identical runtime artifacts (two nginx / two PostgreSQL services)
            # still get separate service receipts, referencing the same content.
            archive = output / (service + '.tar')
            scan.run('save-' + service, ['docker', 'image', 'save', '--platform', args.platform, '--output', str(archive), artifact['imageId']])
            artifact['archiveSha256'] = sha(archive)
            scan.write('artifacts.json', artifacts)
            if not db_ok:
                raise ValueError('mandatory scanner database unavailable; scan blocked')
            scan.trivy('scan-' + service, 'image', '--input', '/work/' + archive.name,
                '--skip-db-update', '--skip-java-db-update', '--offline-scan', '--scanners', 'vuln',
                '--ignorefile', '', '--format', 'json', '--list-all-pkgs', '--exit-code', '0',
                '--output', '/work/' + service + '.raw.json')
            report = json.loads((output / (service + '.raw.json')).read_text())
            # The archive manifest supplies the config digest, independent of
            # Docker's classic vs containerd local index ID representation.
            import tarfile
            with tarfile.open(archive) as tar:
                manifest = json.load(tar.extractfile('manifest.json'))
                config_name = manifest[0]['Config']
                config_bytes = tar.extractfile(config_name).read()
                config_id = 'sha256:' + hashlib.sha256(config_bytes).hexdigest()
                config = json.loads(config_bytes)
                if config.get('os', '') + '/' + config.get('architecture', '') != args.platform:
                    raise ValueError('saved archive platform differs from required platform')
            artifact['configDigest'] = config_id
            scan.write('artifacts.json', artifacts)
            result = evaluate(report, config_id, args.platform, dispositions)
            scan.write(service + '.policy.json', result)
            scan.trivy('sbom-' + service, 'convert', '--format', 'cyclonedx', '--output', '/work/' + service + '.sbom.cdx.json', '/work/' + service + '.raw.json')
            sbom = json.loads((output / (service + '.sbom.cdx.json')).read_text())
            if sbom.get('bomFormat') != 'CycloneDX' or not sbom.get('components'):
                raise ValueError('required SBOM missing or empty')
            # Preserve a hash receipt; raw archives are local artifacts, not CI
            # uploads containing the complete PHP test source/fixture credentials.
            archive.unlink()
            if result['blockers']:
                raise ValueError(f'{len(result["blockers"])} unresolved image policy blockers')
        scan.attempt('image-' + service, image_scan)

    for service, (path, version) in BINARIES.items():
        def binary(service=service, path=path, version=version):
            image = artifacts[service]['imageId']
            _, container = scan.run('create-' + service, ['docker', 'create', '--entrypoint', path, image])
            container = container.strip()
            target = output / (service + '.binary')
            try:
                scan.run('extract-' + service, ['docker', 'cp', container + ':' + path, str(target)])
            finally:
                scan.run('remove-' + service, ['docker', 'rm', container])
            _, text = scan.run('go-binary-' + service, [govuln, '-mode=binary', '-json', str(target)])
            result = go_policy(text, version)
            result['binarySha256'] = sha(target)
            scan.write(service + '.binary.policy.json', result)
            if result['status'] != 'PASSED':
                raise ValueError('delivered binary has raw Go findings requiring review')
        scan.attempt('binary-' + service, binary)

    def unchanged_databases():
        for directory, expected in database_content.items():
            actual = {p.name: sha(p) for p in (scan.cache / directory).iterdir() if p.is_file()}
            if actual != expected:
                raise ValueError('scanner database content changed during this run')
    scan.attempt('database-content-stability', unchanged_databases)
    exit_code = scan.finish(args.platform)
    print(f'Backend security gate: {"FAILED" if scan.failures else "PASSED"}; evidence {output}')
    return exit_code


if __name__ == '__main__':
    sys.exit(main())
