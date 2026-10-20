#!/bin/sh
set -eu
install -d -o postgres -g postgres -m 0700 /var/lib/postgresql-tls
install -o postgres -g postgres -m 0644 /run/tls/server.crt /var/lib/postgresql-tls/server.crt
install -o postgres -g postgres -m 0600 /run/tls/server.key /var/lib/postgresql-tls/server.key
exec docker-entrypoint.sh postgres -c ssl=on -c ssl_min_protocol_version=TLSv1.2 -c ssl_cert_file=/var/lib/postgresql-tls/server.crt -c ssl_key_file=/var/lib/postgresql-tls/server.key -c hba_file=/run/tls/pg_hba.conf
