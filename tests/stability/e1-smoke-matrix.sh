#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SOURCE_MANIFEST="${SCRIPT_DIR}/e1-version-sources.json"

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
WORKDIR="${CF7TG_E1_WORKDIR:-$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e1-${RUN_ID}.XXXXXX")}"
CACHE_DIR="${CF7TG_E1_CACHE_DIR:-${XDG_CACHE_HOME:-${HOME}/.cache}/cf7-telegram/e1-stability}"
RESULTS_DIR="${CF7TG_E1_RESULTS_DIR:-${WORKDIR}/results}"
LOG_DIR="${RESULTS_DIR}/logs"
STATE_DIR="${RESULTS_DIR}/state"
ARTIFACT_DIR="${WORKDIR}/artifacts"
CANDIDATE_STAGE="${WORKDIR}/candidate-stage"
RUNTIME_DIR="${WORKDIR}/runtime"
COMPOSE_FILE="${WORKDIR}/docker-compose.e1.yml"
EVIDENCE_JSONL="${RESULTS_DIR}/evidence.jsonl"
SUMMARY_JSON="${RESULTS_DIR}/summary.json"
WP_VERSION="${CF7TG_E1_WP_VERSION:-$(jq -r '.wordpress.default_core_version' "${SOURCE_MANIFEST}")}"
WP_CLI_IMAGE="${CF7TG_E1_WP_CLI_IMAGE:-$(jq -r '.wordpress.default_cli_image' "${SOURCE_MANIFEST}")}"
CF7_VERSION="${CF7TG_E1_CF7_VERSION:-$(jq -r '.dependencies.contact_form_7.default_version' "${SOURCE_MANIFEST}")}"
FIXTURE="${CF7TG_E1_FIXTURE:-legacy-heavy}"
E2_CHARACTERIZATION="${CF7TG_E2_CHARACTERIZATION:-0}"
KEEP_WORKDIR=0
ARTIFACT_ONLY=0
FAILURES=0
SUMMARY_WRITTEN=0
CURRENT_PROJECT=""
CASES=()

usage() {
	cat <<'USAGE'
Usage: tests/stability/e1-smoke-matrix.sh [options]

Options:
  --case <name>       Run one case. Repeatable. Known names:
                      fresh, upgrade-0.10, upgrade-0.11,
                      upgrade-1.0.9, upgrade-1.0.10
  --artifact-only     Verify/download source artifacts and build candidate zip only.
  --workdir <path>    Use an explicit temporary work directory.
  --keep-workdir      Keep Docker containers/volumes for debugging.
  -h, --help          Show this help.

Environment:
  CF7TG_CANDIDATE_ZIP                 Candidate zip. Defaults to dist/cf7-telegram-wp-plugin.zip
                                      when present, then a complete local plugin-dir fallback.
  CF7TG_EXPECTED_CANDIDATE_VERSION    Expected candidate header version.
  CF7TG_E1_WP_VERSION                 WordPress core version, default 7.0.4.
  CF7TG_E1_WP_CLI_IMAGE               WP-CLI Docker image, default wordpress:cli-php8.2.
  CF7TG_E1_CF7_VERSION                Contact Form 7 version, default 6.0.6.
  CF7TG_E1_FIXTURE                    legacy-heavy, legacy-basic, damaged-legacy,
                                      partial-modern, or none. Default legacy-heavy.
  CF7TG_E2_CHARACTERIZATION           When set to 1, emit E2 semantic migration evidence.
  CF7TG_E1_CACHE_DIR                  Cache for downloaded source zips.
  CF7TG_E1_RESULTS_DIR                Evidence output directory.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--case)
			shift
			[ "$#" -gt 0 ] || { echo "Missing value for --case" >&2; exit 2; }
			CASES+=("$1")
			;;
		--artifact-only)
			ARTIFACT_ONLY=1
			;;
		--workdir)
			shift
			[ "$#" -gt 0 ] || { echo "Missing value for --workdir" >&2; exit 2; }
			WORKDIR="$1"
			RESULTS_DIR="${CF7TG_E1_RESULTS_DIR:-${WORKDIR}/results}"
			LOG_DIR="${RESULTS_DIR}/logs"
			STATE_DIR="${RESULTS_DIR}/state"
			ARTIFACT_DIR="${WORKDIR}/artifacts"
			CANDIDATE_STAGE="${WORKDIR}/candidate-stage"
			RUNTIME_DIR="${WORKDIR}/runtime"
			COMPOSE_FILE="${WORKDIR}/docker-compose.e1.yml"
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

if [ "${#CASES[@]}" -eq 0 ]; then
	CASES=(fresh upgrade-0.10 upgrade-0.11 upgrade-1.0.9 upgrade-1.0.10)
fi

mkdir -p "${CACHE_DIR}" "${RESULTS_DIR}" "${LOG_DIR}" "${STATE_DIR}" "${ARTIFACT_DIR}" "${CANDIDATE_STAGE}" "${RUNTIME_DIR}"
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
		fail_step "${case_id}" "${step}" "Command failed." "$(jq -nc --arg log "${log_file}" --argjson exit_code "${exit_code}" '{log:$log,exit_code:$exit_code}')"
		return "${exit_code}"
	fi
}

project_name_for_case() {
	local case_id="$1"
	local safe
	safe="$(printf '%s' "${case_id}" | tr '[:upper:]' '[:lower:]' | tr '.-' '__' | tr -cd 'a-z0-9_')"
	printf 'cf7tge1%s%s' "$$" "${safe}"
}

dc() {
	docker_compose -f "${COMPOSE_FILE}" -p "${CURRENT_PROJECT}" "$@"
}

cleanup_project() {
	if [ -n "${CURRENT_PROJECT}" ] && [ "${KEEP_WORKDIR}" -eq 0 ]; then
		docker_compose -f "${COMPOSE_FILE}" -p "${CURRENT_PROJECT}" down -v --remove-orphans >/dev/null 2>&1 || true
	fi
}

on_exit() {
	local status="$?"

	if [ -s "${EVIDENCE_JSONL}" ] && [ "${SUMMARY_WRITTEN}" -eq 0 ]; then
		if [ "${status}" -ne 0 ]; then
			emit "run" "exit" "fail" "Script exited before normal completion." "$(jq -nc --argjson exit_code "${status}" '{exit_code:$exit_code}')"
		fi
		write_summary || true
	fi

	cleanup_project
}

trap on_exit EXIT

require_tools() {
	local missing=()
	for tool in curl unzip jq sha256sum zip rsync; do
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
      CF7TG_E1_FIXTURE: ${FIXTURE}
      WP_CLI_PHP_ARGS: -d memory_limit=512M
    volumes:
      - wp-data:/var/www/html
      - ${ARTIFACT_DIR}:/artifacts:ro
      - ${RUNTIME_DIR}:/runtime
      - ${SCRIPT_DIR}:/e1-tests:ro

volumes:
  wp-data:
COMPOSE

	emit "preflight" "compose_file" "pass" "Wrote isolated Docker Compose file." "$(jq -nc --arg file "${COMPOSE_FILE}" --arg image "${WP_CLI_IMAGE}" '{file:$file,wp_cli_image:$image}')"
}

candidate_header_version() {
	local zip_file="$1"
	unzip -p "${zip_file}" "cf7-telegram/cf7-telegram.php" | awk -F': *' '/Version:/{gsub(/\r/,"",$2); print $2; exit}'
}

verify_zip_version() {
	local case_id="$1"
	local step="$2"
	local zip_file="$3"
	local expected="$4"
	local actual

	if ! unzip -tq "${zip_file}" >/dev/null 2>&1; then
		fail_step "${case_id}" "${step}" "Zip integrity check failed." "$(jq -nc --arg file "${zip_file}" '{file:$file}')"
		return 1
	fi

	actual="$(candidate_header_version "${zip_file}")"
	if [ "${actual}" != "${expected}" ]; then
		fail_step "${case_id}" "${step}" "Unexpected plugin header version." "$(jq -nc --arg file "${zip_file}" --arg expected "${expected}" --arg actual "${actual}" '{file:$file,expected:$expected,actual:$actual}')"
		return 1
	fi

	emit "${case_id}" "${step}" "pass" "Zip integrity and expected plugin version verified." "$(jq -nc --arg file "${zip_file}" --arg version "${actual}" --arg sha256 "$(sha256sum "${zip_file}" | awk '{print $1}')" --argjson bytes "$(wc -c < "${zip_file}")" '{file:$file,version:$version,sha256:$sha256,bytes:$bytes}')"
}

prepare_candidate_from_local_plugin_dir() {
	local candidate_zip="$1"
	local stage_dir="${CANDIDATE_STAGE}/cf7-telegram"

	if [ ! -f "${REPO_ROOT}/plugin-dir/vendor/autoload.php" ] || [ ! -d "${REPO_ROOT}/plugin-dir/react/build" ]; then
		fail_step "candidate" "source" "Local plugin-dir is incomplete." "$(jq -nc --arg required_zip "${REPO_ROOT}/dist/cf7-telegram-wp-plugin.zip" '{required_zip:$required_zip,required_env:"CF7TG_CANDIDATE_ZIP",reason:"Local characterization requires plugin-dir/vendor/autoload.php and plugin-dir/react/build. Build the release zip in another task or pass CF7TG_CANDIDATE_ZIP."}')"
		exit 2
	fi

	mkdir -p "${stage_dir}"
	rsync -a \
		--exclude '.git' \
		--exclude 'react/node_modules' \
		--exclude 'react/.cache' \
		--exclude 'react/coverage' \
		"${REPO_ROOT}/plugin-dir/" "${stage_dir}/"

	(
		cd "${CANDIDATE_STAGE}"
		zip -qr "${candidate_zip}" "cf7-telegram" -x '*/node_modules/*' '*/.cache/*' '*/coverage/*'
	)
}

prepare_candidate() {
	local candidate_zip="${ARTIFACT_DIR}/cf7-telegram-candidate.zip"
	local expected="${CF7TG_EXPECTED_CANDIDATE_VERSION:-}"
	local dist_zip="${REPO_ROOT}/dist/cf7-telegram-wp-plugin.zip"

	if [ -n "${CF7TG_CANDIDATE_ZIP:-}" ]; then
		if [ ! -f "${CF7TG_CANDIDATE_ZIP}" ]; then
			fail_step "candidate" "source" "CF7TG_CANDIDATE_ZIP does not exist." "$(jq -nc --arg file "${CF7TG_CANDIDATE_ZIP}" '{file:$file}')"
			exit 2
		fi
		cp "${CF7TG_CANDIDATE_ZIP}" "${candidate_zip}"
		emit "candidate" "source" "pass" "Using candidate zip from CF7TG_CANDIDATE_ZIP." "$(jq -nc --arg file "${CF7TG_CANDIDATE_ZIP}" '{file:$file}')"
	elif [ "${E2_CHARACTERIZATION}" = "1" ]; then
		prepare_candidate_from_local_plugin_dir "${candidate_zip}"
		emit "candidate" "source" "pass" "Using current local plugin-dir candidate for E2 characterization." "$(jq -nc --arg file "${candidate_zip}" '{file:$file,reason:"CF7TG_E2_CHARACTERIZATION avoids silently preferring stale dist artifacts."}')"
	elif [ -f "${dist_zip}" ]; then
		cp "${dist_zip}" "${candidate_zip}"
		emit "candidate" "source" "pass" "Using candidate zip from dist/cf7-telegram-wp-plugin.zip." "$(jq -nc --arg file "${dist_zip}" '{file:$file}')"
	else
		prepare_candidate_from_local_plugin_dir "${candidate_zip}"
		emit "candidate" "source" "pass" "Built candidate zip from local plugin-dir." "$(jq -nc --arg file "${candidate_zip}" '{file:$file}')"
	fi

	if [ -z "${expected}" ]; then
		expected="$(candidate_header_version "${candidate_zip}")"
	fi

	verify_zip_version "candidate" "version" "${candidate_zip}" "${expected}"
}

download_and_verify_sources() {
	local count
	count="$(jq '.legacy_versions | length' "${SOURCE_MANIFEST}")"

	for i in $(seq 0 $((count - 1))); do
		local matrix_id version url expected_sha zip_file actual_sha
		matrix_id="$(jq -r ".legacy_versions[${i}].matrix_id" "${SOURCE_MANIFEST}")"
		version="$(jq -r ".legacy_versions[${i}].version" "${SOURCE_MANIFEST}")"
		url="$(jq -r ".legacy_versions[${i}].url" "${SOURCE_MANIFEST}")"
		expected_sha="$(jq -r ".legacy_versions[${i}].sha256" "${SOURCE_MANIFEST}")"
		zip_file="${CACHE_DIR}/cf7-telegram.${version}.zip"

		if [ ! -f "${zip_file}" ]; then
			if ! curl -fsSL "${url}" -o "${zip_file}"; then
				fail_step "artifact-${matrix_id}" "download" "Could not download legacy artifact." "$(jq -nc --arg url "${url}" --arg file "${zip_file}" '{url:$url,file:$file}')"
				exit 3
			fi
		fi

		actual_sha="$(sha256sum "${zip_file}" | awk '{print $1}')"
		if [ "${actual_sha}" != "${expected_sha}" ]; then
			fail_step "artifact-${matrix_id}" "checksum" "Legacy artifact checksum mismatch." "$(jq -nc --arg file "${zip_file}" --arg expected "${expected_sha}" --arg actual "${actual_sha}" '{file:$file,expected:$expected,actual:$actual}')"
			exit 3
		fi

		verify_zip_version "artifact-${matrix_id}" "version" "${zip_file}" "${version}"
		cp "${zip_file}" "${ARTIFACT_DIR}/cf7-telegram.${version}.zip"
	done
}

legacy_version_for_case() {
	local matrix_id="$1"
	jq -r --arg matrix_id "${matrix_id}" '.legacy_versions[] | select(.matrix_id == $matrix_id) | .version' "${SOURCE_MANIFEST}"
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

wait_for_db() {
	dc run --rm cli php -r '
		$host = getenv("WORDPRESS_DB_HOST") ?: "db:3306";
		$user = getenv("WORDPRESS_DB_USER") ?: "wordpress";
		$pass = getenv("WORDPRESS_DB_PASSWORD") ?: "wordpress";
		$name = getenv("WORDPRESS_DB_NAME") ?: "wordpress";
		[$hostname, $port] = array_pad(explode(":", $host, 2), 2, "3306");

		for ($i = 0; $i < 60; $i++) {
			$mysqli = @mysqli_connect($hostname, $user, $pass, $name, (int) $port);
			if ($mysqli) {
				mysqli_close($mysqli);
				exit(0);
			}
			sleep(2);
		}

		fwrite(STDERR, "Timed out waiting for MySQL at {$host}\n");
		exit(1);
	'
}

setup_site() {
	local case_id="$1"

	run_logged "${case_id}" "db_up" dc up -d db || return 1
	run_logged "${case_id}" "core_download" retry_wp core download --path=/var/www/html --version="${WP_VERSION}" --force || return 1
	run_logged "${case_id}" "config_create" wp_run config create --path=/var/www/html --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db:3306 --skip-check --force || return 1
	run_logged "${case_id}" "db_wait" retry_wp db check --path=/var/www/html --skip-ssl || return 1
	run_logged "${case_id}" "core_install" wp_run core install --path=/var/www/html --url="http://${CURRENT_PROJECT}.test" --title="CF7TG E1 ${case_id}" --admin_user=admin --admin_password=admin-password --admin_email=admin@example.test || return 1

	if [ "${CF7_VERSION}" = "latest" ]; then
		run_logged "${case_id}" "cf7_install" retry_wp plugin install contact-form-7 --activate || return 1
	else
		run_logged "${case_id}" "cf7_install" retry_wp plugin install contact-form-7 --version="${CF7_VERSION}" --activate || return 1
	fi
}

write_state() {
	local case_id="$1"
	local label="$2"
	local state_file="${STATE_DIR}/${case_id}-${label}.json"
	local log_file="${LOG_DIR}/${case_id}-${label}-snapshot.log"

	if wp_run eval-file /e1-tests/wp-state-snapshot.php >"${state_file}" 2>"${log_file}"; then
		emit "${case_id}" "snapshot-${label}" "pass" "Captured WordPress state snapshot." "$(jq -nc --arg file "${state_file}" '{state_file:$file}')"
		return 0
	fi

	fail_step "${case_id}" "snapshot-${label}" "Could not capture WordPress state snapshot." "$(jq -nc --arg log "${log_file}" '{log:$log}')"
	return 1
}

assert_state_jq() {
	local case_id="$1"
	local label="$2"
	local expression="$3"
	local message="$4"
	local state_file="${STATE_DIR}/${case_id}-${label}.json"

	if jq -e "${expression}" "${state_file}" >/dev/null; then
		emit "${case_id}" "assert-${label}" "pass" "${message}" "$(jq -nc --arg state_file "${state_file}" '{state_file:$state_file}')"
		return 0
	fi

	fail_step "${case_id}" "assert-${label}" "${message}" "$(jq -nc --arg state_file "${state_file}" --arg expression "${expression}" '{state_file:$state_file,expression:$expression}')"
	return 1
}

assert_active_version() {
	local case_id="$1"
	local label="$2"
	local expected="$3"

	assert_state_jq "${case_id}" "${label}" ".plugin.active == true and .plugin.version == \"${expected}\"" "Plugin is active at expected version ${expected}."
}

assert_cleanup_scheduled() {
	local case_id="$1"
	local label="$2"

	assert_state_jq "${case_id}" "${label}" '.cron.cf7tg_cleanup.total >= 1 and .cron.cf7tg_cleanup.total <= 2 and .cron.cf7tg_cleanup.recurring <= 1 and (.cron.cf7tg_cleanup.duplicates | length) == 0' "Cleanup cron exists without duplicate recurring schedule."
}

assert_cleanup_absent() {
	local case_id="$1"
	local label="$2"

	assert_state_jq "${case_id}" "${label}" '.cron.cf7tg_cleanup.total == 0' "Cleanup cron is absent."
}

assert_migration_scheduled() {
	local case_id="$1"
	local label="$2"
	local state_file="${STATE_DIR}/${case_id}-${label}.json"

	if jq -e '.cron.cf7tg_migrations.total >= 1' "${state_file}" >/dev/null; then
		emit "${case_id}" "assert-${label}-migration" "pass" "Migration cron was scheduled by the WordPress single-plugin update path." "$(jq -nc --arg state_file "${state_file}" '{state_file:$state_file}')"
		return 0
	fi

	if [ "${E2_CHARACTERIZATION}" = "1" ]; then
		emit "${case_id}" "assert-${label}-migration" "expected_fail" "Migration cron is missing after the WordPress single-plugin update path; this is an E2 blocker." "$(jq -nc --arg state_file "${state_file}" '{state_file:$state_file,downstream_epic:"E2",dependency:"E2.2 durable self-healing migration scheduler"}')"
		return 1
	fi

	fail_step "${case_id}" "assert-${label}-migration" "Migration cron is missing after the WordPress single-plugin update path; this is an E2 blocker." "$(jq -nc --arg state_file "${state_file}" '{state_file:$state_file,downstream_epic:"E2"}')"
	return 1
}

emit_e2_checks() {
	local case_id="$1"
	local file="$2"
	local count index check_id status message extra

	count="$(jq '.checks | length' "${file}")"
	if [ "${count}" -eq 0 ]; then
		return 0
	fi

	for index in $(seq 0 $((count - 1))); do
		check_id="$(jq -r ".checks[${index}].id" "${file}")"
		status="$(jq -r ".checks[${index}].status" "${file}")"
		message="$(jq -r ".checks[${index}].message" "${file}")"
		extra="$(jq -c --arg file "${file}" ".checks[${index}] + {e2_file: \$file}" "${file}")"
		emit "${case_id}" "e2-${check_id}" "${status}" "${message}" "${extra}"
	done
}

write_e2_characterization() {
	local case_id="$1"
	local stage="$2"
	local e2_dir="${RESULTS_DIR}/e2"
	local e2_file="${e2_dir}/${case_id}-${stage}.json"
	local log_file="${LOG_DIR}/${case_id}-e2-${stage}.log"

	[ "${E2_CHARACTERIZATION}" = "1" ] || return 0

	mkdir -p "${e2_dir}"

	if wp_run eval-file /e1-tests/wp-e2-migration-characterization.php "${stage}" >"${e2_file}" 2>"${log_file}"; then
		emit "${case_id}" "e2-${stage}" "pass" "Captured E2 migration characterization evidence." "$(jq -nc --arg file "${e2_file}" '{e2_file:$file}')"
		emit_e2_checks "${case_id}" "${e2_file}"
		return 0
	fi

	fail_step "${case_id}" "e2-${stage}" "Could not capture E2 migration characterization evidence." "$(jq -nc --arg log "${log_file}" '{log:$log}')"
	return 1
}

seed_fixture() {
	local case_id="$1"

	run_logged "${case_id}" "seed_fixture" wp_run eval-file /e1-tests/wp-seed-fixture.php || return 1
}

run_fresh_case() {
	local case_id="fresh"
	local candidate_version
	local rc=0
	candidate_version="$(candidate_header_version "${ARTIFACT_DIR}/cf7-telegram-candidate.zip")"

	CURRENT_PROJECT="$(project_name_for_case "${case_id}")"
	cleanup_project

	if setup_site "${case_id}" \
		&& run_logged "${case_id}" "candidate_install" wp_run plugin install /artifacts/cf7-telegram-candidate.zip --force --activate \
		&& write_state "${case_id}" "after-activate"; then
		assert_active_version "${case_id}" "after-activate" "${candidate_version}" || true
		assert_cleanup_scheduled "${case_id}" "after-activate" || true

		run_logged "${case_id}" "candidate_reactivate" wp_run plugin activate cf7-telegram || true
		write_state "${case_id}" "after-reactivate" || rc=1
		if [ "${rc}" -eq 0 ]; then
			assert_cleanup_scheduled "${case_id}" "after-reactivate" || true
		fi

		run_logged "${case_id}" "deactivate" wp_run plugin deactivate cf7-telegram || rc=1
		if [ "${rc}" -eq 0 ]; then
			write_state "${case_id}" "after-deactivate" || rc=1
			assert_cleanup_absent "${case_id}" "after-deactivate" || true
		fi

		if [ "${rc}" -eq 0 ]; then
			run_logged "${case_id}" "uninstall" wp_run plugin uninstall cf7-telegram || rc=1
			write_state "${case_id}" "after-uninstall" || rc=1
			assert_cleanup_absent "${case_id}" "after-uninstall" || true
			assert_state_jq "${case_id}" "after-uninstall" '.plugin.file_exists == false and ([.options[].count] | add) == 0 and .post_counts.cf7tg_bot == 0 and .post_counts.cf7tg_chat == 0 and .post_counts.cf7tg_channel == 0 and .tables.post_connections_cf7_telegram.exists == false and .tables.post_connections_meta_cf7_telegram.exists == false and .tables.cf7tg_log.exists == false' "Uninstall removed plugin files, plugin options, plugin posts, cron, and plugin tables." || true
		fi
	else
		rc=1
	fi

	cleanup_project
	CURRENT_PROJECT=""
	return "${rc}"
}

run_upgrade_case() {
	local matrix_id="$1"
	local case_id="upgrade-${matrix_id}"
	local legacy_version candidate_version rollback_sql
	local rc=0

	legacy_version="$(legacy_version_for_case "${matrix_id}")"
	if [ -z "${legacy_version}" ] || [ "${legacy_version}" = "null" ]; then
		fail_step "${case_id}" "legacy_lookup" "Unknown matrix id."
		return 1
	fi

	candidate_version="$(candidate_header_version "${ARTIFACT_DIR}/cf7-telegram-candidate.zip")"
	rollback_sql="/runtime/${case_id}-rollback.sql"

	CURRENT_PROJECT="$(project_name_for_case "${case_id}")"
	cleanup_project

	if setup_site "${case_id}" \
		&& run_logged "${case_id}" "legacy_install" wp_run plugin install "/artifacts/cf7-telegram.${legacy_version}.zip" --force --activate \
		&& write_state "${case_id}" "legacy-active"; then
		assert_active_version "${case_id}" "legacy-active" "${legacy_version}" || true
	else
		rc=1
	fi

	if [ "${rc}" -eq 0 ]; then
		seed_fixture "${case_id}" || rc=1
		write_state "${case_id}" "legacy-seeded" || rc=1
		run_logged "${case_id}" "rollback_export" wp_run db export "${rollback_sql}" --path=/var/www/html || rc=1
	fi

	if [ "${rc}" -eq 0 ]; then
		run_logged "${case_id}" "candidate_upgrade" wp_run eval-file /e1-tests/wp-upgrade-candidate.php /artifacts/cf7-telegram-candidate.zip || rc=1
		write_state "${case_id}" "after-upgrade" || rc=1
		write_e2_characterization "${case_id}" "after-upgrade" || rc=1
		assert_active_version "${case_id}" "after-upgrade" "${candidate_version}" || true
		assert_cleanup_scheduled "${case_id}" "after-upgrade" || true
	fi

	if [ "${rc}" -eq 0 ]; then
		if assert_migration_scheduled "${case_id}" "after-upgrade"; then
			run_logged "${case_id}" "migration_event_run" wp_run cron event run cf7tg_migrations || true
		else
			emit "${case_id}" "migration_event_run" "skipped" "Migration cron execution was skipped because no event was scheduled." "$(jq -nc '{downstream_epic:"E2"}')"
		fi
		write_state "${case_id}" "after-migration-run" || rc=1
		write_e2_characterization "${case_id}" "after-migration-run" || rc=1
		if [ "${E2_CHARACTERIZATION}" = "1" ]; then
			write_e2_characterization "${case_id}" "rerun" || rc=1
			write_state "${case_id}" "after-second-migration-run" || rc=1
			write_e2_characterization "${case_id}" "after-second-migration-run" || rc=1
		fi
		assert_active_version "${case_id}" "after-migration-run" "${candidate_version}" || true
	fi

	if [ "${rc}" -eq 0 ]; then
		run_logged "${case_id}" "deactivate" wp_run plugin deactivate cf7-telegram || rc=1
		write_state "${case_id}" "after-deactivate" || rc=1
		assert_cleanup_absent "${case_id}" "after-deactivate" || true
	fi

	if [ "${rc}" -eq 0 ]; then
		run_logged "${case_id}" "reactivate" wp_run plugin activate cf7-telegram || rc=1
		write_state "${case_id}" "after-reactivate" || rc=1
		assert_cleanup_scheduled "${case_id}" "after-reactivate" || true
	fi

	if [ "${rc}" -eq 0 ]; then
		run_logged "${case_id}" "uninstall" wp_run plugin uninstall cf7-telegram --deactivate || rc=1
		write_state "${case_id}" "after-uninstall" || rc=1
		assert_cleanup_absent "${case_id}" "after-uninstall" || true
	fi

	if [ "${rc}" -eq 0 ]; then
		run_logged "${case_id}" "rollback_import" wp_run db import "${rollback_sql}" --path=/var/www/html || rc=1
		run_logged "${case_id}" "rollback_plugin_restore" wp_run plugin install "/artifacts/cf7-telegram.${legacy_version}.zip" --force --activate || rc=1
		write_state "${case_id}" "after-rollback" || rc=1
		assert_active_version "${case_id}" "after-rollback" "${legacy_version}" || true
		assert_state_jq "${case_id}" "after-rollback" '.plugin.active == true and (.options.wpcf7_telegram_.count >= 2 or .options.cf7tg_.count >= 1)' "Rollback restored the legacy plugin and pre-upgrade data/options." || true
	fi

	cleanup_project
	CURRENT_PROJECT=""
	return "${rc}"
}

write_summary() {
	jq -s \
		--arg run_id "${RUN_ID}" \
		--arg workdir "${WORKDIR}" \
		--arg results_dir "${RESULTS_DIR}" \
		--arg source_manifest "${SOURCE_MANIFEST}" \
		--arg wp_version "${WP_VERSION}" \
		--arg wp_cli_image "${WP_CLI_IMAGE}" \
		--arg cf7_version "${CF7_VERSION}" \
		--arg fixture "${FIXTURE}" \
		'{
			run_id: $run_id,
			workdir: $workdir,
			results_dir: $results_dir,
			source_manifest: $source_manifest,
			environment: {
				wp_version: $wp_version,
				wp_cli_image: $wp_cli_image,
				contact_form_7_version: $cf7_version,
				fixture: $fixture,
				e2_characterization: env.CF7TG_E2_CHARACTERIZATION,
				uses_repo_docker_compose: false,
				dev_database_guard: "Harness creates a temporary Compose file and project; it does not use docker-compose.yml or its persistent MySQL volume."
			},
			total_steps: length,
			passed_steps: ([.[] | select(.status == "pass")] | length),
			expected_failed_steps: ([.[] | select(.status == "expected_fail")] | length),
			failed_steps: ([.[] | select(.status == "fail")] | length),
			expected_failures: [.[] | select(.status == "expected_fail")],
			failures: [.[] | select(.status == "fail")],
			evidence: .
		}' "${EVIDENCE_JSONL}" > "${SUMMARY_JSON}"
	SUMMARY_WRITTEN=1
}

require_tools
write_compose_file
prepare_candidate
download_and_verify_sources

if [ "${ARTIFACT_ONLY}" -eq 1 ]; then
	emit "artifact-only" "complete" "pass" "Artifact verification completed; Docker smoke cases were skipped by request."
	write_summary
	echo "E1 artifact verification complete: ${SUMMARY_JSON}"
	exit 0
fi

for case_id in "${CASES[@]}"; do
	case "${case_id}" in
		fresh)
			run_fresh_case || true
			;;
		upgrade-*)
			run_upgrade_case "${case_id#upgrade-}" || true
			;;
		*)
			fail_step "${case_id}" "case" "Unknown case."
			;;
	esac
done

write_summary

echo "E1 smoke matrix evidence: ${SUMMARY_JSON}"
if [ "${FAILURES}" -gt 0 ]; then
	echo "E1 smoke matrix failed with ${FAILURES} failing step(s)." >&2
	exit 1
fi

echo "E1 smoke matrix passed."
