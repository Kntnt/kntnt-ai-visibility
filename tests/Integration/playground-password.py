"""Verify stored-password exclusion over real HTTP on disposable PHP 8.4.

Usage: python3 tests/Integration/playground-password.py
KNTNT_PASSWORD_PORT selects the local port (default 9428).
KNTNT_PASSWORD_HTTP_BASE probes an existing password-blueprint.json site.
KNTNT_PASSWORD_PLUGIN_ROOT selects an isolated source checkout for regression
reproduction. Every worker is stopped, including children, on exit.
"""

import http.cookiejar
import json
import os
from pathlib import Path
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

from playground_process import stop_worker


def request(base, path, opener=None, data=None):
    """Return real response status, headers and bytes, including HTTP errors."""
    outgoing = urllib.request.Request(base + path, data=data)
    if data is not None:
        outgoing.add_header("Referer", base + "/protected/")
    try:
        response = (opener or urllib.request.build_opener()).open(outgoing, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def probe(base):
    """A satisfied password gate must never grant a public Markdown artifact."""
    control = "/?password_fixture=fixture-only&action="
    runtime = json.loads(request(base, control + "ready")[2])["php"]
    if not runtime.startswith("8.4."):
        raise RuntimeError("Expected actual PHP 8.4, got " + runtime)
    print("Actual Playground PHP " + runtime, flush=True)
    failures = 0

    def check(label, condition):
        """Retain independent failures so a leaked subsequent response is visible."""
        nonlocal failures
        failures += not condition
        print(("PASS " if condition else "FAIL ") + label, flush=True)

    request(base, control + "reset")
    path = "/protected.md?password_fixture=fixture-only&action=override"
    status, headers, body = request(base, path)
    check("overridden gate really permits this visitor", headers.get("X-Kntnt-Password-Required") == "0")
    check("overridden gate still refuses public Markdown", status == 403 and b"PASSWORD-CONTENT" not in body)
    check("protected 403 prevents cache storage", "no-store" in headers.get("Cache-Control", "").lower())
    status, headers, body = request(base, "/protected.md")
    check("subsequent anonymous request cannot receive the overridden response", status == 403 and b"PASSWORD-CONTENT" not in body)
    check("override created no public artifact", not json.loads(request(base, control + "ready")[2])["artifact"])

    # Obtain an actual core password cookie, rather than simulating its gate.
    jar = http.cookiejar.CookieJar()
    authorised = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    form = urllib.parse.urlencode({"post_password": "fixture"}).encode()
    request(base, "/wp-login.php?action=postpass", authorised, form)
    check("WordPress issued a password cookie", any(cookie.name.startswith("wp-postpass_") for cookie in jar))
    status, headers, body = request(base, "/protected/", authorised)
    check("real password cookie unlocks normal WordPress content", status == 200 and headers.get("X-Kntnt-Password-Required") == "0" and b"PASSWORD-CONTENT" in body)

    for visitor, opener in [("password cookie", authorised), ("ordinary anonymous visitor", None)]:
        for explicit in ["/protected.md", "/protected/?format=markdown"]:
            request(base, control + "reset")
            status, headers, body = request(base, explicit, opener)
            check(visitor + " receives a plain-text 403 for " + explicit, status == 403 and headers.get_content_type() == "text/plain" and b"password protected" in body and b"PASSWORD-CONTENT" not in body)
            check(visitor + " 403 prevents cache storage", "no-store" in headers.get("Cache-Control", "").lower())
            status, headers, body = request(base, "/protected.md")
            check(visitor + " leaves a safe subsequent anonymous request", status == 403 and b"PASSWORD-CONTENT" not in body)
            check(visitor + " leaves no public artifact", not json.loads(request(base, control + "ready")[2])["artifact"])

    # Build with the satisfied gate, then read the same public aggregates warm.
    request(base, control + "reset")
    for temperature, opener in [("cold authorised", authorised), ("warm anonymous", None)]:
        for aggregate in ["/llms.txt", "/llms-full.txt"]:
            status, headers, body = request(base, aggregate, opener)
            check(temperature + " " + aggregate + " keeps ordinary public content", status == 200 and b"Public fixture" in body)
            check(temperature + " " + aggregate + " excludes the protected source", b"Password fixture" not in body and b"PASSWORD-CONTENT" not in body and b"protected.md" not in body)
    check("aggregate generation creates no protected artifact", not json.loads(request(base, control + "ready")[2])["artifact"])
    print(f"Stored-password HTTP: {failures} failures", flush=True)
    return bool(failures)


def main():
    """Start an isolated Playground process group and always stop it."""
    existing = os.environ.get("KNTNT_PASSWORD_HTTP_BASE")
    if existing:
        return probe(existing.rstrip("/"))
    directory = Path(__file__).resolve().parent
    root = os.environ.get("KNTNT_PASSWORD_PLUGIN_ROOT", str(directory.parent.parent))
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_PASSWORD_PORT", "9428")
    command = [
        "npx", "--yes", "@wp-playground/cli@3.1.36", "server",
        "--php=8.4", "--wp=latest", "--workers=1",
        "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
        "--mount=" + root + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
        "--blueprint=" + str(directory / "password-blueprint.json"),
    ]
    worker = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True)
    output = []
    reader = threading.Thread(target=lambda: output.extend(worker.stdout), daemon=True)
    reader.start()
    print("Playground password worker process group: " + str(worker.pid), flush=True)
    try:
        deadline = time.monotonic() + 180
        while worker.poll() is None and time.monotonic() < deadline:
            try:
                response = request(base, "/?password_fixture=fixture-only&action=ready")
                if response[0] == 200 and "php" in json.loads(response[2]):
                    return probe(base)
            except (OSError, ValueError):
                pass
            time.sleep(0.5)
        print("".join(output))
        print("Playground fixture did not become ready; raise the runtime obstacle to the maintainer.", file=sys.stderr)
        return 1
    finally:
        stop_worker(worker, grace_seconds=10)
        reader.join(timeout=2)


if __name__ == "__main__":
    sys.exit(main())
