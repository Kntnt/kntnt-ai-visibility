"""Verify effective page/aggregate indirect through actual PHP 8.4 WordPress.

KNTNT_INDIRECT_PORT selects the disposable worker (default 9456).
KNTNT_SESSION_CLEANUP_SCRIPT registers its process group immediately.
"""

from http.client import parse_headers
from io import BytesIO
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import Request, urlopen


def request(base, path, method="GET", headers=None):
    """Observe actual headers and bytes, including raw bodyless HEAD responses."""
    if method == "HEAD":
        address = urlsplit(base)
        fields = {"Host": address.netloc, "Connection": "close", **(headers or {})}
        outgoing = f"HEAD {address.path}{path} HTTP/1.1\r\n"
        outgoing += "".join(f"{name}: {value}\r\n" for name, value in fields.items()) + "\r\n"
        with socket.create_connection((address.hostname, address.port), timeout=30) as connection:
            connection.sendall(outgoing.encode("ascii"))
            chunks = []
            while chunk := connection.recv(65536):
                chunks.append(chunk)
        head, separator, body = b"".join(chunks).partition(b"\r\n\r\n")
        assert separator, head
        status, fields = head.split(b"\r\n", 1)
        return int(status.split()[1]), parse_headers(BytesIO(fields)), body
    try:
        response = urlopen(Request(base + path, method=method, headers=headers or {}), timeout=30)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def control(base, action="state", **values):
    """Mutate only explicit fixture options and backdate owned public identities."""
    path = "/?" + urlencode({"indirect_fixture": "fixture-only", "action": action, **values})
    status, _, body = request(base, path)
    assert status == 200, (action, status, body[:1000])
    return json.loads(body)


def verify(base):
    """Observe site headers through real cached HTTP representations."""
    initial = control(base)
    assert initial["php"].startswith("8.4."), initial
    print("Actual indirect Playground PHP " + initial["php"], flush=True)
    paths = ["/indirect-source.md", "/llms.txt", "/llms-full.txt"]
    original = {}
    for path in paths:
        status, fields, body = request(base, path)
        assert status == 200, (path, status, body[:1000])
        original[path] = (body, fields["ETag"])
        if path != paths[0]:
            assert b"SITE-A" in body and b"TAGLINE-A" in body, (path, body[:1000])
    warm = control(base)
    assert all(warm["cached"].values()), warm
    unrelated = control(base, "unrelated")
    assert unrelated["version"] == warm["version"] and unrelated["cached"] == warm["cached"], unrelated
    changed = control(base, "site")
    for path in paths[1:]:
        status, fields, body = request(base, path, headers={"If-None-Match": original[path][1]})
        assert status == 200 and b"SITE-B" in body and b"TAGLINE-B" in body and b"SITE-A" not in body and b"TAGLINE-A" not in body, (path, status, body[:1000])
        assert fields["ETag"] != original[path][1], (path, "stale validator")
    assert changed["version"] > warm["version"] and not any(changed["cached"].values()), ("site dependency did not invalidate lazily", warm, changed)
    print("PASS site name/tagline: both aggregate headers refresh lazily; unrelated option leaves cache/version intact", flush=True)
    original = {}
    for path in [paths[0], paths[2]]:
        status, fields, body = request(base, path)
        assert status == 200 and b'author: "AUTHOR-A"' in body, (path, status, body[:1000])
        original[path] = fields["ETag"]
    warm = control(base)
    unchanged = control(base, "profile-unrelated")
    assert unchanged["version"] == warm["version"] and unchanged["cached"] == warm["cached"], ("unrelated profile write", warm, unchanged)
    changed = control(base, "author")
    for path, etag in original.items():
        status, fields, body = request(base, path, headers={"If-None-Match": etag})
        assert status == 200 and b'author: "AUTHOR-B"' in body and b'author: "AUTHOR-A"' not in body, ("author display name", path, status, body[:1000])
        assert fields["ETag"] != etag, (path, "stale author validator")
    assert changed["version"] > warm["version"] and not any(changed["cached"].values()) and changed["modified"] == warm["modified"], ("author invalidation must be lazy without resave", warm, changed)
    print("PASS author display name: page/full front-matter refresh without source resave; unrelated profile write is ignored", flush=True)
    print("Indirect HTTP: 0 failures", flush=True)


def main():
    """Own one bounded worker group and stop every child on every exit path."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_INDIRECT_PORT", "9456")
    base = "http://127.0.0.1:" + port
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            "--port=" + port, "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/indirect-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if tracker:
                subprocess.run(["uv", "run", tracker, "add", "pid", str(worker.pid), "issue 16 disposable indirect Playground"], check=True)
            for _ in range(120):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during indirect setup")
                try:
                    if control(base).get("php", "").startswith("8.4."):
                        break
                except (URLError, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(1)
            else:
                raise RuntimeError("Playground indirect fixture unavailable; raise the runtime obstacle")
            verify(base)
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
                worker.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(worker.pid, signal.SIGKILL)
                worker.wait()


if __name__ == "__main__":
    main()
