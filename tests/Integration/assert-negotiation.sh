# Assert canonical-URL negotiation against an already seeded Playground server.
# Usage: bash assert-negotiation.sh http://127.0.0.1:PORT
# Requires curl and the e2e-blueprint.json fixtures. Returns 1 on a failed
# runtime or HTTP assertion. Does not start processes or create files.

set -euo pipefail

SCRIPT_DIR="${BASH_SOURCE[0]%/*}"
BASE="${1:?Pass the seeded Playground server URL}"

# Verify the worker rather than assuming the server honoured its PHP flags.
source "$SCRIPT_DIR/playground-php-version.sh"
assert_playground_php_version "$BASE"

# Verify the real status, media type and rendered body for one request.
expect_representation() {

	local path="$1"
	local accept="$2"
	local type="$3"
	local response headers body

	# Keep headers and body together in memory without creating scratch files.
	response="$(curl -fsSi --max-time 20 -H "Accept: ${accept}" "${BASE}${path}")"
	headers="${response%%$'\r\n\r\n'*}"
	body="${response#*$'\r\n\r\n'}"
	if [[ ! "$headers" =~ ^HTTP/[0-9.]+[[:space:]]200 || "${headers,,}" != *"content-type: ${type};"* ]]; then
		printf 'FAIL %s Accept: %s (expected 200 %s)\n%s\n' "$path" "$accept" "$type" "$headers" >&2
		return 1
	fi

	# A media-type assertion alone must not accept an empty or wrong document.
	if [[ "$type" == 'text/html' ]]; then
		[[ "${body,,}" == *'<html'* && "$body" == *'About Us'* ]] || return 1
	else
		[[ "$body" == *'# About Us'* ]] || return 1
		if [[ "$path" == '/about/' ]]; then
			[[ "${headers,,}" == *'vary: accept'* && "$headers" == *'rel="alternate"'* ]] || return 1
		fi
	fi

	printf 'PASS %s Accept: %s → %s\n' "$path" "$accept" "$type"

}

# Canonical HTML wins on tolerance, rejection, wildcard preference and ties.
expect_representation '/about/' 'text/html, text/markdown;q=0.1' 'text/html'
expect_representation '/about/' 'text/markdown;q=0' 'text/html'
expect_representation '/about/' 'text/markdownish' 'text/html'
expect_representation '/about/' 'text/html, text/markdown' 'text/html'
expect_representation '/about/' 'text/markdown, text/html' 'text/html'
expect_representation '/about/' 'text/*;q=1, text/markdown;q=0.1' 'text/html'
expect_representation '/about/' 'text/markdown;q=0.5, */*;q=0.9' 'text/html'
expect_representation '/about/' 'application/xhtml+xml, text/markdown;q=0.5' 'text/html'
expect_representation '/about/' 'text/markdown;q=2' 'text/html'

# Explicit preference and its alias still select the uncached inline form.
expect_representation '/about/' 'text/html;q=0.1, text/markdown;q=0.9' 'text/markdown'
expect_representation '/about/' ' TEXT/X-MARKDOWN ; Q = 0.9 , TEXT/HTML ; Q = 0.1 ' 'text/markdown'
expect_representation '/about/' 'text/*, text/html;q=0.1, text/markdown;q=0.5' 'text/markdown'

# Explicit artifact requests retain precedence over a rejecting Accept header.
expect_representation '/about.md' 'text/markdown;q=0' 'text/markdown'
expect_representation '/about/?format=markdown' 'text/html' 'text/markdown'

echo 'Canonical negotiation HTTP regression passed.'
