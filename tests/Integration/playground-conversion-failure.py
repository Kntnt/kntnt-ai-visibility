"""Check conversion failure/recovery through real service/store HTTP shells.

Run with python3 tests/Integration/playground-conversion-failure.py. The fixture
injects through the existing domain-provider seam, never a fake converter/cache.
KNTNT_CONVERSION_PORT selects the disposable PHP 8.4 worker (default 9456).
No DDEV fallback is attempted.
"""

from http.client import RemoteDisconnected
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from playground_process import stop_worker


def fetch(base, path, headers=None, method="GET"):
    """Preserve deliberate error bodies and headers without scratch files."""
    try:
        response = urlopen(Request(base + path, headers=headers or {}, method=method), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def verify(base):
    """Exercise each failure path twice, HEAD/conditional errors and recovery."""
    def state(action="state"):
        return json.loads(fetch(base, "/?conversion_fixture=" + action)[2])

    runtime = state()["php"]
    assert re.fullmatch(r"8\.4\.[0-9]+", runtime), runtime
    print("Actual Playground PHP " + runtime, flush=True)
    for path, accept in [
        ("/ordinary.md", {}),
        ("/ordinary/?format=markdown", {}),
        ("/ordinary/", {"Accept": "text/markdown"}),
        ("/llms-full.txt", {}),
    ]:
        state("reset")
        for headers in [accept, accept, {**accept, "If-None-Match": "*", "If-Modified-Since": "Wed, 31 Dec 2098 23:59:59 GMT"}]:
            status, response, body = fetch(base, path, headers)
            assert status == 500 and response.get_content_type() == "text/plain", (path, status, response, body)
            assert "no-store" in response.get("Cache-Control", ""), (path, response)
            assert response.get("X-Content-Type-Options") == "nosniff", response
            assert response.get("ETag") is None and response.get("Last-Modified") is None, response
            assert b"could not be converted" in body and b"AUDIT-CONVERSION" not in body and b"private diagnostic" not in body, body
            assert b"PUBLIC-BODY" not in body and b"VALID-PREFIX-BODY" not in body, body
            current = state()
            assert current["page"] is None and current["full"] is None, current
        assert state()["attempts"] == 3, state()
        assert all("AUDIT-CONVERSION-FAULT" in line for line in state()["logs"]), state()
        if path == "/llms-full.txt":
            assert "VALID-PREFIX-BODY" in state()["prefix"], "No successful prefix preceded the failed constituent"

        # Inspect raw HEAD bytes; HTTP clients otherwise hide an incorrect body.
        address = ("127.0.0.1", int(base.rsplit(":", 1)[1]))
        with socket.create_connection(address, timeout=20) as connection:
            fields = {"Host": base.removeprefix("http://"), "Connection": "close", **accept}
            request = "HEAD " + path + " HTTP/1.1\r\n" + "".join(key + ": " + value + "\r\n" for key, value in fields.items()) + "\r\n"
            connection.sendall(request.encode())
            raw = b""
            while chunk := connection.recv(65536):
                raw += chunk
        head, body = raw.split(b"\r\n\r\n", 1)
        assert b" 500 " in head and b"no-store" in head.lower() and body == b"", (path, raw)
        assert state()["attempts"] == 4, state()
        state("recover")
        status, response, body = fetch(base, path, accept)
        assert status == 200 and b"PUBLIC-BODY" in body, (path, status, body)
        assert state()["attempts"] == 5, state()
        if path == "/ordinary/":
            assert state()["page"] is None and "no-store" in response.get("Cache-Control", ""), state()
        elif path == "/llms-full.txt":
            assert "PUBLIC-BODY" in state()["full"] and "VALID-PREFIX-BODY" in state()["full"], state()
            assert fetch(base, path)[2] == body
        else:
            assert "PUBLIC-BODY" in state()["page"], state()
            assert fetch(base, path, accept)[2] == body
        print("PASS non-cacheable GET/HEAD/conditional failures and retry recovery " + path, flush=True)

    state("reset")
    status, response, body = fetch(base, "/empty.md")
    assert status == 200 and response.get_content_type() == "text/markdown", (status, body)
    assert body.rstrip().endswith(b"# Conversion fixture empty"), body
    assert fetch(base, "/empty.md")[2] == body
    print("PASS genuinely empty page remains a successful cacheable artifact", flush=True)
    print("Conversion failure HTTP: 0 failures", flush=True)


def main():
    """Start and record a worker group, then stop every child on every exit."""
    root = Path(__file__).resolve().parents[2]
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_CONVERSION_PORT", "9456")
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest",
            "--workers=1", "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/conversion-failure-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if cleanup:
                subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 36 conversion failure Playground regression"], check=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited before the conversion fixture was ready")
                try:
                    if fetch(base, "/?conversion_fixture=state")[0] == 200:
                        verify(base)
                        return
                except (URLError, RemoteDisconnected, TimeoutError):
                    pass
                time.sleep(2)
            raise RuntimeError("Playground fixture did not become ready; raise the runtime obstacle")
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            stop_worker(worker, grace_seconds=10)


if __name__ == "__main__":
    main()
