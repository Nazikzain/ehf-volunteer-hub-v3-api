#!/usr/bin/env bash
#
# Builds a deployment-ready release of the API (decision H1: dependencies are built here or in
# CI and shipped with the release; the server needs no Composer).
#
#   bin/build-release.sh            # version from git (tag or commit)
#   RELEASE_VERSION=1.0.0 bin/build-release.sh
#
# Output in dist/:
#   ehf-v3-api-<version>.zip         the release (one top-level folder)
#   ehf-v3-api-<version>.zip.sha256  checksum of the zip
# Inside the release folder:
#   CHECKSUMS.sha256                 checksum of every file, for verifying an FTP upload
#   RELEASE                          version, commit, build time, PHP and lock-file details
#
# The release contains production dependencies only: no dev tools, no tests, no .env, no secrets.
# Nothing is uploaded or deployed by this script.
#
# COMPOSER_INSTALL_FLAGS adds flags to the production install (for example --prefer-source
# where GitHub archive downloads are unavailable).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="${RELEASE_VERSION:-$(git describe --tags --always --dirty 2>/dev/null || echo dev)}"
if [[ ! "$VERSION" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "Invalid release version: $VERSION" >&2
    exit 1
fi
COMMIT="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
NAME="ehf-v3-api-${VERSION}"
BUILD_DIR="$ROOT/build"
STAGE="$BUILD_DIR/$NAME"
DIST="$ROOT/dist"

echo "==> Building $NAME"
rm -rf "${BUILD_DIR:?}"
mkdir -p "$STAGE" "$DIST"
rm -f "$DIST/$NAME.zip" "$DIST/$NAME.zip.sha256"

# 1. Application files (an explicit allowlist: anything not listed is not shipped).
cp -R composer.json composer.lock public src config "$STAGE/"
mkdir -p "$STAGE/storage/logs" "$STAGE/docs"
cp storage/.htaccess "$STAGE/storage/.htaccess"
cp storage/logs/.gitignore "$STAGE/storage/logs/.gitignore"
cp .env.example README.md "$STAGE/"
cp docs/deployment.md docs/hosting-compatibility-checklist.md "$STAGE/docs/"
cp deploy/root.htaccess "$STAGE/.htaccess"
rm -f "$STAGE/src/Authorisation/README.md" "$STAGE/src/Repositories/README.md"

# 2. Production dependencies only, with an authoritative class map.
echo "==> Installing production dependencies"
# shellcheck disable=SC2086
(cd "$STAGE" && composer install --no-dev --no-interaction --no-progress --no-scripts \
    --classmap-authoritative ${COMPOSER_INSTALL_FLAGS:-})
find "$STAGE/vendor" -type d -name .git -prune -exec rm -rf {} +

# 3. Verify the package.
echo "==> Verifying the package"
fail() { echo "Package check failed: $*" >&2; exit 1; }
[[ -f "$STAGE/vendor/autoload.php" ]] || fail "vendor/autoload.php missing"
for pkg in slim/slim slim/psr7 php-di/php-di monolog/monolog vlucas/phpdotenv; do
    [[ -d "$STAGE/vendor/$pkg" ]] || fail "runtime dependency $pkg missing"
done
for pkg in phpunit phpstan friendsofphp opis symfony/yaml; do
    [[ ! -e "$STAGE/vendor/$pkg" ]] || fail "development dependency $pkg must not be shipped"
done
for forbidden in .env tests .git .github phpunit.xml.dist phpstan.neon.dist .php-cs-fixer.dist.php build dist var; do
    [[ ! -e "$STAGE/$forbidden" ]] || fail "$forbidden must not be shipped"
done
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || fail "syntax error in $file"
done < <(find "$STAGE/public" "$STAGE/src" "$STAGE/config" -name '*.php' -print0)

# 4. Smoke test: serve the package itself and call the health endpoint.
echo "==> Smoke test"
PORT="${SMOKE_PORT:-18080}"
LOG_FILE="$(mktemp)"
APP_ENV=testing LOG_PATH="$LOG_FILE" php -S "127.0.0.1:$PORT" -t "$STAGE/public" "$STAGE/public/index.php" >/dev/null 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true; rm -f "$LOG_FILE"' EXIT
BODY=""
for _ in $(seq 1 50); do
    BODY="$(php -r '$b=@file_get_contents($argv[1]); echo $b===false ? "" : $b;' "http://127.0.0.1:$PORT/api/v3/health")"
    [[ -n "$BODY" ]] && break
    sleep 0.2
done
php -r '$d=json_decode($argv[1], true); exit(is_array($d) && ($d["data"]["status"] ?? null) === "ok" && is_string($d["meta"]["request_id"] ?? null) ? 0 : 1);' "$BODY" \
    || fail "health check did not return {\"data\":{\"status\":\"ok\"}}: $BODY"
kill "$SERVER_PID" 2>/dev/null || true
echo "    GET /api/v3/health -> $BODY"

# 5. Release metadata and per-file checksums.
{
    echo "name=$NAME"
    echo "version=$VERSION"
    echo "commit=$COMMIT"
    echo "built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "built_with_php=$(php -r 'echo PHP_VERSION;')"
    echo "composer_lock_sha256=$(sha256sum composer.lock | cut -d' ' -f1)"
} > "$STAGE/RELEASE"
(cd "$STAGE" && find . -type f ! -name CHECKSUMS.sha256 -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > CHECKSUMS.sha256)

# 6. Archive.
(cd "$BUILD_DIR" && zip -q -r -X "$DIST/$NAME.zip" "$NAME")
(cd "$DIST" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")

echo "==> Done"
echo "    $DIST/$NAME.zip ($(du -h "$DIST/$NAME.zip" | cut -f1))"
echo "    sha256 $(cut -d' ' -f1 "$DIST/$NAME.zip.sha256")"
echo "    files: $(wc -l < "$STAGE/CHECKSUMS.sha256")"
