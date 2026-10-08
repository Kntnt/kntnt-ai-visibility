"""Verify source query, Loop and locale through real WordPress integrations.

Run with python3 tests/Integration/playground-source-context.py.
KNTNT_SOURCE_CONTEXT_PORT selects the port (default 9433).
KNTNT_SESSION_CLEANUP_SCRIPT optionally registers the local worker for cleanup.
"""

import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request


def request(base, path):
    """Return actual anonymous HTTP output, including rejected artifacts."""
    try:
        response = urllib.request.urlopen(base + path, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def probe(base):
    """Inspect real shortcode/block/field output and exact caller restoration."""
    failures = 0
    control_path = "/?source_context_fixture=fixture-only&action="

    def check(label, condition):
        """Keep independent context failures visible for the complete run."""
        nonlocal failures
        failures += not condition
        print(("PASS " if condition else "FAIL ") + label, flush=True)

    def control(action):
        """Call the real Core service or clear only the disposable cache."""
        return json.loads(request(base, control_path + action)[2])

    initial = control("ready")
    if not initial["php"].startswith("8.4."):
        raise RuntimeError("Expected actual PHP 8.4, got " + initial["php"])
    print("Actual Playground PHP " + initial["php"], flush=True)
    sources = initial["sources"]

    def marker(source):
        """The literal contract is independent of how Core constructs a query."""
        identity = str(source["id"])
        return "Q" + identity + "-P" + identity + "-S1-M1-L1-Tpage-U0-" + source["locale"]

    def document(label, body, source):
        """All three real integration types must see the same source context."""
        body = body.decode() if isinstance(body, bytes) else body
        body = body.replace(r"\_", "_")
        for integration in ["SHORTCODE", "BLOCK"]:
            check(label + " " + integration, integration + "-" + marker(source) in body)
        check(label + " singular/main Loop gated public field", "PUBLIC-FIELD-" + source["name"] + "-" + source["locale"] in body)

    for key, source in sources.items():
        path = source["url"].removeprefix(base)
        status, _, body = request(base, path)
        check(key + " canonical HTML available", status == 200)
        document(key + " canonical HTML", body, source)
        for temperature in ["cold", "warm"]:
            status, headers, body = request(base, path.rstrip("/") + ".md")
            check(key + " " + temperature + " direct Markdown response", status == 200 and headers.get_content_type() == "text/markdown")
            document(key + " " + temperature + " Markdown", body, source)

    # A cold aggregate has no singular source request and must switch A/B locales.
    control("flush")
    for temperature in ["cold", "warm"]:
        status, headers, body = request(base, "/llms-full.txt")
        check(temperature + " source-less full aggregate response", status == 200 and headers.get_content_type() == "text/plain")
        for key, source in sources.items():
            document(temperature + " full " + key, body, source)

    for action in ["direct-success-alias", "direct-success-distinct", "direct-failure-alias", "direct-failure-distinct", "direct-conversion-failure"]:
        result = control(action)
        check(action + " restores query object identities/aliases, Loop globals, locale and caller", result["restored"])
        if "failure" in action:
            if "conversion" in action:
                check(action + " propagates typed conversion exception", result.get("error_class") == "Kntnt\\Ai_Visibility\\Core\\Markdown_Conversion_Failed")
            else:
                check(action + " propagates content exception", result.get("error") == "source-context-failure")
            check(action + " exception occurs in correct source context", result.get("failure_context") == marker(sources["a-en_GB"]))
        else:
            for key, source in sources.items():
                document(action + " A/B render " + key, result["renders"][key], source)
    result = control("direct-posts-page")
    check("supplied posts-page source restores the caller", result["restored"])
    document("supplied posts-page uses source singular context", result["renders"]["b-sv_SE"], sources["b-sv_SE"])
    print("Source context HTTP: " + str(failures) + " failures", flush=True)
    return bool(failures)


def main():
    """Boot one worker group and always terminate it and its descendants."""
    directory = Path(__file__).resolve().parent
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_SOURCE_CONTEXT_PORT", "9433")
    command = [
        "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
        "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
        "--mount=" + str(directory.parent.parent) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
        "--blueprint=" + str(directory / "source-context-blueprint.json"),
    ]
    worker = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True)
    output = []
    reader = threading.Thread(target=lambda: output.extend(worker.stdout), daemon=True)
    reader.start()
    print("Playground source-context worker process group: " + str(worker.pid), flush=True)
    try:
        cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
        if cleanup:
            subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 13 disposable Playground source context regression"], check=True)
        deadline = time.monotonic() + 180
        while worker.poll() is None and time.monotonic() < deadline:
            try:
                result = request(base, "/?source_context_fixture=fixture-only&action=ready")
                if result[0] == 200 and "php" in json.loads(result[2]):
                    return probe(base)
            except (OSError, ValueError):
                pass
            time.sleep(0.5)
        print("".join(output))
        print("Playground fixture did not become ready; raise the runtime obstacle to the maintainer.", file=sys.stderr)
        return 1
    finally:
        try:
            os.killpg(worker.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            worker.wait(timeout=10)
        except subprocess.TimeoutExpired:
            os.killpg(worker.pid, signal.SIGKILL)
            worker.wait()
        reader.join(timeout=2)


if __name__ == "__main__":
    sys.exit(main())
