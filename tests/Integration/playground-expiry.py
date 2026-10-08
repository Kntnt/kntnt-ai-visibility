"""Verify effective page/aggregate expiry through actual PHP 8.4 WordPress.

KNTNT_EXPIRY_PORT selects the disposable worker (default 9449).
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
    path = "/?" + urlencode({"expiry_fixture": "fixture-only", "action": action, **values})
    status, _, body = request(base, path)
    assert status == 200, (action, status, body[:1000])
    return json.loads(body)


def verify(base):
    """Keep missed-invalidation changes stale within TTL, then regenerate safely."""
    initial = control(base)
    assert initial["php"].startswith("8.4."), initial
    print("Actual expiry Playground PHP " + initial["php"] + " home " + base, flush=True)
    artifacts = [
        ("page", "/ttl.md", b"TTL-BODY-", "text/markdown"),
        ("index", "/llms.txt", b"TTL-EXCERPT-", "text/plain"),
        ("full", "/llms-full.txt", b"TTL-BODY-", "text/plain"),
    ]
    scenarios = [
        ("default", 60, 700000),
        ("604800", 60, 700000),
        ("60", 30, 120),
        ("120", 90, 180),
        ("0", 700000, None),
        ("-5", 700000, None),
        ("30", 15, 90),
        ("invalid", 60, 700000),
    ]
    for ttl, within_age, expired_age in scenarios:
        configured = control(base, "configure", ttl=ttl)
        original = {}
        for name, path, marker, content_type in artifacts:
            status, fields, body = request(base, path)
            assert status == 200 and fields.get_content_type() == content_type and marker + b"A" in body, (ttl, path, status, fields, body[:1000])
            original[name] = (body, fields["ETag"])
        changed = control(base, "mutate")
        assert changed["version"] == configured["version"], (ttl, "fixture accidentally invalidated", configured, changed)
        aged = control(base, "age", age=within_age)
        assert all(value is not None for value in aged["files"].values()), aged
        old_dates = {}
        for name, path, _, _ in artifacts:
            body, etag = original[name]
            status, fields, cached = request(base, path)
            assert status == 200 and cached == body and fields["ETag"] == etag, (ttl, "within TTL", path, status, cached[:1000])
            old_dates[name] = fields["Last-Modified"]
            for method in ["GET", "HEAD"]:
                status, fields, empty = request(base, path, method, {"If-None-Match": etag})
                assert status == 304 and empty == b"" and fields["ETag"] == etag, (ttl, method, path, status, empty[:1000])
        print("PASS TTL=" + ttl + ": page/index/full hits and validators survive deliberately missed changes", flush=True)
        if expired_age is None:
            continue
        aged = control(base, "age", age=expired_age)
        # Full-first cases must also renew their still-expired page constituent.
        expired_artifacts = list(reversed(artifacts)) if ttl in ["default", "120"] else artifacts
        for name, path, marker, content_type in expired_artifacts:
            old_body, old_etag = original[name]
            status, fields, body = request(base, path, headers={"If-None-Match": old_etag})
            assert status == 200 and fields.get_content_type() == content_type and marker + b"B" in body and marker + b"A" not in body, (ttl, "expired slow path", path, status, body[:1000])
            assert body != old_body and fields["ETag"] != old_etag, (ttl, path, "stale validator")
            renewed = control(base)
            assert renewed["files"][name] > aged["files"][name], (ttl, path, "mtime not renewed", aged, renewed)
            status, head, empty = request(base, path, "HEAD")
            assert status == 200 and empty == b"" and head["ETag"] == fields["ETag"] and head["Content-Length"] == str(len(body)) and head["Last-Modified"] == fields["Last-Modified"], (ttl, "HEAD", path, status, head, empty[:1000])
            for method in ["GET", "HEAD"]:
                status, conditional, empty = request(base, path, method, {"If-None-Match": fields["ETag"]})
                assert status == 304 and empty == b"" and conditional["ETag"] == fields["ETag"], (ttl, "new validator", path, method, status)
            status, _, dated = request(base, path, headers={"If-Modified-Since": old_dates[name]})
            assert status == 200 and dated == body, (ttl, "old date validator", path, status, dated[:1000])
            status, _, empty = request(base, path, headers={"If-Modified-Since": fields["Last-Modified"]})
            assert status == 304 and empty == b"", (ttl, "new date validator", path, status)
        print("PASS TTL=" + ttl + ": expired early miss regenerates page/index/full, mtime, ETag and bodyless HEAD/304", flush=True)
    print("Expiry HTTP: 0 failures", flush=True)


def main():
    """Own one bounded worker group and stop every child on every exit path."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_EXPIRY_PORT", "9449")
    base = "http://127.0.0.1:" + port
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            "--port=" + port, "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/expiry-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if tracker:
                subprocess.run(["uv", "run", tracker, "add", "pid", str(worker.pid), "issue 19 disposable expiry Playground"], check=True)
            for _ in range(120):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during expiry setup")
                try:
                    if control(base).get("php", "").startswith("8.4."):
                        break
                except (URLError, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(1)
            else:
                raise RuntimeError("Playground expiry fixture unavailable; raise the runtime obstacle")
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
