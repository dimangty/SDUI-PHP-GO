#!/usr/bin/env python3
"""Run the real edge -> two Go replicas -> Nginx -> FPM -> PostgreSQL chain.

Requires a migrated disposable database whose name starts with trust_. Uses real
container source IPs; spoof headers are adversarial inputs, never test identity.
Always tears down only its own Compose project. No host ports are published.
"""
import hashlib
import hmac
import base64
import json
import os
from pathlib import Path
import subprocess
import struct
import time
from urllib.parse import urlparse
import uuid

PROJECT = "shopkmp-b7-trust-" + uuid.uuid4().hex[:10]
COMPOSE = ["docker", "compose", "--project-name", PROJECT, "-f", str(Path(__file__).with_name("compose.yml"))]


def run(args, **kwargs):
    result = subprocess.run(args, text=True, capture_output=True, **kwargs)
    if result.returncode:
        print(result.stdout + result.stderr)
        # curl argv may contain a disposable bearer. Never include it in an
        # exception/traceback; retain the exit and provider/transport diagnostics.
        raise RuntimeError(f"subprocess failed (exit {result.returncode})")
    return result.stdout


def compose(*args):
    return run(COMPOSE + list(args))


def remove_project_leftovers(project):
    # Compose can leave Created containers after a partially failed `up`.
    # Match the exact unique project label, never a shared name/network prefix.
    label = "label=com.docker.compose.project=" + project
    containers = run(["docker", "ps", "-aq", "--filter", label]).split()
    if containers:
        run(["docker", "rm", "-f", *containers])
    networks = run(["docker", "network", "ls", "-q", "--filter", label]).split()
    if networks:
        run(["docker", "network", "rm", *networks])


def php(code):
    return compose("exec", "-T", "yii2", "php", "-r", "require '/app/vendor/autoload.php'; " + code)


def reset():
    php("(new Shop\\infrastructure\\Database(getenv('DATABASE_URL')))->execute('DELETE FROM rate_limit_hits');")


def buckets():
    return json.loads(php("print json_encode((new Shop\\infrastructure\\Database(getenv('DATABASE_URL')))->all('SELECT bucket_key,count(*) AS n FROM rate_limit_hits GROUP BY bucket_key ORDER BY bucket_key'));") or "[]")


def curl_args(path, target, headers, body, source):
    args = ["curl", "--silent", "--show-error", "--noproxy", "*", "--max-time", "10", "-i"]
    if source:
        args += ["--interface", source]
    for key, value in (headers or {}).items():
        args += ["-H", key + ": " + value]
    if body is not None:
        args += ["--data-binary", body]
    return args + [target + path]


def response(raw):
    head, _, body = raw.replace("\r\n", "\n").partition("\n\n")
    lines = head.splitlines()
    return int(lines[0].split()[1]), dict(line.lower().split(": ", 1) for line in lines[1:] if ": " in line), body


def request(client, path, *, headers=None, body=None, v6=False, target=None):
    ips = {"client-a": ("10.248.11.10", "fd07:bb7:0:11::10"), "client-b": ("10.248.12.10", "fd07:bb7:0:12::10"), "client-neighbor": ("10.248.11.11", "fd07:bb7:0:11::11"), "untrusted": ("10.247.249.99", None)}
    target = target or ("http://[fd07:bb7::2]:8080" if v6 else "http://10.248.0.2:8080")
    return response(compose("exec", "-T", client, *curl_args(path, target, headers, body, ips[client][int(v6)])))


def expect(status, result):
    assert result[0] == status, ("expected", status, "got", result)
    if status == 429:
        assert result[1].get("cache-control") == "no-store", result
        assert result[1].get("retry-after") == "60", result
        assert json.loads(result[2])["error"]["code"] == "RATE_LIMITED", result
    return result


def fixture():
    return json.loads(php("$db=new Shop\\infrastructure\\Database(getenv('DATABASE_URL')); $id=Shop\\domain\\Ids::uuid(); $db->execute(\"INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Trust Live Buyer','+79991234567')\",[$id,$id.'@trust-live.example']); print json_encode($db->transaction(fn()=>(new Shop\\application\\SessionService($db,new Shop\\repositories\\ShopRepository($db)))->issue($id)));") )


def check_networks(v6=False):
    reset()
    spoof = {"X-Real-IP": "203.0.113.7", "X-Forwarded-For": "203.0.113.8", "Forwarded": "for=203.0.113.9", "X-Shop-Rate-Client-IP": "203.0.113.10", "X-User-Id": str(uuid.uuid4()), "X-Admin": "true"}
    # Catalog makes two sequential upstream reads. Four are allowed per network.
    for client in ["client-a", "client-neighbor"]:
        expect(200, request(client, "/api/v1/screens/catalog", headers=spoof, v6=v6))
    expect(429, request("client-a", "/api/v1/categories", headers=spoof, v6=v6))
    expect(200, request("client-b", "/api/v1/screens/catalog", headers=spoof, v6=v6))
    expect(200, request("client-b", "/api/v1/screens/catalog", headers=spoof, v6=v6))
    expect(429, request("client-b", "/api/v1/screens/catalog", headers=spoof, v6=v6))
    expected = (["read:network-fd07:bb7:0:11::/64", "read:network-fd07:bb7:0:12::/64"] if v6 else ["read:network-10.248.11.0/24", "read:network-10.248.12.0/24"])
    assert buckets() == [{"bucket_key": key, "n": 4} for key in expected], buckets()
    print("PASS real IPv6 /64 networks" if v6 else "PASS real IPv4 /24 networks, direct API + screens, spoofing, Retry-After/no-store")


def check_untrusted():
    for target in ["http://10.247.249.3:8080", "http://10.247.249.4:8080", "http://10.247.249.5:8080"]:
        reset()
        path = "/api/v1/users/me" if target.endswith(".3:8080") else "/api/v1/screens/profile"
        for attempt in range(5):
            headers = {"Authorization": "Bearer app_access_forged", "X-Real-IP": f"198.51.{attempt}.1", "X-Forwarded-For": f"198.52.{attempt}.1", "X-Shop-Rate-Client-IP": f"198.53.{attempt}.1", "X-User-Id": str(uuid.uuid4()), "X-Admin": "true"}
            expect(401 if attempt < 4 else 429, request("untrusted", path, target=target, headers=headers))
        assert buckets() == [{"bucket_key": "read:network-10.247.249.0/24", "n": 4}], buckets()
    print("PASS untrusted same-subnet container cannot assert IP or identity against either BFF or internal Nginx")


def check_auth():
    reset()
    user = fixture()
    headers = {"Authorization": "Bearer " + user["accessToken"]}
    for client in ["client-a", "client-b", "client-a", "client-b"]:
        expect(200, request(client, "/api/v1/users/me", headers=headers))
    expect(429, request("client-a", "/api/v1/users/me", headers=headers))
    # A trusted rate context cannot authorize a protected screen.
    reset()
    forged = {"Authorization": "Bearer app_access_forged", "X-User-Id": user["userId"], "X-Admin": "true"}
    for _ in range(4):
        expect(401, request("client-a", "/api/v1/screens/profile", headers=forged))
    expect(429, request("client-a", "/api/v1/screens/profile", headers=forged))
    for _ in range(4):
        actor = fixture()
        expect(200, request("client-a", "/api/v1/users/me", headers={"Authorization": "Bearer " + actor["accessToken"]}))
    actor = fixture()
    expect(429, request("client-a", "/api/v1/users/me", headers={"Authorization": "Bearer " + actor["accessToken"]}))
    print("PASS per-user ceiling across networks; only independently verified auth earns network multiplier")


def check_webhook():
    reset()
    # Real business services initiate a disposable demo payment; the Nginx/FPM
    # boundary must reach its financial state machine, not just return NOT_FOUND.
    order = json.loads(php("$db=new Shop\\infrastructure\\Database(getenv('DATABASE_URL')); $repo=new Shop\\repositories\\ShopRepository($db); $c=new Shop\\application\\CommerceService($db,$repo); $u=Shop\\domain\\Ids::uuid(); $a=Shop\\domain\\Ids::uuid(); $cat=Shop\\domain\\Ids::uuid(); $p=Shop\\domain\\Ids::uuid(); $db->execute(\"INSERT INTO users(id,email,password_hash,full_name,phone) VALUES(?,?,'','Webhook buyer','+79991234567')\",[$u,$u.'@trust.example']); $db->execute(\"INSERT INTO addresses(id,user_id,city,street,house) VALUES(?,?,'X','Y','1')\",[$a,$u]); $db->execute(\"INSERT INTO categories(id,name) VALUES(?,'Webhook')\",[$cat]); $db->execute(\"INSERT INTO products(id,category_id,name,price,stock) VALUES(?,?,'Webhook','10.23',10)\",[$p,$cat]); $c->addCart($u,['productId'=>$p,'quantity'=>1]); $o=$c->createOrder($u,['deliveryAddressId'=>$a,'paymentMethod'=>'CARD']); (new Shop\\application\\PaymentService($db,$repo,$c))->confirm($u,$o['paymentId']); print json_encode($o);"))
    body = json.dumps({"paymentId": order["paymentId"].upper(), "status": "CAPTURED"}, separators=(",", ":"))
    timestamp, event = str(int(time.time())), str(uuid.uuid4())
    signature = hmac.new(b"trust-live-test-only", (timestamp + ":" + event + ":" + body).encode(), hashlib.sha256).hexdigest()
    headers = {"Content-Type": "application/json", "X-Webhook-Timestamp": timestamp, "X-Webhook-Id": event, "X-Webhook-Signature": signature, "X-Real-IP": "10.248.11.10", "X-Shop-Rate-Client-IP": "10.248.11.10", "X-Forwarded-For": "10.248.11.10"}
    path = "/api/v1/payments/webhook"
    invalid = headers | {"X-Webhook-Signature": "invalid"}
    expect(401, request("client-a", path, headers=invalid, body=body))
    expect(204, request("client-a", path, headers=headers, body=body))
    expect(409, request("client-a", path, headers=headers, body=body))
    for client, target in [("client-b", None), ("untrusted", "http://10.247.249.3:8080")]:
        result = expect(403, request(client, path, target=target, headers=headers, body=body))
        assert json.loads(result[2])["error"]["code"] == "WEBHOOK_SOURCE_DENIED", result
    # Even a transport peer trusted for rate context is NOT a webhook source proxy.
    container = compose("ps", "-q", "sdui").strip()
    raw = run(["docker", "run", "--rm", "--network", "container:" + container, os.getenv("TRUST_PHP_IMAGE", "shopkmp-php-tests"), *curl_args(path, "http://10.247.249.3:8080", headers, body, None)])
    result = expect(403, response(raw))
    assert json.loads(result[2])["error"]["code"] == "WEBHOOK_SOURCE_DENIED", result
    print("PASS webhook source allowlist survives edge spoofing, untrusted container and trusted BFF rate context")


def check_fpm():
    reset()
    # A same-network container can send FastCGI, including a forged REMOTE_ADDR.
    # FPM must reject its actual TCP source before accepting those parameters.
    params = {"SCRIPT_FILENAME": "/app/web/index.php", "SCRIPT_NAME": "/index.php", "REQUEST_METHOD": "GET", "REQUEST_URI": "/api/v1/users/me", "SERVER_PROTOCOL": "HTTP/1.1", "SERVER_NAME": "yii2", "SERVER_PORT": "8080", "REMOTE_ADDR": "10.247.249.4", "HTTP_X_SHOP_RATE_CLIENT_IP": "203.0.113.1", "HTTP_AUTHORIZATION": "Bearer app_access_forged"}
    def record(kind, body):
        return struct.pack("!BBHHBB", 1, kind, 1, len(body), 0, 0) + body
    encoded = b"".join(bytes([len(key), len(value)]) + key.encode() + value.encode() for key, value in params.items())
    payload = record(1, b"\x00\x01" + b"\x00" * 6) + record(4, encoded) + record(4, b"") + record(5, b"")
    code = "$s=stream_socket_client('tcp://yii2:9000',$errno,$error,3); if(!$s){throw new RuntimeException($error);} stream_set_timeout($s,3); @fwrite($s,base64_decode('" + base64.b64encode(payload).decode() + "')); $reply=@stream_get_contents($s); print json_encode(['bytes'=>strlen($reply===false?'':$reply),'timeout'=>stream_get_meta_data($s)['timed_out']]);"
    result = json.loads(compose("exec", "-T", "untrusted", "php", "-r", code))
    assert result == {"bytes": 0, "timeout": False}, result
    assert buckets() == [], buckets()
    # The worker closes the socket before its stderr reaches the master's log.
    deadline = time.monotonic() + 3
    while "Connection disallowed" not in compose("logs", "yii2"):
        if time.monotonic() >= deadline:
            raise AssertionError("FPM denial log not observed after transport rejection")
        time.sleep(.05)
    print("PASS untrusted FastCGI peer cannot forge REMOTE_ADDR or rate context")


def main():
    database = urlparse(os.environ.get("TRUST_DATABASE_URL", "")).path.lstrip("/")
    if not database.startswith("trust_"):
        raise SystemExit("TRUST_DATABASE_URL must identify a migrated disposable trust_ database")
    try:
        print(compose("up", "--build", "-d"), end="")
        deadline = time.monotonic() + 60
        while True:
            try:
                expect(401, request("client-a", "/api/v1/users/me"))
                break
            except (subprocess.CalledProcessError, AssertionError):
                if time.monotonic() >= deadline:
                    raise
                time.sleep(1)
        check_networks()
        check_networks(v6=True)
        check_untrusted()
        check_auth()
        check_webhook()
        check_fpm()
        logs = compose("logs", "edge")
        assert "upstream=10.247.249.4:8080" in logs and "upstream=10.247.249.5:8080" in logs, logs
        print("PASS both exact-address BFF replicas served real edge requests")
    except Exception:
        print(compose("logs", "--no-color", "edge", "yii2-http", "yii2", "sdui", "sdui-2"))
        raise
    finally:
        try:
            print(compose("down", "--volumes", "--remove-orphans"), end="")
        finally:
            remove_project_leftovers(PROJECT)


if __name__ == "__main__":
    main()
