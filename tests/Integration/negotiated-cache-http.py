"""Check negotiated cache policy over an already running Playground HTTP site.

Usage: python3 tests/Integration/negotiated-cache-http.py http://127.0.0.1:9425
The site must use negotiated-cache-blueprint.json. No services or files are
created here. Exit 0 means every HTTP assertion passed; failure exits non-zero.
"""

import sys
import urllib.error
import urllib.request


def request(url, accept, etag=None):
    """Read a representation and keep duplicate headers, including Vary."""
    headers = {"Accept": accept}
    if etag:
        headers["If-None-Match"] = etag
    outgoing = urllib.request.Request(url, headers=headers)
    try:
        response = urllib.request.urlopen(outgoing, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def vary_values(headers):
    """Normalise the combined Vary field as an HTTP recipient would."""
    return {
        token.strip().lower()
        for value in headers.get_all("Vary", [])
        for token in value.split(",")
    }


def check(label, condition):
    """Record every assertion without hiding later independent failures."""
    global failures
    print(("PASS " if condition else "FAIL ") + label)
    failures += not condition


failures = 0
base = sys.argv[1].rstrip("/")
runtime = urllib.request.urlopen(
    base + "/wp-content/plugins/kntnt-ai-visibility/tests/Integration/php-version.php"
).read().decode()
if not runtime.startswith("8.4."):
    raise RuntimeError("Expected actual PHP 8.4, got " + runtime)
print("Actual Playground PHP " + runtime)
canonical = base + "/ordinary/"
markdown = request(canonical, "text/markdown")
status, headers, body = markdown
check("negotiated 200 is Markdown", status == 200 and headers.get_content_type() == "text/markdown" and b"# Ordinary page" in body)
policy = headers.get("Cache-Control", "").lower()
check("negotiated 200 forbids shared storage", "private" in policy and "no-store" in policy)
check("negotiated 200 preserves all Vary values", vary_values(headers) == {"accept", "cookie", "accept-encoding"})
check("bypass exists before ordinary plugins_loaded callbacks", headers.get("X-Kntnt-Early-Bypass") == "1")
check("site integrations receive the cache bypass", headers.get("X-Kntnt-Integration-Bypass") == "1")

status, headers, body = request(canonical, "text/markdown", markdown[1].get("ETag"))
check("negotiated conditional returns bodyless 304", status == 304 and body == b"")
policy = headers.get("Cache-Control", "").lower()
check("negotiated 304 forbids shared storage", "private" in policy and "no-store" in policy)
check("negotiated 304 preserves all Vary values", vary_values(headers) == {"accept", "cookie", "accept-encoding"})

# Model a shared cache keyed only by URL: it ignores Accept and Vary selection,
# but honours response storage prohibitions. The origin is real WordPress HTTP.
cache = {}
if not {"private", "no-store"}.intersection(token.strip() for token in markdown[1].get("Cache-Control", "").lower().split(",")):
    cache[canonical] = markdown
html = cache.get(canonical) or request(canonical, "text/html")
status, headers, body = html
check("URL-only cache cannot replay preceding Markdown to an HTML visitor", status == 200 and headers.get_content_type() == "text/html" and b"<!DOCTYPE html>" in body and b"# Ordinary page" not in body)
check("HTML coherently varies on Accept and preserves existing Vary", vary_values(headers) == {"accept", "cookie", "accept-encoding"})
check("ordinary HTML does not inherit Markdown cache bypass", headers.get("X-Kntnt-Early-Bypass") == "0" and headers.get("X-Kntnt-Integration-Bypass") == "0")

status, headers, body = request(canonical + "?cache_vary_star=1", "text/markdown")
check("a pre-existing Vary wildcard survives", "*" in vary_values(headers))

request(base + "/?cache_test_reset=fixture-only", "text/html")
for temperature in ["cold", "warm"]:
    status, headers, body = request(base + "/ordinary.md", "text/html")
    check(temperature + " dedicated .md remains Markdown", status == 200 and headers.get_content_type() == "text/markdown" and b"PUBLIC-CACHE-FIXTURE" in body)
    policy = headers.get("Cache-Control", "").lower()
    check(temperature + " dedicated .md remains eligible for caching", "private" not in policy and "no-store" not in policy)
    check(temperature + " dedicated .md has no canonical Accept variation", "accept" not in vary_values(headers))

print(f"Cache policy HTTP: {failures} failures")
sys.exit(bool(failures))
