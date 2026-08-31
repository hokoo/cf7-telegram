#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SOURCE_MANIFEST="${SCRIPT_DIR}/e1-version-sources.json"
PLUGIN_SLUG="${PLUGIN_SLUG:-$(jq -r '.plugin_slug // "cf7-telegram"' "${SOURCE_MANIFEST}")}"
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
WORKDIR="${CF7TG_E5_BROWSER_WORKDIR:-$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e5-browser-${RUN_ID}.XXXXXX")}"
RESULTS_DIR="${CF7TG_E5_BROWSER_RESULTS_DIR:-${WORKDIR}/results}"
LOG_DIR="${RESULTS_DIR}/logs"
ARTIFACT_DIR="${WORKDIR}/artifacts"
RUNTIME_DIR="${WORKDIR}/runtime"
COMPOSE_FILE="${WORKDIR}/docker-compose.e5-browser.yml"
EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
SUMMARY_JSON="${RESULTS_DIR}/summary.json"
BROWSER_RESULT_JSON="${RESULTS_DIR}/browser-result.json"
PLAYWRIGHT_REPORT_JSON="${RESULTS_DIR}/playwright-report.json"
CURRENT_ROW="$(jq -c '[.support_matrix[]? | select(.label == "current")][0] // {}' "${SOURCE_MANIFEST}")"
CURRENT_ROW_ID="$(jq -r '.matrix_id // "current"' <<<"${CURRENT_ROW}")"
CURRENT_ROW_LABEL="$(jq -r '.label // "current"' <<<"${CURRENT_ROW}")"
CURRENT_ROW_REQUIRED="$(jq -r '.required // true' <<<"${CURRENT_ROW}")"
WP_VERSION="${CF7TG_E5_BROWSER_WP_VERSION:-$(jq -r '.wordpress_version // .wordpress.default_core_version // "7.1"' <<<"${CURRENT_ROW}")}"
PHP_VERSION="${CF7TG_E5_BROWSER_PHP_VERSION:-$(jq -r '.php_version // "8.3"' <<<"${CURRENT_ROW}")}"
WP_CLI_IMAGE="${CF7TG_E5_BROWSER_WP_CLI_IMAGE:-$(jq -r '.wp_cli_image // .wordpress.default_cli_image // "wordpress:cli-php8.3"' <<<"${CURRENT_ROW}")}"
CF7_VERSION="${CF7TG_E5_BROWSER_CF7_VERSION:-$(jq -r '.contact_form_7_version // .dependencies.contact_form_7.default_version // "6.1.7"' <<<"${CURRENT_ROW}")}"
EXPECTED_CANDIDATE_VERSION="${CF7TG_EXPECTED_CANDIDATE_VERSION:-$(jq -r '.candidate.expected_version // .support_contract.candidate_expected_version // "1.0.13"' "${SOURCE_MANIFEST}")}"
CANDIDATE_ZIP="${CF7TG_CANDIDATE_ZIP:-${REPO_ROOT}/dist/${PLUGIN_SLUG}-wp-plugin.zip}"
WEB_PORT="${CF7TG_E5_BROWSER_WEB_PORT:-$(shuf -i 20000-45000 -n 1)}"
BASE_URL="${CF7TG_E5_BROWSER_BASE_URL:-http://127.0.0.1:${WEB_PORT}}"
IMAGE_PROBE_TIMEOUT="${CF7TG_E5_BROWSER_IMAGE_PROBE_TIMEOUT:-90s}"
INSTALL_BROWSER="${CF7TG_E5_BROWSER_INSTALL_BROWSER:-1}"
KEEP_WORKDIR=0
FAILURES=0
SUMMARY_WRITTEN=0
CURRENT_PROJECT=""
WP_WEB_IMAGE=""

REQUIRED_CHECKS='["authenticated-admin-render","no-page-errors","no-console-errors","full-page-background","system-notices-hidden","pagination-beyond-ten","post-mutation-observed"]'

usage() {
	cat <<'USAGE'
Usage: tests/stability/e5-browser-smoke.sh [options]

Options:
  --candidate-zip <path>     Candidate plugin ZIP to install and test.
  --expected-version <ver>   Expected candidate plugin version. Defaults to manifest candidate.expected_version.
  --workdir <path>           Use an explicit temporary work directory.
  --web-port <port>          Host web port for the isolated WordPress container.
  --skip-browser-install     Do not run `playwright install chromium`.
  --keep-workdir             Keep Docker containers/volumes and temp files for debugging.
  -h, --help                 Show this help.

Environment:
  CF7TG_CANDIDATE_ZIP                 Candidate ZIP path.
  CF7TG_EXPECTED_CANDIDATE_VERSION    Expected candidate version, default 1.0.13.
  CF7TG_E5_BROWSER_RESULTS_DIR        Summary, logs, traces, screenshots, and Playwright report directory.
  CF7TG_E5_BROWSER_WP_VERSION         WordPress core version, default current support row.
  CF7TG_E5_BROWSER_WP_CLI_IMAGE       WP-CLI image, default current support row.
  CF7TG_E5_BROWSER_WP_WEB_IMAGE       Apache WordPress image. Defaults to versioned image if available, else generic php image.
  CF7TG_E5_BROWSER_WEB_PORT           Host port. Defaults to a random high port.
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
			RESULTS_DIR="${CF7TG_E5_BROWSER_RESULTS_DIR:-${WORKDIR}/results}"
			LOG_DIR="${RESULTS_DIR}/logs"
			ARTIFACT_DIR="${WORKDIR}/artifacts"
			RUNTIME_DIR="${WORKDIR}/runtime"
			COMPOSE_FILE="${WORKDIR}/docker-compose.e5-browser.yml"
			EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
			SUMMARY_JSON="${RESULTS_DIR}/summary.json"
			BROWSER_RESULT_JSON="${RESULTS_DIR}/browser-result.json"
			PLAYWRIGHT_REPORT_JSON="${RESULTS_DIR}/playwright-report.json"
			;;
		--web-port)
			shift
			[ "$#" -gt 0 ] || { echo "Missing value for --web-port" >&2; exit 2; }
			WEB_PORT="$1"
			BASE_URL="http://127.0.0.1:${WEB_PORT}"
			;;
		--skip-browser-install)
			INSTALL_BROWSER=0
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
	local kind="$1"
	local id="$2"
	local status="$3"
	local message="$4"
	local extra="${5:-}"

	if [ -z "${extra}" ]; then
		extra='{}'
	fi

	jq -nc \
		--arg run_id "${RUN_ID}" \
		--arg kind "${kind}" \
		--arg id "${id}" \
		--arg status "${status}" \
		--arg message "${message}" \
		--argjson extra "${extra}" \
		'{
			run_id: $run_id,
			kind: $kind,
			id: $id,
			status: $status,
			message: $message,
			extra: $extra,
			captured_at_gmt: (now | todate)
		}' >> "${EVIDENCE_JSONL}"
}

fail_step() {
	local id="$1"
	local message="$2"
	local extra="${3:-}"

	FAILURES=$((FAILURES + 1))
	emit "step" "${id}" "fail" "${message}" "${extra}"
}

run_logged() {
	local id="$1"
	local exit_code
	shift
	local log_file="${LOG_DIR}/${id}.log"

	if "$@" >"${log_file}" 2>&1; then
		emit "step" "${id}" "pass" "Command succeeded." "$(jq -nc --arg log "${log_file}" '{log:$log}')"
		return 0
	else
		exit_code="$?"
		fail_step "${id}" "Command failed." "$(jq -nc --arg log "${log_file}" --argjson exit_code "${exit_code}" '{log:$log,exit_code:$exit_code}')"
		return "${exit_code}"
	fi
}

project_name() {
	local safe
	safe="$(printf '%s' "${RUN_ID}" | tr '[:upper:]' '[:lower:]' | tr '.-:' '___' | tr -cd 'a-z0-9_')"
	printf 'cf7tge5browser%s' "${safe}"
}

dc() {
	docker_compose -f "${COMPOSE_FILE}" -p "${CURRENT_PROJECT}" "$@"
}

cleanup_project() {
	if [ -n "${CURRENT_PROJECT}" ] && [ "${KEEP_WORKDIR}" -eq 0 ]; then
		docker_compose -f "${COMPOSE_FILE}" -p "${CURRENT_PROJECT}" down -v --remove-orphans >/dev/null 2>&1 || true
	fi
}

candidate_header_version() {
	unzip -p "$1" "${PLUGIN_SLUG}/cf7-telegram.php" | sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' | head -n 1
}

candidate_sha256() {
	sha256sum "${ARTIFACT_DIR}/${PLUGIN_SLUG}-candidate.zip" 2>/dev/null | awk '{print $1}'
}

candidate_version() {
	candidate_header_version "${ARTIFACT_DIR}/${PLUGIN_SLUG}-candidate.zip" 2>/dev/null || true
}

browser_result_json() {
	if [ -s "${BROWSER_RESULT_JSON}" ] && jq -e 'type == "object"' "${BROWSER_RESULT_JSON}" >/dev/null 2>&1; then
		cat "${BROWSER_RESULT_JSON}"
	else
		printf '{}'
	fi
}

write_summary() {
	local browser_result
	browser_result="$(browser_result_json)"

	jq -s \
		--arg run_id "${RUN_ID}" \
		--arg workdir "${WORKDIR}" \
		--arg results_dir "${RESULTS_DIR}" \
		--arg summary_json "${SUMMARY_JSON}" \
		--arg browser_result_json "${BROWSER_RESULT_JSON}" \
		--arg playwright_report_json "${PLAYWRIGHT_REPORT_JSON}" \
		--arg source_manifest "${SOURCE_MANIFEST}" \
		--arg support_row_id "${CURRENT_ROW_ID}" \
		--arg support_row_label "${CURRENT_ROW_LABEL}" \
		--arg support_row_required "${CURRENT_ROW_REQUIRED}" \
		--arg wp_version "${WP_VERSION}" \
		--arg wp_cli_image "${WP_CLI_IMAGE}" \
		--arg wp_web_image "${WP_WEB_IMAGE}" \
		--arg cf7_version "${CF7_VERSION}" \
		--arg base_url "${BASE_URL}" \
		--arg candidate_zip "${CANDIDATE_ZIP}" \
		--arg candidate_stage_zip "${ARTIFACT_DIR}/${PLUGIN_SLUG}-candidate.zip" \
		--arg candidate_version "$(candidate_version)" \
		--arg candidate_sha256 "$(candidate_sha256)" \
		--arg expected_candidate_version "${EXPECTED_CANDIDATE_VERSION}" \
		--argjson required_checks "${REQUIRED_CHECKS}" \
		--argjson browser "${browser_result}" \
		'
		. as $events
		| ($browser.checks // []) as $browser_checks
		| ($required_checks | map(
			. as $required_id
			| (([$browser_checks[]? | select(.id == $required_id)] | last)
				// {id: $required_id, status: "fail", message: "Required check did not run.", extra: {}})
			| {id, status, message: (.message // ""), extra: (.extra // {})}
		)) as $checks
		| {
			schema: 1,
			run_id: $run_id,
			workdir: $workdir,
			results_dir: $results_dir,
			summary_json: $summary_json,
			source_manifest: $source_manifest,
			candidate: {
				version: $candidate_version,
				sha256: $candidate_sha256,
				expected_version: $expected_candidate_version,
				source_zip: $candidate_zip,
				staged_zip: $candidate_stage_zip
			},
			environment: {
				base_url: $base_url,
				wordpress: {
					expected_version: $wp_version,
					actual_version: ($browser.wordpress.version // null),
					cli_image: $wp_cli_image,
					web_image: $wp_web_image
				},
				contact_form_7_version: $cf7_version,
				support_row: {
					matrix_id: $support_row_id,
					label: $support_row_label,
					required: $support_row_required
				},
				uses_repo_docker_compose: false
			},
			checks: $checks,
			failed_steps: (([$events[] | select(.kind != "check" and .status == "fail")] | length) + ([$checks[] | select(.status != "pass")] | length)),
			passed_steps: (([$events[] | select(.status == "pass")] | length) + ([$checks[] | select(.status == "pass")] | length)),
			playwright: {
				status: ($browser.status // null),
				result_json: $browser_result_json,
				report_json: $playwright_report_json,
				output_dir: ($browser.output_dir // null)
			},
			evidence: {
				events: $events,
				browser: $browser
			}
		}' "${EVIDENCE_JSONL}" > "${SUMMARY_JSON}"
	SUMMARY_WRITTEN=1
}

on_exit() {
	local status="$?"

	if [ -s "${EVIDENCE_JSONL}" ] && [ "${SUMMARY_WRITTEN}" -eq 0 ]; then
		if [ "${status}" -ne 0 ]; then
			emit "step" "exit" "fail" "Script exited before normal completion." "$(jq -nc --argjson exit_code "${status}" '{exit_code:$exit_code}')"
		fi
		write_summary || true
	fi

	if [ -f "${SUMMARY_JSON}" ]; then
		echo "E5 browser smoke evidence: ${SUMMARY_JSON}"
	fi

	cleanup_project
}

trap on_exit EXIT

require_tools() {
	local missing=()
	for tool in curl jq npm sha256sum shuf unzip; do
		command -v "${tool}" >/dev/null 2>&1 || missing+=("${tool}")
	done

	if ! command -v docker >/dev/null 2>&1; then
		missing+=("docker")
	fi
	if ! command -v docker-compose >/dev/null 2>&1 && ! docker compose version >/dev/null 2>&1; then
		missing+=("docker-compose or docker compose")
	fi

	if [ "${#missing[@]}" -gt 0 ]; then
		fail_step "preflight-tools" "Missing required tools: ${missing[*]}"
		exit 2
	fi

	emit "step" "preflight-tools" "pass" "Required tools are available."
}

image_available() {
	local image="$1"

	if docker image inspect "${image}" >/dev/null 2>&1; then
		return 0
	fi

	timeout "${IMAGE_PROBE_TIMEOUT}" docker manifest inspect "${image}" >/dev/null 2>&1
}

resolve_web_image() {
	local versioned="wordpress:${WP_VERSION}-php${PHP_VERSION}-apache"
	local fallback="wordpress:php${PHP_VERSION}-apache"

	if [ -n "${CF7TG_E5_BROWSER_WP_WEB_IMAGE:-}" ]; then
		WP_WEB_IMAGE="${CF7TG_E5_BROWSER_WP_WEB_IMAGE}"
		emit "step" "web-image" "pass" "Using caller-provided WordPress web image." "$(jq -nc --arg image "${WP_WEB_IMAGE}" '{image:$image,source:"env"}')"
		return 0
	fi

	if docker image inspect "${versioned}" >/dev/null 2>&1; then
		WP_WEB_IMAGE="${versioned}"
		emit "step" "web-image" "pass" "Using version-specific WordPress web image." "$(jq -nc --arg image "${WP_WEB_IMAGE}" --arg source "local" '{image:$image,source:$source}')"
		return 0
	fi

	if docker image inspect "${fallback}" >/dev/null 2>&1; then
		WP_WEB_IMAGE="${fallback}"
		emit "step" "web-image" "pass" "Version-specific WordPress web image unavailable locally; using cached generic Apache image while WP-CLI downloads the exact core version." "$(jq -nc --arg attempted "${versioned}" --arg image "${WP_WEB_IMAGE}" --arg source "local" '{attempted:$attempted,image:$image,source:$source}')"
		return 0
	fi

	if image_available "${versioned}"; then
		WP_WEB_IMAGE="${versioned}"
		emit "step" "web-image" "pass" "Using version-specific WordPress web image." "$(jq -nc --arg image "${WP_WEB_IMAGE}" --arg source "registry" '{image:$image,source:$source}')"
		return 0
	fi

	WP_WEB_IMAGE="${fallback}"
	emit "step" "web-image" "pass" "Version-specific WordPress web image unavailable; using generic Apache image while WP-CLI downloads the exact core version." "$(jq -nc --arg attempted "${versioned}" --arg image "${WP_WEB_IMAGE}" --arg source "registry" '{attempted:$attempted,image:$image,source:$source}')"
}

prepare_candidate() {
	local staged_zip="${ARTIFACT_DIR}/${PLUGIN_SLUG}-candidate.zip"
	local actual_version

	if [ ! -f "${CANDIDATE_ZIP}" ]; then
		fail_step "candidate-source" "Candidate ZIP does not exist." "$(jq -nc --arg file "${CANDIDATE_ZIP}" '{file:$file}')"
		exit 2
	fi

	cp "${CANDIDATE_ZIP}" "${staged_zip}"

	if ! unzip -tq "${staged_zip}" >/dev/null; then
		fail_step "candidate-integrity" "Candidate ZIP failed unzip integrity check." "$(jq -nc --arg file "${staged_zip}" '{file:$file}')"
		exit 2
	fi

	actual_version="$(candidate_header_version "${staged_zip}")"
	if [ "${actual_version}" != "${EXPECTED_CANDIDATE_VERSION}" ]; then
		fail_step "candidate-version" "Candidate version did not match expected version." "$(jq -nc --arg file "${staged_zip}" --arg expected "${EXPECTED_CANDIDATE_VERSION}" --arg actual "${actual_version}" '{file:$file,expected:$expected,actual:$actual}')"
		exit 2
	fi

	run_logged "candidate-validate-release-zip" env EXPECTED_VERSION="${EXPECTED_CANDIDATE_VERSION}" "${REPO_ROOT}/scripts/validate-release-zip.sh" "${staged_zip}" || exit 2
	emit "step" "candidate-ready" "pass" "Candidate ZIP staged for browser smoke." "$(jq -nc --arg file "${staged_zip}" --arg version "${actual_version}" --arg sha256 "$(candidate_sha256)" '{file:$file,version:$version,sha256:$sha256}')"
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
  wordpress:
    image: ${WP_WEB_IMAGE}
    depends_on:
      - db
    ports:
      - "127.0.0.1:${WEB_PORT}:80"
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DEBUG: "1"
    volumes:
      - wp-data:/var/www/html
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
      - ${SCRIPT_DIR}:/e5-tests:ro

volumes:
  wp-data:
COMPOSE

	emit "step" "compose-file" "pass" "Wrote isolated browser Docker Compose file." "$(jq -nc --arg file "${COMPOSE_FILE}" --arg web_image "${WP_WEB_IMAGE}" --arg cli_image "${WP_CLI_IMAGE}" --arg base_url "${BASE_URL}" '{file:$file,web_image:$web_image,cli_image:$cli_image,base_url:$base_url}')"
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

	echo "WP-CLI command did not succeed after ${tries} attempts: wp $*" >&2
	wp_run "$@"
}

setup_site() {
	local actual_wp_version

	run_logged "db-up" dc up -d db || exit 1
	run_logged "core-download" retry_wp core download --path=/var/www/html --version="${WP_VERSION}" --force || exit 1
	run_logged "config-create" wp_run config create --path=/var/www/html --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db:3306 --skip-check --force || exit 1
	run_logged "db-wait" retry_wp db check --path=/var/www/html --skip-ssl || exit 1
	run_logged "core-install" wp_run core install --path=/var/www/html --url="${BASE_URL}" --title="CF7TG E5 Browser Smoke" --admin_user=admin --admin_password=admin-password --admin_email=admin@example.test || exit 1

	if [ "${CF7_VERSION}" = "latest" ]; then
		run_logged "cf7-install" retry_wp plugin install contact-form-7 --activate || exit 1
	else
		run_logged "cf7-install" retry_wp plugin install contact-form-7 --version="${CF7_VERSION}" --activate || exit 1
	fi

	run_logged "candidate-install" wp_run plugin install "/artifacts/${PLUGIN_SLUG}-candidate.zip" --force --activate || exit 1
	run_logged "seed-browser-fixture" wp_run eval-file /e5-tests/wp-e5-browser-fixture.php || exit 1

	actual_wp_version="$(wp_run core version --path=/var/www/html | tr -d '\r')"
	if [ "${actual_wp_version}" != "${WP_VERSION}" ]; then
		fail_step "wordpress-version" "Installed WordPress version did not match the current support row." "$(jq -nc --arg expected "${WP_VERSION}" --arg actual "${actual_wp_version}" '{expected:$expected,actual:$actual}')"
		exit 1
	fi
	emit "step" "wordpress-version" "pass" "Installed WordPress version matches the current support row." "$(jq -nc --arg version "${actual_wp_version}" '{version:$version}')"
}

wait_for_web() {
	local tries=60
	local delay=2
	local i
	for i in $(seq 1 "${tries}"); do
		if curl -fsS "${BASE_URL}/wp-login.php" >/dev/null 2>&1; then
			emit "step" "web-ready" "pass" "WordPress web container is reachable." "$(jq -nc --arg base_url "${BASE_URL}" '{base_url:$base_url}')"
			return 0
		fi
		sleep "${delay}"
	done

	fail_step "web-ready" "Timed out waiting for WordPress web container." "$(jq -nc --arg base_url "${BASE_URL}" '{base_url:$base_url}')"
	return 1
}

ensure_playwright() {
	if [ ! -x "${REPO_ROOT}/plugin-dir/react/node_modules/.bin/playwright" ]; then
		run_logged "npm-ci" npm --prefix "${REPO_ROOT}/plugin-dir/react" ci || exit 1
	else
		emit "step" "npm-ci" "pass" "React node_modules already includes Playwright."
	fi

	run_logged "playwright-version" npm --prefix "${REPO_ROOT}/plugin-dir/react" exec -- playwright --version || exit 1

	if [ "${INSTALL_BROWSER}" = "1" ]; then
		run_logged "playwright-install-chromium" npm --prefix "${REPO_ROOT}/plugin-dir/react" exec -- playwright install chromium || exit 1
	else
		emit "step" "playwright-install-chromium" "pass" "Skipped browser install by request."
	fi
}

run_browser_smoke() {
	local exit_code=0
	local log_file="${LOG_DIR}/playwright.log"

	set +e
	(
		cd "${REPO_ROOT}/plugin-dir/react"
		CF7TG_E5_BROWSER_BASE_URL="${BASE_URL}" \
		CF7TG_E5_BROWSER_RESULTS_DIR="${RESULTS_DIR}" \
		CF7TG_E5_BROWSER_RESULT_JSON="${BROWSER_RESULT_JSON}" \
		CF7TG_E5_BROWSER_PLAYWRIGHT_REPORT_JSON="${PLAYWRIGHT_REPORT_JSON}" \
		CF7TG_EXPECTED_CANDIDATE_VERSION="${EXPECTED_CANDIDATE_VERSION}" \
		CF7TG_CANDIDATE_SHA256="$(candidate_sha256)" \
		CF7TG_E5_BROWSER_EXPECTED_WP_VERSION="${WP_VERSION}" \
		npm exec -- playwright test --config "${REPO_ROOT}/tests/e2e/playwright.config.js"
	) >"${log_file}" 2>&1
	exit_code="$?"
	set -e

	if [ "${exit_code}" -eq 0 ]; then
		emit "step" "playwright" "pass" "Playwright browser smoke passed." "$(jq -nc --arg log "${log_file}" --arg result "${BROWSER_RESULT_JSON}" '{log:$log,result:$result}')"
		return 0
	fi

	fail_step "playwright" "Playwright browser smoke failed." "$(jq -nc --arg log "${log_file}" --arg result "${BROWSER_RESULT_JSON}" --argjson exit_code "${exit_code}" '{log:$log,result:$result,exit_code:$exit_code}')"
	return "${exit_code}"
}

require_tools
prepare_candidate
resolve_web_image
write_compose_file

CURRENT_PROJECT="$(project_name)"
cleanup_project

setup_site
run_logged "web-up" dc up -d wordpress || exit 1
wait_for_web || exit 1
ensure_playwright
run_browser_smoke || true
write_summary

if [ "$(jq -r '.failed_steps' "${SUMMARY_JSON}")" -gt 0 ]; then
	echo "E5 browser smoke failed." >&2
	exit 1
fi

echo "E5 browser smoke passed."
