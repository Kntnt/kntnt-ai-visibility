# Verify the serving Playground worker's actual PHP major/minor over HTTP.
# Source this helper from an HTTP harness after the server is ready.
# Requires Bash 5+ and curl. Returns 0 for PHP 8.4, 1 for a failed HTTP probe
# or a different runtime. Safety flags are scoped to the function subshell.

# Reject runtimes selected differently from the CLI's requested PHP version.
assert_playground_php_version() (
	set -euo pipefail

	local base="$1"
	local runtime

	# Read PHP_VERSION from the worker, independently of CLI banners and flags.
	runtime="$(curl -fsS "${base}/wp-content/plugins/kntnt-ai-visibility/tests/Integration/php-version.php")" || return 1
	if [[ ! "$runtime" =~ ^8\.4\.[0-9]+$ ]]; then
		echo "Error: expected actual Playground PHP 8.4, got '${runtime}'." >&2
		return 1
	fi
	echo "Playground actual PHP runtime: ${runtime} (8.4 asserted)."

)
