#!/usr/bin/env bash
# One-time preparation of a fresh Ubuntu 24.04 server for OSRM.
# Run from this folder:  sudo bash setup-server.sh
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run it with sudo:  sudo bash setup-server.sh"
  exit 1
fi

# 1. Swap. The e2-micro has 1 GB of memory; preparing the map needs a bit
#    more for a few minutes each month.
if ! swapon --show=NAME --noheadings | grep -qx '/swapfile'; then
  echo "Adding 2 GB of swap..."
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# 2. Docker (runs OSRM and Caddy), osmium (cuts the map to our area), curl.
echo "Installing Docker, osmium and curl..."
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io docker-compose-v2 osmium-tool curl ca-certificates
systemctl enable --now docker

# 3. Let your own user run docker without sudo (from the next login).
if [ -n "${SUDO_USER:-}" ]; then
  usermod -aG docker "$SUDO_USER"
fi

echo
echo "Done. Close this SSH window and open a new one before the next step,"
echo "so your user can use Docker."
