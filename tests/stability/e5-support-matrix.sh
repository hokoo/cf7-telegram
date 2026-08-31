#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SOURCE_MANIFEST="${SCRIPT_DIR}/e1-version-sources.json"
E1_HARNESS="${SCRIPT_DIR}/e1-smoke-matrix.sh"

PLUGIN_SLUG="${PLUGIN_SLUG:-$(jq -r '.plugin_slug' "${SOURCE_MANIFEST}")}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
WORKDIR="${CF7TG_E5_SUPPORT_MATRIX_WORKDIR:-$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e5-support-matrix-${RUN_ID}.XXXXXX")}"
RESULTS_DIR="${CF7TG_E5_SUPPORT_MATRIX_RESULTS_DIR:-${WORKDIR}/results}"
LOG_DIR="${RESULTS_DIR}/logs"
ROW_RESULTS_DIR="${RESULTS_DIR}/rows"
EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
SUMMARY_JSON="${RESULTS_DIR}/summary.json"
CANDIDATE_ZIP="${CF7TG_CANDIDATE_ZIP:-${REPO_ROOT}/dist/${PLUGIN_SLUG}-wp-plugin.zip}"
EXPECTED_CANDIDATE_VERSION="${CF7TG_EXPECTED_CANDIDATE_VERSION:-$(jq -r '.candidate.expected_version // .support_contract.candidate_expected_version // empty' "${SOURCE_MANIFEST}")}"
PROMOTION_ROLLBACK_VERSION="${CF7TG_E5_PROMOTION_ROLLBACK_VERSION:-$(jq -r '.release_assumptions.wordpress_org_production_baseline // empty' "${SOURCE_MANIFEST}")}"
IMAGE_PROBE_TIMEOUT="${CF7TG_E5_SUPPORT_MATRIX_IMAGE_PROBE_TIMEOUT:-90s}"
KEEP_WORKDIR=0
FAILURES=0
SUMMARY_WRITTEN=0
ROWS=()

usage() {
	cat <<'USAGE'
Usage: tests/stability/e5-support-matrix.sh [options]

Options:
  --row <matrix_id>          Run one support_matrix row. Repeatable.
  --candidate-zip <path>     Candidate plugin ZIP to install and test.
  --expected-version <ver>   Expected candidate plugin version. Defaults to manifest candidate.expected_version.
  --workdir <path>           Use an explicit temporary work directory.
  --keep-workdir             Keep child E1 workdirs for debugging.
  -h, --help                 Show this help.

Environment:
  CF7TG_CANDIDATE_ZIP                         Candidate ZIP path.
  CF7TG_EXPECTED_CANDIDATE_VERSION            Expected candidate version, default 1.0.13.
  CF7TG_E5_SUPPORT_MATRIX_IMAGE_PROBE_TIMEOUT Docker manifest probe timeout, default 90s.
  CF7TG_E5_SUPPORT_MATRIX_RESULTS_DIR         Evidence output directory.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--row)
			shift
			[ "$#" -gt 0 ] || { echo "Missing value for --row" >&2; exit 2; }
			ROWS+=("$1")
			;;
		--candidate-zip)
			shift
			[ "$#" -gt 0 ] || { echo "Missing value for --candidate-zip" >&2; exit 2; }
			CANDIDATE_ZIP="$1"
			;;
		--expected-version)
			shift
			[ "$#" -gt 0 ] || { echo "Missing value for --expected-version" >&2; exit 2; }
			EXPECTED_CANDIDATE_VERSION="$1"
			;;
		--workdir)
			shift
			[ "$#" -gt 0 ] || { echo "Missing value for --workdir" >&2; exit 2; }
			WORKDIR="$1"
			RESULTS_DIR="${CF7TG_E5_SUPPORT_MATRIX_RESULTS_DIR:-${WORKDIR}/results}"
			LOG_DIR="${RESULTS_DIR}/logs"
			ROW_RESULTS_DIR="${RESULTS_DIR}/rows"
			EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
			SUMMARY_JSON="${RESULTS_DIR}/summary.json"
			;;
		--keep-workdir)
			KEEP_WORKDIR=1
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown argument: $1" >&2
			usage >&2
			exit 2
			;;
	esac
	shift
done

if [ "${#ROWS[@]}" -eq 0 ]; then
	while IFS= read -r row; do
		ROWS+=("${row}")
	done < <(jq -r '.support_matrix[].matrix_id' "${SOURCE_MANIFEST}")
fi

mkdir -p "${RESULTS_DIR}" "${LOG_DIR}" "${ROW_RESULTS_DIR}"
: > "${EVIDENCE_JSONL}"

emit() {
	local row_id="$1"
	local step="$2"
	local status="$3"
	local message="$4"
	local extra="${5:-}"

	if [ -z "${extra}" ]; then
		extra='{}'
	fi

	jq -nc \
		--arg run_id "${RUN_ID}" \
		--arg row_id "${row_id}" \
		--arg step "${step}" \
		--arg status "${status}" \
		--arg message "${message}" \
		--argjson extra "${extra}" \
		'{
			run_id: $run_id,
			row: $row_id,
			step: $step,
			status: $status,
			message: $message,
			extra: $extra,
			captured_at_gmt: (now | todate)
		}' >> "${EVIDENCE_JSONL}"
}

fail_step() {
	local row_id="$1"
	local step="$2"
	local message="$3"
	local extra="${4:-}"

	if [ -z "${extra}" ]; then
		extra='{}'
	fi

	FAILURES=$((FAILURES + 1))
	emit "${row_id}" "${step}" "fail" "${message}" "${extra}"
}

write_summary() {
	local promotion_rollback='null'
	local row_summaries_file="${RESULTS_DIR}/row-summaries.json"
	local summary_files=()

	while IFS= read -r -d '' summary_file; do
		summary_files+=("${summary_file}")
	done < <(find "${ROW_RESULTS_DIR}" -mindepth 2 -maxdepth 2 -name summary.json -print0 2>/dev/null)

	if [ "${#summary_files[@]}" -gt 0 ]; then
		jq -s '.' "${summary_files[@]}" > "${row_summaries_file}"
	else
		printf '[]\n' > "${row_summaries_file}"
	fi

	if [ -s "${RESULTS_DIR}/rollback.sql" ]; then
		promotion_rollback="$(jq -nc \
			--arg version "${PROMOTION_ROLLBACK_VERSION}" \
			--arg file "${RESULTS_DIR}/rollback.sql" \
			--arg sha256 "$(sha256sum "${RESULTS_DIR}/rollback.sql" | awk '{print $1}')" \
			'{version:$version,file:$file,sha256:$sha256}')"
	fi

	jq -s \
		--arg run_id "${RUN_ID}" \
		--arg workdir "${WORKDIR}" \
		--arg results_dir "${RESULTS_DIR}" \
		--arg source_manifest "${SOURCE_MANIFEST}" \
		--arg candidate_zip "${CANDIDATE_ZIP}" \
		--arg candidate_sha256 "$(sha256sum "${CANDIDATE_ZIP}" 2>/dev/null | awk '{print $1}')" \
		--arg expected_candidate_version "${EXPECTED_CANDIDATE_VERSION}" \
		--arg image_probe_timeout "${IMAGE_PROBE_TIMEOUT}" \
		--argjson promotion_rollback "${promotion_rollback}" \
		--argjson support_contract "$(jq -c '.support_contract // {}' "${SOURCE_MANIFEST}")" \
		--argjson support_matrix "$(jq -c '.support_matrix // []' "${SOURCE_MANIFEST}")" \
		--slurpfile row_summaries "${row_summaries_file}" \
		'{
			run_id: $run_id,
			workdir: $workdir,
			results_dir: $results_dir,
			source_manifest: $source_manifest,
			candidate: {
				zip: $candidate_zip,
				sha256: $candidate_sha256,
				expected_version: $expected_candidate_version
			},
			image_probe_timeout: $image_probe_timeout,
			promotion_rollback: $promotion_rollback,
			support_contract: $support_contract,
			support_matrix: $support_matrix,
			row_summaries: $row_summaries[0],
			total_steps: length,
			passed_steps: ([.[] | select(.status == "pass")] | length),
			skipped_steps: ([.[] | select(.status == "skipped")] | length),
			failed_steps: ([.[] | select(.status == "fail")] | length),
			skips: [.[] | select(.status == "skipped")],
			failures: [.[] | select(.status == "fail")],
			evidence: .
		}' "${EVIDENCE_JSONL}" > "${SUMMARY_JSON}"
	SUMMARY_WRITTEN=1
}

stage_promotion_rollback() {
	local current_row_id source_snapshot

	[ -n "${PROMOTION_ROLLBACK_VERSION}" ] || return 0
	current_row_id="$(jq -r '[.support_matrix[] | select(.label == "current")][0].matrix_id // empty' "${SOURCE_MANIFEST}")"
	[ -n "${current_row_id}" ] || return 0
	source_snapshot="${ROW_RESULTS_DIR}/${current_row_id}/rollback/upgrade-${PROMOTION_ROLLBACK_VERSION}-rollback.sql"
	[ -s "${source_snapshot}" ] || return 0

	cp "${source_snapshot}" "${RESULTS_DIR}/rollback.sql"
	emit "promotion" "rollback_snapshot" "pass" "Staged the current support-row pre-upgrade database snapshot for manual promotion." "$(jq -nc \
		--arg source "${source_snapshot}" \
		--arg file "${RESULTS_DIR}/rollback.sql" \
		--arg version "${PROMOTION_ROLLBACK_VERSION}" \
		--arg sha256 "$(sha256sum "${RESULTS_DIR}/rollback.sql" | awk '{print $1}')" \
		'{source:$source,file:$file,version:$version,sha256:$sha256}')"
}

on_exit() {
	local status="$?"

	if [ -s "${EVIDENCE_JSONL}" ] && [ "${SUMMARY_WRITTEN}" -eq 0 ]; then
		if [ "${status}" -ne 0 ]; then
			emit "run" "exit" "fail" "Script exited before normal completion." "$(jq -nc --argjson exit_code "${status}" '{exit_code:$exit_code}')"
		fi
		write_summary || true
		if [ -f "${SUMMARY_JSON}" ]; then
			echo "E5 support matrix evidence: ${SUMMARY_JSON}"
		fi
	fi
}

trap on_exit EXIT

require_tools() {
	local missing=()
	for tool in jq sha256sum timeout; do
		command -v "${tool}" >/dev/null 2>&1 || missing+=("${tool}")
	done

	if ! command -v docker >/dev/null 2>&1; then
		missing+=("docker")
	fi

	if [ "${#missing[@]}" -gt 0 ]; then
		fail_step "preflight" "tools" "Missing required tools: ${missing[*]}"
		exit 2
	fi

	emit "preflight" "tools" "pass" "Required tools are available."
}

row_json() {
	local row_id="$1"
	jq -c --arg row_id "${row_id}" '.support_matrix[] | select(.matrix_id == $row_id)' "${SOURCE_MANIFEST}"
}

probe_image() {
	local row_id="$1"
	local image="$2"
	local required="$3"
	local log_file="${LOG_DIR}/${row_id}-image-probe.log"
	local exit_code

	if docker image inspect "${image}" >"${log_file}" 2>&1; then
		emit "${row_id}" "image_probe" "pass" "Official WordPress CLI Docker image is available in the local image cache." "$(jq -nc --arg image "${image}" --arg log "${log_file}" '{image:$image,probe:"docker image inspect",source:"local-cache",log:$log}')"
		return 0
	fi

	set +e
	timeout "${IMAGE_PROBE_TIMEOUT}" docker manifest inspect "${image}" >"${log_file}" 2>&1
	exit_code="$?"
	set -e

	if [ "${exit_code}" -eq 0 ]; then
		emit "${row_id}" "image_probe" "pass" "Official WordPress CLI Docker image is available." "$(jq -nc --arg image "${image}" --arg log "${log_file}" '{image:$image,probe:"docker manifest inspect",log:$log}')"
		return 0
	fi

	if [ "${required}" = "true" ]; then
		fail_step "${row_id}" "image_probe" "Required official WordPress CLI Docker image is unavailable or probe timed out." "$(jq -nc --arg image "${image}" --arg log "${log_file}" --arg timeout "${IMAGE_PROBE_TIMEOUT}" --argjson exit_code "${exit_code}" '{image:$image,probe:"docker manifest inspect",timeout:$timeout,log:$log,exit_code:$exit_code}')"
		return 1
	fi

	emit "${row_id}" "image_probe" "skipped" "Optional newest PHP row skipped because the official WordPress CLI Docker image is unavailable or probe timed out." "$(jq -nc --arg image "${image}" --arg log "${log_file}" --arg timeout "${IMAGE_PROBE_TIMEOUT}" --argjson exit_code "${exit_code}" '{image:$image,probe:"docker manifest inspect",timeout:$timeout,log:$log,exit_code:$exit_code}')"
	return 2
}

run_row() {
	local matrix_id="$1"
	local row config label required wp_version php_version image cf7_version row_results row_workdir row_log
	local case_args=()
	local keep_args=()
	local probe_rc=0
	local exit_code=0

	row="$(row_json "${matrix_id}")"
	if [ -z "${row}" ]; then
		fail_step "${matrix_id}" "manifest" "Support matrix row is not defined in manifest."
		return 1
	fi

	label="$(jq -r '.label' <<<"${row}")"
	required="$(jq -r '.required' <<<"${row}")"
	wp_version="$(jq -r '.wordpress_version' <<<"${row}")"
	php_version="$(jq -r '.php_version' <<<"${row}")"
	image="$(jq -r '.wp_cli_image' <<<"${row}")"
	cf7_version="$(jq -r '.contact_form_7_version' <<<"${row}")"
	config="$(jq -c '{matrix_id:.matrix_id,label:.label,required:.required,wordpress_version:.wordpress_version,php_version:.php_version,wp_cli_image:.wp_cli_image,contact_form_7_version:.contact_form_7_version,cases:.cases}' <<<"${row}")"

	while IFS= read -r case_id; do
		case_args+=(--case "${case_id}")
	done < <(jq -r '.cases[]' <<<"${row}")

	emit "${matrix_id}" "manifest" "pass" "Loaded exact support matrix row." "${config}"

	set +e
	probe_image "${matrix_id}" "${image}" "${required}"
	probe_rc="$?"
	set -e

	if [ "${probe_rc}" -eq 2 ]; then
		return 0
	fi
	if [ "${probe_rc}" -ne 0 ]; then
		return 1
	fi

	row_results="${ROW_RESULTS_DIR}/${matrix_id}"
	row_workdir="${WORKDIR}/workdirs/${matrix_id}"
	row_log="${LOG_DIR}/${matrix_id}-e1.log"
	mkdir -p "${row_results}" "${row_workdir}"
	if [ "${KEEP_WORKDIR}" -eq 1 ]; then
		keep_args=(--keep-workdir)
	fi

	set +e
	CF7TG_CANDIDATE_ZIP="${CANDIDATE_ZIP}" \
	CF7TG_EXPECTED_CANDIDATE_VERSION="${EXPECTED_CANDIDATE_VERSION}" \
	CF7TG_E1_WP_VERSION="${wp_version}" \
	CF7TG_E1_WP_CLI_IMAGE="${image}" \
	CF7TG_E1_CF7_VERSION="${cf7_version}" \
	CF7TG_E1_RESULTS_DIR="${row_results}" \
	CF7TG_E1_WORKDIR="${row_workdir}" \
	CF7TG_E1_SUPPORT_ROW_ID="${matrix_id}" \
	CF7TG_E1_SUPPORT_ROW_LABEL="${label}" \
	CF7TG_E1_SUPPORT_ROW_REQUIRED="${required}" \
	CF7TG_E1_SUPPORT_ROW_PHP="${php_version}" \
	"${E1_HARNESS}" "${case_args[@]}" "${keep_args[@]}" >"${row_log}" 2>&1
	exit_code="$?"
	set -e

	if [ -s "${row_results}/summary.json" ] && jq -e 'type == "object"' "${row_results}/summary.json" >/dev/null 2>&1; then
		local failed_steps expected_failed_steps candidate_sha256
		failed_steps="$(jq '.failed_steps' "${row_results}/summary.json")"
		expected_failed_steps="$(jq '.expected_failed_steps' "${row_results}/summary.json")"
		candidate_sha256="$(jq -r '.environment.candidate.sha256 // ""' "${row_results}/summary.json")"

		if [ "${exit_code}" -eq 0 ] && [ "${failed_steps}" -eq 0 ]; then
			emit "${matrix_id}" "lifecycle" "pass" "Support row lifecycle completed with zero failures." "$(jq -nc --arg summary "${row_results}/summary.json" --arg log "${row_log}" --arg candidate_sha256 "${candidate_sha256}" --argjson failed_steps "${failed_steps}" --argjson expected_failed_steps "${expected_failed_steps}" '{summary:$summary,log:$log,candidate_sha256:$candidate_sha256,failed_steps:$failed_steps,expected_failed_steps:$expected_failed_steps}')"
			return 0
		fi

		fail_step "${matrix_id}" "lifecycle" "Support row lifecycle failed." "$(jq -nc --arg summary "${row_results}/summary.json" --arg log "${row_log}" --argjson exit_code "${exit_code}" --argjson failed_steps "${failed_steps}" --argjson expected_failed_steps "${expected_failed_steps}" '{summary:$summary,log:$log,exit_code:$exit_code,failed_steps:$failed_steps,expected_failed_steps:$expected_failed_steps}')"
		return 1
	fi

	fail_step "${matrix_id}" "lifecycle" "Support row did not produce parseable E1 summary evidence." "$(jq -nc --arg summary "${row_results}/summary.json" --arg log "${row_log}" --argjson exit_code "${exit_code}" '{summary:$summary,log:$log,exit_code:$exit_code}')"
	return 1
}

require_tools

for row_id in "${ROWS[@]}"; do
	run_row "${row_id}" || true
done

stage_promotion_rollback
write_summary

echo "E5 support matrix evidence: ${SUMMARY_JSON}"
if [ "${FAILURES}" -gt 0 ]; then
	echo "E5 support matrix failed with ${FAILURES} failing step(s)." >&2
	exit 1
fi

echo "E5 support matrix passed."
