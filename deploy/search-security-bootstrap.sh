#!/bin/bash
set -euo pipefail
# Fresh security-index initialization ONLY. Operator mTLS is independent of all
# internal passwords. Do not use -nhnv, -arc or an index deletion/reinitialization.
status=$(curl --silent --show-error --head --max-time 10 --cacert /run/tls/ca.crt \
  --cert /run/tls/search-admin.crt --key /run/tls/search-admin.key \
  --output /dev/null --write-out '%{http_code}' \
  https://opensearch:9200/.opendistro_security)
if [ "$status" = 200 ]; then
  printf '%s\n' 'Security index exists: no bootstrap writes; use explicit upgrade/check.'
  exit 0
fi
if [ "$status" != 404 ]; then
  printf '%s\n' 'Fresh security bootstrap refused: inspect TLS/operator access.' >&2
  exit 1
fi
export JAVA_OPTS='-Xms64m -Xmx256m'
plugins/opensearch-security/tools/securityadmin.sh -cd /opt/search-security/ -icl \
  -h opensearch -p 9200 -cacert /run/tls/ca.crt \
  -cert /run/tls/search-admin.crt -key /run/tls/search-admin.key
# Wait for the REST security configuration to become visible after the transport
# acknowledgements. This polls only; no retry of a configuration write.
for attempt in {1..30}; do
  status=$(curl --silent --show-error --max-time 3 --cacert /run/tls/ca.crt \
    --cert /run/tls/search-admin.crt --key /run/tls/search-admin.key \
    --output /dev/null --write-out '%{http_code}' https://opensearch:9200/_plugins/_security/api/roles)
  if [ "$status" = 200 ]; then exit 0; fi
  sleep 1
done
printf '%s\n' 'Security bootstrap written but REST verification unavailable; inspect before any recovery.' >&2
exit 1
