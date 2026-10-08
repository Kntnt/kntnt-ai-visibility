"""Verify HTTP method policy in Playground; exit 1 on a failed assertion.

Run with ``python3 tests/Integration/playground-methods.py``. Requires Node/npx
and the installed Composer dependencies. KNTNT_METHODS_PORT selects the HTTP
port. The server runs in its own process group and is stopped on every exit.
"""

import json
from http.client import parse_headers
from io import BytesIO
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener
from urllib.parse import urlsplit


class NoRedirect(HTTPRedirectHandler):
    """Expose unwanted redirects instead of following them into a GET."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Keep the original method's actual response visible to assertions."""
        return None


def request(base, path, method="GET", headers=None, data=None):
    """Return the actual status, headers and body, including HTTP errors."""
    if method == "HEAD":
        return head_request(base, path, headers or {})
    outgoing = Request(base + path, data=data, headers=headers or {}, method=method)
    try:
        response = build_opener(NoRedirect).open(outgoing, timeout=15)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def head_request(base, path, headers):
    """Read HEAD bytes from the socket because HTTP clients discard any body."""
    address = urlsplit(base)
    fields = {"Host": address.netloc, "Connection": "close", **headers}
    outgoing = f"HEAD {path} HTTP/1.1\r\n"
    outgoing += "".join(f"{name}: {value}\r\n" for name, value in fields.items()) + "\r\n"
    with socket.create_connection((address.hostname, address.port), timeout=15) as connection:
        connection.sendall(outgoing.encode("ascii"))
        chunks = []
        while chunk := connection.recv(65536):
            chunks.append(chunk)
    header_block, separator, body = b"".join(chunks).partition(b"\r\n\r\n")
    assert separator, header_block
    status_line, header_fields = header_block.split(b"\r\n", 1)
    return int(status_line.split()[1]), parse_headers(BytesIO(header_fields)), body


def verify(base):
    """Exercise the form's public HTTP seam after the serving runtime probe."""
    status, _, body = request(
        base, "/wp-content/plugins/kntnt-ai-visibility/tests/Integration/php-version.php"
    )
    assert status == 200 and re.fullmatch(rb"8\.4\.[0-9]+", body), body
    print(f"Playground actual PHP runtime: {body.decode()} (8.4 asserted).", flush=True)

    status, headers, body = request(
        base, "/ordinary/", "POST", {"Accept": "text/markdown"}, b"submission=hello-form"
    )
    assert status == 200 and headers.get_content_type() == "text/html", (status, headers, body)
    assert b"FORM-RECEIVED: hello-form" in body, body
    print("PASS: canonical POST reaches the downstream form with its submitted field.", flush=True)

    paths = ["/ordinary.md", "/llms.txt", "/llms-full.txt", "/ordinary.md/", "/ordinary/?format=markdown"]
    for path in paths:
        for method in ["POST", "OPTIONS", "PUT"]:
            for conditional in [
                {"If-None-Match": "*"},
                {"If-Modified-Since": "Wed, 01 Jan 2098 00:00:00 GMT"},
            ]:
                state = json.loads(request(base, "/?methods_fixture=reset")[2])
                assert all(value is None for value in state.values()), state
                for temperature in ["cold", "warm"]:
                    if temperature == "warm":
                        # Use the corresponding dedicated GET without following
                        # trailing-slash redirects or canonical query aliases.
                        artifact = "/ordinary.md" if path.startswith("/ordinary") else path
                        status, headers, _ = request(base, artifact)
                        assert status == 200, (artifact, status)
                        state = json.loads(request(base, "/?methods_fixture=state")[2])
                        assert any(value is not None for value in state.values()), state
                    status, headers, body = request(
                        base, path, method, {"Accept": "text/markdown", **conditional}
                    )
                    assert status == 200 and headers.get_content_type() == "text/html", (path, method, status, body)
                    marker = f"WORKFLOW-RECEIVED: {method} {path.split('?')[0]}".encode()
                    assert marker in body, (path, method, body)
                    actual = json.loads(request(base, "/?methods_fixture=state")[2])
                    assert actual == state, (temperature, path, method, actual)
                print(f"PASS: cold/warm {method} {path} falls through without caching or 304 ({next(iter(conditional))}).", flush=True)

    for path in ["/ordinary.md", "/llms.txt", "/llms-full.txt", "/ordinary/?format=markdown", "/ordinary/"]:
        expected_type = "text/plain" if path.startswith("/llms") else "text/markdown"
        accept = {"Accept": "text/markdown"}
        for first in ["GET", "HEAD"]:
            request(base, "/?methods_fixture=reset")
            second = "HEAD" if first == "GET" else "GET"
            responses = {first: request(base, path, first, accept)}
            responses[second] = request(base, path, second, accept)
            get_status, get_headers, get_body = responses["GET"]
            head_status, head_headers, head_body = responses["HEAD"]
            assert get_status == head_status == 200, (path, responses)
            assert get_body and not head_body, (path, get_body, head_body)
            assert get_headers.get_content_type() == head_headers.get_content_type() == expected_type, path
            for name in ["Content-Length", "ETag", "Last-Modified", "X-Content-Type-Options"]:
                assert get_headers[name] and get_headers[name] == head_headers[name], (path, name, responses)
            assert int(get_headers["Content-Length"]) == len(get_body), path
            for method in ["GET", "HEAD"]:
                status, _, body = request(base, path, method, {**accept, "If-None-Match": get_headers["ETag"]})
                assert status == 304 and not body, (path, method, status, body)
            print(f"PASS: {first}-first cold/warm GET/HEAD {path} preserves headers, validators and bodyless HEAD.", flush=True)

    print("Methods e2e: 0 failed", flush=True)


def main():
    """Boot one worker and terminate its whole process group after verification."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_METHODS_PORT", "9447")
    base = f"http://127.0.0.1:{port}"
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen(
            [
                "npx", "--yes", "@wp-playground/cli@3.1.36", "server",
                "--php=8.4", "--wp=latest", "--workers=1", f"--port={port}",
                f"--site-url={base}",
                f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
                f"--blueprint={root}/tests/Integration/methods-blueprint.json",
            ],
            stdout=log,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        print(f"Playground process group: {server.pid}", flush=True)
        try:
            for _ in range(90):
                if server.poll() is not None:
                    raise RuntimeError("Playground exited before its fixture was ready")
                try:
                    status, _, body = request(base, "/ordinary/")
                    if status == 200 and b"PUBLIC-ARTIFACT-CONTENT" in body:
                        break
                except (URLError, TimeoutError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Playground fixture did not become ready")
            verify(base)
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            if server.poll() is None:
                os.killpg(server.pid, signal.SIGTERM)
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    os.killpg(server.pid, signal.SIGKILL)
                    server.wait()


if __name__ == "__main__":
    main()
