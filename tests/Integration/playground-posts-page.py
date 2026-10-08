"""Verify translated listing roles, lazy turnover and explicit source rendering.

Run with python3; KNTNT_POSTS_PAGE_PORT selects the disposable worker (default
9458). Actual PHP 8.4 is required. Both installation bases and permalink styles
run by default; KNTNT_POSTS_PAGE_SUBPATH restricts one installation for diagnosis.
KNTNT_POSTS_PAGE_STYLES can restrict diagnosis to pretty or plain.
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
from urllib.parse import urlencode
from urllib.request import HTTPRedirectHandler, Request, build_opener

from http_fixture import raw_head


class NoRedirect(HTTPRedirectHandler):
    """Keep unexpected aliases visible instead of following guessed pages."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Return the original redirect for independent route assertions."""
        return None


def request(url, headers=None, method="GET"):
    """Preserve wire bytes and raw HEAD bodies without following redirects."""
    if method == "HEAD":
        return raw_head(url, headers)
    try:
        response = build_opener(NoRedirect).open(Request(url, headers=headers or {}, method=method), timeout=30)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def control(base, action="state"):
    """Read composition state or commit a real option change, never flush here."""
    status, _, body = request(base + "/?" + urlencode({"posts_page_token": "fixture-only", "action": action}))
    assert status == 200, (action, status, body[:3000])
    return json.loads(body)


def verify(base):
    """Use native Bogo options, supported/public seams and actual HTTP bodies."""
    checks = 0

    def state(action="state"):
        value = control(base, action)
        assert "php" in value, value
        assert re.fullmatch(r"8\.4\.[0-9]+", value["php"]), value
        assert value["restored"], value
        for locale, raw in value["raw"].items():
            assert raw["body"], (locale, raw)
            assert raw["scope"] == {"home": False, "singular": True, "main": True, "loop": True,
                "queried": value["sources"][locale]["posts"]["id"], "user": 0, "locale": locale}, raw
        return value

    def document(source):
        nonlocal checks
        status, fields, body = request(source["alternate"])
        assert status == 200 and fields.get_content_type() == "text/markdown", (source, status, body[:2000])
        assert fields["Link"] == f'<{source["canonical"]}>; rel="canonical"', dict(fields)
        status, head, empty = request(source["alternate"], method="HEAD")
        assert status == 200 and empty == b"" and head["ETag"] == fields["ETag"], (status, empty, dict(head))
        status, _, empty = request(source["alternate"], {"If-None-Match": fields["ETag"]})
        assert status == 304 and empty == b"", (status, empty)
        checks += 3
        return body.replace(b"\\_", b"_")

    def matrix(value, excluded):
        nonlocal checks
        for locale, sources in value["sources"].items():
            for role, source in sources.items():
                eligible = role != excluded
                assert source["eligible"] == eligible, (locale, role, excluded, source)
                assert (source["id"] in value["enumerated"]) == eligible, (role, value)
                assert bool(source["advertised"]) == eligible, source
                marker = f"ROLE-{role}-{locale}".encode()
                if eligible:
                    for _ in range(2):
                        assert document(source).count(marker) == 1, source
                else:
                    for _ in range(2):
                        status, fields, body = request(source["alternate"])
                        assert status == (404 if "?" not in source["alternate"] else 200), (source, status, body[:1800])
                        assert fields.get_content_type() != "text/markdown" and marker not in body.replace(b"\\_", b"_"), (source, status, body[:1800])
                        checks += 1
                    status, _, body = request(source["alternate"], method="HEAD")
                    assert status in [200, 404] and body == b"", (source, status, body[:1800])
                    checks += 1
                canonical = source["canonical"]
                for url, headers in [(canonical + ("&" if "?" in canonical else "?") + "format=markdown", {}),
                                     (canonical, {"Accept": "text/markdown"})]:
                    status, fields, body = request(url, headers)
                    assert status == 200, (url, status, body[:1800])
                    assert (fields.get_content_type() == "text/markdown") == eligible, (url, dict(fields), body[:1800])
                    assert (marker in body.replace(b"\\_", b"_")) == eligible, (url, role, body[:1800])
                    checks += 1
                status, fields, body = request(canonical)
                assert status == 200, (canonical, status, body[:1800])
                page_links = re.findall(rb'<link[^>]+type=["\']text/markdown["\'][^>]*>', body)
                assert bool(page_links) == eligible, (canonical, page_links, body[:1800])
                checks += 1
        for path in ["/llms.txt", "/llms-full.txt"]:
            for _ in range(2):
                status, _, body = request(base + path)
                assert status == 200, (path, status, body[:1800])
                for locale, sources in value["sources"].items():
                    for role, source in sources.items():
                        needle = source["alternate"].encode() if path == "/llms.txt" else f"ROLE-{role}-{locale}".encode()
                        assert (needle in body.replace(b"\\_", b"_")) == (role != excluded), (path, role, locale, body[:4000])
                checks += 1
        if excluded == "posts":
            assert all(value["materialise_refused"].values()) and len(value["materialise_refused"]) == 2, value

    def transition(value, excluded):
        """Verify changed role bytes and both aggregates after a warmed policy."""
        nonlocal checks
        for locale, sources in value["sources"].items():
            for role, source in sources.items():
                assert source["eligible"] == (role != excluded), (locale, role, excluded, source)
                assert (source["id"] in value["enumerated"]) == (role != excluded), (role, value)
                assert bool(source["advertised"]) == (role != excluded), source
            for role in ["posts", "ordinary"]:
                source = sources[role]
                status, fields, body = request(source["alternate"])
                marker = f"ROLE-{role}-{locale}".encode()
                assert (fields.get_content_type() == "text/markdown") == (role != excluded), (role, status, dict(fields), body[:1800])
                assert (marker in body.replace(b"\\_", b"_")) == (role != excluded), (role, status, body[:1800])
                checks += 1
        for path in ["/llms.txt", "/llms-full.txt"]:
            status, _, body = request(base + path)
            assert status == 200, (path, status, body[:1800])
            for locale, sources in value["sources"].items():
                for role, source in sources.items():
                    needle = source["alternate"].encode() if path == "/llms.txt" else f"ROLE-{role}-{locale}".encode()
                    assert (needle in body.replace(b"\\_", b"_")) == (role != excluded), (path, role, locale, body[:4000])
            checks += 1
        print(f"PASS role transition: excluded={excluded}, generation={value['current']}", flush=True)

    assert state("stale")["stale_refused"], "Raw explicit-source freshness was weakened"
    styles = os.environ.get("KNTNT_POSTS_PAGE_STYLES", "pretty,plain").split(",")
    assert styles and all(style in ["pretty", "plain"] for style in styles), styles
    for explicit in [False, True]:
        state("explicit" if explicit else "implicit")
        for style in styles:
            state(style)
            state("restore")
            initial = state("static")
            print(f"Actual PHP {initial['php']}; {base}; {style}; explicit={explicit}", flush=True)
            matrix(initial, "posts")
            print("PASS cold/warm dedicated/query/Accept/discovery and raw-source policy", flush=True)
            before = state()
            swapped = state("swap")
            assert swapped["current"] > before["current"], ("page_for_posts did not advance", before, swapped)
            assert not any(source["cached"] for roles in swapped["sources"].values() for source in roles.values()), swapped
            transition(swapped, "ordinary")
            state("restore")
            transition(state("blog"), None)
            transition(state("static"), "posts")
            # Removing and first adding a role use the same completed-option hooks.
            before = state()
            absent = state("delete")
            assert absent["current"] > before["current"], (before, absent)
            transition(absent, None)
            added = state("add")
            assert added["current"] > absent["current"], (absent, added)
            transition(added, "posts")
            # Ordinary source work cannot publish after becoming a posts listing.
            source = added["sources"]["en_GB"]["ordinary"]
            # A native source save invalidates the earlier warm file for a cold producer.
            state("blog")
            state("static")
            status, fields, body = request(source["canonical"], {"Accept": "text/markdown", "X-Role-Revoke": "fixture-only"})
            assert status == 403 and fields.get_content_type() == "text/plain", (status, dict(fields), body[:1800])
            assert b"ROLE-ordinary" not in body, body[:1800]
            assert "no-store" in fields["Cache-Control"] and fields.get("ETag") is None, dict(fields)
            checks += 1
            state("restore")
    print(f"Posts-page HTTP: 0 failures ({checks} response checks)", flush=True)


def run(subpath):
    """Register and stop only this disposable actual PHP 8.4 worker group."""
    root = Path(__file__).resolve().parents[2]
    port = os.environ.get("KNTNT_POSTS_PAGE_PORT", "9458")
    base = f"http://127.0.0.1:{port}{subpath}"
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen(["npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            f"--port={port}", f"--site-url={base}", f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
            f"--blueprint={root}/tests/Integration/posts-page-blueprint.json"], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path(os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT", str(Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py")))
            if tracker.exists():
                subprocess.run(["uv", "run", str(tracker), "add", "pid", str(worker.pid), "Final posts-page role Playground"], check=True, stdout=subprocess.DEVNULL)
            print(f"Playground process group: {worker.pid}", flush=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited before readiness")
                try:
                    if control(base)["sources"]:
                        break
                except (URLError, HTTPError, TimeoutError, ValueError, KeyError, AssertionError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Playground readiness timed out")
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
                worker.wait(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(worker.pid, signal.SIGKILL)
                worker.wait()


if __name__ == "__main__":
    for installation in ([os.environ["KNTNT_POSTS_PAGE_SUBPATH"]] if "KNTNT_POSTS_PAGE_SUBPATH" in os.environ else ["", "/sub"]):
        run(installation)
