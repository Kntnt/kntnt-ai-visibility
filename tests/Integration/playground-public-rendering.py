"""Verify public artifact audiences over real WordPress HTTP on PHP 8.4.

Run with python3 tests/Integration/playground-public-rendering.py. The disposable
worker uses KNTNT_PUBLIC_PORT (default 9427), records its process group and stops
all children on exit. No DDEV fallback is attempted.
"""

from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPCookieProcessor, Request, build_opener
from urllib.parse import urlencode


def fetch(client, base, path, headers=None, data=None):
    """Preserve real HTTP error bodies as evidence without writing scratch files."""
    try:
        response = client.open(Request(base + path, headers=headers or {}, data=data), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def verify(base):
    """Check both audiences in both request orders and authenticated aggregates."""
    anonymous = build_opener()
    member = build_opener(HTTPCookieProcessor(CookieJar()))
    status, _, body = fetch(anonymous, base, "/?public_fixture=state")
    assert status == 200 and re.fullmatch(r"8\.4\.[0-9]+", json.loads(body)["php"]), body
    print("Actual Playground PHP " + json.loads(body)["php"], flush=True)
    fetch(member, base, "/?public_fixture=login")
    assert json.loads(fetch(member, base, "/?public_fixture=state")[2])["logged_in"], "Real login cookie not accepted"
    assert b"MEMBER-PRIVATE-DETAIL" in fetch(member, base, "/ordinary/")[2]
    print("PASS real WordPress login selects privileged ordinary HTML", flush=True)

    for first, second in [(member, anonymous), (anonymous, member)]:
        fetch(anonymous, base, "/?public_fixture=reset")
        for client in [first, second, anonymous]:
            status, headers, body = fetch(client, base, "/ordinary.md")
            assert status == 200 and headers.get_content_type() == "text/markdown", (status, body)
            assert b"PUBLIC-DETAIL" in body and b"MEMBER-PRIVATE" not in body, body
        state = json.loads(fetch(anonymous, base, "/?public_fixture=state")[2])
        assert "PUBLIC-DETAIL" in state["artifact"] and "MEMBER-PRIVATE" not in state["artifact"], state
        status, _, body = fetch(anonymous, base, "/llms-full.txt")
        assert status == 200 and b"PUBLIC-DETAIL" in body and b"MEMBER-PRIVATE" not in body, body
        print("PASS both request orders publish and reuse only anonymous page/full bytes", flush=True)

    for path in ["/llms.txt", "/llms-full.txt"]:
        fetch(anonymous, base, "/?public_fixture=reset")
        for client in [member, anonymous]:
            status, _, body = fetch(client, base, path)
            assert status == 200 and b"PUBLIC-AGGREGATE" in body and b"MEMBER-PRIVATE" not in body, body
        print("PASS authenticated-first aggregate filters remain anonymous " + path, flush=True)
    fetch(anonymous, base, "/?public_fixture=reset")
    status, _, body = fetch(anonymous, base, "/ordinary.md", {"Cookie": "fixture_member=private"})
    assert status == 200 and b"PUBLIC-DETAIL" in body and b"MEMBER-PRIVATE" not in body, body
    print("PASS visitor-specific cookie cannot personalise the public artifact", flush=True)
    fetch(anonymous, base, "/?public_fixture=reset")
    status, _, body = fetch(anonymous, base, "/ordinary.md?member_token=private")
    assert status == 200 and b"PUBLIC-DETAIL" in body and b"MEMBER-PRIVATE" not in body, body
    assert b"MEMBER-PRIVATE" not in fetch(anonymous, base, "/ordinary.md")[2]
    print("PASS registered WordPress visitor query vars cannot personalise shared artifacts", flush=True)
    for action in ["direct-success", "direct-failure"]:
        result = json.loads(fetch(member, base, "/?public_fixture=" + action)[2])
        assert result["restored"], result
        if action == "direct-success":
            assert "PUBLIC-DETAIL" in result["bytes"] and "MEMBER-PRIVATE" not in result["bytes"], result
        else:
            assert result["error"] == "fixture-render-failure", result
        print("PASS real Core render restores identity, capabilities and request state " + action, flush=True)
    fetch(anonymous, base, "/?public_fixture=reset")
    preview = fetch(member, base, "/?public_fixture=preview-url")[2].decode()
    preview_path = preview.removeprefix(base)
    assert b"PREVIEW-PRIVATE-DETAIL" in fetch(member, base, preview_path)[2], "Real preview did not select autosave"
    status, headers, body = fetch(member, base, preview_path + "&format=markdown")
    assert status == 403 and "no-store" in headers.get("Cache-Control", "") and b"PREVIEW-PRIVATE" not in body, (status, body)
    assert json.loads(fetch(anonymous, base, "/?public_fixture=state")[2])["artifact"] is None
    assert b"PREVIEW-PRIVATE" not in fetch(anonymous, base, "/ordinary.md")[2]
    print("PASS real unpublished preview never populates a shared artifact", flush=True)
    fetch(member, base, "/wp-login.php?action=postpass", {"Referer": base + "/protected/"}, urlencode({"post_password": "fixture"}).encode())
    assert not json.loads(fetch(member, base, "/?public_fixture=state")[2])["password_required"], "Real password cookie was not accepted"
    for client in [member, anonymous]:
        status, headers, body = fetch(client, base, "/protected.md")
        assert status == 403 and "no-store" in headers.get("Cache-Control", ""), (status, body)
    assert not json.loads(fetch(anonymous, base, "/?public_fixture=state")[2])["protected"]
    fetch(anonymous, base, "/?public_fixture=reset")
    assert b"MEMBER-PRIVATE" not in fetch(member, base, "/llms-full.txt")[2]
    print("PASS actual password cookie cannot publish protected page or aggregate bytes", flush=True)
    for signal in ["headers", "constant", "litespeed", "cookie", "headers-preexisting", "constant-preexisting"]:
        fetch(anonymous, base, "/?public_fixture=reset")
        fetch(anonymous, base, "/?public_fixture=signal-" + signal)
        status, headers, body = fetch(anonymous, base, "/uncacheable.md")
        assert status == 403 and "no-store" in headers.get("Cache-Control", ""), (signal, status, headers, body)
        assert b"SIGNALLED-PRIVATE-CONTENT" not in body, body
        state = json.loads(fetch(anonymous, base, "/?public_fixture=state")[2])
        assert not state["uncacheable"], state
        status, headers, body = fetch(anonymous, base, "/llms-full.txt")
        assert status == 403 and b"SIGNALLED-PRIVATE-CONTENT" not in body, (signal, status, body)
        print("PASS content cacheability signal prevents page and full publication " + signal, flush=True)
    print("Public rendering HTTP: 0 failures", flush=True)


def main():
    """Launch and record one worker, then terminate its complete process group."""
    root = Path(__file__).resolve().parents[2]
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_PUBLIC_PORT", "9427")
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest",
            "--workers=1", "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/public-rendering-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if cleanup:
                subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 7 anonymous-rendering Playground regression"], check=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited before its fixture was ready")
                try:
                    if fetch(build_opener(), base, "/?public_fixture=state")[0] == 200:
                        verify(base)
                        return
                except (URLError, TimeoutError):
                    pass
                time.sleep(2)
            raise RuntimeError("Playground fixture did not become ready; raise the runtime obstacle")
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            if worker.poll() is None:
                os.killpg(worker.pid, signal.SIGTERM)
                try:
                    worker.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    os.killpg(worker.pid, signal.SIGKILL)
                    worker.wait()


if __name__ == "__main__":
    main()
