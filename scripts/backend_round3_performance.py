#!/usr/bin/env python3
"""Opt-in same-data original/current PHP measurement on the disposable PG stack.

Secrets travel only through captured inspect output / child stdin environment.
The only database this runner creates or drops is round3_perf_<random hex>.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import platform
import secrets
import subprocess
import sys
from urllib.parse import urlsplit, urlunsplit

PROJECT = "shopkmp-round3-final-e2e"
ROOT = Path(__file__).resolve().parents[1]


def command(argv, *, data=None, env=None):
    result = subprocess.run(argv, input=data, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, env=env, cwd=ROOT)
    if result.returncode:
        # Never reproduce argv/env/output that could include database credentials.
        raise RuntimeError(f"{argv[0]} command failed, exit={result.returncode}; sensitive output withheld")
    return result.stdout


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--evidence", type=Path, required=True)
    args = parser.parse_args()
    if os.environ.get("ROUND3_DISPOSABLE") != PROJECT:
        parser.error(f"explicit ROUND3_DISPOSABLE={PROJECT} required")
    evidence = args.evidence.resolve()
    evidence.mkdir(parents=True, exist_ok=True)
    dbname = "round3_perf_" + secrets.token_hex(8)
    created = False
    lifecycle = {"project": PROJECT, "database": dbname,
                 "started": datetime.datetime.now(datetime.timezone.utc).isoformat(), "commands": []}
    failures = []
    php_container = PROJECT + "-yii2-1"
    pg_container = PROJECT + "-postgres-1"
    try:
        lifecycle["hostPlatform"] = platform.platform()
        lifecycle["dockerHardware"] = json.loads(command([
            "docker", "info", "--format",
            '{"cpus":{{.NCPU}},"memoryBytes":{{.MemTotal}},"architecture":"{{.Architecture}}","kernelVersion":"{{.KernelVersion}}","operatingSystem":"{{.OperatingSystem}}","serverVersion":"{{.ServerVersion}}"'
            + '}',
        ]))
        config = json.loads(command(["docker", "inspect", php_container]))[0]
        pg_config = json.loads(command(["docker", "inspect", pg_container]))[0]
        for container in (config, pg_config):
            if container["Config"]["Labels"].get("com.docker.compose.project") != PROJECT:
                raise RuntimeError("container project identity mismatch")
        private_env = dict(e.split("=", 1) for e in config["Config"]["Env"] if "=" in e)
        source_db = urlsplit(private_env["DATABASE_URL"])
        if source_db.hostname != "postgres" or source_db.path != "/shopkmp":
            raise RuntimeError("unexpected source project database")
        database_url = urlunsplit(source_db._replace(path="/" + dbname))
        child_env = os.environ.copy()
        child_env.update(DATABASE_URL=database_url, ROUND3_DISPOSABLE=PROJECT)
        pg_user = dict(e.split("=", 1) for e in pg_config["Config"]["Env"] if "=" in e)["POSTGRES_USER"]

        def psql(sql):
            return command(["docker", "exec", "-i", pg_container, "psql", "-X", "-v", "ON_ERROR_STOP=1", "-U", pg_user, "-d", "postgres"], data=sql.encode())

        psql(f'CREATE DATABASE "{dbname}";')
        created = True
        lifecycle["commands"].append({"operation": "create isolated database", "exitCode": 0})
        network = PROJECT + "_data"
        # Existing checksum-validating migration tool, not hand-copied SQL/schema.
        migrate = ["docker", "run", "--rm", "--network", network, "-e", "DATABASE_URL", PROJECT + "-migrate"]
        command(migrate, env=child_env)
        lifecycle["commands"].append({"argv": migrate, "exitCode": 0})
        base_image = "shopkmp-round3-php"
        current_image = PROJECT + "-yii2"
        common = ["docker", "run", "--rm", "--network", network, "-e", "DATABASE_URL", "-e", "ROUND3_DISPOSABLE"]
        current_mounts = ["-v", f"{ROOT / 'backend-yii2'}:/app:ro", "-v", "/app/vendor", "-v", "/app/runtime"]
        yii_migrate = common + current_mounts + [current_image, "php", "yii", "migrate", "--interactive=0"]
        command(yii_migrate, env=child_env)
        lifecycle["commands"].append({"argv": yii_migrate, "exitCode": 0})
        fixture = ROOT / "backend-yii2/tests/round3_performance.php"
        seed = common + current_mounts + [current_image, "php", "/app/tests/round3_performance.php", "seed"]
        seeded = json.loads(command(seed, env=child_env))
        lifecycle["commands"].append({"argv": seed, "exitCode": 0, "fixture": seeded})
        for image in (base_image, current_image, PROJECT + "-postgres", PROJECT + "-migrate"):
            image_data = json.loads(command(["docker", "image", "inspect", image]))[0]
            lifecycle.setdefault("images", {})[image] = image_data["Id"]
        lifecycle["sourceSHA256"] = {
            str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest()
            for sub in ("application", "repositories", "domain", "infrastructure")
            for p in sorted((ROOT / "backend-yii2" / sub).glob("*.php"))
        }
        collected = {}
        for n in (0, 1, 20, 100):
            # Alternate order to reduce systematic ordering bias; shared warm caches remain a limitation.
            variants = ("baseline", "current") if n in (0, 20) else ("current", "baseline")
            for variant in variants:
                image = base_image if variant == "baseline" else current_image
                mounts = ["-v", f"{fixture}:/app/tests/round3_performance.php:ro"] if variant == "baseline" else current_mounts
                argv = common + mounts + [image, "php", "/app/tests/round3_performance.php", "measure", str(n), variant]
                try:
                    payload = json.loads(command(argv, env=child_env))
                    # Persist full public outputs once, not on each of the 30 iterations.
                    outputs = payload.pop("outputs")
                    plans = payload.pop("plans")
                    write_json(evidence / f"perf-{variant}-{n}-outputs.json", outputs)
                    write_json(evidence / f"perf-{variant}-{n}-plans.json", plans)
                    write_json(evidence / f"perf-{variant}-{n}-samples.json", payload)
                    collected[(variant, n)] = (payload, outputs)
                    lifecycle["commands"].append({"argv": argv, "exitCode": 0})
                    for name, metrics in payload["results"].items():
                        print(f"{variant:8s} n={n:3d} {name:13s} executes={metrics['pdoExecutionCounts']} "
                              f"wall_p50_ms={metrics['wallP50MS']:.3f} wall_p95_ms={metrics['wallP95MS']:.3f} "
                              f"pdo_p50_ms={metrics['pdoExecuteP50MS']:.3f} pdo_p95_ms={metrics['pdoExecuteP95MS']:.3f} "
                              f"samples={metrics['measuredIterations']} service_peak_delta_bytes={metrics['servicePeakDeltaMaxBytes']}", flush=True)
                except Exception as error:
                    failures.append(f"{variant}/{n}: {error}")
                    lifecycle["commands"].append({"argv": argv, "exitCode": 1})
            if all((v, n) in collected for v in ("baseline", "current")):
                before = collected[("baseline", n)][1]
                after = collected[("current", n)][1]
                for operation in ("catalog-full", "orders", "popular-full"):
                    equal = before[operation] == after[operation]
                    lifecycle.setdefault("publicOutputEquality", []).append({"n": n, "operation": operation, "equal": equal})
                    print(f"same_data_full_output n={n} operation={operation} equal={equal}", flush=True)
                    if not equal:
                        failures.append(f"full public output differs: {operation}/{n}")
        lifecycle["failures"] = failures
        write_json(evidence / "perf-summary.json", [
            {"variant": variant, "n": n, "php": payload["php"], "postgres": payload["postgres"],
             "results": {name: {key: value for key, value in metrics.items() if key != "samples"}
                         for name, metrics in payload["results"].items()}}
            for (variant, n), (payload, _) in collected.items()
        ])
    except Exception as error:
        failures.append(str(error))
        lifecycle["failures"] = failures
    finally:
        if created:
            try:
                # Identifiers are generated above, never supplied by the caller; main shopkmp is never dropped.
                command(["docker", "exec", "-i", pg_container, "psql", "-X", "-v", "ON_ERROR_STOP=1", "-U", pg_user, "-d", "postgres"], data=f'DROP DATABASE "{dbname}";'.encode())
                lifecycle["cleanupOK"] = True
            except Exception as error:
                failures.append(f"isolated database cleanup failed: {error}")
                lifecycle["cleanupOK"] = False
        lifecycle["failures"] = failures
        lifecycle["finished"] = datetime.datetime.now(datetime.timezone.utc).isoformat()
        write_json(evidence / "perf-lifecycle.json", lifecycle)
    for failure in failures:
        print("FAIL " + failure, flush=True)
    print(f"isolated_database_cleanup={lifecycle.get('cleanupOK')} failures={len(failures)}", flush=True)
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
