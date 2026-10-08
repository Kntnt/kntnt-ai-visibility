"""Run the canonical-cache regression on disposable PHP 8.4 Playground.

Usage: python3 tests/Integration/playground-negotiated-cache.py
KNTNT_CACHE_PORT selects the local port (default 9425). KNTNT_CACHE_HTTP_BASE
uses an already-running instance of negotiated-cache-blueprint.json instead.
Exit 0 means every HTTP assertion passed. No DDEV fallback is attempted.
"""

import os
from pathlib import Path
import signal
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request


def run_probe(base):
    """Run the assertions as a separate process and preserve their output."""
    return subprocess.call([sys.executable, str(Path(__file__).with_name("negotiated-cache-http.py")), base])


def main():
    """Start an isolated worker, await the seeded fixture and always stop it."""
    existing = os.environ.get("KNTNT_CACHE_HTTP_BASE")
    if existing:
        return run_probe(existing)
    directory = Path(__file__).resolve().parent
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_CACHE_PORT", "9425")
    command = [
        "npx", "--yes", "@wp-playground/cli@3.1.36", "server",
        "--php=8.4", "--wp=latest", "--workers=1",
        "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
        "--mount=" + str(directory.parent.parent) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
        "--blueprint=" + str(directory / "negotiated-cache-blueprint.json"),
    ]
    worker = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, start_new_session=True)
    output = []
    reader = threading.Thread(target=lambda: output.extend(worker.stdout), daemon=True)
    reader.start()
    print("Playground cache regression worker process group: " + str(worker.pid), flush=True)
    try:
        deadline = time.monotonic() + 180
        while worker.poll() is None and time.monotonic() < deadline:
            try:
                with urllib.request.urlopen(base + "/?cache_test_ready=fixture-only", timeout=3) as response:
                    if response.read() == b"cache-ready":
                        return run_probe(base)
            except (OSError, urllib.error.HTTPError):
                pass
            time.sleep(0.5)
        print("".join(output))
        print("Playground fixture did not become ready; raise the runtime obstacle to the maintainer.", file=sys.stderr)
        return 1
    finally:
        # Stop the complete CLI process group, including its server children.
        try:
            os.killpg(worker.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            worker.wait(timeout=10)
        except subprocess.TimeoutExpired:
            os.killpg(worker.pid, signal.SIGKILL)
            worker.wait()
        reader.join(timeout=2)


if __name__ == "__main__":
    sys.exit(main())
