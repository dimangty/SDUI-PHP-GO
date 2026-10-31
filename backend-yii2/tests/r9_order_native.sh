#!/usr/bin/env bash
set -euo pipefail
root=$(git -C "$(dirname "$0")/../.." rev-parse --show-toplevel)
mounts=()
test_file=/app/tests/r9_order_summary.php
if [[ "${1:-}" == --legacy-reads ]]; then
  test_file=/app/tests/round3_reads.php
  shift
fi
for path in repositories/OrderReadRepository.php repositories/OrderDtoMapper.php application/CommerceService.php tests/r9_order_summary.php; do
  mounts+=(--mount "type=bind,src=$root/backend-yii2/$path,dst=/app/$path,readonly")
done
# Password stays in the inherited container environment, never expanded in argv.
docker run --rm --network shopkmp-round5-e2e_data --env-file "$root/.env.example" \
  "${mounts[@]}" shopkmp-round5-baseline-php sh -c \
  'export DATABASE_URL="postgresql://shopkmp:${POSTGRES_PASSWORD}@postgres/shopkmp"; exec php "$@"' sh "$test_file" "$@"
