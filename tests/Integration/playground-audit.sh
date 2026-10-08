#!/usr/bin/env bash
# Regression tests for Bogo, rendering context and public-cache lifecycle.
# KNTNT_AUDIT_EXPLICIT=1 also prefixes the default language (/en and /sv).
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PORT="${KNTNT_AUDIT_PORT:-9414}"
BASE="http://127.0.0.1:${PORT}"
SCRATCH="$(mktemp -d)"
SERVER_PID=""
cleanup() {
	[[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null
	[[ -n "$SERVER_PID" ]] && wait "$SERVER_PID" 2>/dev/null
	rm -f "$SCRATCH/log" "$SCRATCH/body" "$SCRATCH/headers"
	rmdir "$SCRATCH"
}
trap cleanup EXIT

npx --yes @wp-playground/cli@3.1.36 server --php=8.4 --wp=latest --workers=1 \
	--port="$PORT" --site-url="$BASE" \
	--mount="$PLUGIN_ROOT:/wordpress/wp-content/plugins/kntnt-ai-visibility" \
	--blueprint="$SCRIPT_DIR/audit-blueprint.json" >"$SCRATCH/log" 2>&1 &
SERVER_PID=$!
ready=false
for _ in $(seq 1 90); do
	if curl -fsS -o /dev/null "$BASE/" 2>/dev/null; then ready=true; break; fi
	kill -0 "$SERVER_PID" 2>/dev/null || break
	sleep 2
done
if [[ "$ready" != true ]]; then cat "$SCRATCH/log"; exit 1; fi

# Assert the worker's runtime before any language or cache result is accepted.
source "$SCRIPT_DIR/playground-php-version.sh"
assert_playground_php_version "$BASE" || exit 1

PASS=0
FAIL=0
check() {
	local label="$1"
	shift
	if "$@"; then PASS=$((PASS + 1)); echo "  ✓ $label"
	else
		FAIL=$((FAIL + 1)); echo "  ✗ $label"
		if [[ "$label" == *'HTTP headers'* ]]; then
			grep -i '^link:' "$SCRATCH/headers" || true
		fi
	fi
}
request() {
	STATUS="$(curl -sS -D "$SCRATCH/headers" -o "$SCRATCH/body" -w '%{http_code}' "$@")"
}
contains() { sed 's/\\_/_/g' "$SCRATCH/body" | grep -qF -- "$1"; }
lacks() { ! contains "$1"; }
control() { curl -fsS "$BASE/?audit_token=fixture-only&audit_action=$1${2:-}"; }

IDS="$(control flush)"
# Playground may answer HTTP while the final blueprint step is still running.
for _ in $(seq 1 30); do
	if printf '%s' "$IDS" | php -r '$a=json_decode(stream_get_contents(STDIN),true); exit(isset($a["en_GB"], $a["sv_SE"])?0:1);'; then break; fi
	sleep 1
	IDS="$(control flush)"
done
if ! printf '%s' "$IDS" | php -r '$a=json_decode(stream_get_contents(STDIN),true); exit(isset($a["en_GB"], $a["sv_SE"])?0:1);'; then
	echo 'Fixture setup did not complete'; cat "$SCRATCH/log"; exit 1
fi
EN_ID="$(printf '%s' "$IDS" | php -r '$a=json_decode(stream_get_contents(STDIN),true); echo $a["en_GB"];')"
SV_ID="$(printf '%s' "$IDS" | php -r '$a=json_decode(stream_get_contents(STDIN),true); echo $a["sv_SE"];')"
EN_BASE="$BASE"
if [[ "${KNTNT_AUDIT_EXPLICIT:-0}" == 1 ]]; then
	control explicit >/dev/null
	EN_BASE="$BASE/en"
fi
for round in cold warm; do
	request "$EN_BASE/same-slug.md"
	check "$round English Markdown is public" test "$STATUS" = 200
	check "$round English content" contains BODY-en_GB
	check "$round English has no Swedish content" lacks BODY-sv_SE
	check "$round English render context" contains "CONTEXT-$EN_ID-en_GB"
	request "$BASE/sv/same-slug.md"
	check "$round Swedish Markdown is public" test "$STATUS" = 200
	check "$round Swedish content" contains BODY-sv_SE
	check "$round Swedish has no English content" lacks BODY-en_GB
	check "$round Swedish render context" contains "CONTEXT-$SV_ID-sv_SE"
done
request "$EN_BASE/same-slug/"
check 'English HTML resolves without a redirect' test "$STATUS" = 200
check 'English HTML canonical includes its configured prefix' contains "href=\"$EN_BASE/same-slug/\""
request "$EN_BASE/same-slug/?format=markdown"
check 'English query negotiation' contains BODY-en_GB
request -H 'Accept: text/markdown' "$EN_BASE/same-slug/"
check 'English Accept negotiation' contains BODY-en_GB
request "$BASE/sv/same-slug/?format=markdown"
check 'Swedish query negotiation' contains BODY-sv_SE
request -H 'Accept: text/markdown' "$BASE/sv/same-slug/"
check 'Swedish Accept negotiation' contains BODY-sv_SE
request "$BASE/sv/same-slug/"
check 'Global index discovery stays at the site root' grep -qF "<$BASE/llms.txt>" "$SCRATCH/headers"
request "$BASE/llms.txt"
check 'Index includes English' contains "$EN_BASE/same-slug.md"
check 'Index includes Swedish' contains "$BASE/sv/same-slug.md"
request "$BASE/llms-full.txt"
check 'Full text includes English render context' contains "CONTEXT-$EN_ID-en_GB"
check 'Full text includes Swedish render context' contains "CONTEXT-$SV_ID-sv_SE"
request "$EN_BASE/secret.md?audit_token=fixture-only&audit_action=unlock"
check 'Authorised password access cannot create a public artifact' test "$STATUS" = 403
request "$EN_BASE/secret.md"
check 'No subsequent anonymous password leak' lacks PASSWORD-CONTENT

for action in draft rename delete protect; do
	request "$EN_BASE/$action-later.md"
	check "$action fixture warms" test "$STATUS" = 200
	control "$action" "&slug=$action-later" >/dev/null
	request "$EN_BASE/$action-later.md"
	check "$action invalidates previous public bytes" lacks "PRIVATE-CANDIDATE-$action-later"
done
control front >/dev/null
control flush >/dev/null
request "$EN_BASE/index.md"
check 'English static front Markdown' contains BODY-en_GB
request "$EN_BASE/index.md"
check 'Warm English front has the exact HTML canonical' grep -qF "<$EN_BASE/>; rel=\"canonical\"" "$SCRATCH/headers"
request "$EN_BASE/"
check 'English static front advertises its index.md in HTML' contains "$EN_BASE/index.md"
check 'English static front advertises its index.md in HTTP headers' grep -qF "<$EN_BASE/index.md>" "$SCRATCH/headers"
request "$BASE/sv/index.md"
check 'Swedish static front Markdown' contains BODY-sv_SE
request "$BASE/sv/index.md"
check 'Warm Swedish front has the exact HTML canonical' grep -qF "<$BASE/sv/>; rel=\"canonical\"" "$SCRATCH/headers"
request "$BASE/sv/"
check 'Swedish static front advertises its index.md' contains "$BASE/sv/index.md"
check 'Swedish static front advertises its index.md in HTTP headers' grep -qF "<$BASE/sv/index.md>" "$SCRATCH/headers"
request "$BASE/llms.txt"
check 'Index links the English front alternate' contains "$EN_BASE/index.md"
check 'Index links the translated front alternate' contains "$BASE/sv/index.md"
echo "Audit: $PASS passed, $FAIL failed"
[[ "$FAIL" == 0 ]]
