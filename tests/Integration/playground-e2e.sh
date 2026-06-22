#!/usr/bin/env bash
#
# Behavioural end-to-end test of the Markdown alternate in WordPress Playground.
#
# Boots a real Playground HTTP server (WASM PHP 8.4) with the plugin mounted and
# fixtures seeded (e2e-blueprint.json + e2e-seed.php), then drives the actual
# request lifecycle over HTTP with curl and asserts the contracts of
# docs/spec/markdown-alternate.md and docs/spec/llms-txt.md: a real `.md` (200 +
# text/markdown + the front-matter/H1/converted body), `?format=markdown`,
# `Accept` negotiation (Vary + the steering alternate Link), `/index.md`, a 404
# for ineligible content, a 403 for password-protected content, a 301 for a
# trailing slash, and path-traversal payloads that never leak; then `/llms.txt`
# (text/plain, the curated index, no canonical/Vary), `/llms-full.txt` (the
# concatenated Pages-only full text), the early-router cache hit with a stable
# ETag and a conditional 304, HEAD, traversal/evasion against the singletons, and
# an invalidation round-trip (publishing a page bumps the cache version, the
# rebuilt /llms.txt reflects the change, and the orphaned old aggregate is pruned).
#
# Per docs/adr/0004 there is NO DDEV fallback: a failure is raised to the
# maintainer, not worked around by switching runtimes.
#
# Usage:
#   bash playground-e2e.sh [--verbose]
#
# Exit codes:
#   0  every assertion passed
#   1  npx missing, the server failed to boot, or one or more assertions failed

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CLI_VERSION="3.1.36"
PORT="${KNTNT_E2E_PORT:-9412}"
BASE="http://127.0.0.1:${PORT}"
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

echo "Booting WordPress Playground (PHP 8.4) on ${BASE} with the plugin mounted…"

# Start the server in the background; the blueprint activates the plugin and
# seeds the fixtures before the server begins accepting requests. A single
# worker is mandatory: Playground gives each worker its own VFS/SQLite snapshot,
# so with several workers the seeded content and the files this plugin writes to
# the cache on one request are invisible to the worker handling the next.
npx --yes "@wp-playground/cli@${CLI_VERSION}" server \
	--php=8.4 \
	--wp=latest \
	--workers=1 \
	--port="${PORT}" \
	--site-url="${BASE}" \
	--mount="${PLUGIN_ROOT}:${MOUNT_TARGET}" \
	--blueprint="${SCRIPT_DIR}/e2e-blueprint.json" \
	>"$LOG" 2>&1 &
SERVER_PID=$!

# Wait for the home page to answer, bailing early if the server process dies.
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
	echo "Error: Playground server did not become ready." >&2
	cat "$LOG" >&2
	exit 1
fi

# Warm the Markdown path. Playground's WASM SQLite intermittently fails the very
# first content query of a fresh boot (the page lookup returns nothing, so the
# first cold `.md` request 404s), but it resolves correctly once warm — every
# repeat in the same boot succeeds, and the resolution logic is unit-tested. This
# is an environment warmup artifact, not a plugin bug, so poll the first `.md`
# until it serves before asserting, exactly as the home page is polled above.
warm=false
for _ in $(seq 1 20); do
	if [[ "$(curl -sS --path-as-is -o /dev/null -w '%{http_code}' "${BASE}/about.md")" == "200" ]]; then
		warm=true
		break
	fi
	sleep 1
done
if [[ "$warm" != true ]]; then
	echo "Error: the Markdown path never warmed up (/about.md never returned 200)." >&2
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

# Perform a request, capturing status, headers and body. --path-as-is keeps
# traversal payloads intact instead of letting curl normalise them away.
STATUS=""
do_req() {
	STATUS="$(curl -sS --path-as-is -D "$HDR" -o "$BODYF" -w '%{http_code}' "$@" 2>/dev/null)"
}

expect_status() { [[ "$STATUS" == "$1" ]] && ok "$2 (status $1)" || no "$2 (expected $1, got $STATUS)"; }
header_has() { grep -iqF -- "$1" "$HDR" && ok "$2" || no "$2 — header missing: $1"; }
header_lacks() { ! grep -iqF -- "$1" "$HDR" && ok "$2" || no "$2 — header unexpectedly present: $1"; }
body_has() { grep -qF -- "$1" "$BODYF" && ok "$2" || no "$2 — body missing: $1"; }
body_lacks() { ! grep -qF -- "$1" "$BODYF" && ok "$2" || no "$2 — body unexpectedly contains: $1"; }

echo ""
echo "Scenario 1: a real .md request"
do_req "${BASE}/about.md"
expect_status 200 "GET /about.md is 200"
header_has 'text/markdown; charset=utf-8' "GET /about.md is text/markdown"
header_has 'X-Content-Type-Options: nosniff' "GET /about.md sends nosniff"
header_has 'rel="canonical"' "GET /about.md links its HTML canonical"
body_has '---' "front-matter fence present"
body_has 'title: "About Us"' "front-matter carries the title"
body_has 'canonical_url:' "front-matter carries the canonical URL"
body_has '# About Us' "body leads with the visible H1"
body_has '## Our team' "HTML was converted to Markdown"
body_has "${BASE}/contact/" "relative links were absolutised"

echo ""
echo "Scenario 1b: a real .md request for a post (publicly_queryable type)"
do_req "${BASE}/hello-md.md"
expect_status 200 "GET /hello-md.md is 200"
header_has 'text/markdown; charset=utf-8' "GET /hello-md.md is text/markdown"
body_has '# Hello Markdown' "post body leads with its H1"

echo ""
echo "Scenario 2: ?format=markdown on the canonical URL"
do_req "${BASE}/about/?format=markdown"
expect_status 200 "GET /about/?format=markdown is 200"
header_has 'text/markdown; charset=utf-8' "?format=markdown is text/markdown"

echo ""
echo "Scenario 3: Accept negotiation (inline, uncached)"
do_req "${BASE}/about/" -H 'Accept: text/markdown'
expect_status 200 "GET /about/ (Accept: text/markdown) is 200"
header_has 'text/markdown; charset=utf-8' "negotiated response is text/markdown"
header_has 'Vary: Accept' "negotiated response varies on Accept"
header_has 'rel="alternate"' "negotiated response steers to the .md alternate"
body_has '# About Us' "negotiated body is the Markdown alternate"

echo ""
echo "Scenario 4: /index.md for the slug-index home"
do_req "${BASE}/index.md"
expect_status 200 "GET /index.md is 200"
header_has 'text/markdown; charset=utf-8' "GET /index.md is text/markdown"
body_has '# Home' "index body leads with the home H1"

echo ""
echo "Scenario 5: 404 for ineligible (draft) content"
do_req "${BASE}/draft-item.md"
expect_status 404 "GET /draft-item.md is 404"

echo ""
echo "Scenario 6: 403 for password-protected content"
do_req "${BASE}/secret.md"
expect_status 403 "GET /secret.md is 403"
header_has 'text/plain' "403 body is plain text"
body_has 'password protected' "403 explains the password protection"

echo ""
echo "Scenario 7: 301 for a trailing-slashed .md"
do_req "${BASE}/about.md/"
expect_status 301 "GET /about.md/ is 301"
header_has 'Location:' "301 sends a Location"
header_has '/about.md' "301 targets the de-slashed .md"

echo ""
echo "Scenario 8: path-traversal payloads never leak"
for payload in "/%2e%2e%2f%2e%2e%2fwp-config.php.md" "/../../wp-config.php.md"; do
	do_req "${BASE}${payload}"
	[[ "$STATUS" != "200" ]] && ok "traversal ${payload} is not served (status $STATUS)" || no "traversal ${payload} returned 200"
	body_lacks 'DB_PASSWORD' "traversal ${payload} leaks no wp-config"
	body_lacks 'DB_NAME' "traversal ${payload} leaks no DB credentials"
done

# Warm the llms aggregate path the same way as the `.md` path: the first cold
# enumeration query can miss on WASM SQLite, so poll /llms.txt until it serves.
warm=false
for _ in $(seq 1 20); do
	if [[ "$(curl -sS --path-as-is -o /dev/null -w '%{http_code}' "${BASE}/llms.txt")" == "200" ]]; then
		warm=true
		break
	fi
	sleep 1
done
[[ "$warm" == true ]] || no "/llms.txt never warmed up"

echo ""
echo "Scenario 9: GET /llms.txt — the curated index"
do_req "${BASE}/llms.txt"
expect_status 200 "GET /llms.txt is 200"
header_has 'text/plain; charset=utf-8' "GET /llms.txt is text/plain"
header_has 'X-Content-Type-Options: nosniff' "GET /llms.txt sends nosniff"
header_lacks 'rel="canonical"' "GET /llms.txt sends no canonical link"
header_lacks 'Vary:' "GET /llms.txt sends no Vary"
body_has 'Full text: /llms-full.txt' "index intro references the full file"
body_has '## Pages' "index has a Pages section"
body_has '## Posts' "index has a Posts section"
body_has '/about.md)' "index links the page .md alternate"
body_has 'The team behind the site.' "index carries the page excerpt"
body_has 'Hello Markdown' "index lists the published post"
body_lacks 'Secret' "password-protected page absent from the index"
body_lacks 'Draft Item' "draft absent from the index"

echo ""
echo "Scenario 10: GET /llms-full.txt — the concatenated full text (Pages only)"
do_req "${BASE}/llms-full.txt"
expect_status 200 "GET /llms-full.txt is 200"
header_has 'text/plain; charset=utf-8' "GET /llms-full.txt is text/plain"
body_has '# About Us' "full file concatenates the page Markdown"
body_has '## Our team' "full file carries the converted page body"
body_has 'Welcome home.' "full file includes the home page"
body_lacks 'A post body.' "the post is absent (Pages only by default)"
body_lacks 'Members only.' "the password-protected page is absent"
body_lacks 'Not published.' "the draft is absent"

echo ""
echo "Scenario 11: early-router cache hit and conditional request"
do_req "${BASE}/llms.txt"
ETAG1="$(grep -i '^ETag:' "$HDR" | tr -d '\r\n' | awk '{print $2}')"
header_has 'ETag:' "GET /llms.txt carries an ETag validator"
do_req "${BASE}/llms.txt"
ETAG2="$(grep -i '^ETag:' "$HDR" | tr -d '\r\n' | awk '{print $2}')"
[[ -n "$ETAG1" && "$ETAG1" == "$ETAG2" ]] && ok "the cached aggregate serves a stable ETag across requests" || no "ETag differed between requests ($ETAG1 vs $ETAG2)"
do_req "${BASE}/llms.txt" -H "If-None-Match: ${ETAG1}"
expect_status 304 "a matching If-None-Match yields 304"

echo ""
echo "Scenario 12: HEAD /llms.txt"
HEAD_STATUS="$(curl -sS -o /dev/null -w '%{http_code}' -I "${BASE}/llms.txt")"
[[ "$HEAD_STATUS" == "200" ]] && ok "HEAD /llms.txt is 200" || no "HEAD /llms.txt expected 200, got $HEAD_STATUS"

echo ""
echo "Scenario 13: traversal and evasion against the singleton paths"
# The invariant is the security one: the plugin must never serve a file outside
# the cache base (no wp-config / credential leak) and must never serve the llms
# singleton for an evasion path. The fall-through status itself is WordPress's
# call — e.g. `/llms.txt/../../wp-config.php` is normalised to `/wp-config.php`,
# which WordPress executes (a harmless 200 with no source leak), so status is not
# asserted; the body invariants are.
for payload in "/llms.txt/../../wp-config.php" "/llms.txt%00" "/llms.txt.md" "/LLMS.TXT"; do
	do_req "${BASE}${payload}"
	body_lacks 'DB_PASSWORD' "evasion ${payload} leaks no wp-config"
	body_lacks 'DB_NAME' "evasion ${payload} leaks no DB credentials"
	body_lacks 'Full text: /llms-full.txt' "evasion ${payload} is not served as llms.txt"
done

echo ""
echo "Scenario 14: invalidation round-trip — a content change bumps the version and prunes the old aggregate"
# The aggregate is cached at the current version (one file in the kind dir), and
# does not yet list the page we are about to publish.
TOKEN="kntnt-e2e-secret"
do_req "${BASE}/llms.txt"
ETAG_BEFORE="$(grep -i '^ETag:' "$HDR" | tr -d '\r\n' | awk '{print $2}')"
body_lacks 'Fresh Page' "the page to publish is absent from /llms.txt before publishing"
do_req "${BASE}/?kntnt_e2e=cache_count&kind=llms-txt&token=${TOKEN}"
body_has 'COUNT 1' "exactly one llms-txt aggregate file is cached before the change"

# Publish a new page through the test-only endpoint; this fires save_post /
# transition_post_status, so the llms invalidation bumps the cache version.
do_req "${BASE}/?kntnt_e2e=publish&type=page&title=Fresh+Page&slug=fresh-page&token=${TOKEN}"
body_has 'PUBLISHED' "the test endpoint published the new page"

# The bump makes the next /llms.txt miss in the early router and rebuild lazily;
# poll until the rebuilt index reflects the new page (also rides out the WASM
# SQLite cold-query flake on the fresh enumeration).
reflected=false
for _ in $(seq 1 20); do
	do_req "${BASE}/llms.txt"
	if grep -qF -- 'Fresh Page' "$BODYF"; then
		reflected=true
		break
	fi
	sleep 1
done
[[ "$reflected" == true ]] && ok "the rebuilt /llms.txt reflects the published page" || no "/llms.txt never reflected the published page"
ETAG_AFTER="$(grep -i '^ETag:' "$HDR" | tr -d '\r\n' | awk '{print $2}')"
[[ -n "$ETAG_BEFORE" && -n "$ETAG_AFTER" && "$ETAG_BEFORE" != "$ETAG_AFTER" ]] && ok "the ETag changed after the content change" || no "the ETag did not change across the bump ($ETAG_BEFORE vs $ETAG_AFTER)"

# After the rebuild the handler pruned the orphaned previous version, so the kind
# directory again holds exactly one aggregate file (this is task 1's behaviour;
# without pruning there would be two).
do_req "${BASE}/?kntnt_e2e=cache_count&kind=llms-txt&token=${TOKEN}"
body_has 'COUNT 1' "the stale previous-version aggregate was pruned (still one file)"

echo ""
echo "═══ e2e summary: ${PASS} passed, ${FAIL} failed ═══"
if [[ "$FAIL" -gt 0 ]]; then
	echo "Some assertions failed. Server log follows:" >&2
	cat "$LOG" >&2
	exit 1
fi
echo "Playground behavioural e2e passed on PHP 8.4 / WordPress latest."
exit 0
