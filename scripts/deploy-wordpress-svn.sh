#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATUS_PARSER="${SCRIPT_DIR}/svn-status.py"

SLUG="${SLUG:-}"
VERSION="${VERSION:-}"
BUILD_DIR="${BUILD_DIR:-}"
SVN_URL="${SVN_URL:-}"
SVN_DIR="${SVN_DIR:-}"
DRY_RUN="${INPUT_DRY_RUN:-${DRY_RUN:-false}}"
KEEP_WORKING_COPY="${KEEP_WORKING_COPY:-false}"
CREATED_WORKING_COPY=false

usage() {
	cat <<'USAGE'
Usage: scripts/deploy-wordpress-svn.sh [options]

Options:
  --slug <slug>             WordPress.org plugin slug.
  --version <version>       Version/tag to publish.
  --build-dir <path>        Unpacked, verified plugin build.
  --svn-url <url>           SVN repository URL (defaults to WordPress.org).
  --working-copy <path>     Explicit working-copy path.
  --dry-run                 Prepare and validate without committing.
  --keep-working-copy       Do not remove an automatically-created checkout.
  -h, --help                Show this help.

SVN_USERNAME and SVN_PASSWORD are required unless --dry-run is used.
USAGE
}

fail() {
	printf 'WordPress.org deploy failed: %s\n' "$1" >&2
	exit 1
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--slug)
			shift
			[ "$#" -gt 0 ] || fail 'missing value for --slug'
			SLUG="$1"
			;;
		--version)
			shift
			[ "$#" -gt 0 ] || fail 'missing value for --version'
			VERSION="$1"
			;;
		--build-dir)
			shift
			[ "$#" -gt 0 ] || fail 'missing value for --build-dir'
			BUILD_DIR="$1"
			;;
		--svn-url)
			shift
			[ "$#" -gt 0 ] || fail 'missing value for --svn-url'
			SVN_URL="$1"
			;;
		--working-copy)
			shift
			[ "$#" -gt 0 ] || fail 'missing value for --working-copy'
			SVN_DIR="$1"
			;;
		--dry-run)
			DRY_RUN=true
			;;
		--keep-working-copy)
			KEEP_WORKING_COPY=true
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			fail "unknown argument: $1"
			;;
	esac
	shift
done

[[ "${SLUG}" =~ ^[a-z0-9][a-z0-9-]*$ ]] || fail 'SLUG must contain lowercase letters, digits, or hyphens'
[[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]] || fail 'VERSION must be a semantic plugin version'
[ -d "${BUILD_DIR}" ] || fail "BUILD_DIR does not exist: ${BUILD_DIR}"
[ -f "${STATUS_PARSER}" ] || fail "status parser is missing: ${STATUS_PARSER}"

for tool in python3 rsync svn; do
	command -v "${tool}" >/dev/null 2>&1 || fail "required tool is missing: ${tool}"
done

SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org/${SLUG}/}"
SVN_URL="${SVN_URL%/}/"
BUILD_DIR="$(cd "${BUILD_DIR}" && pwd)"

if [ -z "${SVN_DIR}" ]; then
	SVN_DIR="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/${SLUG}-svn.XXXXXX")"
	CREATED_WORKING_COPY=true
else
	mkdir -p "${SVN_DIR}"
	SVN_DIR="$(cd "${SVN_DIR}" && pwd)"
fi

cleanup() {
	if [ "${CREATED_WORKING_COPY}" = true ] && [ "${KEEP_WORKING_COPY}" != true ]; then
		rm -rf -- "${SVN_DIR}"
	fi
}
trap cleanup EXIT

if [ -e "${SVN_DIR}/.svn" ]; then
	[ "$(svn info --show-item url "${SVN_DIR}")/" = "${SVN_URL}" ] || fail 'working copy URL does not match SVN_URL'
	svn cleanup "${SVN_DIR}"
	svn revert -R "${SVN_DIR}" >/dev/null
else
	[ -z "$(find "${SVN_DIR}" -mindepth 1 -maxdepth 1 -print -quit)" ] || fail 'working-copy path is not empty'
	svn checkout --depth immediates "${SVN_URL}" "${SVN_DIR}"
fi

cd "${SVN_DIR}"
svn update --set-depth infinity trunk
svn update --set-depth immediates tags
if [ -d assets ]; then
	svn update --set-depth infinity assets
fi

[ ! -e "tags/${VERSION}" ] || fail "version ${VERSION} is already present in SVN tags"

plugin_version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "${BUILD_DIR}/${SLUG}.php" | head -n 1)"
stable_tag="$(sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' "${BUILD_DIR}/readme.txt" | head -n 1)"
[ "${plugin_version}" = "${VERSION}" ] || fail "plugin header version ${plugin_version:-missing} does not match ${VERSION}"
[ "${stable_tag}" = "${VERSION}" ] || fail "readme stable tag ${stable_tag:-missing} does not match ${VERSION}"

printf 'Syncing verified build into SVN trunk...\n'
rsync -rc --delete --delete-excluded "${BUILD_DIR}/" trunk/

# Snapshot status before changing the working copy. Streaming `svn status` into
# `svn rm` races on large removals and caused the 1.0.12 deployment failure.
STATUS_XML="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/${SLUG}-svn-status.XXXXXX.xml")"
MISSING_LIST="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/${SLUG}-svn-missing.XXXXXX.list")"
svn status --xml > "${STATUS_XML}"
python3 "${STATUS_PARSER}" missing-roots --working-copy "${SVN_DIR}" < "${STATUS_XML}" > "${MISSING_LIST}"
while IFS= read -r -d '' missing_path; do
	svn rm --force -- "${missing_path}" >/dev/null
done < "${MISSING_LIST}"
rm -f -- "${STATUS_XML}" "${MISSING_LIST}"

svn add trunk --force >/dev/null
svn cp trunk "tags/${VERSION}"

FINAL_STATUS_XML="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/${SLUG}-svn-final-status.XXXXXX.xml")"
svn status --xml > "${FINAL_STATUS_XML}"
python3 "${STATUS_PARSER}" validate --working-copy "${SVN_DIR}" < "${FINAL_STATUS_XML}"
rm -f -- "${FINAL_STATUS_XML}"

svn status

if [ "${DRY_RUN}" = true ]; then
	printf 'Dry run complete; no SVN commit was made.\n'
	exit 0
fi

[ -n "${SVN_USERNAME:-}" ] || fail 'SVN_USERNAME is required'
[ -n "${SVN_PASSWORD:-}" ] || fail 'SVN_PASSWORD is required'

svn commit \
	-m "Update to version ${VERSION} from GitHub" \
	--no-auth-cache \
	--non-interactive \
	--username "${SVN_USERNAME}" \
	--password "${SVN_PASSWORD}"

printf 'Plugin %s version %s deployed to WordPress.org SVN.\n' "${SLUG}" "${VERSION}"
