"""Verify home/index identity separation over actual PHP 8.4 Playground HTTP.

Run with python3; KNTNT_HOME_INDEX_PORT selects the port. The optional
KNTNT_HOME_INDEX_PLUGIN_ROOT mounts an isolated historical checkout for RED
verification. Every server process group is tracked immediately and stopped.
"""

import json
import os
from pathlib import Path
import re
import signal
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    """Keep accidentally advertised aliases visible to the assertions."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Return the original response instead of following another identity."""
        return None


def request(base, path, headers=None):
    """Return the response's real status, headers and source-specific body."""
    try:
        response = build_opener(NoRedirect).open(Request(base + path, headers=headers or {}), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read().replace(b"\\_", b"_")


def control(base, action):
    """Change home configuration through WordPress's actual option lifecycle."""
    status, _, body = request(base, f"/?home_index_token=fixture-only&home_index_action={action}")
    assert status == 200, (action, status, body[:1200])
    return json.loads(body)


def markdown(base, path, role, locale, headers=None):
    """Require one source's exact body, rejecting every other fixture source."""
    status, fields, body = request(base, path, headers)
    assert status == 200 and fields.get_content_type() == "text/markdown", (path, status, fields, body[:1200])
    marker = f"BODY-{role}-{locale}".encode()
    assert body.count(marker) == 1, (path, marker, body)
    for other_locale in ["en_GB", "sv_SE"]:
        for other_role in ["front", "index", "nested"]:
            other = f"BODY-{other_role}-{other_locale}".encode()
            if other != marker:
                assert other not in body, (path, other, body)
    return body


def verify(base):
    """Verify static/blog homes, both warm orders and both Bogo URL policies."""
    status, _, runtime = request(base, "/wp-content/plugins/kntnt-ai-visibility/tests/Integration/php-version.php")
    assert status == 200 and re.fullmatch(rb"8\.4\.[0-9]+", runtime), runtime
    print(f"Playground actual PHP runtime: {runtime.decode()} (8.4 asserted); home {base}.", flush=True)

    # This is the original incident: canonical home cannot select the index page.
    markdown(base, "/?format=markdown", "front", "en_GB")
    for explicit in [False, True]:
        if explicit:
            control(base, "explicit")
        for home_type in ["static", "blog"]:
            control(base, home_type)
            for locale, prefix in [("en_GB", "/en" if explicit else ""), ("sv_SE", "/sv")]:
                canonical = {
                    "front": prefix + ("/" if home_type == "static" else "/ordinary/"),
                    "index": prefix + "/index/",
                    "nested": prefix + "/index/index/",
                }
                paths = {
                    "front": prefix + ("/index.md" if home_type == "static" else "/ordinary.md"),
                    "index": prefix + "/index/index.md",
                    "nested": prefix + "/index/index/index.md",
                }
                for order in [("front", "index", "nested"), ("index", "nested", "front")]:
                    control(base, "flush")
                    for temperature in ["cold", "warm"]:
                        for role in order:
                            markdown(base, paths[role], role, locale)
                        for role in ["front", "index", "nested"]:
                            markdown(base, canonical[role] + "?format=markdown", role, locale)
                            markdown(base, canonical[role], role, locale, {"Accept": "text/markdown"})
                    print(f"PASS: {home_type}, {locale}, {'explicit' if explicit else 'implicit'}, {order[0]} first: cold/warm dedicated and negotiated bytes stay separate.", flush=True)

                # Follow exactly the URLs offered to a client in both discovery forms.
                for role in ["front", "index", "nested"]:
                    status, headers, body = request(base, canonical[role])
                    assert status == 200, (canonical[role], status, body[:1200])
                    links = " ".join(headers.get_all("Link", []))
                    assert (base + paths[role]).encode() in body and f"<{base + paths[role]}>" in links, (role, links, body[:1200])
                    markdown(base, paths[role], role, locale)
                if home_type == "blog":
                    for path, headers in [(prefix + "/?format=markdown", {}), (prefix + "/", {"Accept": "text/markdown"})]:
                        status, fields, _ = request(base, path, headers)
                        assert status == 200 and fields.get_content_type() == "text/html", (path, status, fields)
                    status, _, body = request(base, prefix + "/index.md")
                    assert status == 404 and b"BODY-index-" not in body, (status, body[:1200])
                print(f"PASS: {home_type} {locale} discovery resolves each source; the blog home never borrows an index page.", flush=True)

            # Aggregate-first materialisation must also keep both source identities.
            control(base, "flush")
            for temperature in ["cold", "warm"]:
                status, _, full = request(base, "/llms-full.txt")
                assert status == 200, status
                for locale in ["en_GB", "sv_SE"]:
                    for role in ["front", "index", "nested"]:
                        marker = f"BODY-{role}-{locale}".encode()
                        assert full.count(marker) == 1, (marker, full)
                status, _, index = request(base, "/llms.txt")
                assert status == 200, status
                for locale, prefix in [("en_GB", "/en" if explicit else ""), ("sv_SE", "/sv")]:
                    front_path = prefix + ("/index.md" if home_type == "static" else "/ordinary.md")
                    for role, path in [("front", front_path), ("index", prefix + "/index/index.md"), ("nested", prefix + "/index/index/index.md")]:
                        assert (base + path).encode() in index, (path, index)
                        markdown(base, path, role, locale)
                print(f"PASS: {temperature} {home_type} aggregates contain all six bodies exactly once and every linked alternate resolves.", flush=True)
    print("Home/index e2e: 0 failed", flush=True)


def run(subpath):
    """Boot one server and always stop its entire recorded process group."""
    root = Path(os.environ.get("KNTNT_HOME_INDEX_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    port = os.environ.get("KNTNT_HOME_INDEX_PORT", "9432")
    base = f"http://127.0.0.1:{port}{subpath}"
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            f"--port={port}", f"--site-url={base}", f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
            f"--blueprint={root}/tests/Integration/home-index-blueprint.json",
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
        try:
            if tracker.exists():
                subprocess.run(["uv", "run", str(tracker), "add", "pid", str(server.pid), "#12 home/index Playground verification"], check=True, stdout=subprocess.DEVNULL)
            print(f"Playground process group: {server.pid}", flush=True)
            for _ in range(90):
                if server.poll() is not None:
                    raise RuntimeError("Playground exited before fixture setup")
                try:
                    if "nested" in control(base, "state").get("sv_SE", {}):
                        break
                except (URLError, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Playground fixtures did not become ready")
            verify(base)
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            try:
                os.killpg(server.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(server.pid, signal.SIGKILL)
                server.wait()


if __name__ == "__main__":
    for installation_path in ["", "/sub"]:
        run(installation_path)
