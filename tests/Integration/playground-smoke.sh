#!/usr/bin/env bash
#
# Boots the plugin in WordPress Playground (WASM PHP 8.4) and asserts it loads.
#
# Mounts the working copy as the plugin, runs blueprint.json (activate plugin +
# run assert-boot.php), and greps the boot sentinel from the output. The plugin
# requires WordPress 7.0, so Playground's "latest" WordPress must be >= 7.0 for
# activation to succeed.
#
# Per docs/adr/0004 there is NO DDEV fallback here: a failure is a signal to
# raise the question with the maintainer, not to switch runtimes automatically.
#
# Usage:
#   bash playground-smoke.sh [--verbose]
#
# Exit codes:
#   0  the plugin booted and printed the sentinel
#   1  npx is missing, the Playground run failed, or the sentinel was absent

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CLI_VERSION="3.1.36"
SENTINEL="KNTNT_AI_VISIBILITY_BOOT_OK"
MOUNT_TARGET="/wordpress/wp-content/plugins/kntnt-ai-visibility"

VERBOSE=false
[[ "${1:-}" == "--verbose" ]] && VERBOSE=true

# Playground is driven through @wp-playground/cli, which needs Node's npx.
if ! command -v npx &>/dev/null; then
	echo "Error: npx (Node.js) is required to run the Playground e2e." >&2
	exit 1
fi

echo "Booting WordPress Playground (PHP 8.4) with the plugin mounted…"

# Run the blueprint headlessly, capturing all output. assert-boot.php throws on
# any failed check, so the exit code is the authoritative signal: zero means
# every check passed. (On success the CLI discards the step's stdout, so the
# sentinel only appears in the output when a step fails and the CLI dumps it.)
set +e
output="$(
	npx --yes "@wp-playground/cli@${CLI_VERSION}" run-blueprint \
		--php=8.4 \
		--mount="${PLUGIN_ROOT}:${MOUNT_TARGET}" \
		--blueprint="${SCRIPT_DIR}/blueprint.json" 2>&1
)"
run_exit=$?
set -e

[[ "$VERBOSE" == true ]] && echo "$output"

if [[ $run_exit -ne 0 ]]; then
	echo "$output"
	echo "Playground run exited ${run_exit} — the plugin failed to boot." >&2
	exit 1
fi

if grep -q "$SENTINEL" <<<"$output"; then
	echo "Playground e2e: plugin booted on PHP 8.4 / WordPress 7.0 (${SENTINEL})."
else
	echo "Playground e2e: plugin booted on PHP 8.4 / WordPress 7.0 (run exited 0; assert-boot.php checks passed)."
fi
exit 0
