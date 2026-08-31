#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="${PLUGIN_DIR:-$ROOT_DIR/plugin-dir}"
REACT_DIR="${REACT_DIR:-$PLUGIN_DIR/react}"
REPORT_DIR="${CF7TG_AUDIT_REPORT_DIR:-$ROOT_DIR/dist/audit-reports}"
FAILURES=0

fail() {
	printf 'release audit failed: %s\n' "$1" >&2
	exit 1
}

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		fail "required command not found: $1"
	fi
}

run_report() {
	local name="$1"
	local blocking="$2"
	shift 2

	local stdout_file="$REPORT_DIR/${name}.json"
	local stderr_file="$REPORT_DIR/${name}.stderr.txt"
	local status_file="$REPORT_DIR/${name}.status.json"
	local exit_code

	printf 'Running %s...\n' "$name"

	set +e
	"$@" >"$stdout_file" 2>"$stderr_file"
	exit_code="$?"
	set -e

	jq -n \
		--arg name "$name" \
		--arg command "$*" \
		--arg stdout "$stdout_file" \
		--arg stderr "$stderr_file" \
		--argjson blocking "$blocking" \
		--argjson exit_code "$exit_code" \
		'{
			name: $name,
			command: $command,
			blocking: $blocking,
			exit_code: $exit_code,
			stdout: $stdout,
			stderr: $stderr,
			passed: ($exit_code == 0),
			captured_at_gmt: (now | todate)
		}' >"$status_file"

	if [ "$blocking" = true ] && [ "$exit_code" -ne 0 ]; then
		FAILURES=$((FAILURES + 1))
	fi
}

require_command composer
require_command jq
require_command npm
require_command readlink

[ -d "$PLUGIN_DIR" ] || fail "plugin directory not found: $PLUGIN_DIR"
[ -d "$REACT_DIR" ] || fail "React directory not found: $REACT_DIR"
[ -f "$ROOT_DIR/composer.lock" ] || fail "root composer.lock not found"
[ -f "$PLUGIN_DIR/composer.lock" ] || fail "plugin composer.lock not found"
[ -f "$REACT_DIR/package-lock.json" ] || fail "React package-lock.json not found"

case "$REPORT_DIR" in
	/*) ;;
	*) REPORT_DIR="$ROOT_DIR/$REPORT_DIR" ;;
esac

ROOT_DIR="$(readlink -m "$ROOT_DIR")"
REPORT_DIR="$(readlink -m "$REPORT_DIR")"
DIST_ROOT="$(readlink -m "$ROOT_DIR/dist")"
TMP_ROOT="$(readlink -m "${TMPDIR:-/tmp}")"
RUNNER_TEMP_ROOT=""

if [ -n "${RUNNER_TEMP:-}" ]; then
	RUNNER_TEMP_ROOT="$(readlink -m "$RUNNER_TEMP")"
fi

is_within() {
	local child="$1"
	local parent="$2"

	[ -n "$parent" ] || return 1
	[ "$child" != "$parent" ] || return 1

	case "$child/" in
		"$parent"/*) return 0 ;;
	esac

	return 1
}

if [ -z "$REPORT_DIR" ] || [ "$REPORT_DIR" = "/" ] || [ "$REPORT_DIR" = "$ROOT_DIR" ]; then
	fail "unsafe audit report directory: $REPORT_DIR"
fi

if ! is_within "$REPORT_DIR" "$DIST_ROOT" && \
	! is_within "$REPORT_DIR" "$TMP_ROOT" && \
	! is_within "$REPORT_DIR" "$RUNNER_TEMP_ROOT"; then
	fail "audit report directory must be under $DIST_ROOT, $TMP_ROOT, or RUNNER_TEMP"
fi

rm -rf "$REPORT_DIR"
mkdir -p "$REPORT_DIR"

run_report "composer-root-audit" true \
	composer --working-dir="$ROOT_DIR" audit --locked --format=json

run_report "composer-plugin-audit" true \
	composer --working-dir="$PLUGIN_DIR" audit --locked --format=json

run_report "composer-plugin-platform" true \
	composer --working-dir="$PLUGIN_DIR" check-platform-reqs --no-dev --format=json

run_report "npm-runtime-audit" true \
	npm --prefix "$REACT_DIR" audit --omit=dev --audit-level=high --json

run_report "npm-full-dev-audit" false \
	npm --prefix "$REACT_DIR" audit --json

jq -s \
	--arg root_dir "$ROOT_DIR" \
	--arg plugin_dir "$PLUGIN_DIR" \
	--arg react_dir "$REACT_DIR" \
	--arg report_dir "$REPORT_DIR" \
	'{
		root_dir: $root_dir,
		plugin_dir: $plugin_dir,
		react_dir: $react_dir,
		report_dir: $report_dir,
		blocking_failed: ([.[] | select(.blocking and (.exit_code != 0))] | length),
		non_blocking_failed: ([.[] | select((.blocking | not) and (.exit_code != 0))] | length),
		results: .
	}' "$REPORT_DIR"/*.status.json > "$REPORT_DIR/summary.json"

if [ "$FAILURES" -gt 0 ]; then
	printf 'release audit failed: %s blocking audit command(s) failed; see %s\n' "$FAILURES" "$REPORT_DIR/summary.json" >&2
	exit 1
fi

printf 'release audit gates passed; reports written to %s\n' "$REPORT_DIR"
