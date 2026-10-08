"""Verify dynamic inline validators on real PHP 8.4 WordPress responses.

Run with python3 tests/Integration/playground-inline-validators.py.
KNTNT_INLINE_PORT selects the disposable worker (default 9448).
No DDEV fallback is attempted.
"""

from email.parser import BytesParser
import json
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def fetch(base, path, headers=None, method="GET"):
    """Preserve status, all response fields and exact bytes for assertions."""
    try:
        response = urlopen(Request(base + path, headers=headers or {}, method=method), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def verify(base):
    """Mutate metadata/shared blocks while retaining the parent timestamp."""
    def state(action="state"):
        return json.loads(fetch(base, "/?inline_fixture=" + action)[2])

    def policy(headers):
        assert "private" in headers.get("Cache-Control", "") and "no-store" in headers.get("Cache-Control", ""), headers
        vary = {part.strip().lower() for value in headers.get_all("Vary", []) for part in value.split(",")}
        assert {"cookie", "accept", "accept-encoding"} <= vary, headers
        assert headers.get("Last-Modified") is None, headers
        assert headers.get("ETag"), headers
        assert headers.get("X-Content-Type-Options") == "nosniff", headers

    initial = state()
    assert re.fullmatch(r"8\.4\.[0-9]+", initial["php"]), initial
    print("Actual Playground PHP " + initial["php"], flush=True)
    status, fields, _ = fetch(base, "/ordinary/")
    assert status == 200 and fields.get("Last-Modified") == initial["source_date"], fields
    selection = {"Accept": "text/markdown"}
    status, headers, body = fetch(base, "/ordinary/", selection)
    assert status == 200 and b"META-ORIGINAL" in body and b"SHARED-ORIGINAL" in body, (status, body)
    policy(headers)
    previous_etag = headers.get("ETag")
    for action, marker in [("metadata", b"META-CHANGED"), ("shared", b"SHARED-CHANGED")]:
        changed = state(action)
        assert changed["modified"] == initial["modified"], (initial, changed)
        status, headers, body = fetch(base, "/ordinary/", selection)
        assert status == 200 and marker in body and headers.get("ETag") != previous_etag, (status, headers, body)
        current_etag = headers.get("ETag")
        for date in [initial["source_date"], "Fri, 01 Jan 2100 00:00:00 GMT"]:
            status, response, current = fetch(base, "/ordinary/", {**selection, "If-Modified-Since": date})
            assert status == 200 and current == body, (action, status, response, current)
            policy(response)
        for validator in [previous_etag, '"does-not-match"']:
            status, response, current = fetch(base, "/ordinary/", {**selection, "If-None-Match": validator, "If-Modified-Since": "Fri, 01 Jan 2100 00:00:00 GMT"})
            assert status == 200 and current == body, (action, status, current)
            policy(response)
        for validator in [current_etag, "W/" + current_etag, '"other", W/' + current_etag, "*"]:
            status, response, current = fetch(base, "/ordinary/", {**selection, "If-None-Match": validator, "If-Modified-Since": "Mon, 01 Jan 2001 00:00:00 GMT"})
            assert status == 304 and current == b"" and response.get("ETag") == current_etag, (action, status, current)
            policy(response)
        assert not state()["cached"], "The inline path unexpectedly published an artifact"
        previous_etag = current_etag
        print("PASS changed " + action + " bytes defeat unchanged-source dates and retain ETag precedence", flush=True)

    # Read raw HEAD bytes because ordinary clients hide an incorrect body.
    current_length = str(len(body))
    for condition, expected in [({"If-Modified-Since": initial["source_date"]}, 200), ({"If-None-Match": previous_etag}, 304)]:
        with socket.create_connection(("127.0.0.1", int(base.rsplit(":", 1)[1])), timeout=20) as connection:
            fields = {"Host": base.removeprefix("http://"), "Connection": "close", **selection, **condition}
            request = "HEAD /ordinary/ HTTP/1.1\r\n" + "".join(name + ": " + value + "\r\n" for name, value in fields.items()) + "\r\n"
            connection.sendall(request.encode())
            raw = b""
            while chunk := connection.recv(65536):
                raw += chunk
        head, body = raw.split(b"\r\n\r\n", 1)
        assert (" " + str(expected) + " ").encode() in head and body == b"", raw
        fields = BytesParser().parsebytes(head.split(b"\r\n", 1)[1] + b"\r\n\r\n")
        policy(fields)
        assert fields.get("ETag") == previous_etag, fields
        assert fields.get("Content-Length") == (current_length if expected == 200 else None), fields
    print("PASS raw HEAD 200 and 304 remain bodyless with coherent inline policy", flush=True)
    status, headers, body = fetch(base, "/ordinary/", {**selection, "If-None-Match": "*"}, method="POST")
    assert status == 200 and headers.get_content_type() == "text/html" and b"META-CHANGED" in body, (status, headers, body)
    assert not state()["cached"], "Unsupported method populated an artifact"
    print("PASS unsupported method retains ordinary WordPress HTML workflow", flush=True)
    print("Inline validator HTTP: 0 failures", flush=True)


def main():
    """Record one disposable worker group immediately and stop all children."""
    root = Path(__file__).resolve().parents[2]
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_INLINE_PORT", "9448")
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest",
            "--workers=1", "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/inline-validators-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if cleanup:
                subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 28 inline validator Playground regression"], check=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited before the inline fixture was ready")
                try:
                    if fetch(base, "/?inline_fixture=state")[0] == 200:
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
            try:
                os.killpg(worker.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                worker.wait(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(worker.pid, signal.SIGKILL)
                worker.wait()


if __name__ == "__main__":
    main()
