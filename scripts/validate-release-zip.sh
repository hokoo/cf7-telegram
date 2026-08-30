#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="${PLUGIN_SLUG:-cf7-telegram}"
ENTRYPOINT="${ENTRYPOINT:-cf7-telegram.php}"
AUTOLOADER_SUFFIX="${AUTOLOADER_SUFFIX:-Cf7Telegram}"
ZIP_PATH="${1:-}"
EXPECTED_VERSION="${EXPECTED_VERSION:-${2:-}}"

fail() {
	printf 'release zip validation failed: %s\n' "$1" >&2
	exit 1
}

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		fail "required command not found: $1"
	fi
}

normalize_version() {
	printf '%s' "$1" | sed -E 's/^(v|version)-?//'
}

if [ -z "$ZIP_PATH" ]; then
	fail "usage: $0 path/to/${PLUGIN_SLUG}-wp-plugin.zip [expected-version]"
fi

if [ ! -f "$ZIP_PATH" ]; then
	fail "zip does not exist: $ZIP_PATH"
fi

require_command unzip

if ! unzip -tq "$ZIP_PATH" >/dev/null; then
	fail "zip cannot be tested by unzip"
fi

mapfile -t ZIP_ENTRIES < <(unzip -Z1 "$ZIP_PATH")

if [ "${#ZIP_ENTRIES[@]}" -eq 0 ]; then
	fail "zip is empty"
fi

for entry in "${ZIP_ENTRIES[@]}"; do
	case "$entry" in
		"${PLUGIN_SLUG}/"*|"${PLUGIN_SLUG}")
			;;
		*)
			fail "entry outside plugin root: $entry"
			;;
	esac

	name="${entry##*/}"
	lower_entry="${entry,,}"
	if [[ "$name" == .* ]]; then
		fail "hidden file or directory is present: $entry"
	fi

	case "$entry" in
		*/node_modules/*|*/.git/*|*/.github/*|*/.env|*/.env.*|*/.gitignore)
			fail "development or hidden artifact is present: $entry"
			;;
		*/local-dev/*)
			fail "local development artifact is present: $entry"
			;;
		"${PLUGIN_SLUG}/react/src/"*|"${PLUGIN_SLUG}/react/public/"*|"${PLUGIN_SLUG}/react/scripts/"*)
			fail "React source is present: $entry"
			;;
		"${PLUGIN_SLUG}/tests/"*|"${PLUGIN_SLUG}/phpunit.xml"|"${PLUGIN_SLUG}/phpunit.xml.dist")
			fail "test infrastructure is present: $entry"
			;;
		"${PLUGIN_SLUG}/vendor/"*"tests/"*|"${PLUGIN_SLUG}/vendor/"*"test/"*)
			fail "vendor test infrastructure is present: $entry"
			;;
		"${PLUGIN_SLUG}/react/package.json"|\
		"${PLUGIN_SLUG}/react/package-lock.json"|\
		"${PLUGIN_SLUG}/react/README.md"|\
		"${PLUGIN_SLUG}/react/config-overrides.js"|\
		"${PLUGIN_SLUG}/react/webpack.config.js"|\
		"${PLUGIN_SLUG}/react/jest-unit.config.js")
			fail "React development file is present: $entry"
			;;
		"${PLUGIN_SLUG}/tests"|\
		"${PLUGIN_SLUG}/tests/"*|\
		"${PLUGIN_SLUG}/phpunit.xml.dist")
			fail "plugin test harness is present: $entry"
			;;
		*.sql|*.zip|*.tgz|*.tar|*.tar.gz|*.pem|*.key)
			fail "archive, database dump, or key file is present: $entry"
			;;
		*private_key*|*secret*|*token*)
			fail "possible secret file is present: $entry"
			;;
	esac

	case "$lower_entry" in
		"${PLUGIN_SLUG,,}/vendor/"*/test/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/tests/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/doc/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/docs/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/example/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/examples/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/vendor-bin/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/phpstan/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/psalm/*|\
		"${PLUGIN_SLUG,,}/vendor/"*/docker/*)
			fail "vendor development directory is present: $entry"
			;;
		"${PLUGIN_SLUG,,}/vendor/"*/phpunit*|\
		"${PLUGIN_SLUG,,}/vendor/"*/phpstan*|\
		"${PLUGIN_SLUG,,}/vendor/"*/psalm*|\
		"${PLUGIN_SLUG,,}/vendor/"*/phpcs*|\
		"${PLUGIN_SLUG,,}/vendor/"*/.php_cs*|\
		"${PLUGIN_SLUG,,}/vendor/"*/dockerfile*)
			fail "vendor development file is present: $entry"
			;;
	esac
done

TMP_DIR="$(mktemp -d)"
cleanup() {
	rm -rf "$TMP_DIR"
}
trap cleanup EXIT

unzip -q "$ZIP_PATH" -d "$TMP_DIR"

PLUGIN_ROOT="$TMP_DIR/$PLUGIN_SLUG"
ENTRYPOINT_PATH="$PLUGIN_ROOT/$ENTRYPOINT"
README_PATH="$PLUGIN_ROOT/readme.txt"

[ -d "$PLUGIN_ROOT" ] || fail "plugin root missing: $PLUGIN_SLUG"
[ -f "$ENTRYPOINT_PATH" ] || fail "plugin entrypoint missing: $PLUGIN_SLUG/$ENTRYPOINT"
[ -f "$PLUGIN_ROOT/vendor/autoload.php" ] || fail "Composer autoload missing: $PLUGIN_SLUG/vendor/autoload.php"
[ -f "$PLUGIN_ROOT/vendor/composer/autoload_real.php" ] || fail "Composer real autoloader missing"
[ -f "$PLUGIN_ROOT/vendor/composer/autoload_static.php" ] || fail "Composer static autoloader missing"
grep -q "ComposerAutoloaderInit${AUTOLOADER_SUFFIX}" "$PLUGIN_ROOT/vendor/autoload.php" || fail "Composer autoloader suffix is not deterministic"
grep -q "class ComposerAutoloaderInit${AUTOLOADER_SUFFIX}" "$PLUGIN_ROOT/vendor/composer/autoload_real.php" || fail "Composer real autoloader suffix is not deterministic"
grep -q "class ComposerStaticInit${AUTOLOADER_SUFFIX}" "$PLUGIN_ROOT/vendor/composer/autoload_static.php" || fail "Composer static autoloader suffix is not deterministic"
[ -d "$PLUGIN_ROOT/react/build" ] || fail "React build directory missing: $PLUGIN_SLUG/react/build"
[ -f "$PLUGIN_ROOT/react/build/settings-content.html" ] || fail "React settings content missing: $PLUGIN_SLUG/react/build/settings-content.html"
[ -f "$PLUGIN_ROOT/react/build/static/js/main.js" ] || fail "React admin script missing: $PLUGIN_SLUG/react/build/static/js/main.js"
[ -f "$PLUGIN_ROOT/react/build/static/css/main.css" ] || fail "React admin stylesheet missing: $PLUGIN_SLUG/react/build/static/css/main.css"
[ -s "$PLUGIN_ROOT/react/build/static/js/main.asset.php" ] || fail "React asset metadata missing or empty: $PLUGIN_SLUG/react/build/static/js/main.asset.php"
[ -f "$README_PATH" ] || fail "WordPress readme missing: $PLUGIN_SLUG/readme.txt"

header_version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$ENTRYPOINT_PATH" | head -n 1)"
const_version="$(sed -nE "s/^[[:space:]]*const[[:space:]]+WPCF7TG_VERSION[[:space:]]*=[[:space:]]*'([^']+)'.*/\1/p" "$ENTRYPOINT_PATH" | head -n 1)"
stable_tag="$(sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' "$README_PATH" | head -n 1)"

[ -n "$header_version" ] || fail "plugin header version is not readable"
[ -n "$const_version" ] || fail "WPCF7TG_VERSION is not readable"
[ -n "$stable_tag" ] || fail "readme stable tag is not readable"

if [ "$header_version" != "$const_version" ]; then
	fail "plugin header version ($header_version) differs from WPCF7TG_VERSION ($const_version)"
fi

if [ "$header_version" != "$stable_tag" ]; then
	fail "plugin header version ($header_version) differs from readme stable tag ($stable_tag)"
fi

if [ -n "$EXPECTED_VERSION" ]; then
	expected="$(normalize_version "$EXPECTED_VERSION")"
	if [ "$header_version" != "$expected" ]; then
		fail "plugin version ($header_version) differs from expected release version ($expected)"
	fi
fi

printf 'release zip validation passed: %s version %s\n' "$ZIP_PATH" "$header_version"
