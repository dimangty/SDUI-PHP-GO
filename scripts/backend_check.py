#!/usr/bin/env python3
"""Record a backend check's argv, timestamps, exit and output without shell rewriting.

Arguments/output must contain no credentials. Evidence is deliberately outside git.
"""
import argparse
import datetime
import json
import os
from pathlib import Path
import subprocess
import sys


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--evidence', type=Path, required=True)
    parser.add_argument('--label', required=True)
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command
    if command[:1] == ['--']:
        command = command[1:]
    if not command or not args.label.replace('-', '').replace('_', '').isalnum():
        parser.error('a safe label and command are required')
    args.evidence.mkdir(parents=True, exist_ok=True)
    result = {'command': command, 'cwd': os.getcwd(), 'started': datetime.datetime.now(datetime.timezone.utc).isoformat()}
    with (args.evidence / (args.label + '.log')).open('w') as log:
        try:
            code = subprocess.run(command, stdout=log, stderr=subprocess.STDOUT).returncode
        except OSError as error:
            log.write(str(error) + '\n')
            code = 127
    result.update(exitCode=code, status='PASSED' if code == 0 else 'FAILED', finished=datetime.datetime.now(datetime.timezone.utc).isoformat())
    (args.evidence / (args.label + '.json')).write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result), flush=True)
    if code:
        print((args.evidence / (args.label + '.log')).read_text(), end='')
    return code


if __name__ == '__main__':
    sys.exit(main())
