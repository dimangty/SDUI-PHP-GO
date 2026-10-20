#!/bin/bash
set -euo pipefail
# Fresh security-index bootstrap only. No demo users/certificates. Existing security
# indices require the operator's normal password rotation procedure.
hash=$(plugins/opensearch-security/tools/hash.sh -p "$OPENSEARCH_ADMIN_PASSWORD" | /usr/bin/tail -n 1)
test -n "$hash"
printf '_meta:\n  type: internalusers\n  config_version: 2\nadmin:\n  hash: "%s"\n  reserved: true\n  backend_roles: [admin]\n' "$hash" > config/opensearch-security/internal_users.yml
exec ./opensearch-docker-entrypoint.sh
