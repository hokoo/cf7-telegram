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
ABILITY_JSON="${RESULTS_DIR}/r1-abilities-smoke.json"
ABILITY_STDERR="${LOG_DIR}/r1-abilities-smoke.stderr"
WP_VERSION="${CF7TG_E4_SMOKE_WP_VERSION:-$(jq -r '.wordpress.default_core_version' "${SOURCE_MANIFEST}")}"
WP_CLI_IMAGE="${CF7TG_E4_SMOKE_WP_CLI_IMAGE:-$(jq -r '.wordpress.default_cli_image' "${SOURCE_MANIFEST}")}"
CF7_VERSION="${CF7TG_E4_SMOKE_CF7_VERSION:-$(jq -r '.dependencies.contact_form_7.default_version' "${SOURCE_MANIFEST}")}"
WEB_PORT="${CF7TG_E4_SMOKE_WEB_PORT:-$(shuf -i 20000-45000 -n 1)}"
BASE_URL="http://127.0.0.1:${WEB_PORT}"
WP_WEB_IMAGE="${CF7TG_E4_SMOKE_WP_WEB_IMAGE:-wordpress:${WP_VERSION}-php8.3-apache}"
CURRENT_WP_VERSION="$(jq -r '.support_matrix[] | select(.label == "current" and .required == true) | .wordpress_version' "${SOURCE_MANIFEST}")"
MCP_REQUIRED="${CF7TG_E4_MCP_REQUIRED:-$([ "${WP_VERSION}" = "${CURRENT_WP_VERSION}" ] && echo 1 || echo 0)}"
MCP_PROTOCOL=2025-11-25
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
  CF7TG_E4_SMOKE_WEB_PORT           Isolated HTTP port.
  CF7TG_E4_SMOKE_WP_WEB_IMAGE       Matching Apache WordPress image.
  CF7TG_E4_MCP_REQUIRED             Set to 1 for official MCP Adapter 0.7.1 STDIO smoke.
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
			ABILITY_JSON="${RESULTS_DIR}/r1-abilities-smoke.json"
			ABILITY_STDERR="${LOG_DIR}/r1-abilities-smoke.stderr"
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
	else
		exit_code="$?"
	fi
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
	local ability_json='null'
	local ability_failed=true

	if [ -s "${SMOKE_JSON}" ] && jq -e 'type == "object"' "${SMOKE_JSON}" >/dev/null 2>&1; then
		smoke_json="$(cat "${SMOKE_JSON}")"
		smoke_failed="$(jq -r '.summary.failed > 0' "${SMOKE_JSON}")"
	elif [ "${FAILURES}" -eq 0 ]; then
		fail_step "smoke" "evidence" "Smoke did not produce parseable JSON evidence." "$(jq -nc --arg file "${SMOKE_JSON}" '{file:$file}')"
	fi
	if [ -s "${ABILITY_JSON}" ] && jq -e 'type == "object" and (.checks | type == "array")' "${ABILITY_JSON}" >/dev/null 2>&1; then
		ability_json="$(cat "${ABILITY_JSON}")"
		ability_failed="$(jq -r '.summary.failed > 0' "${ABILITY_JSON}")"
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
		--argjson abilities "${ability_json}" \
		--argjson abilities_failed "${ability_failed}" \
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
			abilities: $abilities,
			total_steps: length,
			passed_steps: ([.[] | select(.status == "pass")] | length),
			failed_steps: ([.[] | select(.status == "fail")] | length),
			failures: [.[] | select(.status == "fail")],
			smoke_failed: $smoke_failed,
			abilities_failed: $abilities_failed,
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
	for tool in jq sha256sum unzip curl shuf; do
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
	if [ "${WP_VERSION}" = "${CURRENT_WP_VERSION}" ] && [ "${MCP_REQUIRED}" != 1 ]; then
		fail_step "preflight" "mcp_required" "Current Core row requires official MCP smoke."
		exit 2
	fi
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
  web:
    image: ${WP_WEB_IMAGE}
    depends_on:
      - db
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress
    ports:
      - "127.0.0.1:${WEB_PORT}:80"
    volumes:
      - wp-data:/var/www/html

volumes:
  wp-data:
COMPOSE

	emit "preflight" "compose_file" "pass" "Wrote isolated Docker Compose file." "$(jq -nc --arg file "${COMPOSE_FILE}" --arg image "${WP_CLI_IMAGE}" '{file:$file,wp_cli_image:$image}')"
}

wp_run() {
	dc run --rm cli php -d memory_limit=512M /usr/local/bin/wp --allow-root "$@"
}

hidden_cap_denied() {
	wp_run --user=admin eval 'exit(current_user_can("read_post", (int) get_option("cf7tg_r1_hidden_post_id")) ? 1 : 0);'
}

wp_mcp() {
	dc run -T --rm cli php -d memory_limit=512M /usr/local/bin/wp --allow-root mcp-adapter serve --server=mcp-adapter-default-server --user="$1"
}

retry_http() {
	local i
	for i in $(seq 1 30); do
		if curl --silent --show-error --fail "${BASE_URL}/?rest_route=/" >/dev/null 2>&1; then return 0; fi
		sleep 2
	done
	return 1
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
	run_logged "wordpress" "core_install" wp_run core install --path=/var/www/html --url="${BASE_URL}" --title="CF7TG E4 REST UI Smoke" --admin_user=admin --admin_password=admin-password --admin_email=admin@example.test

	if [ "${CF7_VERSION}" = "latest" ]; then
		run_logged "wordpress" "cf7_install" retry_wp plugin install contact-form-7 --activate
	else
		run_logged "wordpress" "cf7_install" retry_wp plugin install contact-form-7 --version="${CF7_VERSION}" --activate
	fi

	run_logged "wordpress" "candidate_install" wp_run plugin install "/artifacts/${PLUGIN_SLUG}-candidate.zip" --force --activate
	run_logged "wordpress" "local_auth" wp_run config set WP_ENVIRONMENT_TYPE local
	run_logged "wordpress" "web_up" dc up -d web
	run_logged "wordpress" "web_ready" retry_http
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

run_abilities() {
	local exit_code=0
	if wp_run eval-file /e4-tests/wp-r1-abilities-smoke.php >"${ABILITY_JSON}" 2>"${ABILITY_STDERR}"; then
		exit_code=0
	else
		exit_code="$?"
	fi
	if ! jq -e 'type == "object" and (.checks | type == "array") and (.summary.failed | type == "number")' "${ABILITY_JSON}" >/dev/null 2>&1; then
		fail_step "r1" "core-evidence" "Real Core fixture did not produce parseable evidence." "$(jq -nc --arg stdout "${ABILITY_JSON}" --arg stderr "${ABILITY_STDERR}" --argjson exit_code "${exit_code}" '{stdout:$stdout,stderr:$stderr,exit_code:$exit_code}')"
		return 1
	fi
	if jq -e --arg wp "${WP_VERSION}" --arg cf7 "${CF7_VERSION}" '.wordpress == $wp and .contact_form_7 == $cf7 and (.php | startswith("8.3."))' "${ABILITY_JSON}" >/dev/null; then
		emit "r1" "versions" "pass" "Exact Core, CF7, and PHP feature row versions verified." "$(jq -c '{wordpress,php,contact_form_7}' "${ABILITY_JSON}")"
	else
		fail_step "r1" "versions" "Feature row runtime versions do not match requested versions."
	fi
	local id
	for id in r1-core-available r1-registered-two r1-core-schema r1-visible-pagination r1-php-rest-parity r1-core-input-validation r1-rest-method-validation r1-rest-normalization-scope r1-numeric-string-input-parity r1-four-resources r1-topic-identity r1-relation-statuses r1-two-bot-cardinality r1-token-precedence r1-diagnose-status r1-diagnose-rest-parity r1-core-nested-output-validation r1-all-diagnosis-codes r1-offline-warning-only r1-mixed-and-corrupt-graph r1-readonly-repeated r1-secret-redaction r1-anonymous-denied r1-subscriber-denied r1-diagnose-hidden-graph r1-hidden-relation; do
		if ! jq -e --arg id "${id}" '[.checks[] | select(.id == $id)] | length == 1 and .[0].status == "pass"' "${ABILITY_JSON}" >/dev/null; then
			fail_step "r1" "${id}" "Required Core assertion missing, skipped, duplicated, or failed." "$(jq -c --arg id "${id}" '[.checks[] | select(.id == $id)] | first | .extra // {}' "${ABILITY_JSON}")"
		fi
	done
	if [ "${exit_code}" -ne 0 ] || [ "$(jq -r '.summary.failed' "${ABILITY_JSON}")" -ne 0 ]; then
		fail_step "r1" "core-exit" "Real Core fixture failed." "$(jq -nc --argjson exit_code "${exit_code}" --arg file "${ABILITY_JSON}" '{exit_code:$exit_code,file:$file}')"
		return 1
	fi
	emit "r1" "core-abilities" "pass" "Required real Core assertions passed." "$(jq -nc --arg file "${ABILITY_JSON}" '{file:$file}')"
}

run_early_probe() {
	if ! run_logged "r1" "mu-probe-install" wp_run eval 'if (!is_dir(WPMU_PLUGIN_DIR)) wp_mkdir_p(WPMU_PLUGIN_DIR); if (!copy("/e4-tests/wp-r1-mu-probes.php", WPMU_PLUGIN_DIR . "/cf7tg-r1-probes.php")) exit(1);'; then return 1; fi
	if dc run -T --rm -e CF7TG_R1_EARLY_PROBE=1 cli php -d memory_limit=512M /usr/local/bin/wp --allow-root eval 'exit(!empty($GLOBALS["cf7tg_r1_early_probe"]) ? 0 : 1);' >"${LOG_DIR}/r1-early-probe.log" 2>&1; then
		emit "r1" "early-not-ready" "pass" "Direct and native Core executions of both abilities return cf7tg_not_ready 503 before init."
	else
		fail_step "r1" "early-not-ready" "Early bootstrap callbacks did not return cf7tg_not_ready 503."
	fi
}

http_request() {
	local user="$1" input="$2" name="$3" method="$4" output="$5"
	local url="${BASE_URL}/"
	local auth=()
	if [ -n "${user}" ]; then auth=(--user "${user}"); fi
	curl --silent --show-error --max-time 20 --output "${output}" --write-out '%{http_code}' \
		--request "${method}" "${url}" --get \
		--data-urlencode "rest_route=/wp-abilities/v1/abilities/cf7-telegram/${name}/run" \
		--data-urlencode "input=${input}" "${auth[@]}"
}

run_http() {
	local password status admin_out="${RESULTS_DIR}/r1-http-admin.json"
	local diagnosis_out="${RESULTS_DIR}/r1-http-diagnosis.json"
	local bad_method_out="${RESULTS_DIR}/r1-http-bad-method.json"
	local anonymous_out="${RESULTS_DIR}/r1-http-anonymous.json"
	local subscriber_out="${RESULTS_DIR}/r1-http-subscriber.json"
	password="$(wp_run user application-password create admin R1E4Smoke --porcelain 2>"${LOG_DIR}/r1-app-password.stderr" | tr -d '\r\n')"
	if [ -z "${password}" ]; then fail_step "r1" "http-auth-setup" "Could not create isolated Application Password."; return 1; fi
	status="$(http_request "admin:${password}" '{"resource":"forms","per_page":1}' get-configuration GET "${admin_out}")"
	if [ "${status}" = 200 ] && jq -e --slurpfile expected "${ABILITY_JSON}" '. == $expected[0].expected["get-configuration"]' "${admin_out}" >/dev/null; then
		emit "r1" "http-admin" "pass" "Authenticated real HTTP GET executes the Core ability."
	else fail_step "r1" "http-admin" "Authenticated real HTTP GET failed." "$(jq -nc --arg status "${status}" --arg file "${admin_out}" '{status:$status,file:$file}')"; fi
	local form_id
	form_id="$(jq -r '.fixture.form_id' "${ABILITY_JSON}")"
	status="$(http_request "admin:${password}" "$(jq -nc --argjson id "${form_id}" '{form_id:$id}')" diagnose-form GET "${diagnosis_out}")"
	if [ "${status}" = 200 ] && jq -e --slurpfile expected "${ABILITY_JSON}" '. == $expected[0].expected["diagnose-form"]' "${diagnosis_out}" >/dev/null; then
		emit "r1" "http-diagnose" "pass" "Authenticated real HTTP GET executes diagnosis."
	else fail_step "r1" "http-diagnose" "Authenticated real HTTP diagnosis failed."; fi
	local numeric_page_out="${RESULTS_DIR}/r1-http-numeric-page.json" numeric_per_page_out="${RESULTS_DIR}/r1-http-numeric-per-page.json" numeric_form_out="${RESULTS_DIR}/r1-http-numeric-form-id.json"
	local numeric_page_status numeric_per_page_status numeric_form_status numeric_page_code numeric_per_page_code numeric_form_code php_page_code php_per_page_code php_form_code numeric_extra
	numeric_page_status="$(http_request "admin:${password}" '{"resource":"forms","page":"1"}' get-configuration GET "${numeric_page_out}")"
	numeric_per_page_status="$(http_request "admin:${password}" '{"resource":"forms","per_page":"1"}' get-configuration GET "${numeric_per_page_out}")"
	numeric_form_status="$(http_request "admin:${password}" "$(jq -nc --arg id "${form_id}" '{form_id:$id}')" diagnose-form GET "${numeric_form_out}")"
	numeric_page_code="$(jq -r '.code // ""' "${numeric_page_out}")"
	numeric_per_page_code="$(jq -r '.code // ""' "${numeric_per_page_out}")"
	numeric_form_code="$(jq -r '.code // ""' "${numeric_form_out}")"
	php_page_code="$(jq -r '.numeric_input.page.php_code // ""' "${ABILITY_JSON}")"
	php_per_page_code="$(jq -r '.numeric_input.per_page.php_code // ""' "${ABILITY_JSON}")"
	php_form_code="$(jq -r '.numeric_input.form_id.php_code // ""' "${ABILITY_JSON}")"
	numeric_extra="$(jq -nc --arg page_status "${numeric_page_status}" --arg page_code "${numeric_page_code}" --arg php_page_code "${php_page_code}" --arg per_page_status "${numeric_per_page_status}" --arg per_page_code "${numeric_per_page_code}" --arg php_per_page_code "${php_per_page_code}" --arg form_status "${numeric_form_status}" --arg form_code "${numeric_form_code}" --arg php_form_code "${php_form_code}" '{page:{http_status:$page_status,http_code:$page_code,php_code:$php_page_code},per_page:{http_status:$per_page_status,http_code:$per_page_code,php_code:$php_per_page_code},form_id:{http_status:$form_status,http_code:$form_code,php_code:$php_form_code}}')"
	if [ "${numeric_page_status}" = 400 ] && [ "${numeric_per_page_status}" = 400 ] && [ "${numeric_form_status}" = 400 ] &&
		[ -n "${php_page_code}" ] && [ -n "${php_per_page_code}" ] && [ -n "${php_form_code}" ] &&
		[ "${numeric_page_code}" = "${php_page_code}" ] && [ "${numeric_per_page_code}" = "${php_per_page_code}" ] && [ "${numeric_form_code}" = "${php_form_code}" ]; then
		emit "r1" "http-numeric-string-input" "pass" "Real HTTP rejects numeric strings with the same codes as PHP." "${numeric_extra}"
	else fail_step "r1" "http-numeric-string-input" "Real HTTP numeric-string input differs from PHP or was accepted." "${numeric_extra}"; fi
	status="$(http_request "admin:${password}" '{"resource":"forms"}' get-configuration POST "${bad_method_out}")"
	if [ "${status}" = 405 ] && jq -e '.code == "rest_ability_invalid_method"' "${bad_method_out}" >/dev/null; then emit "r1" "http-bad-method" "pass" "Core rejects POST for readonly ability with 405."; else fail_step "r1" "http-bad-method" "Core did not reject POST with rest_ability_invalid_method 405."; fi
	local early_list="${RESULTS_DIR}/r1-http-not-ready-list.json" early_diagnose="${RESULTS_DIR}/r1-http-not-ready-diagnose.json"
	if ! wp_run option update cf7tg_r1_force_not_ready 1 >"${LOG_DIR}/r1-http-not-ready-setup.log" 2>&1; then fail_step "r1" "http-not-ready" "Could not set isolated readiness probe."; return 1; fi
	local early_list_status early_diagnose_status
	early_list_status="$(http_request "admin:${password}" '{"resource":"forms"}' get-configuration GET "${early_list}")"
	early_diagnose_status="$(http_request "admin:${password}" "$(jq -nc --argjson id "${form_id}" '{form_id:$id}')" diagnose-form GET "${early_diagnose}")"
	if [ "${early_list_status}" = 503 ] && [ "${early_diagnose_status}" = 503 ] &&
		jq -e -s 'length == 2 and all(.[]; .code == "cf7tg_not_ready")' "${early_list}" "${early_diagnose}" >/dev/null; then
		emit "r1" "http-not-ready" "pass" "Authenticated real HTTP returns 503 from both abilities when models are unavailable."
	else fail_step "r1" "http-not-ready" "Real HTTP readiness guard did not return cf7tg_not_ready 503."; fi
	wp_run option delete cf7tg_r1_force_not_ready >"${LOG_DIR}/r1-http-not-ready-clear.log" 2>&1 || fail_step "r1" "http-not-ready-clear" "Could not clear isolated readiness probe."
	status="$(http_request '' '{"resource":"forms"}' get-configuration GET "${anonymous_out}")"
	local anonymous_diagnose="${RESULTS_DIR}/r1-http-anonymous-diagnose.json" anonymous_diagnose_status
	anonymous_diagnose_status="$(http_request '' "$(jq -nc --argjson id "${form_id}" '{form_id:$id}')" diagnose-form GET "${anonymous_diagnose}")"
	if { [ "${status}" = 401 ] || [ "${status}" = 403 ]; } &&
		{ [ "${anonymous_diagnose_status}" = 401 ] || [ "${anonymous_diagnose_status}" = 403 ]; } &&
		jq -e -s 'length == 2 and all(.[]; .code == "rest_ability_cannot_execute")' "${anonymous_out}" "${anonymous_diagnose}" >/dev/null; then
		emit "r1" "http-anonymous" "pass" "Anonymous real HTTP request denied."
	else fail_step "r1" "http-anonymous" "Anonymous real HTTP request was not denied." "$(jq -nc --arg status "${status}" '{status:$status}')"; fi
	local subscriber_password
	subscriber_password="$(wp_run user application-password create r1_subscriber R1E4Smoke --porcelain 2>"${LOG_DIR}/r1-subscriber-password.stderr" | tr -d '\r\n')"
	if [ -z "${subscriber_password}" ]; then fail_step "r1" "http-subscriber" "Could not create subscriber Application Password."; return 1; fi
	status="$(http_request "r1_subscriber:${subscriber_password}" '{"resource":"forms"}' get-configuration GET "${subscriber_out}")"
	local subscriber_diagnose="${RESULTS_DIR}/r1-http-subscriber-diagnose.json" subscriber_diagnose_status
	subscriber_diagnose_status="$(http_request "r1_subscriber:${subscriber_password}" "$(jq -nc --argjson id "${form_id}" '{form_id:$id}')" diagnose-form GET "${subscriber_diagnose}")"
	if [ "${status}" = 403 ] && [ "${subscriber_diagnose_status}" = 403 ] &&
		jq -e -s 'length == 2 and all(.[]; .code == "rest_ability_cannot_execute" or .code == "cf7tg_forbidden")' "${subscriber_out}" "${subscriber_diagnose}" >/dev/null; then
		emit "r1" "http-subscriber" "pass" "Authenticated subscriber denied by ability capability."
	else fail_step "r1" "http-subscriber" "Authenticated subscriber was not denied with 403." "$(jq -nc --arg status "${status}" '{status:$status}')"; fi
	local hidden_id hidden_out="${RESULTS_DIR}/r1-http-hidden.json"
	hidden_id="$(jq -r '.fixture.hidden_recipient_id' "${ABILITY_JSON}")"
	if ! wp_run option update cf7tg_r1_hidden_post_id "${hidden_id}" >"${LOG_DIR}/r1-http-hide.log" 2>&1; then fail_step "r1" "http-hidden-graph" "Could not set isolated object capability probe."; return 1; fi
	if ! hidden_cap_denied >"${LOG_DIR}/r1-http-hidden-cap.log" 2>&1; then
		fail_step "r1" "http-hidden-graph" "Isolated object capability filter did not deny read_post."
		wp_run option delete cf7tg_r1_hidden_post_id >/dev/null 2>&1 || true
		return 1
	fi
	status="$(http_request "admin:${password}" "$(jq -nc --argjson id "${form_id}" '{form_id:$id}')" diagnose-form GET "${hidden_out}")"
	if [ "${status}" = 403 ] && jq -e '.code == "cf7tg_forbidden"' "${hidden_out}" >/dev/null; then
		emit "r1" "http-hidden-graph" "pass" "Admin diagnosis rejects a hidden graph object over real HTTP."
	else fail_step "r1" "http-hidden-graph" "Real HTTP diagnosis exposed a hidden graph."; fi
	wp_run option delete cf7tg_r1_hidden_post_id >"${LOG_DIR}/r1-http-unhide.log" 2>&1 || fail_step "r1" "http-unhide" "Could not clear isolated capability probe."
	unset password subscriber_password
}

mcp_call() {
	local user="$1" request="$2" output="$3"
	local initialize
	initialize="$(jq -nc --arg version "${MCP_PROTOCOL}" '{jsonrpc:"2.0",id:0,method:"initialize",params:{protocolVersion:$version,capabilities:{},clientInfo:{name:"cf7tg-r1-smoke",version:"1.0"}}}')" || return 1
	printf '%s\n%s\n%s\n' "${initialize}" '{"jsonrpc":"2.0","method":"notifications/initialized"}' "${request}" | wp_mcp "${user}" >"${output}.raw" 2>"${output}.stderr" || return 1
	jq -e -s --arg version "${MCP_PROTOCOL}" '[.[] | select(.id == 0)] | length == 1 and .[0].result.protocolVersion == $version' "${output}.raw" >/dev/null || return 1
	jq -c 'select(.id == 1)' "${output}.raw" >"${output}" || return 1
	jq -e -s 'length == 1 and .[0].jsonrpc == "2.0" and (.[0].result != null or .[0].error != null)' "${output}" >/dev/null || return 1
}

mcp_permission_denied() {
	jq -e --slurpfile denied "${RESULTS_DIR}/r1-http-subscriber.json" '(.result.isError == true and [.result.content[]? | select(.type == "text") | .text] == ["Permission denied"]) or
		(.error.message // "" | test("permission|forbidden|capability"; "i")) or
		([.result.structuredContent, (.result.content[]?.text | fromjson?)] |
			any(.success == false and ((.error | tostring | test("permission|forbidden|capability"; "i")) or .error == $denied[0].message)))' "$1" >/dev/null
}

run_mcp() {
	if [ "${MCP_REQUIRED}" != 1 ]; then return 0; fi
	if ! run_logged "r1" "mcp-install" wp_run plugin install mcp-adapter --version=0.7.1 --activate; then return 1; fi
	local version
	version="$(wp_run plugin get mcp-adapter --field=version | tr -d '\r\n')"
	if [ "${version}" = 0.7.1 ]; then emit "r1" "mcp-version" "pass" "Official MCP Adapter 0.7.1 active." "$(jq -nc --arg version "${version}" --arg protocol "${MCP_PROTOCOL}" '{adapter_version:$version,protocol_version:$protocol,transport:"stdio"}')"; else fail_step "r1" "mcp-version" "MCP Adapter version mismatch."; return 1; fi
	local prefix="${RESULTS_DIR}/r1-mcp"
	local list='{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
	if mcp_call admin "${list}" "${prefix}-tools.json" && jq -e '[.result.tools[]?.name] | index("mcp-adapter-discover-abilities") != null and index("mcp-adapter-get-ability-info") != null and index("mcp-adapter-execute-ability") != null' "${prefix}-tools.json" >/dev/null; then
		emit "r1" "mcp-tools" "pass" "Official STDIO transport exposes three default meta-tools."
	else fail_step "r1" "mcp-tools" "Official STDIO meta-tools unavailable."; return 1; fi
	local discover='{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}'
	if mcp_call admin "${discover}" "${prefix}-discover.json" && jq -e '.result.isError != true and (([.. | objects | .name? // empty | select(startswith("cf7-telegram/"))] + [.result.content[]?.text | fromjson? | .. | objects | .name? // empty | select(startswith("cf7-telegram/"))] | unique) == ["cf7-telegram/diagnose-form","cf7-telegram/get-configuration"])' "${prefix}-discover.json" >/dev/null; then
		emit "r1" "mcp-discover" "pass" "Official MCP discovery contains exactly two plugin abilities."
	else fail_step "r1" "mcp-discover" "Official MCP discovery did not find exactly two abilities."; return 1; fi
	local name request output
	for name in get-configuration diagnose-form; do
		request="$(jq -nc --arg name "cf7-telegram/${name}" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"mcp-adapter-get-ability-info",arguments:{ability_name:$name}}}')"
		output="${prefix}-info-${name}.json"
		if mcp_call admin "${request}" "${output}" && jq -e '.result.isError != true and (.result | tostring | contains("input_schema") and contains("output_schema"))' "${output}" >/dev/null; then
			emit "r1" "mcp-info-${name}" "pass" "Official MCP info exposes the ability schema."
		else fail_step "r1" "mcp-info-${name}" "Official MCP info missing or errored."; fi
	done
	local form_id
	form_id="$(jq -r '.fixture.form_id' "${ABILITY_JSON}")"
	for name in get-configuration diagnose-form; do
		local parameters
		if [ "${name}" = get-configuration ]; then parameters='{"resource":"forms","per_page":1}'; else parameters="$(jq -nc --argjson id "${form_id}" '{form_id:$id}')"; fi
		request="$(jq -nc --arg name "cf7-telegram/${name}" --argjson input "${parameters}" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"mcp-adapter-execute-ability",arguments:{ability_name:$name,parameters:$input}}}')"
		output="${prefix}-execute-${name}.json"
		if mcp_call admin "${request}" "${output}" &&
			jq -c '[.result.structuredContent, (.result.content[]?.text | fromjson?)] | map(select(type == "object") | if .success == true and (.data | type == "object") then .data elif .schema_version == 1 then . else empty end) | first' "${output}" >"${output}.data" &&
			jq -e --slurpfile expected "${ABILITY_JSON}" --arg name "${name}" '. == $expected[0].expected[$name]' "${output}.data" >/dev/null; then
			emit "r1" "mcp-execute-${name}" "pass" "Official MCP STDIO executes the ability."
		else fail_step "r1" "mcp-execute-${name}" "Official MCP result differs from real PHP execute output."; fi
		if mcp_call r1_subscriber "${request}" "${prefix}-denied-${name}.json" && mcp_permission_denied "${prefix}-denied-${name}.json"; then
			emit "r1" "mcp-denied-${name}" "pass" "Official MCP STDIO denies subscriber execution."
		else fail_step "r1" "mcp-denied-${name}" "Official MCP subscriber execution was not denied."; fi
	done
	local numeric_case numeric_parameters numeric_message numeric_failures=""
	for numeric_case in page per_page form_id; do
		case "${numeric_case}" in
			page) numeric_parameters='{"resource":"forms","page":"1"}'; name=get-configuration ;;
			per_page) numeric_parameters='{"resource":"forms","per_page":"1"}'; name=get-configuration ;;
			form_id) numeric_parameters="$(jq -nc --arg id "${form_id}" '{form_id:$id}')"; name=diagnose-form ;;
		esac
		numeric_message="$(jq -r --arg case "${numeric_case}" '.numeric_input[$case].php_message // ""' "${ABILITY_JSON}")"
		request="$(jq -nc --arg name "cf7-telegram/${name}" --argjson input "${numeric_parameters}" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"mcp-adapter-execute-ability",arguments:{ability_name:$name,parameters:$input}}}')"
		output="${prefix}-numeric-${numeric_case}.json"
		if [ -n "${numeric_message}" ] && mcp_call admin "${request}" "${output}" &&
			jq -e --arg message "${numeric_message}" '(.result.structuredContent.success == false and .result.structuredContent.error == $message) or
				(.result.isError == true and [.result.content[]? | select(.type == "text") | .text] == [$message]) or
				([.result.content[]?.text | fromjson?] | any(.success == false and .error == $message))' "${output}" >/dev/null; then
			:
		else numeric_failures="${numeric_failures} ${numeric_case}"; fi
	done
	if [ -z "${numeric_failures}" ]; then
		emit "r1" "mcp-numeric-string-input" "pass" "Official MCP rejects all three numeric strings with the same safe PHP error."
	else fail_step "r1" "mcp-numeric-string-input" "Official MCP numeric-string input differs from native PHP." "$(jq -nc --arg cases "${numeric_failures# }" --arg result_prefix "${prefix}-numeric" '{failed_cases:$cases,result_prefix:$result_prefix}')"; fi
	local hidden_id
	hidden_id="$(jq -r '.fixture.hidden_recipient_id' "${ABILITY_JSON}")"
	if ! wp_run option update cf7tg_r1_hidden_post_id "${hidden_id}" >"${LOG_DIR}/r1-mcp-hide.log" 2>&1; then fail_step "r1" "mcp-hidden-graph" "Could not set isolated object capability probe."; return 1; fi
	if ! hidden_cap_denied >"${LOG_DIR}/r1-mcp-hidden-cap.log" 2>&1; then
		fail_step "r1" "mcp-hidden-graph" "Isolated object capability filter did not deny read_post."
		wp_run option delete cf7tg_r1_hidden_post_id >/dev/null 2>&1 || true
		return 1
	fi
	request="$(jq -nc --argjson id "${form_id}" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"mcp-adapter-execute-ability",arguments:{ability_name:"cf7-telegram/diagnose-form",parameters:{form_id:$id}}}}')"
	if mcp_call admin "${request}" "${prefix}-hidden.json" && jq -e --slurpfile denied "${RESULTS_DIR}/r1-http-hidden.json" '(.result.isError == true and [.result.content[]? | select(.type == "text") | .text] == ["Permission denied"]) or (.result.isError == true and $denied[0].code == "cf7tg_forbidden" and $denied[0].data.status == 403 and [.result.content[]? | select(.type == "text") | .text] == [$denied[0].message]) or (.error.message // "" | test("forbidden"; "i")) or ([.result.structuredContent, (.result.content[]?.text | fromjson?)] | any(.success == false and ((.error // .code // "" | tostring | test("cf7tg_forbidden|forbidden"; "i")) or .error == $denied[0].message)))' "${prefix}-hidden.json" >/dev/null; then
		emit "r1" "mcp-hidden-graph" "pass" "Official MCP execution rejects the same hidden object graph."
	else fail_step "r1" "mcp-hidden-graph" "Official MCP execution exposed a hidden graph."; fi
	wp_run option delete cf7tg_r1_hidden_post_id >"${LOG_DIR}/r1-mcp-unhide.log" 2>&1 || fail_step "r1" "mcp-unhide" "Could not clear isolated capability probe."
}

require_tools
prepare_candidate
write_compose_file

CURRENT_PROJECT="$(project_name)"
cleanup_project

setup_site
run_smoke || true
run_early_probe || true
run_abilities || true
run_http || true
run_mcp || true
for required in versions core-abilities early-not-ready http-admin http-diagnose http-numeric-string-input http-bad-method http-not-ready http-anonymous http-subscriber http-hidden-graph; do
	if ! jq -s -e --arg id "${required}" '[.[] | select(.case == "r1" and .step == $id)] | length == 1 and .[0].status == "pass"' "${EVIDENCE_JSONL}" >/dev/null; then
		fail_step "r1" "required-${required}" "Required HTTP check missing, skipped, duplicated, or failed."
	fi
done
if [ "${MCP_REQUIRED}" = 1 ]; then
	for required in mcp-version mcp-tools mcp-discover mcp-info-get-configuration mcp-info-diagnose-form mcp-execute-get-configuration mcp-execute-diagnose-form mcp-denied-get-configuration mcp-denied-diagnose-form mcp-numeric-string-input mcp-hidden-graph; do
		if ! jq -s -e --arg id "${required}" '[.[] | select(.case == "r1" and .step == $id)] | length == 1 and .[0].status == "pass"' "${EVIDENCE_JSONL}" >/dev/null; then
			fail_step "r1" "required-${required}" "Required MCP check missing, skipped, duplicated, or failed."
		fi
	done
fi
write_summary

echo "E4 REST/UI smoke evidence: ${SUMMARY_JSON}"

if [ "${FAILURES}" -gt 0 ]; then
	echo "E4 REST/UI smoke failed with ${FAILURES} failing step(s)." >&2
	exit 1
fi

echo "E4 REST/UI smoke passed."
