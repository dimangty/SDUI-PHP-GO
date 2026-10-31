#!/bin/sh
set -eu
php /app/bin/database-preflight.php >/dev/null
exec docker-php-entrypoint "$@"
