"""Verify native shared-block edits refresh all dependent public artifacts."""

from http.client import RemoteDisconnected
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
    """Complete a real block mutation without a fixture cache purge."""
    status, _, body = request(base, "/?shared_blocks_token=fixture-only&shared_blocks_action=" + action)
    assert status == 200, (status, body[:1200])
    return json.loads(body)


def verify(base, state):
    """Assert real visible content, lazy invalidation and publication refusal."""
    assert state["php"].startswith("8.4."), state
    print("Actual shared-block Playground PHP " + state["php"], flush=True)
    checks = 0
    mode = "pretty"

    def path(role, html=False):
        if mode == "plain":
            return "/?page_id=" + str(state["ids"][role]) + ("" if html else "&format=markdown")
        return "/" + role + ("/" if html else ".md")

    def document(address, marker, count, absent=()):
        nonlocal checks
        status, headers, body = request(base, address)
        assert status == 200, (address, status, body[:1600])
        assert body.count(marker) == count, (address, marker, count, body[:3000])
        assert all(old not in body for old in absent), (address, absent, body[:3000])
        assert b"Warning:" not in body and b"Fatal error:" not in body, (address, body[:3000])
        checks += 1
        return body

    def visible(marker, nested=False, absent=()):
        # Native HTML is independent evidence for core/block's status handling.
        html = document(path("pattern-a", True), marker, 1 if marker else 0, absent) if marker else request(base, path("pattern-a", True))[2]
        if not marker:
            assert b"SHARED-" not in html, html[:1600]
        for role in ["pattern-a", "pattern-b", "pattern-nested"]:
            body = document(path(role), marker or b"SHARED-", 1 if marker else 0, absent)
            assert body.count(b"NESTED-CHANGED") == (1 if nested and role == "pattern-nested" else 0), (role, body)
        document(path("pattern-unrelated"), b"UNRELATED-SOURCE", 1)
        full = document("/llms-full.txt", marker or b"SHARED-", (4 if "pattern-inflight" in state["ids"] else 3) if marker else 0, absent)
        assert full.count(b"UNRELATED-SOURCE") == 1 and full.count(b"NESTED-CHANGED") == int(nested), full[:2000]
        index = document("/llms.txt", b"[pattern-a](", 1)
        assert b"Shared pattern" not in index and b"Nested pattern" not in index, index

    def mutate(action):
        nonlocal state
        state = control(base, action)
        assert state["current"] > state["before"], (action, state)
        assert state["eager_renders"] == 0, (action, state)
        assert not any(state["cached_pages"].values()), (action, state)
        assert not state["old_full"] and not state["current_full"], (action, state)

    visible(b"SHARED-ORIGINAL")
    visible(b"SHARED-ORIGINAL")  # Every dependent source and aggregate is warm.
    mutate("edit")
    visible(b"SHARED-CHANGED", absent=(b"SHARED-ORIGINAL",))
    mutate("edit-nested")
    visible(b"SHARED-CHANGED", nested=True, absent=(b"SHARED-ORIGINAL",))
    for action, marker in [("draft", None), ("publish", b"SHARED-CHANGED"), ("trash", None), ("publish", b"SHARED-CHANGED"), ("delete", None)]:
        mutate(action)
        visible(marker, nested=True, absent=(b"SHARED-ORIGINAL",))
        print(f"{base} {mode} {action}: all dependent pages and aggregates current; eager renders=0", flush=True)

    state = control(base, "plain")
    mode = "plain"
    visible(b"SHARED-ORIGINAL")
    mutate("edit")
    visible(b"SHARED-CHANGED", absent=(b"SHARED-ORIGINAL",))

    def refused(address):
        nonlocal checks
        status, headers, body = request(base, address, {"If-None-Match": "*"})
        assert status == 403 and b"SHARED-" not in body, (address, status, body[:2000])
        assert "no-store" in headers.get("Cache-Control", "") and headers.get("X-Content-Type-Options") == "nosniff", dict(headers)
        assert not headers.get("ETag") and not headers.get("Last-Modified"), dict(headers)
        checks += 1

    state = control(base, "prepare-page")
    refused(path("pattern-inflight"))
    after = control(base, "state")
    assert not any(after["cached_pages"].values()) and not after["current_full"], after
    visible(b"SHARED-INFLIGHT", absent=(b"SHARED-CHANGED",))
    document(path("pattern-inflight"), b"SHARED-INFLIGHT", 1)
    # The extra shortcode page contributes one more shared-block occurrence.
    document("/llms-full.txt", b"SHARED-INFLIGHT", 4)
    control(base, "arm-full")
    refused("/llms-full.txt")
    after = control(base, "state")
    assert not any(after["cached_pages"].values()) and not after["current_full"], after
    document(path("pattern-a"), b"SHARED-FINAL", 1, (b"SHARED-INFLIGHT",))
    document(path("pattern-b"), b"SHARED-FINAL", 1, (b"SHARED-INFLIGHT",))
    document(path("pattern-nested"), b"SHARED-FINAL", 1, (b"SHARED-INFLIGHT",))
    document(path("pattern-inflight"), b"SHARED-FINAL", 1, (b"SHARED-INFLIGHT",))
    document("/llms-full.txt", b"SHARED-FINAL", 4, (b"SHARED-INFLIGHT",))
    mutate("delete")
    for role in ["pattern-a", "pattern-b", "pattern-nested", "pattern-inflight"]:
        document(path(role), b"SHARED-", 0)
    document("/llms-full.txt", b"SHARED-", 0)
    print(f"Shared blocks HTTP: {checks} response checks; 0 failures", flush=True)


def run(subpath):
    """Run one disposable installation and stop its recorded process group."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_SHARED_BLOCKS_PORT", "9415")
    base = f"http://127.0.0.1:{port}{subpath}"
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            f"--port={port}", f"--site-url={base}", f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
            f"--blueprint={root}/tests/Integration/shared-blocks-blueprint.json",
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
            if tracker.exists():
                subprocess.run(
                    ["uv", "run", str(tracker), "add", "pid", str(worker.pid), "#15 shared block invalidation Playground"],
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
                raise RuntimeError("Shared-block fixtures never became ready")
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
