#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FILTER="${SCRIPT_DIR}/e5-plugin-check-results.jq"
WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/cf7tg-e5-plugin-check-parser.XXXXXX")"

cleanup() {
	rm -rf "${WORKDIR}"
}
trap cleanup EXIT

printf '[]\n' > "${WORKDIR}/empty.json"
printf '[{"type":"ERROR","code":"fixture"}]\n' > "${WORKDIR}/findings.json"
printf 'true\n' > "${WORKDIR}/boolean.json"
printf '{"type":"ERROR"}\n' > "${WORKDIR}/object.json"

jq -e -f "${FILTER}" "${WORKDIR}/empty.json" > "${WORKDIR}/empty.out.json"
jq -e -f "${FILTER}" "${WORKDIR}/findings.json" > "${WORKDIR}/findings.out.json"

jq -e 'type == "array" and length == 0' "${WORKDIR}/empty.out.json" >/dev/null
jq -e 'type == "array" and length == 1 and .[0].type == "ERROR"' "${WORKDIR}/findings.out.json" >/dev/null

if jq -e -f "${FILTER}" "${WORKDIR}/boolean.json" >/dev/null 2>&1; then
	echo "Plugin Check parser accepted a boolean result." >&2
	exit 1
fi

if jq -e -f "${FILTER}" "${WORKDIR}/object.json" >/dev/null 2>&1; then
	echo "Plugin Check parser accepted an object result." >&2
	exit 1
fi

echo "Plugin Check parser fixtures passed."
