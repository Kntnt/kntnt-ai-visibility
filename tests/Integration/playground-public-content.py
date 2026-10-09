"""Verify explicit theme-visible public HTML across all Markdown forms.

Run with python3 tests/Integration/playground-public-content.py.
KNTNT_PUBLIC_CONTENT_PORT selects the port (default 9426).
KNTNT_SESSION_CLEANUP_SCRIPT optionally registers the worker for cleanup.
"""

import json
import os
from pathlib import Path
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

from playground_process import stop_worker


def request(base, path, headers=None, method="GET"):
    """Return the actual anonymous response, including deliberate failures."""
    try:
        response = urllib.request.urlopen(urllib.request.Request(base + path, headers=headers or {}, method=method), timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def probe(base):
    """Compare known public text with field-backed and ordinary content output."""
    failures = 0
    control_path = "/?public_content_fixture=fixture-only&action="

    def check(label, condition):
        """Report independent observable failures without hiding later forms."""
        nonlocal failures
        failures += not condition
        print(("PASS " if condition else "FAIL ") + label, flush=True)

    def control(action):
        """Clear only this disposable installation's artifact files."""
        return json.loads(request(base, control_path + action)[2])

    initial = control("ready")
    if not initial["php"].startswith("8.4."):
        raise RuntimeError("Expected actual PHP 8.4, got " + initial["php"])
    print("Actual Playground PHP " + initial["php"], flush=True)
    sources = initial["sources"]

    def document(label, body, name):
        """Expected text comes from the literal public template, not metadata."""
        text = body.decode()
        if name in ["field", "full"]:
            check(label + " public field heading and body", "FIELD-PUBLIC-HEADING" in text and "FIELD-PUBLIC-BODY" in text)
            check(label + " public field link", "field link" in text and "/public-link" in text)
        if name in ["ordinary", "full"]:
            check(label + " default block pipeline", "ORDINARY-BLOCK-TEXT" in text)
            check(label + " default shortcode pipeline", "ORDINARY-SHORTCODE-TEXT" in text)
        check(label + " no automatic internal or unpublished metadata", "INTERNAL-NEVER-PUBLISH" not in text and "UNPUBLISHED-NEVER-PUBLISH" not in text)
        check(label + " correct source and anonymous audience", "WRONG-SOURCE-OR-AUDIENCE" not in text)

    for name in ["field", "ordinary", "empty"]:
        path = sources[name]["url"].removeprefix(base)
        status, _, body = request(base, path)
        check(name + " canonical HTML", status == 200)
        document(name + " HTML", body, name)
        forms = [("direct", path.rstrip("/") + ".md", {}), ("query", path + "?format=markdown", {}), ("Accept", path, {"Accept": "text/markdown"})]
        for form, url, headers in forms:
            control("flush")
            for temperature in ["cold", "warm"]:
                status, response_headers, body = request(base, url, headers)
                label = name + " " + form + " " + temperature
                check(label + " Markdown response", status == 200 and response_headers.get_content_type() == "text/markdown")
                document(label, body, name)
    control("flush")
    for temperature in ["cold", "warm"]:
        status, headers, body = request(base, "/llms-full.txt")
        check(temperature + " full aggregate", status == 200 and headers.get_content_type() == "text/plain")
        document(temperature + " full", body, "full")
        check(temperature + " no draft constituent", "Public content draft" not in body.decode())
    # A failed integration cannot become a title-only success or an HTML error.
    path = sources["field"]["url"].removeprefix(base)
    failure_forms = [("direct", path.rstrip("/") + ".md", {}), ("query", path + "?format=markdown", {}), ("Accept", path, {"Accept": "text/markdown"}), ("full", "/llms-full.txt", {})]
    for mode in ["invalid", "throw"]:
        control(mode)
        for form, url, headers in failure_forms:
            for method in ["GET", "HEAD"]:
                status, response_headers, body = request(base, url, {**headers, "If-None-Match": "*", "If-Modified-Since": "Wed, 31 Dec 2098 23:59:59 GMT"}, method)
                label = mode + " renderer " + form + " " + method
                check(label + " deliberate plaintext 500", status == 500 and response_headers.get_content_type() == "text/plain")
                check(label + " no-store and nosniff", "no-store" in response_headers.get("Cache-Control", "") and response_headers.get("X-Content-Type-Options") == "nosniff")
                check(label + " no diagnostic or partial artifact", b"PRIVATE-RENDERER-DIAGNOSTIC" not in body and b"FIELD-PUBLIC" not in body and b"canonical_url:" not in body)
                if method == "HEAD":
                    check(label + " no body", body == b"")
        state = control("state")
        check(mode + " failed renderer publishes no page or partial full artifact", state["page"] is None and state["full"] is None)
        if mode == "throw":
            result = control("direct-failure")
            check("renderer exception has its own typed discriminator", result.get("error_class") == "Kntnt\\Ai_Visibility\\Core\\Public_Content_Rendering_Failed")
            check("renderer exception preserves internal diagnostic cause", result.get("previous") == "PRIVATE-RENDERER-DIAGNOSTIC" and any("Public content rendering failed" in line for line in result["logs"]))
            check("renderer exception runs in anonymous source context", result["failure_context"])
            check("renderer exception restores exact caller query, Loop, audience, locale and request", result["restored"])
            check("renderer direct failure writes no page", result["page"] is None)
        control("valid")
        status, _, body = request(base, "/llms-full.txt")
        check(mode + " renderer automatic recovery", status == 200 and b"FIELD-PUBLIC-BODY" in body)
    print("Public content HTTP: " + str(failures) + " failures", flush=True)
    return bool(failures)


def main():
    """Boot one worker group and always terminate it and its descendants."""
    directory = Path(__file__).resolve().parent
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_PUBLIC_CONTENT_PORT", "9426")
    command = [
        "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
        "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
        "--mount=" + str(directory.parent.parent) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
        "--blueprint=" + str(directory / "public-content-blueprint.json"),
    ]
    worker = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True)
    output = []
    reader = threading.Thread(target=lambda: output.extend(worker.stdout), daemon=True)
    reader.start()
    print("Playground public-content worker process group: " + str(worker.pid), flush=True)
    try:
        cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
        if cleanup:
            subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 6 disposable Playground public content regression"], check=True)
        deadline = time.monotonic() + 180
        while worker.poll() is None and time.monotonic() < deadline:
            try:
                result = request(base, "/?public_content_fixture=fixture-only&action=ready")
                if result[0] == 200 and "php" in json.loads(result[2]):
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
