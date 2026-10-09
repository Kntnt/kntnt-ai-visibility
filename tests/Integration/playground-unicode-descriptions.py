"""Validate exact llms.txt UTF-8 bytes on actual PHP 8.4 WordPress.

KNTNT_UNICODE_PORT selects the worker (default 9446).
KNTNT_UNICODE_PLUGIN_ROOT can mount an exact historical checkout for RED.
Every own server process group is recorded immediately and stopped on exit.
"""

from http.client import RemoteDisconnected
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import urlopen

from playground_process import stop_worker


def fetch(url):
    """Preserve the actual response status, headers and raw bytes."""
    try:
        response = urlopen(url, timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def verify(base):
    """Observe byte validity and independently specified excerpt boundaries."""
    status, _, body = fetch(base + "/?unicode_fixture=state")
    assert status == 200, (status, body)
    state = json.loads(body)
    assert re.fullmatch(r"8\.4\.[0-9]+", state["php"]), state
    print("Actual Playground Unicode runtime: " + json.dumps(state), flush=True)
    expected = {
        "Odd boundary": "a" * 199 + "ö",
        "Odd over limit": "a" * 199 + "ö…",
        "All multibyte exact": "界" * 200,
        "All multibyte over limit": "界" * 200 + "…",
        "Mixed characters": "a" * 198 + "ö🙂…",
        "Decoded entity": "a" * 199 + "ö",
        "Cleaned excerpt": "ö" * 199 + "…",
    }
    for temperature in ["cold", "warm"]:
        status, headers, body = fetch(base + "/llms.txt")
        assert status == 200 and headers.get_content_type() == "text/plain", (status, headers, body)
        assert headers.get_content_charset() == "utf-8", headers
        try:
            document = body.decode("utf-8", errors="strict")
        except UnicodeDecodeError as error:
            print("Invalid llms.txt UTF-8 around byte " + str(error.start) + ": " + body[max(0, error.start - 5):error.start + 8].hex(" "), flush=True)
            raise
        entries = dict(re.findall(r"^- \[([^\]]+)\]\([^\n]+?\): (.*)$", document, re.MULTILINE))
        for title, description in expected.items():
            assert entries.get(title) == description, (temperature, title, entries.get(title), description)
            print("PASS " + temperature + " " + title + ": " + str(len(description)) + " code points, " + str(len(description.encode("utf-8"))) + " bytes", flush=True)
        assert "[gallery]" not in document and "&ouml;" not in document and "<span>" not in document, document
    print("Unicode descriptions HTTP: 0 failures", flush=True)


def main():
    """Boot one recorded disposable worker and stop its whole process group."""
    root = Path(os.environ.get("KNTNT_UNICODE_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_UNICODE_PORT", "9446")
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest",
            "--workers=1", "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/unicode-descriptions-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if cleanup:
                subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 26 Unicode llms descriptions Playground regression"], check=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited before Unicode fixtures became ready")
                try:
                    if fetch(base + "/?unicode_fixture=state")[0] == 200:
                        verify(base)
                        return
                except (URLError, RemoteDisconnected, TimeoutError):
                    pass
                time.sleep(2)
            raise RuntimeError("Playground Unicode fixture did not become ready; raise the runtime obstacle")
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            stop_worker(worker, grace_seconds=10)


if __name__ == "__main__":
    main()
