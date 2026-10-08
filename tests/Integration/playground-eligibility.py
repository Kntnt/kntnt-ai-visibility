"""Verify effective Markdown policy through anonymous Playground HTTP.

Run with python3 tests/Integration/playground-eligibility.py. KNTNT_POLICY_PORT
selects the port (default 9437). A separate administrator browser performs the
real clear-cache action during simulated integration-code deployments.
"""

import html
import http.cookiejar
import os
from pathlib import Path
import signal
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request


def request(url, opener=None, data=None):
    """Read the HTTP result including unsuccessful artifact requests."""
    client = opener or urllib.request.build_opener()
    try:
        response = client.open(url, data=data, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def probe(base):
    """Exercise cold policy and the documented purge of already-warm artifacts."""
    failures = 0

    def check(label, condition):
        """Keep independent failures visible without masking later assertions."""
        nonlocal failures
        print(("PASS " if condition else "FAIL ") + label, flush=True)
        failures += not condition

    runtime = request(base + "/wp-content/plugins/kntnt-ai-visibility/tests/Integration/php-version.php")[2].decode()
    if not runtime.startswith("8.4."):
        raise RuntimeError("Expected actual PHP 8.4, got " + runtime)
    print("Actual Playground PHP " + runtime, flush=True)

    # Use authenticated HTTP solely for the existing privileged purge action.
    admin = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    request(base + "/wp-login.php", admin)
    login = urllib.parse.urlencode({"log": "eligibility-admin", "pwd": "fixture-only", "wp-submit": "Log In"}).encode()
    request(base + "/wp-login.php", admin, login)

    def control(action):
        """Change only the fixture's deployment policy, never plugin settings."""
        return request(base + "/?eligibility_token=fixture-only&eligibility_action=" + action, admin)

    def deploy(policy):
        """Apply the new code policy and purge before resuming public traffic."""
        check("deployment selects " + policy, control(policy)[2] == b"policy-updated")
        status, _, body = control("purge-url")
        purge_url = html.unescape(body.decode())
        if status != 200 or "action=kntnt_ai_visibility_clear_cache" not in purge_url:
            raise RuntimeError("Authenticated clear-cache URL was not available: " + purge_url)
        status, _, _ = request(purge_url, admin)
        check("authorised deployment cache purge completes", status == 200)

    def excluded(label, all_types=False):
        """Request aggregates first to catch indirect per-page materialisation."""
        status, _, index = request(base + "/llms.txt")
        check(label + " index excludes page alternate", status == 200 and b"/policy-page.md" not in index)
        status, _, full = request(base + "/llms-full.txt")
        check(label + " full excludes page content", status == 200 and b"ELIGIBILITY-PAGE-BODY" not in full)
        status, headers, _ = request(base + "/policy-page.md")
        check(label + " aggregates cannot materialise excluded page", status == 404 and headers.get_content_type() != "text/markdown")
        status, headers, body = request(base + "/policy-page/")
        check(label + " HTML remains available without Markdown discovery", status == 200 and b"ELIGIBILITY-PAGE-BODY" in body and b"/policy-page.md" not in body and "/policy-page.md" not in headers.get("Link", ""))
        if all_types:
            status, _, _ = request(base + "/policy-post.md")
            check(label + " empty Markdown policy excludes posts", status == 404)
            check(label + " empty policy excludes aggregate post link and content", b"/policy-post.md" not in index and b"ELIGIBILITY-POST-BODY" not in full)
        else:
            check(label + " unaffected post remains in both aggregates", b"/policy-post.md" in index and b"ELIGIBILITY-POST-BODY" in full)

    excluded("cold")
    deploy("open")
    for temperature in ["cold", "warm"]:
        for path, marker in [("/policy-page.md", b"ELIGIBILITY-PAGE-BODY"), ("/llms.txt", b"/policy-page.md"), ("/llms-full.txt", b"ELIGIBILITY-PAGE-BODY")]:
            status, _, body = request(base + path)
            check(temperature + " enabled artifact " + path, status == 200 and marker in body)
    deploy("no-page")
    excluded("after warm deployment purge")
    deploy("empty")
    excluded("empty policy after purge", all_types=True)
    print("Eligibility HTTP: " + str(failures) + " failures", flush=True)
    return bool(failures)


def main():
    """Start a PHP 8.4 process group and always stop every worker it spawned."""
    directory = Path(__file__).resolve().parent
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_POLICY_PORT", "9437")
    command = [
        "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
        "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
        "--mount=" + str(directory.parent.parent) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
        "--blueprint=" + str(directory / "eligibility-blueprint.json"),
    ]
    worker = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True)
    output = []
    reader = threading.Thread(target=lambda: output.extend(worker.stdout), daemon=True)
    reader.start()
    print("Playground eligibility worker process group: " + str(worker.pid), flush=True)
    try:
        # Local sessions can record the worker at launch without coupling CI to a
        # machine-specific cleanup tool.
        cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
        if cleanup:
            subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 17 disposable Playground eligibility regression"], check=True)
        deadline = time.monotonic() + 180
        while worker.poll() is None and time.monotonic() < deadline:
            try:
                if request(base + "/?eligibility_token=fixture-only&eligibility_action=ready")[2] == b"eligibility-ready":
                    return probe(base)
            except OSError:
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
