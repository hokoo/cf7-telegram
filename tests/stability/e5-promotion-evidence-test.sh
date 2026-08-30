#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e5-promotion.XXXXXX")"

cleanup() {
	rm -rf -- "${WORKDIR}"
}
trap cleanup EXIT

make_zip() {
	local version="$1"
	local output="$2"
	local stage="${WORKDIR}/stage-${version}/cf7-telegram"
	mkdir -p "${stage}"
	printf '%s\n' '<?php' '/**' ' * Plugin Name: CF7 Telegram' " * Version: ${version}" ' */' > "${stage}/cf7-telegram.php"
	printf '%s\n' '=== CF7 Telegram ===' "Stable tag: ${version}" > "${stage}/readme.txt"
	(
		cd "$(dirname "${stage}")"
		zip -qr "${output}" cf7-telegram
	)
}

CANDIDATE_ZIP="${WORKDIR}/candidate.zip"
CANARY_ZIP="${WORKDIR}/canary.zip"
ROLLBACK_ZIP="${WORKDIR}/rollback.zip"
DB_SNAPSHOT="${WORKDIR}/rollback.sql"
SUPPORT_SUMMARY="${WORKDIR}/support-summary.json"
BROWSER_SUMMARY="${WORKDIR}/browser-summary.json"
OUTPUT_FILE="${WORKDIR}/promotion-evidence.json"

make_zip 1.0.13 "${CANDIDATE_ZIP}"
cp "${CANDIDATE_ZIP}" "${CANARY_ZIP}"
make_zip 1.0.11 "${ROLLBACK_ZIP}"
printf '%s\n' '-- deterministic pre-upgrade database snapshot' > "${DB_SNAPSHOT}"

EXPECTED_SHA256="$(sha256sum "${CANDIDATE_ZIP}" | awk '{print $1}')"
ROLLBACK_SHA256="$(sha256sum "${ROLLBACK_ZIP}" | awk '{print $1}')"
DB_SNAPSHOT_SHA256="$(sha256sum "${DB_SNAPSHOT}" | awk '{print $1}')"

jq -n \
	--arg candidate_sha256 "${EXPECTED_SHA256}" \
	--arg rollback_sha256 "${ROLLBACK_SHA256}" \
	--arg snapshot_sha256 "${DB_SNAPSHOT_SHA256}" '
	{
		failed_steps: 0,
		candidate: {sha256: $candidate_sha256, expected_version: "1.0.13"},
		support_matrix: [{matrix_id: "current", required: true}],
		evidence: [{row: "current", step: "lifecycle", status: "pass"}],
		row_summaries: [{evidence: [
			{case: "upgrade-1.0.11", step: "rollback_evidence", status: "pass", extra: {baseline_version: "1.0.11", baseline_sha256: $rollback_sha256, db_snapshot_sha256: $snapshot_sha256}},
			{case: "upgrade-1.0.11", step: "rollback_import", status: "pass"},
			{case: "upgrade-1.0.11", step: "rollback_plugin_restore", status: "pass"},
			{case: "upgrade-1.0.11", step: "assert-after-rollback", status: "pass"}
		]}]
	}' > "${SUPPORT_SUMMARY}"

jq -n --arg candidate_sha256 "${EXPECTED_SHA256}" '
	{
		failed_steps: 0,
		candidate: {version: "1.0.13", sha256: $candidate_sha256},
		checks: [
			"authenticated-admin-render",
			"no-page-errors",
			"no-console-errors",
			"admin-notice-visible",
			"pagination-beyond-ten",
			"post-mutation-observed"
		] | map({id: ., status: "pass"})
	}' > "${BROWSER_SUMMARY}"

export CANDIDATE_ZIP CANARY_ZIP SUPPORT_SUMMARY BROWSER_SUMMARY ROLLBACK_ZIP DB_SNAPSHOT
export EXPECTED_SHA256 ROLLBACK_SHA256 DB_SNAPSHOT_SHA256 OUTPUT_FILE
export EXPECTED_VERSION=1.0.13 ROLLBACK_VERSION=1.0.11

"${REPO_ROOT}/scripts/verify-promotion-evidence.sh" >/dev/null
jq -e '.candidate.version == "1.0.13" and .rollback.version == "1.0.11"' "${OUTPUT_FILE}" >/dev/null

tampered_snapshot="${WORKDIR}/tampered.sql"
printf '%s\n' '-- changed snapshot' > "${tampered_snapshot}"
DB_SNAPSHOT="${tampered_snapshot}"
export DB_SNAPSHOT
if "${REPO_ROOT}/scripts/verify-promotion-evidence.sh" >/dev/null 2>&1; then
	printf 'Promotion evidence test failed: tampered snapshot was accepted.\n' >&2
	exit 1
fi

printf 'Promotion evidence regression test passed.\n'
