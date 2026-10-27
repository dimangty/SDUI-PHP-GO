# B7: trusted original client and rate accounting

Baseline: `01d84b3`. Implementation and RED/GREEN evidence: [TRUST_TEST_RESULTS.md](TRUST_TEST_RESULTS.md).

## Trust design

Use **exact transport-peer allowlists, with explicitly allocated addresses for each BFF replica**. This is the smallest design supported by the existing deployment. It requires the coordinator integration below. There is no shared-subnet trust and no header that can authenticate a user or grant an admin role.

The alternatives were a signed original-client envelope or mTLS. Those become appropriate for an orchestrator that cannot allocate/enforce exact replica addresses. They add key/certificate rotation and verification machinery that this fixed-address Compose deployment does not need. Do not replace the explicit allowlist with a Docker CIDR when scaling.

### Request chain

1. **Client → edge:** `proxy_pass_request_headers off` reconstructs the existing finite header set. `X-Real-IP` is overwritten with the actual socket source. Caller `Forwarded`, `X-Forwarded-For`, `X-Shop-Rate-Client-IP`, cookie and asserted user/role headers do not become trusted context.
2. **Edge → Go:** `TRUSTED_EDGE_IPS` is a comma-separated list of literal IPv4/IPv6 addresses. Empty means no trusted edge. CIDRs (including `/32` and `/128`), hostnames, zones, unspecified/multicast addresses and partially invalid lists fail startup. IPv4-mapped addresses are canonicalized. Only an exact matching socket peer may supply one valid `X-Real-IP`; malformed/missing/duplicate claims from that peer produce 400 before business calls. Any other peer's forwarding headers are ignored and its actual socket IP is used.
3. **Go → internal Nginx:** request-scoped `yii.Identity.ClientIP` is a typed `netip.Addr`. The HTTP adapter emits one canonical `X-Real-IP` on each business call, including authentication, parallel/serial reads, retries and mutations. Existing bearer/idempotency/correlation behavior remains independent. No request header map is copied.
4. **Internal Nginx → PHP:** only the existing exact `EDGE_IP` is permitted to rewrite `REMOTE_ADDR`. BFFs are deliberately **not** added to `set_real_ip_from`. A finite FastCGI header set constructs `X-Shop-Rate-Client-IP` from the BFF's `X-Real-IP`; any caller-supplied version is discarded. `Network::client` accepts that context only when `REMOTE_ADDR` is in PHP's exact `TRUSTED_BFF_IPS` list. Empty means forwarding disabled; malformed lists fail closed with an application error. Legacy `TRUSTED_PROXY_CIDRS` and raw forwarding headers no longer enable PHP forwarding.
5. **FastCGI transport boundary:** FPM accepts only the internal Nginx's exact dedicated-network address and its local health probe. An untrusted container must not be able to submit arbitrary FastCGI parameters such as `REMOTE_ADDR`. The isolated live harness verifies an actual forged FastCGI request is refused before PHP executes.

Exact peer trust assumes deployment-controlled network membership/IP allocation and no access to the Docker daemon from application containers. Keep the ingress/FPM networks limited to their named participants and drop `NET_RAW` from these services. The negative harness deliberately adds an ordinary untrusted peer on ingress and the database network and proves forwarding headers and raw FastCGI cannot cross these boundaries.

### Webhook source semantics

`/api/v1/payments/webhook` receives **no BFF rate context**: the internal Nginx map explicitly clears it for that exact normalized path. Its source remains the edge-resolved client for direct requests, or the actual BFF/untrusted peer for internal requests. The existing source CIDR allowlist, HMAC over original body bytes, timestamp and event ID checks remain in force. A BFF peer trusted for rate context cannot use that trust to pass the webhook source allowlist. `Network::contains`, `allowed` and the webhook policy were not broadened.

### Rate policy

PostgreSQL remains the shared limiter across replicas. IPv4 `/24`, IPv6 `/64` and mapped IPv4 canonicalization are unchanged. Different external networks have independent buckets; changing BFF replicas or addresses within the same client network does not reset a bucket. A user keeps the same user bucket across external networks. Only an independently authenticated user earns `AUTHENTICATED_NETWORK_RATE_LIMIT_MULTIPLIER`; an invalid bearer or asserted identity header does not. Each canonical upstream HTTP call consumes its normal policy budget; a composed catalog uses two reads. 429 retains the canonical error envelope, `Retry-After`, and `Cache-Control: no-store` through Go.

## Required coordinator integration

These are exact required changes to shared/coordinator-owned files. This task does not edit those files. **Application changes alone are not a deployable trust boundary until this wiring is applied.**

### 1. `docker-compose.yml`: peer configuration and deterministic egress

Replace the obsolete `TRUSTED_PROXY_CIDRS` entry in `x-yii-env` with:

```yaml
  TRUSTED_BFF_IPS: ${TRUSTED_BFF_IPS:?comma-separated exact BFF ingress IPs required}
```

Add to `services.sdui.environment`, and replace its network map with **ingress only**, preserving the existing allocated address:

```yaml
    environment:
      YII_ORIGIN: http://yii2-http:8080
      TRUSTED_EDGE_IPS: ${EDGE_IP:-172.30.95.2}
    networks:
      ingress:
        ipv4_address: ${SDUI_IP:-172.30.95.4}
```

Removing `application` from this service is necessary: otherwise Docker DNS/routing may send BFF calls over a dynamically addressed second interface, which cannot match the explicit BFF allowlist. Go needs only the HTTP origin on ingress.

### 2. FPM admission and dedicated Nginx/FPM network

Add the following line to `[www]` in `deploy/php/fpm.conf` (already mounted by `yii2`):

```ini
listen.allowed_clients = ${FPM_TRUSTED_NGINX_IPS}
```

Add the following environment and networks to `services.yii2` (preserving its other environment/volumes):

```yaml
    environment:
      <<: *yii-env
      FPM_TRUSTED_NGINX_IPS: "${YII_FPM_HTTP_IP:-172.30.94.3},127.0.0.1"
    networks:
      application:
      data:
      fpm:
```

Replace the `yii2-http` network map with:

```yaml
    networks:
      ingress:
        ipv4_address: ${YII_HTTP_IP:-172.30.95.3}
      fpm:
        ipv4_address: ${YII_FPM_HTTP_IP:-172.30.94.3}
```

Add the network:

```yaml
  fpm:
    internal: true
    ipam:
      config: [{subnet: '${YII_FPM_SUBNET:-172.30.94.0/24}'}]
```

FPM remains reachable for its outgoing application/data dependencies, but only Nginx's exact FPM-network address may submit FastCGI. `127.0.0.1` preserves `deploy/php/fpm-health.php`. Pin the Nginx peer, not an entire subnet. Add `cap_drop: [NET_RAW]` to `edge`, `sdui`, `yii2-http`, and `yii2`; keep the existing no-new-privileges policy and do not publish FPM/BFF/internal Nginx ports.

### 3. Environment examples and production overlay

Add to `.env.example` and `.env.prod.example`, aligned with any existing IP/subnet overrides:

```dotenv
# Exact addresses only; list every allocated BFF replica, comma-separated.
TRUSTED_BFF_IPS=172.30.95.4
YII_FPM_SUBNET=172.30.94.0/24
YII_FPM_HTTP_IP=172.30.94.3
```

Keep `EDGE_IP` a single literal IP and keep `yii2-http.environment.NGINX_ENVSUBST_FILTER: EDGE_IP`. That filter preserves Nginx's own `$uri`, `$remote_addr` and `$shop_rate_client_ip` variables when rendering the template.

`docker-compose.prod.yml` must retain the merged `TRUSTED_BFF_IPS`, `FPM_TRUSTED_NGINX_IPS`, Go `TRUSTED_EDGE_IPS`, FPM admission-config mount, and the base network maps. If its environment/volume overrides replace any of these, include them explicitly. Retain the existing trusted-CA TLS configuration. No ApiKernel change is required for this design.

For native IPv6 listeners, add these alongside the matching existing IPv4 listeners in coordinator-owned edge configs:

```nginx
# edge.dev.conf public server
listen [::]:8080;
# edge.prod.conf.template: both reject-default and named TLS servers
listen [::]:8443 ssl;
```

If an external load balancer terminates IPv6 first, its edge source-trust design must be explicit; adding arbitrary upstream forwarding trust is outside this fixed single-edge chain. Both direct API and SDUI contexts support canonical IPv6 clients, as exercised by the dual-stack live harness.

### 4. Two replicas

Use one allocated IP per replica and add **every** address to `TRUSTED_BFF_IPS`, for example `172.30.95.4,172.30.95.5`. A second service can use the same build/image/environment/health settings as `sdui`, with:

```yaml
    networks:
      ingress:
        ipv4_address: 172.30.95.5
        aliases: [sdui]
```

The first replica keeps the service name `sdui`, so edge DNS sees both endpoints. Static IPs require individually allocated replica services; `--scale sdui=2` cannot duplicate one static address. Before moving/removing an address, update the allowlist and network allocation together. The dedicated Compose harness implements and checks this two-replica arrangement, including both actual upstream addresses in edge logs. For dynamic replica IPs, adopt authenticated per-hop context/mTLS rather than relaxing this allowlist.

### 5. Test runner / Makefile

Add `trusted_clients.php` and `trusted_rate_limits.php` after `throttling.php` in the shared `backend-yii2/tests/all.php` runner. Suggested separate Makefile target (with `TRUST_DATABASE_URL` pointing only to a migrated disposable `trust_` database):

```makefile
.PHONY: test-trust-live
test-trust-live:
	python3 backend-yii2/tests/trust-live/run.py
```

Run `make compose-config`, production Compose config validation, the existing PHP suites, and the live harness after integration. Avoid a host `/app/vendor` bind mount over the PHP 8.3 image: this checkout's host vendor platform check currently requires 8.5.11. Source-only mounts or a freshly built project image use its correct locked dependencies.

## Live harness

`backend-yii2/tests/trust-live/run.py` builds the existing Go Dockerfile, starts a fresh Compose project with two BFF replicas, production Nginx route/proxy/FastCGI files, PHP-FPM, real dual-stack clients, and an untrusted container. It attaches only PHP and the attacker to the named disposable database network. It creates no host ports or database volume, deletes rate hits only in the explicitly named `trust_` database, and tears down its own containers/networks in `finally`.

```sh
export PATH=/Applications/Docker.app/Contents/Resources/bin:$PATH
export TRUST_DATABASE_URL='postgres://backend_fixes:disposable_backend_fixes_only@shopkmp-backend-fixes-pg:5432/trust_fixes?sslmode=disable'
export TRUST_DB_NETWORK=shopkmp-backend-fixes-20261026
export TRUST_PHP_IMAGE=shopkmp-php-tests
python3 backend-yii2/tests/trust-live/run.py
```

The database must already be migrated. The supplied session baseline was cloned with:

```sh
docker exec shopkmp-backend-fixes-pg createdb -U backend_fixes -T backend_fixes trust_fixes
```

The harness reserves test-only subnets `10.246.249.0/24`, `10.247.249.0/24`, `10.248.0.0/16`, and `fd07:bb7::/48`. An overlap is a test setup error; it must not lead to relaxing peer trust. `TRUST_PHP_IMAGE` can name the coordinator's rebuilt current PHP image; the harness bind-mounts the current `Network.php` and its test-only FPM admission config, leaving vendor dependencies inside the image.
