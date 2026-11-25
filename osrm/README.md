# OSRM routing server

The driving distance behind the delivery fee. The API (`server/`) asks this
server for the route from the shop to the customer's pin. It runs OSRM with
OpenStreetMap data of the area around the shop, behind Caddy, which adds
HTTPS and lets in only requests carrying our token.

It runs on a Google Cloud e2-micro (free tier) with a DuckDNS name. The full
walkthrough, from creating the account to connecting the API, is in
`docs/OSRM-Setup.md`.

**Not set up yet (2026-11-26).** Until it is, the API uses OpenRouteService
(the default). Once this server answers, set `ROUTING_DRIVER=osrm`,
`OSRM_ENDPOINT` and `OSRM_TOKEN` on Render and in `server/.env`.

## Files

| File | What it does |
|---|---|
| `setup-server.sh` | One-time setup of a fresh Ubuntu 24.04 server: swap, Docker, osmium. |
| `update-map.sh` | Downloads the Philippines, keeps the area around the shop (roads only), prepares it for OSRM, swaps it in and checks it. Cron runs it monthly. |
| `duckdns.sh` | Points the DuckDNS name at the server's IP. Cron runs it every 5 minutes. |
| `docker-compose.yml` | OSRM (port 5000, only reachable from the server itself) and Caddy (ports 80 and 443). |
| `Caddyfile` | HTTPS, `/health` for the uptime monitor, and `/route/v1/...` for requests carrying the token. |
| `.env.example` | Copy to `.env` and fill in. `.env` is never committed. |

## Everyday commands (on the server, in this folder)

```bash
docker compose ps                      # both running?
docker compose logs --tail 50 osrm     # OSRM's recent messages
bash update-map.sh                     # fresh map now, instead of waiting for the 1st
free -h && docker stats --no-stream    # memory
curl -s https://$(grep ^OSRM_DOMAIN .env | cut -d= -f2)/health   # answers {"code":"Ok",...}
```

## Rules

- The token in `.env` (`OSRM_TOKEN`) must equal `OSRM_TOKEN` on Render and in `server/.env`.
- `AREA_RADIUS_KM` must stay at least 15 km above the delivery limit in Store Settings.
- After changing `STORE_LAT`/`STORE_LNG`, `AREA_RADIUS_KM` or `OSRM_IMAGE`, run `bash update-map.sh`.
