#!/usr/bin/env bash
# Points the DuckDNS name at this server's current public IP address.
# Prints OK when it worked. Cron runs it every 5 minutes, because Google can
# give the server a new address if it is ever stopped and started again.
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"

set -a
. ./.env
set +a

: "${OSRM_DOMAIN:?Set OSRM_DOMAIN in .env}"
: "${DUCKDNS_TOKEN:?Set DUCKDNS_TOKEN in .env}"

# DuckDNS wants the name without .duckdns.org; an empty ip= means "the
# address this request comes from".
curl -fsS --max-time 15 "https://www.duckdns.org/update?domains=${OSRM_DOMAIN%%.duckdns.org}&token=${DUCKDNS_TOKEN}&ip="
echo
