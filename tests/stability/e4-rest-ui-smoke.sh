#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SOURCE_MANIFEST="${SCRIPT_DIR}/e1-version-sources.json"

PLUGIN_SLUG="${PLUGIN_SLUG:-cf7-telegram}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
WORKDIR="${CF7TG_E4_SMOKE_WORKDIR:-$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e4-smoke-${RUN_ID}.XXXXXX")}"
RESULTS_DIR="${CF7TG_E4_SMOKE_RESULTS_DIR:-${WORKDIR}/results}"
LOG_DIR="${RESULTS_DIR}/logs"
ARTIFACT_DIR="${WORKDIR}/artifacts"
RUNTIME_DIR="${WORKDIR}/runtime"
COMPOSE_FILE="${WORKDIR}/docker-compose.e4-smoke.yml"
EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
SUMMARY_JSON="${RESULTS_DIR}/summary.json"
SMOKE_JSON="${RESULTS_DIR}/e4-rest-ui-smoke.json"
SMOKE_STDERR="${LOG_DIR}/e4-rest-ui-smoke.stderr"
WP_VERSION="${CF7TG_E4_SMOKE_WP_VERSION:-$(jq -r '.wordpress.default_core_version' "${SOURCE_MANIFEST}")}"
WP_CLI_IMAGE="${CF7TG_E4_SMOKE_WP_CLI_IMAGE:-$(jq -r '.wordpress.default_cli_image' "${SOURCE_MANIFEST}")}"
CF7_VERSION="${CF7TG_E4_SMOKE_CF7_VERSION:-$(jq -r '.dependencies.contact_form_7.default_version' "${SOURCE_MANIFEST}")}"
CANDIDATE_ZIP="${CF7TG_CANDIDATE_ZIP:-${REPO_ROOT}/dist/${PLUGIN_SLUG}-wp-plugin.zip}"
EXPECTED_CANDIDATE_VERSION="${CF7TG_EXPECTED_CANDIDATE_VERSION:-}"
KEEP_WORKDIR=0
FAILURES=0
SUMMARY_WRITTEN=0
CURRENT_PROJECT=""

usage() {
	cat <<'USAGE'
Usage: tests/stability/e4-rest-ui-smoke.sh [options]

Options:
  --candidate-zip <path>     Candidate plugin ZIP to install and test.
  --expected-version <ver>   Expected candidate plugin version. Defaults to source header version.
  --workdir <path>           Use an explicit temporary work directory.
  --keep-workdir             Keep Docker containers/volumes for debugging.
  -h, --help                 Show this help.

Environment:
  CF7TG_CANDIDATE_ZIP               Candidate ZIP path.
  CF7TG_EXPECTED_CANDIDATE_VERSION  Expected candidate version.
  CF7TG_E4_SMOKE_WP_VERSION         WordPress core version.
  CF7TG_E4_SMOKE_WP_CLI_IMAGE       WP-CLI Docker image.
  CF7TG_E4_SMOKE_CF7_VERSION        Contact Form 7 version.
  CF7TG_E4_SMOKE_RESULTS_DIR        Evidence output directory.
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
			RESULTS_DIR="${CF7TG_E4_SMOKE_RESULTS_DIR:-${WORKDIR}/results}"
			LOG_DIR="${RESULTS_DIR}/logs"
			ARTIFACT_DIR="${WORKDIR}/artifacts"
			RUNTIME_DIR="${WORKDIR}/runtime"
			COMPOSE_FILE="${WORKDIR}/docker-compose.e4-smoke.yml"
			EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
			SUMMARY_JSON="${RESULTS_DIR}/summary.json"
			SMOKE_JSON="${RESULTS_DIR}/e4-rest-ui-smoke.json"
			SMOKE_STDERR="${LOG_DIR}/e4-rest-ui-smoke.stderr"
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

mkdir -p "${RESULTS_DIR}" "${LOG_DIR}" "${ARTIFACT_DIR}" "${RUNTIME_DIR}"
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
	printf 'cf7tge4smoke%s' "${safe}"
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
	local smoke_json='null'
	local smoke_failed=true

	if [ -s "${SMOKE_JSON}" ] && jq -e 'type == "object"' "${SMOKE_JSON}" >/dev/null 2>&1; then
		smoke_json="$(cat "${SMOKE_JSON}")"
		smoke_failed="$(jq -r '.summary.failed > 0' "${SMOKE_JSON}")"
	elif [ "${FAILURES}" -eq 0 ]; then
		fail_step "smoke" "evidence" "Smoke did not produce parseable JSON evidence." "$(jq -nc --arg file "${SMOKE_JSON}" '{file:$file}')"
	fi

	jq -s \
		--arg run_id "${RUN_ID}" \
		--arg workdir "${WORKDIR}" \
		--arg results_dir "${RESULTS_DIR}" \
		--arg wp_version "${WP_VERSION}" \
		--arg wp_cli_image "${WP_CLI_IMAGE}" \
		--arg cf7_version "${CF7_VERSION}" \
		--arg candidate_zip "${CANDIDATE_ZIP}" \
		--arg candidate_sha256 "$(sha256sum "${CANDIDATE_ZIP}" 2>/dev/null | awk '{print $1}')" \
		--argjson smoke "${smoke_json}" \
		--argjson smoke_failed "${smoke_failed}" \
		'{
			run_id: $run_id,
			workdir: $workdir,
			results_dir: $results_dir,
			environment: {
				wp_version: $wp_version,
				wp_cli_image: $wp_cli_image,
				contact_form_7_version: $cf7_version,
				candidate_zip: $candidate_zip,
				candidate_sha256: $candidate_sha256,
				uses_repo_docker_compose: false
			},
			smoke: $smoke,
			total_steps: length,
			passed_steps: ([.[] | select(.status == "pass")] | length),
			failed_steps: ([.[] | select(.status == "fail")] | length),
			failures: [.[] | select(.status == "fail")],
			smoke_failed: $smoke_failed,
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
			echo "E4 REST/UI smoke evidence: ${SUMMARY_JSON}"
		fi
	fi

	cleanup_project
}

trap on_exit EXIT

require_tools() {
	local missing=()
	for tool in jq sha256sum unzip; do
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
		fail_step "candidate" "exists" "Candidate ZIP does not exist; build it before running E4 smoke." "$(jq -nc --arg file "${CANDIDATE_ZIP}" '{file:$file}')"
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
      - ${SCRIPT_DIR}:/e4-tests:ro

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
	run_logged "wordpress" "core_install" wp_run core install --path=/var/www/html --url="http://${CURRENT_PROJECT}.test" --title="CF7TG E4 REST UI Smoke" --admin_user=admin --admin_password=admin-password --admin_email=admin@example.test

	if [ "${CF7_VERSION}" = "latest" ]; then
		run_logged "wordpress" "cf7_install" retry_wp plugin install contact-form-7 --activate
	else
		run_logged "wordpress" "cf7_install" retry_wp plugin install contact-form-7 --version="${CF7_VERSION}" --activate
	fi

	run_logged "wordpress" "candidate_install" wp_run plugin install "/artifacts/${PLUGIN_SLUG}-candidate.zip" --force --activate
}

run_smoke() {
	local exit_code=0

	if wp_run eval-file /e4-tests/wp-e4-rest-ui-smoke.php >"${SMOKE_JSON}" 2>"${SMOKE_STDERR}"; then
		exit_code=0
	else
		exit_code="$?"
	fi

	if ! jq -e 'type == "object" and (.summary.failed | type == "number")' "${SMOKE_JSON}" >/dev/null 2>&1; then
		fail_step "smoke" "run" "E4 smoke did not produce parseable JSON evidence." "$(jq -nc --arg stdout "${SMOKE_JSON}" --arg stderr "${SMOKE_STDERR}" --argjson exit_code "${exit_code}" '{stdout:$stdout,stderr:$stderr,exit_code:$exit_code}')"
		return 1
	fi

	local total passed failed
	total="$(jq '.summary.total' "${SMOKE_JSON}")"
	passed="$(jq '.summary.passed' "${SMOKE_JSON}")"
	failed="$(jq '.summary.failed' "${SMOKE_JSON}")"

	emit "smoke" "run" "pass" "E4 smoke completed and emitted machine-readable evidence." "$(jq -nc --arg result "${SMOKE_JSON}" --arg stderr "${SMOKE_STDERR}" --argjson exit_code "${exit_code}" --argjson total "${total}" --argjson passed "${passed}" --argjson failed "${failed}" '{result:$result,stderr:$stderr,exit_code:$exit_code,total:$total,passed:$passed,failed:$failed}')"

	if [ "${failed}" -gt 0 ]; then
		fail_step "smoke" "assertions" "E4 smoke reported failing assertions." "$(jq -nc --arg result "${SMOKE_JSON}" --argjson failed "${failed}" '{result:$result,failed:$failed}')"
		return 1
	fi

	if [ "${exit_code}" -ne 0 ]; then
		fail_step "smoke" "exit_code" "E4 smoke exited non-zero despite zero assertion failures." "$(jq -nc --arg result "${SMOKE_JSON}" --argjson exit_code "${exit_code}" '{result:$result,exit_code:$exit_code}')"
		return 1
	fi

	return 0
}

require_tools
prepare_candidate
write_compose_file

CURRENT_PROJECT="$(project_name)"
cleanup_project

setup_site
run_smoke || true
write_summary

echo "E4 REST/UI smoke evidence: ${SUMMARY_JSON}"

if [ "${FAILURES}" -gt 0 ]; then
	echo "E4 REST/UI smoke failed with ${FAILURES} failing step(s)." >&2
	exit 1
fi

echo "E4 REST/UI smoke passed."
