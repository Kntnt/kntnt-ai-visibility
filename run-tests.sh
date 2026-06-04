#!/usr/bin/env bash
#
# Single entry point for the Kntnt AI Visibility test suite.
#
# Two levels:
#   Level 1  PHP unit tests (Pest + Brain Monkey + Mockery + Patchwork).
#   Level 2  End-to-end smoke test that boots the plugin in WordPress Playground
#            (WASM PHP 8.5) via @wp-playground/cli — see tests/Integration.
#
# There is deliberately NO automatic DDEV fallback. If Playground cannot run the
# behaviour under test, that is raised to the maintainer as a decision, not
# resolved automatically (see docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md).
#
# Usage:
#   bash run-tests.sh              # Run both levels
#   bash run-tests.sh --unit-only  # Level 1 only
#   bash run-tests.sh --e2e-only   # Level 2 only
#   bash run-tests.sh --filter <p> # Pass a Pest --filter pattern to Level 1
#   bash run-tests.sh --verbose    # Surface full Playground output on Level 2
#
# Tool resolution: PHP_BIN / COMPOSER_BIN env vars override; otherwise PATH.
#
# Exit codes:
#   0  all selected levels passed
#   1  a usage error or an environment problem (missing tool, wrong PHP version)
#   2  one or more selected levels failed

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_FLOOR="8.5"

PHP_BIN="${PHP_BIN:-}"
COMPOSER_BIN="${COMPOSER_BIN:-}"
MODE="all"
FILTER=""
VERBOSE=false
UNIT_EXIT=-1
E2E_EXIT=-1

# Parse the command-line arguments.
while [[ $# -gt 0 ]]; do
	case "$1" in
		--unit-only)
			MODE="unit"
			shift
			;;
		--e2e-only)
			MODE="e2e"
			shift
			;;
		--filter)
			[[ $# -lt 2 ]] && {
				echo "Error: --filter requires a value." >&2
				exit 1
			}
			FILTER="$2"
			shift 2
			;;
		--verbose)
			VERBOSE=true
			shift
			;;
		*)
			echo "Unknown option: $1" >&2
			echo "Usage: bash run-tests.sh [--unit-only|--e2e-only] [--filter <pattern>] [--verbose]" >&2
			exit 1
			;;
	esac
done

# Resolve PHP and Composer from the environment overrides or PATH.
resolve_tools() {
	[[ -z "$PHP_BIN" ]] && PHP_BIN="$(command -v php 2>/dev/null || true)"
	[[ -z "$COMPOSER_BIN" ]] && COMPOSER_BIN="$(command -v composer 2>/dev/null || true)"

	local missing=()
	[[ -z "$PHP_BIN" ]] && missing+=("PHP (set PHP_BIN)")
	[[ -z "$COMPOSER_BIN" ]] && missing+=("Composer (set COMPOSER_BIN)")
	if [[ ${#missing[@]} -gt 0 ]]; then
		printf 'Error: missing required tool(s):\n' >&2
		printf '  - %s\n' "${missing[@]}" >&2
		exit 1
	fi
}

# Enforce the PHP 8.5 floor — the plugin and its bundled converter require it.
verify_php_version() {
	local version
	version="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo '0.0')"
	if [[ "$(printf '%s\n%s\n' "$PHP_FLOOR" "$version" | sort -V | head -1)" != "$PHP_FLOOR" ]]; then
		echo "Error: PHP ${PHP_FLOOR}+ required (found ${version})." >&2
		echo "Set PHP_BIN to a PHP ${PHP_FLOOR}+ binary, or install one in PATH." >&2
		exit 1
	fi
	echo "PHP:      $PHP_BIN ($version)"
	echo "Composer: $COMPOSER_BIN"
}

# Install dev dependencies when vendor/ is absent.
install_deps() {
	if [[ ! -d "$SCRIPT_DIR/vendor" ]]; then
		echo "Installing PHP dependencies…"
		"$COMPOSER_BIN" install --quiet
	fi
}

# Level 1: PHP unit tests.
run_unit() {
	echo ""
	echo "═══ Level 1: PHP unit tests (Pest) ═══"
	local args=(--testsuite=Unit --colors=always)
	[[ -n "$FILTER" ]] && args+=(--filter "$FILTER")
	if "$PHP_BIN" "$SCRIPT_DIR/vendor/bin/pest" "${args[@]}"; then
		UNIT_EXIT=0
	else
		UNIT_EXIT=$?
	fi
}

# Level 2: WordPress Playground end-to-end smoke test.
run_e2e() {
	echo ""
	echo "═══ Level 2: Playground e2e smoke test ═══"
	local args=()
	[[ "$VERBOSE" == true ]] && args+=(--verbose)
	if bash "$SCRIPT_DIR/tests/Integration/playground-smoke.sh" "${args[@]}"; then
		E2E_EXIT=0
	else
		E2E_EXIT=$?
		echo "" >&2
		echo "Playground could not boot the plugin. Per docs/adr/0004, this is NOT" >&2
		echo "auto-resolved with a DDEV fallback — raise it to the maintainer with" >&2
		echo "the options (DDEV / lower the converter to PHP 8.4 / other)." >&2
	fi
}

# Print a summary and return the number of failed levels.
print_summary() {
	echo ""
	echo "═══ Summary ═══"
	local failures=0
	if [[ $UNIT_EXIT -ge 0 ]]; then
		if [[ $UNIT_EXIT -eq 0 ]]; then
			echo "  Level 1 unit:  PASSED"
		else
			echo "  Level 1 unit:  FAILED"
			failures=$((failures + 1))
		fi
	fi
	if [[ $E2E_EXIT -ge 0 ]]; then
		if [[ $E2E_EXIT -eq 0 ]]; then
			echo "  Level 2 e2e:   PASSED"
		else
			echo "  Level 2 e2e:   FAILED"
			failures=$((failures + 1))
		fi
	fi
	return "$failures"
}

main() {
	cd "$SCRIPT_DIR"

	resolve_tools
	verify_php_version
	install_deps

	[[ "$MODE" != "e2e" ]] && run_unit
	[[ "$MODE" != "unit" ]] && run_e2e

	if print_summary; then
		exit 0
	else
		exit 2
	fi
}

main
