#!/usr/bin/env sh
set -eu

ROOT_DIR="${ROOT_DIR:-/var/www/treabo-ru}"
API_DIR="$ROOT_DIR/pixer-api"
SHOP_DIR="$ROOT_DIR/shop"
COMPOSE_FILE="$API_DIR/deploy/treabo-compose.yml"

echo "==> Pull shop"
cd "$SHOP_DIR"
git pull --ff-only

echo "==> Install deps and build Next.js in reusable node container"
cd "$API_DIR"
docker-compose -f "$COMPOSE_FILE" run --rm --no-deps shop sh -lc \
  "apk add --no-cache libc6-compat python3 make g++ >/dev/null \
  && NODE_ENV=development npm install --include=dev --legacy-peer-deps --ignore-scripts --no-audit --no-fund \
  && NODE_ENV=development npm run build \
  && test -f .next/BUILD_ID"

echo "==> Restart shop only"
docker-compose -f "$COMPOSE_FILE" up -d --no-deps shop

echo "==> Check shop"
docker ps | grep deploy_shop || true
curl -sS -I http://127.0.0.1:${TREABO_SHOP_HOST_PORT:-3010} | head -8 || true
