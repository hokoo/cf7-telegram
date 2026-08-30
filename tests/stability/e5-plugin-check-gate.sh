#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SOURCE_MANIFEST="${SCRIPT_DIR}/e1-version-sources.json"

PLUGIN_SLUG="${PLUGIN_SLUG:-cf7-telegram}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
WORKDIR="${CF7TG_E5_PLUGIN_CHECK_WORKDIR:-$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e5-plugin-check-${RUN_ID}.XXXXXX")}"
CACHE_DIR="${CF7TG_E5_PLUGIN_CHECK_CACHE_DIR:-${XDG_CACHE_HOME:-${HOME}/.cache}/cf7-telegram/e5-plugin-check}"
RESULTS_DIR="${CF7TG_E5_PLUGIN_CHECK_RESULTS_DIR:-${WORKDIR}/results}"
LOG_DIR="${RESULTS_DIR}/logs"
ARTIFACT_DIR="${WORKDIR}/artifacts"
RUNTIME_DIR="${WORKDIR}/runtime"
COMPOSE_FILE="${WORKDIR}/docker-compose.e5-plugin-check.yml"
EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
SUMMARY_JSON="${RESULTS_DIR}/summary.json"
PLUGIN_CHECK_RAW_JSON="${RESULTS_DIR}/plugin-check.raw.json"
PLUGIN_CHECK_STDOUT="${LOG_DIR}/plugin-check.stdout"
PLUGIN_CHECK_STDERR="${LOG_DIR}/plugin-check.stderr"
WP_VERSION="${CF7TG_E5_PLUGIN_CHECK_WP_VERSION:-$(jq -r '.wordpress.default_core_version' "${SOURCE_MANIFEST}")}"
WP_CLI_IMAGE="${CF7TG_E5_PLUGIN_CHECK_WP_CLI_IMAGE:-$(jq -r '.wordpress.default_cli_image' "${SOURCE_MANIFEST}")}"
CF7_VERSION="${CF7TG_E5_PLUGIN_CHECK_CF7_VERSION:-$(jq -r '.dependencies.contact_form_7.default_version' "${SOURCE_MANIFEST}")}"
PLUGIN_CHECK_VERSION="${CF7TG_PLUGIN_CHECK_VERSION:-1.9.0}"
PLUGIN_CHECK_SHA256="${CF7TG_PLUGIN_CHECK_SHA256:-028330721e01041a28d1465e2c802357119870580670f2dcf00326af859da9a2}"
PLUGIN_CHECK_URL="${CF7TG_PLUGIN_CHECK_URL:-https://downloads.wordpress.org/plugin/plugin-check.${PLUGIN_CHECK_VERSION}.zip}"
CANDIDATE_ZIP="${CF7TG_CANDIDATE_ZIP:-${REPO_ROOT}/dist/${PLUGIN_SLUG}-wp-plugin.zip}"
EXPECTED_CANDIDATE_VERSION="${CF7TG_EXPECTED_CANDIDATE_VERSION:-}"
KEEP_WORKDIR=0
FAILURES=0
SUMMARY_WRITTEN=0
CURRENT_PROJECT=""

usage() {
	cat <<'USAGE'
Usage: tests/stability/e5-plugin-check-gate.sh [options]

Options:
  --candidate-zip <path>     Candidate plugin ZIP to install and check.
  --expected-version <ver>   Expected candidate plugin version. Defaults to source header version.
  --workdir <path>           Use an explicit temporary work directory.
  --keep-workdir             Keep Docker containers/volumes for debugging.
  -h, --help                 Show this help.

Environment:
  CF7TG_CANDIDATE_ZIP                    Candidate ZIP path.
  CF7TG_EXPECTED_CANDIDATE_VERSION       Expected candidate version.
  CF7TG_PLUGIN_CHECK_VERSION             Pinned Plugin Check version. Default 1.9.0.
  CF7TG_PLUGIN_CHECK_SHA256              Expected Plugin Check ZIP SHA-256.
  CF7TG_E5_PLUGIN_CHECK_WP_VERSION       WordPress core version.
  CF7TG_E5_PLUGIN_CHECK_WP_CLI_IMAGE     WP-CLI Docker image.
  CF7TG_E5_PLUGIN_CHECK_CF7_VERSION      Contact Form 7 version.
  CF7TG_E5_PLUGIN_CHECK_RESULTS_DIR      Evidence output directory.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
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
			RESULTS_DIR="${CF7TG_E5_PLUGIN_CHECK_RESULTS_DIR:-${WORKDIR}/results}"
			LOG_DIR="${RESULTS_DIR}/logs"
			ARTIFACT_DIR="${WORKDIR}/artifacts"
			RUNTIME_DIR="${WORKDIR}/runtime"
			COMPOSE_FILE="${WORKDIR}/docker-compose.e5-plugin-check.yml"
			EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
			SUMMARY_JSON="${RESULTS_DIR}/summary.json"
			PLUGIN_CHECK_RAW_JSON="${RESULTS_DIR}/plugin-check.raw.json"
			PLUGIN_CHECK_STDOUT="${LOG_DIR}/plugin-check.stdout"
			PLUGIN_CHECK_STDERR="${LOG_DIR}/plugin-check.stderr"
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

mkdir -p "${CACHE_DIR}" "${RESULTS_DIR}" "${LOG_DIR}" "${ARTIFACT_DIR}" "${RUNTIME_DIR}"
chmod 0777 "${RUNTIME_DIR}"
: > "${EVIDENCE_JSONL}"

docker_compose() {
	if command -v docker-compose >/dev/null 2>&1; then
		docker-compose "$@"
	else
		docker compose "$@"
	fi
}

emit() {
	local case_id="$1"
	local step="$2"
	local status="$3"
	local message="$4"
	local extra="${5:-}"

	if [ -z "${extra}" ]; then
		extra='{}'
	fi

	jq -nc \
		--arg run_id "${RUN_ID}" \
		--arg case_id "${case_id}" \
		--arg step "${step}" \
		--arg status "${status}" \
		--arg message "${message}" \
		--argjson extra "${extra}" \
		'{
			run_id: $run_id,
			case: $case_id,
			step: $step,
			status: $status,
			message: $message,
			extra: $extra,
			captured_at_gmt: (now | todate)
		}' >> "${EVIDENCE_JSONL}"
}

fail_step() {
	local case_id="$1"
	local step="$2"
	local message="$3"
	local extra="${4:-}"

	if [ -z "${extra}" ]; then
		extra='{}'
	fi

	FAILURES=$((FAILURES + 1))
	emit "${case_id}" "${step}" "fail" "${message}" "${extra}"
}

run_logged() {
	local case_id="$1"
	local step="$2"
	local exit_code
	shift 2
	local log_file="${LOG_DIR}/${case_id}-${step}.log"

	if "$@" >"${log_file}" 2>&1; then
		emit "${case_id}" "${step}" "pass" "Command succeeded." "$(jq -nc --arg log "${log_file}" '{log:$log}')"
		return 0
	fi

	exit_code="$?"
	fail_step "${case_id}" "${step}" "Command failed." "$(jq -nc --arg log "${log_file}" --argjson exit_code "${exit_code}" '{log:$log,exit_code:$exit_code}')"
	return "${exit_code}"
}

project_name() {
	local safe
	safe="$(printf '%s' "${RUN_ID}" | tr '[:upper:]' '[:lower:]' | tr '.-:' '___' | tr -cd 'a-z0-9_')"
	printf 'cf7tge5pc%s' "${safe}"
}

dc() {
	docker_compose -f "${COMPOSE_FILE}" -p "${CURRENT_PROJECT}" "$@"
}

cleanup_project() {
	if [ -n "${CURRENT_PROJECT}" ] && [ "${KEEP_WORKDIR}" -eq 0 ]; then
		docker_compose -f "${COMPOSE_FILE}" -p "${CURRENT_PROJECT}" down -v --remove-orphans >/dev/null 2>&1 || true
	fi
}

write_summary() {
	local plugin_check_json='[]'
	local infrastructure_failed=false

	if [ -s "${PLUGIN_CHECK_RAW_JSON}" ] && jq -e 'type == "array"' "${PLUGIN_CHECK_RAW_JSON}" >/dev/null 2>&1; then
		plugin_check_json="$(cat "${PLUGIN_CHECK_RAW_JSON}")"
	elif [ "${FAILURES}" -gt 0 ]; then
		infrastructure_failed=true
	fi

	jq -s \
		--arg run_id "${RUN_ID}" \
		--arg workdir "${WORKDIR}" \
		--arg results_dir "${RESULTS_DIR}" \
		--arg wp_version "${WP_VERSION}" \
		--arg wp_cli_image "${WP_CLI_IMAGE}" \
		--arg cf7_version "${CF7_VERSION}" \
		--arg plugin_check_version "${PLUGIN_CHECK_VERSION}" \
		--arg plugin_check_sha256 "${PLUGIN_CHECK_SHA256}" \
		--arg candidate_zip "${CANDIDATE_ZIP}" \
		--arg candidate_sha256 "$(sha256sum "${CANDIDATE_ZIP}" 2>/dev/null | awk '{print $1}')" \
		--argjson plugin_check "${plugin_check_json}" \
		--argjson infrastructure_failed "${infrastructure_failed}" \
		'{
			run_id: $run_id,
			workdir: $workdir,
			results_dir: $results_dir,
			environment: {
				wp_version: $wp_version,
				wp_cli_image: $wp_cli_image,
				contact_form_7_version: $cf7_version,
				plugin_check_version: $plugin_check_version,
				plugin_check_sha256: $plugin_check_sha256,
				candidate_zip: $candidate_zip,
				candidate_sha256: $candidate_sha256,
				uses_repo_docker_compose: false
			},
			plugin_check: {
				raw_json: env.PLUGIN_CHECK_RAW_JSON,
				stdout: env.PLUGIN_CHECK_STDOUT,
				stderr: env.PLUGIN_CHECK_STDERR,
				total: ($plugin_check | length),
				errors: ([ $plugin_check[] | select(.type == "ERROR") ] | length),
				warnings: ([ $plugin_check[] | select(.type == "WARNING") ] | length),
				results: $plugin_check
			},
			total_steps: length,
			passed_steps: ([.[] | select(.status == "pass")] | length),
			failed_steps: ([.[] | select(.status == "fail")] | length),
			failures: [.[] | select(.status == "fail")],
			infrastructure_failed: $infrastructure_failed,
			evidence: .
		}' "${EVIDENCE_JSONL}" > "${SUMMARY_JSON}"
	SUMMARY_WRITTEN=1
}

on_exit() {
	local status="$?"

	if [ -s "${EVIDENCE_JSONL}" ] && [ "${SUMMARY_WRITTEN}" -eq 0 ]; then
		if [ "${status}" -ne 0 ]; then
			emit "run" "exit" "fail" "Script exited before normal completion." "$(jq -nc --argjson exit_code "${status}" '{exit_code:$exit_code}')"
		fi
		write_summary || true
		if [ -f "${SUMMARY_JSON}" ]; then
			echo "E5 Plugin Check evidence: ${SUMMARY_JSON}"
		fi
	fi

	cleanup_project
}

trap on_exit EXIT

require_tools() {
	local missing=()
	for tool in curl jq sha256sum unzip; do
		command -v "${tool}" >/dev/null 2>&1 || missing+=("${tool}")
	done

	if ! command -v docker >/dev/null 2>&1; then
		missing+=("docker")
	fi
	if ! command -v docker-compose >/dev/null 2>&1 && ! docker compose version >/dev/null 2>&1; then
		missing+=("docker-compose or docker compose")
	fi

	if [ "${#missing[@]}" -gt 0 ]; then
		fail_step "preflight" "tools" "Missing required tools: ${missing[*]}"
		exit 2
	fi

	emit "preflight" "tools" "pass" "Required tools are available."
}

normalize_version() {
	printf '%s' "$1" | sed -E 's/^(v|version)-?//'
}

source_header_version() {
	sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "${REPO_ROOT}/plugin-dir/cf7-telegram.php" | head -n 1
}

candidate_header_version() {
	unzip -p "$1" "${PLUGIN_SLUG}/cf7-telegram.php" | sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' | head -n 1
}

prepare_candidate() {
	if [ ! -f "${CANDIDATE_ZIP}" ]; then
		fail_step "candidate" "exists" "Candidate ZIP does not exist; build it before running Plugin Check." "$(jq -nc --arg file "${CANDIDATE_ZIP}" '{file:$file}')"
		exit 2
	fi

	if ! unzip -tq "${CANDIDATE_ZIP}" >/dev/null; then
		fail_step "candidate" "integrity" "Candidate ZIP failed unzip integrity check." "$(jq -nc --arg file "${CANDIDATE_ZIP}" '{file:$file}')"
		exit 2
	fi

	local actual_version expected_version source_version
	actual_version="$(candidate_header_version "${CANDIDATE_ZIP}")"
	source_version="$(source_header_version)"
	expected_version="${EXPECTED_CANDIDATE_VERSION:-${source_version}}"
	expected_version="$(normalize_version "${expected_version}")"

	if [ -z "${actual_version}" ]; then
		fail_step "candidate" "version" "Candidate ZIP plugin header version is not readable." "$(jq -nc --arg file "${CANDIDATE_ZIP}" '{file:$file}')"
		exit 2
	fi

	if [ -z "${expected_version}" ]; then
		fail_step "candidate" "expected_version" "Expected candidate version is not available." "$(jq -nc --arg file "${REPO_ROOT}/plugin-dir/cf7-telegram.php" '{source:$file}')"
		exit 2
	fi

	if [ "${actual_version}" != "${expected_version}" ]; then
		fail_step "candidate" "version" "Candidate ZIP version does not match expected/source version." "$(jq -nc --arg file "${CANDIDATE_ZIP}" --arg expected "${expected_version}" --arg actual "${actual_version}" --arg source "${source_version}" '{file:$file,expected:$expected,actual:$actual,source_version:$source}')"
		exit 2
	fi

	cp "${CANDIDATE_ZIP}" "${ARTIFACT_DIR}/${PLUGIN_SLUG}-candidate.zip"
	emit "candidate" "version" "pass" "Candidate ZIP integrity and expected version verified." "$(jq -nc --arg file "${CANDIDATE_ZIP}" --arg version "${actual_version}" --arg sha256 "$(sha256sum "${CANDIDATE_ZIP}" | awk '{print $1}')" '{file:$file,version:$version,sha256:$sha256}')"
}

prepare_plugin_check() {
	local zip_file="${CACHE_DIR}/plugin-check.${PLUGIN_CHECK_VERSION}.zip"
	local actual_sha

	if [ ! -f "${zip_file}" ]; then
		curl -fsSL "${PLUGIN_CHECK_URL}" -o "${zip_file}"
	fi

	actual_sha="$(sha256sum "${zip_file}" | awk '{print $1}')"
	if [ "${actual_sha}" != "${PLUGIN_CHECK_SHA256}" ]; then
		fail_step "plugin-check" "checksum" "Pinned Plugin Check ZIP checksum mismatch." "$(jq -nc --arg file "${zip_file}" --arg expected "${PLUGIN_CHECK_SHA256}" --arg actual "${actual_sha}" '{file:$file,expected:$expected,actual:$actual}')"
		exit 3
	fi

	cp "${zip_file}" "${ARTIFACT_DIR}/plugin-check.zip"
	emit "plugin-check" "checksum" "pass" "Pinned Plugin Check ZIP verified." "$(jq -nc --arg file "${zip_file}" --arg version "${PLUGIN_CHECK_VERSION}" --arg sha256 "${actual_sha}" '{file:$file,version:$version,sha256:$sha256}')"
}

write_compose_file() {
	cat > "${COMPOSE_FILE}" <<COMPOSE
services:
  db:
    image: mysql:5.7
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
    command: --default-authentication-plugin=mysql_native_password
  cli:
    image: ${WP_CLI_IMAGE}
    depends_on:
      - db
    working_dir: /var/www/html
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress
      WP_CLI_PHP_ARGS: -d memory_limit=512M
    volumes:
      - wp-data:/var/www/html
      - ${ARTIFACT_DIR}:/artifacts:ro
      - ${RUNTIME_DIR}:/runtime
      - ${RESULTS_DIR}:/results

volumes:
  wp-data:
COMPOSE

	emit "preflight" "compose_file" "pass" "Wrote isolated Docker Compose file." "$(jq -nc --arg file "${COMPOSE_FILE}" --arg image "${WP_CLI_IMAGE}" '{file:$file,wp_cli_image:$image}')"
}

wp_run() {
	dc run --rm cli php -d memory_limit=512M /usr/local/bin/wp --allow-root "$@"
}

retry_wp() {
	local tries=30
	local delay=2
	local i
	for i in $(seq 1 "${tries}"); do
		if wp_run "$@"; then
			return 0
		fi
		sleep "${delay}"
	done

	wp_run "$@"
}

setup_site() {
	run_logged "wordpress" "db_up" dc up -d db
	run_logged "wordpress" "core_download" retry_wp core download --path=/var/www/html --version="${WP_VERSION}" --force
	run_logged "wordpress" "config_create" wp_run config create --path=/var/www/html --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db:3306 --skip-check --force
	run_logged "wordpress" "db_wait" retry_wp db check --path=/var/www/html --skip-ssl
	run_logged "wordpress" "core_install" wp_run core install --path=/var/www/html --url="http://${CURRENT_PROJECT}.test" --title="CF7TG Plugin Check" --admin_user=admin --admin_password=admin-password --admin_email=admin@example.test

	if [ "${CF7_VERSION}" = "latest" ]; then
		run_logged "wordpress" "cf7_install" retry_wp plugin install contact-form-7 --activate
	else
		run_logged "wordpress" "cf7_install" retry_wp plugin install contact-form-7 --version="${CF7_VERSION}" --activate
	fi

	run_logged "wordpress" "candidate_install" wp_run plugin install /artifacts/${PLUGIN_SLUG}-candidate.zip --force --activate
	run_logged "wordpress" "plugin_check_install" wp_run plugin install /artifacts/plugin-check.zip --force --activate
}

run_plugin_check() {
	local exit_code=0

	if wp_run \
		--require=/var/www/html/wp-content/plugins/plugin-check/cli.php \
		plugin check "${PLUGIN_SLUG}" \
		--format=strict-json \
		--mode=update \
		--fields=type,code,message,file,line,column,docs \
		>"${PLUGIN_CHECK_STDOUT}" 2>"${PLUGIN_CHECK_STDERR}"; then
		exit_code=0
	else
		exit_code="$?"
	fi

	if jq -e 'type == "array"' "${PLUGIN_CHECK_STDOUT}" >"${PLUGIN_CHECK_RAW_JSON}" 2>/dev/null; then
		:
	elif grep -q 'Success: Checks complete. No errors found.' "${PLUGIN_CHECK_STDOUT}"; then
		printf '[]\n' > "${PLUGIN_CHECK_RAW_JSON}"
	else
		fail_step "plugin-check" "run" "Plugin Check did not produce parseable JSON evidence." "$(jq -nc --arg stdout "${PLUGIN_CHECK_STDOUT}" --arg stderr "${PLUGIN_CHECK_STDERR}" --argjson exit_code "${exit_code}" '{stdout:$stdout,stderr:$stderr,exit_code:$exit_code}')"
		return 1
	fi

	local errors warnings total
	errors="$(jq '[.[] | select(.type == "ERROR")] | length' "${PLUGIN_CHECK_RAW_JSON}")"
	warnings="$(jq '[.[] | select(.type == "WARNING")] | length' "${PLUGIN_CHECK_RAW_JSON}")"
	total="$(jq 'length' "${PLUGIN_CHECK_RAW_JSON}")"

	emit "plugin-check" "run" "pass" "Plugin Check completed and emitted machine-readable evidence." "$(jq -nc --arg raw_json "${PLUGIN_CHECK_RAW_JSON}" --arg stdout "${PLUGIN_CHECK_STDOUT}" --arg stderr "${PLUGIN_CHECK_STDERR}" --argjson exit_code "${exit_code}" --argjson total "${total}" --argjson errors "${errors}" --argjson warnings "${warnings}" '{raw_json:$raw_json,stdout:$stdout,stderr:$stderr,exit_code:$exit_code,total:$total,errors:$errors,warnings:$warnings}')"

	if [ "${errors}" -gt 0 ]; then
		fail_step "plugin-check" "errors" "Plugin Check reported error findings." "$(jq -nc --arg raw_json "${PLUGIN_CHECK_RAW_JSON}" --argjson errors "${errors}" --argjson warnings "${warnings}" '{raw_json:$raw_json,errors:$errors,warnings:$warnings}')"
		return 1
	fi

	return 0
}

export PLUGIN_CHECK_RAW_JSON PLUGIN_CHECK_STDOUT PLUGIN_CHECK_STDERR

require_tools
prepare_candidate
prepare_plugin_check
write_compose_file

CURRENT_PROJECT="$(project_name)"
cleanup_project

setup_site
run_plugin_check || true
write_summary

echo "E5 Plugin Check evidence: ${SUMMARY_JSON}"

if [ "${FAILURES}" -gt 0 ]; then
	echo "E5 Plugin Check gate failed with ${FAILURES} failing step(s)." >&2
	exit 1
fi

echo "E5 Plugin Check gate passed."
