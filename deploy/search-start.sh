#!/bin/bash
set -euo pipefail
# Ordinary starts must never initialize or rewrite security configuration, even
# on a fresh data directory. Only the explicitly invoked operator can provision it.
exec ./opensearch-docker-entrypoint.sh -Eplugins.security.allow_default_init_securityindex=false "$@"
