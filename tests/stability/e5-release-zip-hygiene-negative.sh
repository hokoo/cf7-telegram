#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
FIXTURE_ENTRIES="${SCRIPT_DIR}/fixtures/e5-release-zip-forbidden-entries.txt"
PLUGIN_SLUG="${PLUGIN_SLUG:-cf7-telegram}"
EXPECTED_VERSION="${EXPECTED_VERSION:-1.0.13}"
WORKDIR="${CF7TG_E5_ZIP_HYGIENE_WORKDIR:-$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e5-zip-hygiene.XXXXXX")}"
PASSED=0

cleanup() {
	rm -rf "$WORKDIR"
}
trap cleanup EXIT

fail() {
	printf 'release zip hygiene negative test failed: %s\n' "$1" >&2
	exit 1
}

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		fail "required command not found: $1"
	fi
}

write_valid_fixture() {
	local root="$1/$PLUGIN_SLUG"

	mkdir -p \
		"$root/vendor/composer" \
		"$root/react/build/static/css" \
		"$root/react/build/static/js"

	cat > "$root/cf7-telegram.php" <<PHP
<?php
/*
 * Plugin Name: CF7 Telegram
 * Version: ${EXPECTED_VERSION}
 */
const WPCF7TG_VERSION = '${EXPECTED_VERSION}';
PHP

	cat > "$root/readme.txt" <<TXT
=== CF7 Telegram ===
Stable tag: ${EXPECTED_VERSION}
TXT

	cat > "$root/vendor/autoload.php" <<'PHP'
<?php
class ComposerAutoloaderInitCf7Telegram {}
PHP

	cat > "$root/vendor/composer/autoload_real.php" <<'PHP'
<?php
class ComposerAutoloaderInitCf7Telegram {}
PHP

	cat > "$root/vendor/composer/autoload_static.php" <<'PHP'
<?php
class ComposerStaticInitCf7Telegram {}
PHP

	printf '<div id="settings-content"></div>\n' > "$root/react/build/settings-content.html"
	printf 'body{display:block}\n' > "$root/react/build/static/css/main.css"
	printf 'window.cf7TelegramBooted=true;\n' > "$root/react/build/static/js/main.js"
	printf "<?php return array('dependencies' => array(), 'version' => 'fixture');\n" > "$root/react/build/static/js/main.asset.php"
}

make_zip() {
	local source_dir="$1"
	local zip_path="$2"

	(
		cd "$source_dir"
		find "$PLUGIN_SLUG" -print | LC_ALL=C sort | zip -X -q "$zip_path" -@
	)
}

require_command cp
require_command find
require_command grep
require_command mkdir
require_command rm
require_command zip

[ -f "$FIXTURE_ENTRIES" ] || fail "fixture entries not found: $FIXTURE_ENTRIES"

BASE_DIR="$WORKDIR/base"
mkdir -p "$BASE_DIR"
write_valid_fixture "$BASE_DIR"

GOOD_ZIP="$WORKDIR/good.zip"
make_zip "$BASE_DIR" "$GOOD_ZIP"
"$ROOT_DIR/scripts/validate-release-zip.sh" "$GOOD_ZIP" "$EXPECTED_VERSION" >/dev/null

while IFS= read -r entry || [ -n "$entry" ]; do
	case "$entry" in
		''|'#'*)
			continue
			;;
	esac

	case "$entry" in
		"$PLUGIN_SLUG"/*)
			;;
		*)
			fail "forbidden fixture entry must start with ${PLUGIN_SLUG}/: $entry"
			;;
	esac

	case_dir="$WORKDIR/case-$PASSED"
	case_zip="$WORKDIR/case-$PASSED.zip"
	case_log="$WORKDIR/case-$PASSED.log"
	cp -R "$BASE_DIR" "$case_dir"

	mkdir -p "$(dirname "$case_dir/$entry")"
	printf 'forbidden fixture content\n' > "$case_dir/$entry"
	make_zip "$case_dir" "$case_zip"

	if "$ROOT_DIR/scripts/validate-release-zip.sh" "$case_zip" "$EXPECTED_VERSION" >"$case_log" 2>&1; then
		fail "validator accepted forbidden entry: $entry"
	fi

	if ! grep -q 'release zip validation failed:' "$case_log"; then
		fail "validator failed without expected failure prefix for entry: $entry"
	fi

	PASSED=$((PASSED + 1))
done < "$FIXTURE_ENTRIES"

printf 'release zip hygiene negative checks passed: %s forbidden entries rejected\n' "$PASSED"
