"""Verify add/update/delete exposure options through real WordPress hooks.

Run with python3 tests/Integration/playground-exposure-options.py.
KNTNT_EXPOSURE_OPTIONS_PORT selects the port (default 9449).
KNTNT_SESSION_CLEANUP_SCRIPT optionally registers the local worker for cleanup.
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


def request(base, path):
    """Return actual anonymous HTTP output, including rejected artifacts."""
    try:
        response = urllib.request.urlopen(base + path, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def probe(base):
    """Warm artifacts before every option transition and inspect public output."""
    failures = 0
    control_path = "/?exposure_options_fixture=fixture-only&action="

    def check(label, condition):
        """Keep independent failures visible for the whole option lifecycle."""
        nonlocal failures
        failures += not condition
        print(("PASS " if condition else "FAIL ") + label, flush=True)

    def control(action, turnover=None):
        """Use real WP option hooks and observe the public cache-version service."""
        result = json.loads(request(base, control_path + action)[2])
        if turnover is not None:
            check(action + " changes cache generation exactly " + str(turnover) + " time(s)", result["after"] - result["before"] == turnover)
        return result

    initial = control("ready")
    if not initial["php"].startswith("8.4."):
        raise RuntimeError("Expected actual PHP 8.4, got " + initial["php"])
    print("Actual Playground PHP " + initial["php"], flush=True)
    check("regression begins with a genuinely absent option", not initial["exists"])

    def policy(label, page=True, other=True, index=True, full=True):
        """Inspect cold and warm page/discovery/aggregate responses per policy."""
        for temperature in ["cold", "warm"]:
            for name, exposed in [("page", page), ("other", other)]:
                status, headers, body = request(base, "/exposure-" + name + ".md")
                marker = ("EXPOSURE-" + name.upper() + "-CONTENT").encode()
                check(label + " " + temperature + " .md " + name, (status == 200 and headers.get_content_type() == "text/markdown" and marker in body) if exposed else (status == 404 and headers.get_content_type() != "text/markdown" and marker not in body))
            status, _, index_body = request(base, "/llms.txt")
            check(label + " " + temperature + " index page links", status == 200 and ((b"/exposure-page.md" in index_body) == (page and index)) and ((b"/exposure-other.md" in index_body) == (other and index)))
            status, _, full_body = request(base, "/llms-full.txt")
            check(label + " " + temperature + " full page content", status == 200 and ((b"EXPOSURE-PAGE-CONTENT" in full_body) == (page and full)) and ((b"EXPOSURE-OTHER-CONTENT" in full_body) == (other and full)))
        status, headers, body = request(base, "/exposure-page/")
        check(label + " ordinary HTML remains available with correct discovery", status == 200 and headers.get_content_type() == "text/html" and b"EXPOSURE-PAGE-CONTENT" in body and ((b"/exposure-page.md" in body) == page) and (("/exposure-page.md" in headers.get("Link", "")) == page))
        status, headers, body = request(base, "/exposure-page/?format=markdown")
        check(label + " canonical explicit form follows the same policy", status == 200 and headers.get_content_type() == ("text/markdown" if page else "text/html") and b"EXPOSURE-PAGE-CONTENT" in body)

    policy("untouched defaults")
    control("matrix-off", 1)
    policy("first matrix-only save", page=False, other=False)
    check("matrix removal restores absent option", not control("remove", 1)["exists"])
    policy("matrix removal restores defaults")
    control("exclude-page", 1)
    policy("first exclusions save", page=False)
    control("combined", 1)
    policy("combined matrix/exclusions update", other=False, index=False, full=False)
    control("reset", 1)
    policy("empty reset restores defaults")
    control("exclude-page", 1)
    policy("later exclusions update", page=False)
    check("exclusions removal restores absent option", not control("remove", 1)["exists"])
    policy("exclusions removal restores defaults")
    control("signals-one", 0)
    control("signals-two", 0)
    control("signals-two", 0)
    control("unrelated", 0)
    policy("unrelated settings preserve defaults")
    print("Exposure options HTTP: " + str(failures) + " failures", flush=True)
    return bool(failures)


def main():
    """Boot one worker group and always terminate it and its descendants."""
    directory = Path(__file__).resolve().parent
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_EXPOSURE_OPTIONS_PORT", "9449")
    command = [
        "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
        "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
        "--mount=" + str(directory.parent.parent) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
        "--blueprint=" + str(directory / "exposure-options-blueprint.json"),
    ]
    worker = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True)
    output = []
    reader = threading.Thread(target=lambda: output.extend(worker.stdout), daemon=True)
    reader.start()
    print("Playground exposure-options worker process group: " + str(worker.pid), flush=True)
    try:
        cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
        if cleanup:
            subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 29 disposable Playground option lifecycle regression"], check=True)
        deadline = time.monotonic() + 180
        while worker.poll() is None and time.monotonic() < deadline:
            try:
                result = request(base, "/?exposure_options_fixture=fixture-only&action=ready")
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
