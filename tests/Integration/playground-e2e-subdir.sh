#!/usr/bin/env bash
#
# Behavioural end-to-end test of a SUBDIRECTORY WordPress install in Playground.
#
# A second, deliberately separate Playground boot — WordPress lives under `/sub`
# (set through --site-url) rather than the domain root — that asserts the one
# property the root run cannot: the plugin resolves and serves its artifacts
# home-relative, so both the per-page `.md` and the llms singletons work under a
# subpath and stay contained inside the cache base. It reuses the same blueprint
# and seed as playground-e2e.sh (their paths are site-url-independent) and runs a
# focused subset of assertions; it is a separate run because the second boot
# roughly doubles the e2e wall-clock.
#
# Per docs/adr/0004 there is NO DDEV fallback: a failure is raised to the
# maintainer, not worked around by switching runtimes.
#
# Usage:
#   bash playground-e2e-subdir.sh [--verbose]
#
# Exit codes:
#   0  every assertion passed
#   1  npx missing, the server failed to boot, or one or more assertions failed

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CLI_VERSION="3.1.36"
PORT="${KNTNT_E2E_SUBDIR_PORT:-9413}"
HOST="http://127.0.0.1:${PORT}"
SUBPATH="/sub"
BASE="${HOST}${SUBPATH}"
MOUNT_TARGET="/wordpress/wp-content/plugins/kntnt-ai-visibility"

VERBOSE=false
[[ "${1:-}" == "--verbose" ]] && VERBOSE=true

# Playground is driven through @wp-playground/cli, which needs Node's npx.
if ! command -v npx &>/dev/null; then
	echo "Error: npx (Node.js) is required to run the Playground e2e." >&2
	exit 1
fi

LOG="$(mktemp)"
HDR="$(mktemp)"
BODYF="$(mktemp)"
SERVER_PID=""

cleanup() {
	[[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null
	[[ -n "$SERVER_PID" ]] && wait "$SERVER_PID" 2>/dev/null
	rm -f "$LOG" "$HDR" "$BODYF"
}
trap cleanup EXIT

echo "Booting WordPress Playground (PHP 8.5) under ${BASE} with the plugin mounted…"

# Boot with a subdirectory site URL; a single worker is mandatory for the same
# VFS/SQLite-snapshot reason as the root run.
npx --yes "@wp-playground/cli@${CLI_VERSION}" server \
	--php=8.5 \
	--wp=latest \
	--workers=1 \
	--port="${PORT}" \
	--site-url="${BASE}" \
	--mount="${PLUGIN_ROOT}:${MOUNT_TARGET}" \
	--blueprint="${SCRIPT_DIR}/e2e-blueprint.json" \
	>"$LOG" 2>&1 &
SERVER_PID=$!

# Wait for the subdirectory home to answer, bailing early if the process dies.
ready=false
for _ in $(seq 1 90); do
	if curl -fsS -o /dev/null "${BASE}/"; then
		ready=true
		break
	fi
	kill -0 "$SERVER_PID" 2>/dev/null || break
	sleep 2
done

if [[ "$ready" != true ]]; then
	echo "Error: Playground subdirectory server did not become ready." >&2
	cat "$LOG" >&2
	exit 1
fi

# Warm the Markdown path under the subpath (the first cold WASM SQLite query can
# miss), exactly as the root run does before asserting.
warm=false
for _ in $(seq 1 20); do
	if [[ "$(curl -sS --path-as-is -o /dev/null -w '%{http_code}' "${BASE}/about.md")" == "200" ]]; then
		warm=true
		break
	fi
	sleep 1
done
if [[ "$warm" != true ]]; then
	echo "Error: the subdirectory Markdown path never warmed up (${BASE}/about.md never 200)." >&2
	cat "$LOG" >&2
	exit 1
fi

[[ "$VERBOSE" == true ]] && cat "$LOG"

PASS=0
FAIL=0
ok() {
	PASS=$((PASS + 1))
	echo "  ✓ $1"
}
no() {
	FAIL=$((FAIL + 1))
	echo "  ✗ $1" >&2
}

STATUS=""
do_req() {
	STATUS="$(curl -sS --path-as-is -D "$HDR" -o "$BODYF" -w '%{http_code}' "$@" 2>/dev/null)"
}
expect_status() { [[ "$STATUS" == "$1" ]] && ok "$2 (status $1)" || no "$2 (expected $1, got $STATUS)"; }
header_has() { grep -iqF -- "$1" "$HDR" && ok "$2" || no "$2 — header missing: $1"; }
body_has() { grep -qF -- "$1" "$BODYF" && ok "$2" || no "$2 — body missing: $1"; }
body_lacks() { ! grep -qF -- "$1" "$BODYF" && ok "$2" || no "$2 — body unexpectedly contains: $1"; }

echo ""
echo "Scenario S1: a per-page .md resolves under the subdirectory"
do_req "${BASE}/about.md"
expect_status 200 "GET ${SUBPATH}/about.md is 200"
header_has 'text/markdown; charset=utf-8' "subdirectory .md is text/markdown"
body_has '# About Us' "subdirectory .md leads with the visible H1"
body_has "canonical_url: \"${BASE}/about/\"" "subdirectory .md carries the subpath-aware canonical URL"

echo ""
echo "Scenario S2: /index.md resolves for the subdirectory home"
do_req "${BASE}/index.md"
expect_status 200 "GET ${SUBPATH}/index.md is 200"
body_has '# Home' "subdirectory /index.md leads with the home H1"

echo ""
echo "Scenario S3: /llms.txt resolves under the subdirectory and links subpath .md"
do_req "${BASE}/llms.txt"
expect_status 200 "GET ${SUBPATH}/llms.txt is 200"
header_has 'text/plain; charset=utf-8' "subdirectory /llms.txt is text/plain"
body_has '## Pages' "subdirectory /llms.txt has a Pages section"
body_has "${BASE}/about.md)" "subdirectory /llms.txt links the subpath-aware .md alternate"

echo ""
echo "Scenario S4: /llms-full.txt resolves under the subdirectory"
do_req "${BASE}/llms-full.txt"
expect_status 200 "GET ${SUBPATH}/llms-full.txt is 200"
header_has 'text/plain; charset=utf-8' "subdirectory /llms-full.txt is text/plain"
body_has '# About Us' "subdirectory /llms-full.txt concatenates the page Markdown"

echo ""
echo "Scenario S5: traversal against the subdirectory paths stays contained"
for payload in "${SUBPATH}/llms.txt/../../wp-config.php" "${SUBPATH}/../wp-config.php.md" "${SUBPATH}/%2e%2e%2fwp-config.php.md"; do
	do_req "${HOST}${payload}"
	body_lacks 'DB_PASSWORD' "traversal ${payload} leaks no wp-config"
	body_lacks 'DB_NAME' "traversal ${payload} leaks no DB credentials"
done

echo ""
echo "═══ subdirectory e2e summary: ${PASS} passed, ${FAIL} failed ═══"
if [[ "$FAIL" -gt 0 ]]; then
	echo "Some assertions failed. Server log follows:" >&2
	cat "$LOG" >&2
	exit 1
fi
echo "Playground subdirectory behavioural e2e passed on PHP 8.5 / WordPress latest."
exit 0
