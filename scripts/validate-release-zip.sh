#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="${PLUGIN_SLUG:-cf7-telegram}"
ENTRYPOINT="${ENTRYPOINT:-cf7-telegram.php}"
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
		"${PLUGIN_SLUG}/react/config-overrides.js")
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
[ -d "$PLUGIN_ROOT/react/build" ] || fail "React build directory missing: $PLUGIN_SLUG/react/build"
[ -f "$PLUGIN_ROOT/react/build/index.html" ] || fail "React build index missing: $PLUGIN_SLUG/react/build/index.html"
[ -s "$PLUGIN_ROOT/react/build/asset-manifest.json" ] || fail "React asset manifest missing or empty"
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
