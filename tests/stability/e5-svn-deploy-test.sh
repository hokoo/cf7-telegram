#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e5-svn-deploy.XXXXXX")"
REPOSITORY="${WORKDIR}/repository"
IMPORT_DIR="${WORKDIR}/import"
BUILD_DIR="${WORKDIR}/build"
WORKING_COPY="${WORKDIR}/working-copy"

cleanup() {
	rm -rf -- "${WORKDIR}"
}
trap cleanup EXIT

for tool in python3 rsync svn svnadmin svnlook; do
	command -v "${tool}" >/dev/null 2>&1 || {
		printf 'SVN deploy test failed: required tool is missing: %s\n' "${tool}" >&2
		exit 2
	}
done

mkdir -p \
	"${IMPORT_DIR}/trunk/vendor/psr/http-message/src" \
	"${IMPORT_DIR}/trunk/vendor/package with spaces/nested" \
	"${IMPORT_DIR}/tags" \
	"${IMPORT_DIR}/assets" \
	"${BUILD_DIR}/vendor/ramsey/collection/src"

printf '%s\n' '<?php // old plugin' > "${IMPORT_DIR}/trunk/cf7-telegram.php"
printf '%s\n' 'old sdk file' > "${IMPORT_DIR}/trunk/vendor/psr/http-message/src/MessageInterface.php"
printf '%s\n' 'old spaced path' > "${IMPORT_DIR}/trunk/vendor/package with spaces/nested/file.php"
printf '%s\n' '<?php' '/**' ' * Plugin Name: CF7 Telegram' ' * Version: 1.0.13' ' */' > "${BUILD_DIR}/cf7-telegram.php"
printf '%s\n' '=== CF7 Telegram ===' 'Stable tag: 1.0.13' > "${BUILD_DIR}/readme.txt"
printf '%s\n' '<?php // retained runtime dependency' > "${BUILD_DIR}/vendor/ramsey/collection/src/Collection.php"

svnadmin create "${REPOSITORY}"
svn import --quiet "${IMPORT_DIR}" "file://${REPOSITORY}" -m 'Seed old release tree'

"${REPO_ROOT}/scripts/deploy-wordpress-svn.sh" \
	--slug cf7-telegram \
	--version 1.0.13 \
	--build-dir "${BUILD_DIR}" \
	--svn-url "file://${REPOSITORY}" \
	--working-copy "${WORKING_COPY}" \
	--dry-run \
	--keep-working-copy >/dev/null

[ ! -e "${WORKING_COPY}/trunk/vendor/psr" ] || {
	printf 'SVN deploy test failed: removed SDK tree remains in trunk.\n' >&2
	exit 1
}
[ ! -e "${WORKING_COPY}/trunk/vendor/package with spaces" ] || {
	printf 'SVN deploy test failed: removed path containing spaces remains in trunk.\n' >&2
	exit 1
}
[ -f "${WORKING_COPY}/tags/1.0.13/vendor/ramsey/collection/src/Collection.php" ] || {
	printf 'SVN deploy test failed: prepared tag does not contain candidate files.\n' >&2
	exit 1
}

svn status --xml "${WORKING_COPY}" | python3 "${REPO_ROOT}/scripts/svn-status.py" validate --working-copy "${WORKING_COPY}"
[ "$(svnlook youngest "${REPOSITORY}")" = '1' ] || {
	printf 'SVN deploy test failed: dry run changed the repository revision.\n' >&2
	exit 1
}

svn commit --quiet "${WORKING_COPY}" -m 'Publish prepared candidate'
rerun_output="$(
	"${REPO_ROOT}/scripts/deploy-wordpress-svn.sh" \
		--slug cf7-telegram \
		--version 1.0.13 \
		--build-dir "${BUILD_DIR}" \
		--svn-url "file://${REPOSITORY}" \
		--working-copy "${WORKING_COPY}" \
		--dry-run \
		--keep-working-copy
)"
grep -q 'already deployed exactly' <<<"${rerun_output}" || {
	printf 'SVN deploy test failed: exact existing tag was not treated idempotently.\n' >&2
	exit 1
}
[ "$(svnlook youngest "${REPOSITORY}")" = '2' ] || {
	printf 'SVN deploy test failed: idempotent rerun changed the repository revision.\n' >&2
	exit 1
}

STATUS_FIXTURE="${WORKDIR}/status.xml"
cat > "${STATUS_FIXTURE}" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<status><target path=".">
<entry path="trunk/vendor/parent"><wc-status item="missing" props="none" revision="1"/></entry>
<entry path="trunk/vendor/parent/child.php"><wc-status item="missing" props="none" revision="1"/></entry>
<entry path="trunk/vendor/path with spaces"><wc-status item="missing" props="none" revision="1"/></entry>
</target></status>
XML

mapfile -d '' missing_roots < <(python3 "${REPO_ROOT}/scripts/svn-status.py" missing-roots --working-copy "${WORKING_COPY}" < "${STATUS_FIXTURE}")
[ "${#missing_roots[@]}" -eq 2 ]
[ "${missing_roots[0]}" = 'trunk/vendor/parent' ]
[ "${missing_roots[1]}" = 'trunk/vendor/path with spaces' ]

printf 'SVN deploy regression test passed: snapshot parsing removed nested legacy trees safely.\n'
