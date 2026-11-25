#!/usr/bin/env bash
# Builds a fresh OSRM map of the delivery area and swaps it in.
# Run from this folder:  bash update-map.sh   (cron runs it once a month)
#
# 1. downloads the Philippines from Geofabrik (OpenStreetMap, updated daily)
# 2. keeps AREA_RADIUS_KM around the shop, and only what OSRM reads
# 3. prepares it for OSRM (extract, partition, customize)
# 4. swaps it in and restarts OSRM; the old map is kept in data/previous
# 5. checks the new map answers, and puts the old one back if it does not
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"

if [ ! -f .env ]; then
  echo "Missing .env. Run: cp .env.example .env  and fill it in."
  exit 1
fi
set -a
. ./.env
set +a

: "${STORE_LAT:?Set STORE_LAT in .env}"
: "${STORE_LNG:?Set STORE_LNG in .env}"
IMAGE="${OSRM_IMAGE:-ghcr.io/project-osrm/osrm-backend:v26.11.0-debian}"
RADIUS="${AREA_RADIUS_KM:-60}"
SOURCE_URL="${MAP_SOURCE_URL:-https://download.geofabrik.de/asia/philippines-latest.osm.pbf}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

answers() {
  curl -fsS --max-time 10 "http://127.0.0.1:5000/nearest/v1/driving/${STORE_LNG},${STORE_LAT}?number=1" 2>/dev/null \
    | grep -q '"code":"Ok"'
}

wait_until_it_answers() {
  for _ in $(seq 1 30); do
    if answers; then return 0; fi
    sleep 2
  done
  return 1
}

mkdir -p data
rm -rf data/next data/download
mkdir -p data/next data/download

log "1/5 Downloading the Philippines map (about 600 MB)"
curl -fL --retry 3 --silent --show-error -A "osrm-map-update" \
  -o data/download/philippines.osm.pbf "$SOURCE_URL"

log "2/5 Keeping ${RADIUS} km around the shop, roads only"
BBOX=$(awk -v lat="$STORE_LAT" -v lng="$STORE_LNG" -v r="$RADIUS" 'BEGIN {
  pi = atan2(0, -1); dlat = r / 111.32; dlng = r / (111.32 * cos(lat * pi / 180));
  printf "%.4f,%.4f,%.4f,%.4f", lng - dlng, lat - dlat, lng + dlng, lat + dlat }')
osmium extract --overwrite --bbox "$BBOX" \
  -o data/download/area.osm.pbf data/download/philippines.osm.pbf
# Roads, ferries and turn restrictions are all OSRM reads. Dropping the rest
# (mostly buildings) is what lets this run on a 1 GB server.
osmium tags-filter --overwrite -o data/next/area.osm.pbf data/download/area.osm.pbf \
  w/highway w/route=ferry r/type=restriction
rm -rf data/download

log "3/5 Preparing the map for OSRM (the longest step)"
docker run --rm -v "$PWD/data/next:/data" "$IMAGE" osrm-extract -p /opt/car.lua /data/area.osm.pbf
docker run --rm -v "$PWD/data/next:/data" "$IMAGE" osrm-partition /data/area.osrm
docker run --rm -v "$PWD/data/next:/data" "$IMAGE" osrm-customize /data/area.osrm
rm -f data/next/area.osm.pbf

log "4/5 Swapping in the new map"
rm -rf data/previous
if [ -d data/current ]; then
  mv data/current data/previous
fi
mv data/next data/current
docker compose up -d --force-recreate osrm
# Starts Caddy the first time; afterwards this leaves it running as it is.
docker compose up -d caddy

log "5/5 Checking the new map answers"
if wait_until_it_answers; then
  log "Done. OSRM is answering with the new map."
  exit 0
fi

log "The new map does not answer."
if [ -d data/previous ]; then
  log "Putting the previous map back."
  rm -rf data/failed
  mv data/current data/failed
  mv data/previous data/current
  docker compose up -d --force-recreate osrm
  wait_until_it_answers && log "The previous map is answering again." || log "The previous map does not answer either."
fi
log "See: docker compose logs --tail 50 osrm"
exit 1
