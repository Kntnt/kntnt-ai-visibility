"""Verify immutable response bytes while two real generations prune each other."""

from http.client import RemoteDisconnected
from email.utils import formatdate
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener

from http_fixture import raw_head

from playground_process import stop_worker


class NoRedirect(HTTPRedirectHandler):
    """Expose stale addresses rather than following WordPress's guessed URL."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Return the original response for independent identity assertions."""
        return None


def request(base, path, headers=None, method="GET"):
    """Read actual status, headers and source bytes from the public endpoint."""
    if method == "HEAD":
        return raw_head(base + path, headers, timeout=20)
    try:
        response = build_opener(NoRedirect).open(Request(base + path, headers=headers or {}, method=method), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()



def control(base, action):
    """Read or reset only the disposable fixture's actual storage."""
    status, _, body = request(base, "/?pruning_token=fixture-only&pruning_action=" + action)
    assert status == 200, (status, body[:1200])
    return json.loads(body)


def verify(base, ids):
    """Old response metadata and body survive removal of its pathname."""
    assert ids["php"].startswith("8.4."), ids
    print("Actual Playground PHP: " + ids["php"], flush=True)
    cases = [
        ("GET", {}, 200),
        ("HEAD", {}, 200),
        ("GET", {"If-None-Match": "fixture-etag"}, 304),
        ("HEAD", {"If-None-Match": "fixture-etag"}, 304),
        ("GET", {"If-Modified-Since": "Wed, 01 Jan 2098 00:00:00 GMT"}, 304),
        ("HEAD", {"If-Modified-Since": "Wed, 01 Jan 2098 00:00:00 GMT"}, 304),
        ("GET", {"If-None-Match": '"other-generation"', "If-Modified-Since": "Wed, 01 Jan 2098 00:00:00 GMT"}, 200),
    ]
    for path, kind, old, new in [
        ("/llms.txt", "index", b"OLD TWO\n", b"CURRENT THREE\n"),
        ("/llms-full.txt", "full", b"OLD FULL TWO\n", b"CURRENT FULL THREE\n"),
    ]:
        etag = '"' + hashlib.md5(old).hexdigest() + '"'
        for method, conditions, expected_status in cases:
            control(base, "reset")
            fields = {key: etag if value == "fixture-etag" else value for key, value in conditions.items()}
            fields["X-Pruning-Overlap"] = "new-after-old"
            status, headers, body = request(base, path, fields, method)
            current = control(base, "state")
            assert current["current"] == 3 and current["old_" + kind] is False and current["new_" + kind].encode() == new, current
            assert status == expected_status, (method, fields, status, dict(headers), body[:2000])
            assert body == (old if method == "GET" and status == 200 else b""), (method, status, body[:2000])
            assert headers["Last-Modified"] == formatdate(int(current["old_mtime"]), usegmt=True), (dict(headers), current)
            assert headers["X-Content-Type-Options"] == "nosniff", dict(headers)
            if status == 200:
                assert headers["Content-Length"] == str(len(old)), dict(headers)
                assert headers.get_content_type() == "text/plain", dict(headers)
                assert headers["ETag"] == etag, dict(headers)
            elif "If-None-Match" in fields:
                assert headers["ETag"] == etag, dict(headers)
            else:
                assert headers.get("ETag") is None, dict(headers)
            # The public early router now serves the surviving newer generation.
            status, warm_headers, body = request(base, path)
            assert status == 200 and body == new, (status, body)
            assert warm_headers["Content-Length"] == str(len(new)), dict(warm_headers)
            assert warm_headers["ETag"] == '"' + hashlib.md5(new).hexdigest() + '"', dict(warm_headers)
            status, head_headers, body = request(base, path, method="HEAD")
            assert status == 200 and body == b"" and head_headers["ETag"] == warm_headers["ETag"], (status, body, dict(head_headers))
            status, _, body = request(base, path, {"If-None-Match": warm_headers["ETag"]})
            assert status == 304 and body == b"", (status, body)
            print(f"{path} old {method}/{expected_status}: coherent bytes, v3 retained, warm GET/HEAD/304", flush=True)
    # Ordinary pages still use the same immutable response path and exact link.
    status, cold_headers, cold = request(base, "/pruning-source.md")
    assert status == 200 and cold.count(b"ORDINARY SOURCE") == 1, (status, cold[:1000])
    assert cold_headers["Link"] == f'<{base}/pruning-source/>; rel="canonical"', dict(cold_headers)
    status, warm_headers, warm = request(base, "/pruning-source.md")
    assert status == 200 and warm == cold and warm_headers["Link"] == cold_headers["Link"], (status, warm[:1000])
    assert warm_headers["Content-Length"] == str(len(cold)) and warm_headers["ETag"] == '"' + hashlib.md5(cold).hexdigest() + '"', dict(warm_headers)
    status, head_headers, body = request(base, "/pruning-source.md", method="HEAD")
    assert status == 200 and body == b"" and head_headers["Link"] == cold_headers["Link"], (status, body, dict(head_headers))
    status, _, body = request(base, "/pruning-source.md", {"If-None-Match": warm_headers["ETag"]})
    assert status == 304 and body == b"", (status, body)
    print("Pruning response HTTP: 0 failures (60 response checks)", flush=True)


def run(subpath):
    """Run one disposable installation and stop its recorded process group."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_PRUNING_PORT", "9421")
    base = f"http://127.0.0.1:{port}{subpath}"
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            f"--port={port}", f"--site-url={base}", f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
            f"--blueprint={root}/tests/Integration/pruning-blueprint.json",
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
            if tracker.exists():
                subprocess.run(
                    ["uv", "run", str(tracker), "add", "pid", str(worker.pid), "#21 two-generation pruning Playground"],
                    check=True, stdout=subprocess.DEVNULL,
                )
            print(f"Playground process group: {worker.pid}", flush=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during setup")
                try:
                    ids = control(base, "state")
                    if ids.get("ready"):
                        break
                except (URLError, RemoteDisconnected, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Pruning fixtures never became ready")
            verify(base, ids)
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            stop_worker(worker)


if __name__ == "__main__":
    for installation in ["", "/sub"]:
        run(installation)
