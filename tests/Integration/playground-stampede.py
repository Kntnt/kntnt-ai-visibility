"""Verify bounded default locks and safe failure through actual WordPress HTTP.

KNTNT_STAMPEDE_PORT selects the disposable single-worker PHP8.4 server.
Independent concurrent producers are covered by the approved native seam;
this WASM fixture reports its process capabilities without a DDEV fallback.
"""

import os
from pathlib import Path
import signal
import subprocess
import tempfile
import time
from urllib.error import URLError

import json
import runpy
from types import SimpleNamespace

source = Path(__file__).with_name("playground-publication.py")
helpers = runpy.run_path(str(source))
http = SimpleNamespace(request=helpers["request"], control=helpers["control"])


def verify(base):
    """Use native saves, actual default lock files and public artifact responses."""
    state = http.control(base, "state")
    assert state["php"].startswith("8.4."), state
    print("Actual Playground PHP: " + state["php"], flush=True)
    paths = ["/publication-sql-source.md", "/llms.txt", "/llms-full.txt"]
    previous = None
    healthy = {}
    for turn in range(1, 11):
        state = http.control(base, "stampede-turn")
        marker = ("CURRENT-LOCK-" + str(turn)).encode()
        for path in paths:
            status, _, body = http.request(base, path)
            assert status == 200 and marker in body, (path, status, body[:1000])
            assert previous is None or previous not in body, (path, body[:1000])
            assert http.request(base, path)[2] == body, path
            healthy[path] = body
        state = http.control(base, "stampede-state")
        assert state["directories"] == 1 and state["locks"] == 3, state
        print(f"Native save generation {turn}: current bodies, one namespace/three stable family locks", flush=True)
        previous = marker
    print("WASM pcntl_fork available: " + str(state["fork"]) + "; HTTP fixture has one PHP worker", flush=True)
    http.control(base, "stampede-obstruct")
    try:
        for path in paths:
            status, headers, body = http.request(base, path)
            assert status == 200 and body == healthy[path], (path, status, body[:1000])
            assert "no-store" in headers.get("Cache-Control", ""), headers
            status, headers, body = http.request(base, path, method="HEAD")
            assert status == 200 and body == b"" and "no-store" in headers.get("Cache-Control", ""), (path, status, body)
            print("Unavailable stampede directory: valid uncached GET/raw HEAD " + path, flush=True)
    finally:
        http.control(base, "stampede-restore")
    for path in paths:
        assert http.request(base, path)[2] == healthy[path], path
    print("Stampede HTTP: 0 failures", flush=True)


def run(subpath):
    """Record and stop this fixture's entire owned Playground process group."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_STAMPEDE_PORT", "9432")
    base = "http://127.0.0.1:" + port + subpath
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            "--port=" + port, "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/publication-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
            if tracker.exists():
                subprocess.run(["uv", "run", str(tracker), "add", "pid", str(worker.pid), "#32 stampede Playground"], check=True, stdout=subprocess.DEVNULL)
            print("Playground process group: " + str(worker.pid), flush=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during setup")
                try:
                    if http.control(base, "state").get("ready"):
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
    for installation in ["", "/sub"]:
        run(installation)

