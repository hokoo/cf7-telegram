#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="${PLUGIN_DIR:-$ROOT_DIR/plugin-dir}"
PLUGIN_SLUG="${PLUGIN_SLUG:-cf7-telegram}"
ENTRYPOINT="${ENTRYPOINT:-cf7-telegram.php}"
DIST_DIR="${DIST_DIR:-$ROOT_DIR/dist}"
ZIP_NAME="${ZIP_NAME:-${PLUGIN_SLUG}-wp-plugin.zip}"
ZIP_PATH="${ZIP_PATH:-$DIST_DIR/$ZIP_NAME}"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-}"

# ZIP headers store local timestamps, so pin the timezone for cross-host reproducibility.
export TZ=UTC

case "$DIST_DIR" in
	/*) ;;
	*) DIST_DIR="$ROOT_DIR/$DIST_DIR" ;;
esac

case "$ZIP_PATH" in
	/*) ;;
	*) ZIP_PATH="$ROOT_DIR/$ZIP_PATH" ;;
esac

fail() {
	printf 'release zip build failed: %s\n' "$1" >&2
	exit 1
}

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		fail "required command not found: $1"
	fi
}

require_command composer
require_command find
require_command npm
require_command rsync
require_command sed
require_command sort
require_command touch
require_command zip

[ -d "$PLUGIN_DIR" ] || fail "plugin directory not found: $PLUGIN_DIR"
[ -f "$PLUGIN_DIR/$ENTRYPOINT" ] || fail "plugin entrypoint not found: $PLUGIN_DIR/$ENTRYPOINT"
[ -f "$PLUGIN_DIR/composer.lock" ] || fail "composer.lock not found in plugin directory"
[ -f "$PLUGIN_DIR/react/package-lock.json" ] || fail "React package-lock.json not found"

if [ -z "$SOURCE_DATE_EPOCH" ]; then
	SOURCE_DATE_EPOCH="$(
		git -C "$ROOT_DIR" log -1 --format=%ct -- \
			"$PLUGIN_DIR" \
			"$ROOT_DIR/scripts/build-release-zip.sh" \
			"$ROOT_DIR/scripts/validate-release-zip.sh" \
			2>/dev/null || true
	)"
	SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(date +%s)}"
fi

printf 'Installing React dependencies...\n'
npm --prefix "$PLUGIN_DIR/react" ci

printf 'Building React production assets...\n'
CI=false npm --prefix "$PLUGIN_DIR/react" run build

[ -d "$PLUGIN_DIR/react/build" ] || fail "React build was not generated"
[ -f "$PLUGIN_DIR/react/build/index.html" ] || fail "React build index was not generated"

STAGE_DIR="$(mktemp -d)"
cleanup() {
	rm -rf "$STAGE_DIR"
}
trap cleanup EXIT

PLUGIN_STAGE="$STAGE_DIR/$PLUGIN_SLUG"
mkdir -p "$PLUGIN_STAGE" "$DIST_DIR"

printf 'Preparing production staging directory...\n'
rsync -a --delete \
	--exclude '/vendor' \
	--exclude '/tests' \
	--exclude '/phpunit.xml.dist' \
	--exclude '/react/build' \
	--exclude '/react/node_modules' \
	--exclude '/react/src' \
	--exclude '/react/public' \
	--exclude '/react/scripts' \
	--exclude '/react/package.json' \
	--exclude '/react/package-lock.json' \
	--exclude '/react/README.md' \
	--exclude '/react/config-overrides.js' \
	--exclude '/phpunit.xml' \
	--exclude '/.git' \
	--exclude '/.gitignore' \
	--exclude '/.github' \
	--exclude '/.env' \
	--exclude '/.env.*' \
	"$PLUGIN_DIR/" "$PLUGIN_STAGE/"

mkdir -p "$PLUGIN_STAGE/react/build"
rsync -a --delete "$PLUGIN_DIR/react/build/" "$PLUGIN_STAGE/react/build/"

printf 'Installing Composer production dependencies into staging...\n'
composer --working-dir="$PLUGIN_STAGE" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

[ -f "$PLUGIN_STAGE/vendor/autoload.php" ] || fail "Composer autoload was not generated"

find "$PLUGIN_STAGE/vendor" -depth -type d \( \
	-name 'local-dev' -o \
	-iname 'test' -o \
	-iname 'tests' -o \
	-iname 'doc' -o \
	-iname 'docs' -o \
	-iname 'example' -o \
	-iname 'examples' -o \
	-iname 'vendor-bin' -o \
	-iname 'phpstan' -o \
	-iname 'psalm' -o \
	-iname 'docker' \
\) -exec rm -rf {} +
find "$PLUGIN_STAGE/vendor" -type f \( \
	-iname 'phpunit*' -o \
	-iname 'phpstan*' -o \
	-iname 'psalm*' -o \
	-iname 'phpcs*' -o \
	-iname '.php_cs*' -o \
	-iname 'dockerfile*' \
\) -delete
find "$PLUGIN_STAGE" -type f \( -name '*.key' -o -name '*.pem' -o -name '*.sql' -o -name '*.zip' -o -name '*.tgz' -o -name '*.tar' -o -name '*.tar.gz' \) -delete
find "$PLUGIN_STAGE" -depth -name '.*' -exec rm -rf {} +

printf 'Normalizing archive timestamps...\n'
find "$PLUGIN_STAGE" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +

rm -f "$ZIP_PATH"

printf 'Creating release zip: %s\n' "$ZIP_PATH"
(
	cd "$STAGE_DIR"
	find "$PLUGIN_SLUG" -print | LC_ALL=C sort | zip -X -q "$ZIP_PATH" -@
)

"$ROOT_DIR/scripts/validate-release-zip.sh" "$ZIP_PATH"
