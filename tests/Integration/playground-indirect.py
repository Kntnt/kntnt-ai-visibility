"""Verify indirect public rendering dependencies in actual PHP 8.4 WordPress.

KNTNT_INDIRECT_PORT selects the disposable worker (default 9456).
KNTNT_INDIRECT_SUBPATH selects one installation; default runs root and /sub.
KNTNT_SESSION_CLEANUP_SCRIPT registers its process group immediately.
"""

import json
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


from http_fixture import raw_head


def request(base, path, method="GET", headers=None):
    """Observe actual headers and bytes, including raw bodyless HEAD responses."""
    if method == "HEAD":
        return raw_head(base + path, headers, timeout=30)
    try:
        response = urlopen(Request(base + path, method=method, headers=headers or {}), timeout=30)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def control(base, action="state", **values):
    """Write through native dependency APIs and observe public cache state."""
    path = "/?" + urlencode({"indirect_fixture": "fixture-only", "action": action, **values})
    status, _, body = request(base, path)
    assert status == 200, (action, status, body[:1000])
    return json.loads(body)


def verify(base):
    """Observe committed dependencies through cached HTTP and real public rendering."""

    # Site header writes remove existing files and defer their regeneration.
    initial = control(base)
    assert initial["php"].startswith("8.4."), initial
    print("Actual indirect Playground PHP " + initial["php"] + " home " + base, flush=True)
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

    # Author identity changes without modifying the consuming post.
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

    # Native menu updates publish their final URL and label before signalling.
    original = {}
    for path in [paths[0], paths[2]]:
        status, fields, body = request(base, path)
        assert status == 200 and b"MENU-A" in body and b"https://example.test/resource-a" in body, (path, status, body[:1000])
        original[path] = fields["ETag"]
    warm = control(base)
    changed = control(base, "menu")
    for path, etag in original.items():
        status, fields, body = request(base, path, headers={"If-None-Match": etag})
        assert status == 200 and b"MENU-B" in body and b"https://example.test/resource-b" in body and b"MENU-A" not in body, ("declared final menu dependency", path, status, body[:1000])
        assert fields["ETag"] != etag, (path, "stale menu validator")
    assert changed["version"] > warm["version"] and not any(changed["cached"].values()) and changed["modified"] == warm["modified"], ("declared menu invalidation must be lazy without resave", warm, changed)
    print("PASS declared menu final writes refresh public body links without source resave", flush=True)

    # A real MO catalogue feeds WordPress gettext inside the public body.
    original = {}
    for path in [paths[0], paths[2]]:
        status, fields, body = request(base, path)
        assert status == 200 and b"CATALOG-A" in body, (path, status, body[:1000])
        original[path] = fields["ETag"]
    warm = control(base)
    changed = control(base, "catalog")
    for path in [paths[2], paths[0]]:
        status, fields, body = request(base, path, headers={"If-None-Match": original[path]})
        assert status == 200 and b"CATALOG-B" in body and b"CATALOG-A" not in body, ("declared real catalogue dependency", path, status, body[:1000])
        assert fields["ETag"] != original[path], (path, "stale catalogue validator")
    assert changed["version"] > warm["version"] and not any(changed["cached"].values()) and changed["modified"] == warm["modified"], ("catalogue invalidation must be lazy without resave", warm, changed)
    print("PASS real MO catalogue writes refresh WordPress-translated public body through declared seam; full-first regeneration", flush=True)

    # Ordinary reads and translation loads preserve the generation and validators.
    stable = control(base)
    for path in paths:
        status, fields, body = request(base, path)
        assert status == 200, (path, status)
        status, head, empty = request(base, path, "HEAD")
        assert status == 200 and empty == b"" and head["ETag"] == fields["ETag"] and head["Content-Length"] == str(len(body)), (path, status, head, empty)
        status, _, empty = request(base, path, headers={"If-None-Match": fields["ETag"]})
        assert status == 304 and empty == b"", (path, status, empty)
    assert control(base)["version"] == stable["version"], "ordinary reads/translation loading advanced generation"

    # Re-entrant post-write signals revoke captured bytes before publication.
    for action, path, method, expected in [("during-full", paths[2], "HEAD", b"CATALOG-C"), ("during-page", paths[0], "GET", b"CATALOG-D")]:
        control(base, action)
        status, fields, body = request(base, path, method, {"If-None-Match": "*"})
        assert status == 403 and "no-store" in fields.get("Cache-Control", "") and fields.get("ETag") is None, (action, path, method, status, fields, body[:1000])
        assert method != "HEAD" or body == b"", (action, body)
        assert not any(control(base)["cached"].values()), (action, "obsolete dependency bytes cached")
        for current_path in [paths[2], paths[0]]:
            status, _, body = request(base, current_path)
            assert status == 200 and expected in body, (action, current_path, status, body[:1000])
    print("PASS declared dependency change during page/full rendering refuses obsolete publication and later recovers", flush=True)
    print("Indirect HTTP: 0 failures", flush=True)


def run(subpath):
    """Own one bounded worker group and stop every child on every exit path."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_INDIRECT_PORT", "9456")
    base = "http://127.0.0.1:" + port + subpath
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
    for installation in ([os.environ["KNTNT_INDIRECT_SUBPATH"]] if "KNTNT_INDIRECT_SUBPATH" in os.environ else ["", "/sub"]):
        run(installation)
