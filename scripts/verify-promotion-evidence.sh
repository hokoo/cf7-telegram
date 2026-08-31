#!/usr/bin/env bash
set -Eeuo pipefail

fail() {
	printf 'Promotion evidence failed: %s\n' "$1" >&2
	exit 1
}

for variable in \
	CANDIDATE_ZIP \
	CANARY_ZIP \
	SUPPORT_SUMMARY \
	BROWSER_SUMMARY \
	ROLLBACK_ZIP \
	DB_SNAPSHOT \
	EXPECTED_VERSION \
	EXPECTED_SHA256 \
	ROLLBACK_VERSION \
	ROLLBACK_SHA256 \
	DB_SNAPSHOT_SHA256; do
	[ -n "${!variable:-}" ] || fail "${variable} is required"
done

for tool in jq sha256sum unzip; do
	command -v "${tool}" >/dev/null 2>&1 || fail "required tool is missing: ${tool}"
done

for file in \
	"${CANDIDATE_ZIP}" \
	"${CANARY_ZIP}" \
	"${SUPPORT_SUMMARY}" \
	"${BROWSER_SUMMARY}" \
	"${ROLLBACK_ZIP}" \
	"${DB_SNAPSHOT}"; do
	[ -s "${file}" ] || fail "evidence file is missing or empty: ${file}"
done

normalize_sha256() {
	printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

zip_version() {
	local zip_file="$1"
	unzip -Z1 "${zip_file}" | awk '/\/cf7-telegram\.php$/ { print; exit }' | while IFS= read -r plugin_file; do
		unzip -p "${zip_file}" "${plugin_file}" | sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' | head -n 1
	done
}

EXPECTED_SHA256="$(normalize_sha256 "${EXPECTED_SHA256}")"
ROLLBACK_SHA256="$(normalize_sha256 "${ROLLBACK_SHA256}")"
DB_SNAPSHOT_SHA256="$(normalize_sha256 "${DB_SNAPSHOT_SHA256}")"

[[ "${EXPECTED_SHA256}" =~ ^[0-9a-f]{64}$ ]] || fail 'EXPECTED_SHA256 must be a SHA-256 digest'
[[ "${ROLLBACK_SHA256}" =~ ^[0-9a-f]{64}$ ]] || fail 'ROLLBACK_SHA256 must be a SHA-256 digest'
[[ "${DB_SNAPSHOT_SHA256}" =~ ^[0-9a-f]{64}$ ]] || fail 'DB_SNAPSHOT_SHA256 must be a SHA-256 digest'

candidate_sha256="$(sha256sum "${CANDIDATE_ZIP}" | awk '{print $1}')"
canary_sha256="$(sha256sum "${CANARY_ZIP}" | awk '{print $1}')"
rollback_sha256="$(sha256sum "${ROLLBACK_ZIP}" | awk '{print $1}')"
snapshot_sha256="$(sha256sum "${DB_SNAPSHOT}" | awk '{print $1}')"

[ "${candidate_sha256}" = "${EXPECTED_SHA256}" ] || fail 'final candidate hash does not match the approved hash'
[ "${canary_sha256}" = "${EXPECTED_SHA256}" ] || fail 'canary artifact hash does not match the approved hash'
cmp -s "${CANDIDATE_ZIP}" "${CANARY_ZIP}" || fail 'final and canary ZIP bytes differ'
[ "$(zip_version "${CANDIDATE_ZIP}")" = "${EXPECTED_VERSION}" ] || fail 'final candidate version is not the expected release version'
[ "$(zip_version "${CANARY_ZIP}")" = "${EXPECTED_VERSION}" ] || fail 'canary candidate version is not the expected release version'
[ "${rollback_sha256}" = "${ROLLBACK_SHA256}" ] || fail 'rollback ZIP hash does not match recorded evidence'
[ "$(zip_version "${ROLLBACK_ZIP}")" = "${ROLLBACK_VERSION}" ] || fail 'rollback ZIP version does not match recorded evidence'
[ "${snapshot_sha256}" = "${DB_SNAPSHOT_SHA256}" ] || fail 'database snapshot hash does not match recorded evidence'

jq -e \
	--arg candidate_sha256 "${EXPECTED_SHA256}" \
	--arg candidate_version "${EXPECTED_VERSION}" \
	--arg rollback_version "${ROLLBACK_VERSION}" \
	--arg rollback_sha256 "${ROLLBACK_SHA256}" \
	--arg snapshot_sha256 "${DB_SNAPSHOT_SHA256}" '
		type == "object"
		and .failed_steps == 0
		and .candidate.sha256 == $candidate_sha256
		and .candidate.expected_version == $candidate_version
		and (
			[.support_matrix[] | select(.required == true) | .matrix_id] as $required
			| [.evidence[] | select(.step == "lifecycle" and .status == "pass") | .row] as $passed
			| ($required | length) > 0
			and all($required[]; . as $row | $passed | index($row) != null)
		)
		and any(
			.row_summaries[].evidence[];
			.step == "rollback_evidence"
			and .status == "pass"
			and .extra.baseline_version == $rollback_version
			and .extra.baseline_sha256 == $rollback_sha256
			and .extra.db_snapshot_sha256 == $snapshot_sha256
		)
		and any(
			.row_summaries[].evidence[];
			.case == ("upgrade-" + $rollback_version)
			and .step == "rollback_import"
			and .status == "pass"
		)
		and any(
			.row_summaries[].evidence[];
			.case == ("upgrade-" + $rollback_version)
			and .step == "rollback_plugin_restore"
			and .status == "pass"
		)
		and any(
			.row_summaries[].evidence[];
			.case == ("upgrade-" + $rollback_version)
			and .step == "assert-after-rollback"
			and .status == "pass"
		)
	' "${SUPPORT_SUMMARY}" >/dev/null || fail 'support matrix or executable rollback evidence is incomplete'

jq -e \
	--arg candidate_sha256 "${EXPECTED_SHA256}" \
	--arg candidate_version "${EXPECTED_VERSION}" '
		type == "object"
		and .failed_steps == 0
		and .candidate.sha256 == $candidate_sha256
		and .candidate.version == $candidate_version
		and (
			["authenticated-admin-render", "no-page-errors", "no-console-errors", "full-page-background", "system-notices-hidden", "pagination-beyond-ten", "post-mutation-observed"] as $required
			| [.checks[] | select(.status == "pass") | .id] as $passed
			| all($required[]; . as $check | $passed | index($check) != null)
		)
	' "${BROWSER_SUMMARY}" >/dev/null || fail 'browser canary evidence is incomplete'

OUTPUT_FILE="${OUTPUT_FILE:-promotion-evidence.json}"
jq -n \
	--arg candidate_version "${EXPECTED_VERSION}" \
	--arg candidate_sha256 "${EXPECTED_SHA256}" \
	--arg rollback_version "${ROLLBACK_VERSION}" \
	--arg rollback_sha256 "${ROLLBACK_SHA256}" \
	--arg db_snapshot_sha256 "${DB_SNAPSHOT_SHA256}" \
	--arg support_summary "${SUPPORT_SUMMARY}" \
	--arg browser_summary "${BROWSER_SUMMARY}" \
	'{
		schema: 1,
		verified_at_gmt: (now | todate),
		candidate: {version: $candidate_version, sha256: $candidate_sha256},
		rollback: {
			version: $rollback_version,
			zip_sha256: $rollback_sha256,
			db_snapshot_sha256: $db_snapshot_sha256
		},
		evidence: {support_summary: $support_summary, browser_summary: $browser_summary}
	}' > "${OUTPUT_FILE}"

printf 'Promotion evidence passed for version %s (%s).\n' "${EXPECTED_VERSION}" "${EXPECTED_SHA256}"
