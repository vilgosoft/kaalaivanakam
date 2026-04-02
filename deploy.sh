#!/usr/bin/env bash
set -euo pipefail

#
# Build & package Kaalaivanakam for Hostinger shared hosting.
#
# Usage:  ./deploy.sh
# Output: dist/kaalaivanakam-deploy.zip  (upload to public_html via Hostinger File Manager)
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST="$SCRIPT_DIR/dist"
STAGE="$DIST/public_html"

echo "==> Cleaning previous build…"
rm -rf "$DIST"
mkdir -p "$STAGE"

# ── 1. Copy frontend (prebuilt bundle) ───────────────────────────────
echo "==> Copying frontend assets…"
cd "$SCRIPT_DIR/frontend"
cp index.html "$STAGE/"
cp -r public/* "$STAGE/"

# ── 2. Copy API ──────────────────────────────────────────────────────
echo "==> Copying API…"
mkdir -p "$STAGE/api"

# Copy PHP source files
cp "$SCRIPT_DIR/api/bootstrap.php"  "$STAGE/api/"
cp "$SCRIPT_DIR/api/composer.json"  "$STAGE/api/"
cp "$SCRIPT_DIR/api/composer.lock"  "$STAGE/api/"
cp "$SCRIPT_DIR/api/index.php"      "$STAGE/api/"
cp -r "$SCRIPT_DIR/api/config"      "$STAGE/api/"
cp -r "$SCRIPT_DIR/api/src"         "$STAGE/api/"
cp -r "$SCRIPT_DIR/api/public"      "$STAGE/api/"

# Copy API .htaccess (blocks .env access)
cp "$SCRIPT_DIR/api/.htaccess"      "$STAGE/api/"

# Install composer dependencies (production only)
echo "==> Installing Composer dependencies…"
cd "$STAGE/api"
if command -v composer &>/dev/null; then
  composer install --no-dev --optimize-autoloader --no-interaction --quiet
else
  echo "    WARNING: composer not found. You must run 'composer install --no-dev' inside api/ on the server."
  # Copy existing vendor if available
  if [ -d "$SCRIPT_DIR/api/vendor" ]; then
    echo "    Copying existing vendor/ directory…"
    cp -r "$SCRIPT_DIR/api/vendor" "$STAGE/api/"
  fi
fi

# ── 3. Copy root .htaccess ───────────────────────────────────────────
echo "==> Copying .htaccess…"
cp "$SCRIPT_DIR/.htaccess" "$STAGE/"

# ── 4. Copy .env.example as reference ────────────────────────────────
cp "$SCRIPT_DIR/api/.env.example" "$STAGE/api/.env.example"

# ── 5. Create ZIP ────────────────────────────────────────────────────
echo "==> Creating deployment archive…"
cd "$DIST"
zip -r kaalaivanakam-deploy.zip public_html/ -q

echo ""
echo "✅ Build complete!"
echo "   Archive: dist/kaalaivanakam-deploy.zip"
echo ""
echo "Next steps:"
echo "  1. Upload & extract the ZIP in Hostinger File Manager"
echo "  2. Copy the contents of public_html/ into your actual public_html/"
echo "  3. Create api/.env from api/.env.example with your DB credentials"
echo "  4. Verify: https://www.kaalaivanakam.in/v1/health"
