"""Verify declared post metadata through native WordPress writes and HTTP."""

import json
import os
from pathlib import Path
import runpy
import signal
import subprocess
import tempfile
import time
from urllib.error import URLError

request = runpy.run_path(str(Path(__file__).with_name("playground-publication.py")))["request"]


def control(base, action="state"):
    """Write through native WordPress APIs and expose only fixture state."""
    status, _, body = request(base, "/?rendered_meta_fixture=" + action)
    assert status == 200, (status, body[:1000])
    return json.loads(body)


def verify(base):
    """Warm direct and full representations, then change only native metadata."""
    initial = control(base)
    assert initial["php"].startswith("8.4."), initial
    print("Actual Playground PHP: " + initial["php"], flush=True)
    paths = ["/rendered-meta.md", "/llms-full.txt"]
    if os.environ.get("KNTNT_RENDERED_META_SLICE") == "family":
        for path in paths:
            assert b"FAMILY-ABSENT" in request(base, path)[2]
        control(base, "family-add")
        for path in paths:
            status, _, body = request(base, path)
            assert status == 200 and b"FAMILY-ADDED" in body and b"FAMILY-ABSENT" not in body, (path, status, body[:1000])
        print("Native family-member add refreshes warm direct/full: PASS", flush=True)
        return
    def documents(marker, removed=None):
        """Both public addresses rebuild once and retain the resulting warm bytes."""
        result = {}
        for path in paths:
            status, _, body = request(base, path)
            assert status == 200 and body.count(marker) == 1, (path, status, body[:1500])
            assert removed is None or removed not in body, (path, body[:1500])
            assert b"PRIVATE-DO-NOT-EXPOSE" not in body, (path, body[:1500])
            assert request(base, path)[2] == body, path
            result[path] = body
        return result

    documents(b"META-ABSENT")
    _, original_headers, _ = request(base, "/rendered-meta/", {"Accept": "text/markdown"})
    original_etag = original_headers["ETag"]
    previous = b"META-ABSENT"
    for action, marker in [
        ("add", b"META-ADDED"), ("update", b"META-UPDATED"), ("delete", b"META-ABSENT"),
        ("rest", b"META-REST-FINAL"), ("late-stage", b"META-INTERMEDIATE"), ("late-final", b"META-LATE-FINAL"),
    ]:
        before = control(base)
        after = control(base, action)
        assert after["version"] > before["version"], (action, before, after)
        if action in ["add", "update", "delete"]:
            assert after["modified"] == initial["modified"], (action, initial, after)
        documents(marker, previous)
        previous = marker
        print("Native " + action + " final metadata refreshes direct/warm full: PASS", flush=True)
        if action == "update":
            fields = {"Accept": "text/markdown", "If-Modified-Since": "Fri, 01 Jan 2100 00:00:00 GMT", "If-None-Match": original_etag}
            status, headers, body = request(base, "/rendered-meta/", fields)
            assert status == 200 and marker in body and headers["ETag"] != original_etag, (status, headers, body)
            assert headers.get("Last-Modified") is None and "no-store" in headers.get("Cache-Control", ""), headers
            current_etag = headers["ETag"]
            for method in ["GET", "HEAD"]:
                status, headers, body = request(base, "/rendered-meta/", {**fields, "If-None-Match": current_etag}, method)
                assert status == 304 and body == b"" and headers.get("Last-Modified") is None, (method, status, headers, body)
                status, headers, body = request(base, "/rendered-meta/", {"Accept": "text/markdown", "If-Modified-Since": fields["If-Modified-Since"]}, method)
                assert status == 200 and headers.get("Last-Modified") is None, (method, status, headers, body)
                if method == "HEAD":
                    assert body == b"", body
            print("Unchanged post date: inline ETag precedence/no Last-Modified/raw HEAD: PASS", flush=True)

    control(base, "panel")
    documents(b"PANEL-CURRENT", b"PANEL-ABSENT")
    previous = b"FAMILY-ABSENT"
    for action, marker in [("family-add", b"FAMILY-ADDED"), ("family-update", b"FAMILY-UPDATED"), ("family-delete", b"FAMILY-ABSENT")]:
        before = control(base)
        after = control(base, action)
        assert after["version"] > before["version"], (action, before, after)
        documents(marker, previous)
        previous = marker
        print("Declared repeater family " + action + " (including deleted child): PASS", flush=True)

    previous_bodies = documents(b"META-LATE-FINAL")
    before = control(base)
    for action in ["irrelevant", "revision", "autosave"]:
        after = control(base, action)
        assert after["version"] == before["version"], (action, before, after)
        assert documents(b"META-LATE-FINAL") == previous_bodies, action
        print("Unrelated/edit/revision/autosave metadata remains unchanged: " + action, flush=True)

    previous_image = None
    for action in ["thumbnail-one", "thumbnail-two", "thumbnail-remove"]:
        state = control(base, action)
        bodies = documents(b"META-LATE-FINAL")
        for path, body in bodies.items():
            if state["thumbnail"]:
                assert ('featured_image: "' + state["thumbnail"] + '"').encode() in body, (path, state, body)
            else:
                assert b"featured_image:" not in body, (path, body)
            if previous_image:
                assert previous_image.encode() not in body, (path, body)
        previous_image = state["thumbnail"]
        print("Real attachment front-matter refresh: " + action, flush=True)

    for path, fields in [("/rendered-meta.md", {}), ("/rendered-meta/", {"Accept": "text/markdown"}), ("/llms-full.txt", {})]:
        for method in ["GET", "HEAD"]:
            control(base, "during-render")
            status, headers, body = request(base, path, {**fields, "If-None-Match": "*"}, method)
            assert status == 403 and headers.get_content_type() == "text/plain", (path, method, status, body)
            assert "no-store" in headers.get("Cache-Control", "") and headers.get("ETag") is None, headers
            assert b"PANEL-BEFORE" not in body and (method != "HEAD" or body == b""), body
            documents(b"PANEL-FRESH", b"PANEL-BEFORE")
            print("Metadata revoked during " + method + " " + path + " refuses then lazily recovers: PASS", flush=True)
    print("Rendered metadata HTTP: 0 failures", flush=True)


def run(subpath):
    """Record and stop this fixture's entire owned Playground process group."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_RENDERED_META_PORT", "9414")
    base = "http://127.0.0.1:" + port + subpath
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            "--port=" + port, "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/rendered-meta-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
            if tracker.exists():
                subprocess.run(["uv", "run", str(tracker), "add", "pid", str(worker.pid), "#14 rendered metadata Playground"], check=True, stdout=subprocess.DEVNULL)
            print("Playground process group: " + str(worker.pid), flush=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during setup")
                try:
                    if control(base).get("ready"):
                        break
                except (URLError, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Playground fixtures never ready")
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
    for installation in ([""] if os.environ.get("KNTNT_RENDERED_META_SLICE") else ["", "/sub"]):
        run(installation)

