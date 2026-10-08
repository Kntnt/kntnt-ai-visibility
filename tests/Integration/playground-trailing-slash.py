"""Verify complete safe redirect Locations through actual PHP 8.4 WordPress.

KNTNT_SLASH_PORT selects the worker (default 9444). KNTNT_SLASH_PLUGIN_ROOT
can mount an exact pre-fix checkout. KNTNT_SLASH_INSTALLATIONS selects a JSON
list of installation subpaths; the standard matrix runs both root and /sub.
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
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    """Observe the first Location instead of hiding chains by following them."""
    def redirect_request(self, request, response, code, message, headers, target):
        """Leave the redirect response available for exact header assertions."""
        return None


CLIENT = build_opener(NoRedirect())


def fetch(url, method="GET"):
    """Keep actual status, complete headers and response bytes."""
    try:
        response = CLIENT.open(Request(url, method=method), timeout=30)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def control(base, action):
    """Change native permalink/language options only in the disposable site."""
    status, _, body = fetch(base + "/?slash_fixture_token=fixture-only&action=" + action)
    assert status == 200, (action, status, body[:1000])
    return json.loads(body)


def expected(base, source, explicit, plain=False):
    """Known native source identities, independent of normaliser/provider code."""
    role, locale = source["role"], source["locale"]
    if plain:
        query = {} if role == "front" else {"page_id": source["id"]}
        if locale == "sv_SE" or explicit:
            query["lang"] = "sv" if locale == "sv_SE" else "en"
        return base + "/?" + urlencode({**query, "format": "markdown"})
    prefix = "/sv" if locale == "sv_SE" else "/en" if explicit else ""
    leaf = {"ordinary": "/about.md", "front": "/index.md", "index": "/index/index.md", "nested": "/index/index/index.md", "unicode": "/%c3%b6ppettider.md"}[role]
    return base + prefix + leaf


def redirect(url, target, method="GET"):
    """Require the complete local Location and the documented 301 status."""
    status, fields, body = fetch(url, method)
    assert status == 301 and fields.get_all("Location", []) == [target], (url, method, status, fields.get_all("Location"), target, body[:1000])
    assert target.startswith("/") and not target.startswith("//"), target
    if method == "HEAD":
        assert body == b"", (url, body[:1000])


def destination(url, source):
    """The single redirect must finish at the correct real Markdown source."""
    status, fields, body = fetch(url)
    assert status == 200 and fields.get_content_type() == "text/markdown" and "Location" not in fields, (url, status, fields, body[:1000])
    marker = ("SLASH-SOURCE-" + source["role"] + "-" + source["locale"]).encode()
    assert marker in body.replace(b"\\_", b"_"), (url, marker, body[:1000])


def downstream(url, method="GET"):
    """Observe a site workflow after the plugin's template_redirect priority."""
    separator = "&" if "?" in url else "?"
    status, fields, body = fetch(url + separator + "slash_fixture_probe=fixture-only", method)
    assert status == 200 and fields.get("X-Kntnt-Slash-Downstream") == "reached" and "Location" not in fields, (url, method, status, dict(fields), body[:1000])
    if method != "HEAD":
        assert body == b"DOWNSTREAM WORKFLOW", (url, body[:1000])


def verify(base):
    """Pin complete targets, supported forms and cold/warm method fall-through."""
    initial = control(base, "state")
    assert re.fullmatch(r"8\.4\.[0-9]+", initial["php"]), initial
    print("Actual trailing-slash Playground PHP " + initial["php"] + " home " + base, flush=True)
    first = initial["sources"][0]
    ordinary = expected(base, first, False)
    assert first["alternate"] == ordinary, first
    redirect(ordinary + "/", urlsplit(ordinary).path)
    destination(ordinary, first)

    # A repeated installation/language prefix does not identify an alternate.
    duplicate = base + ("/sub/about.md/" if base.endswith("/sub") else "/sv/sv/about.md/")
    downstream(duplicate)
    print("PASS untrusted duplicate prefix is left to the site workflow", flush=True)

    for mode, explicit in [("pretty", False), ("noslash", True)]:
        control(base, "explicit" if explicit else "implicit")
        state = control(base, mode)
        for source in state["sources"]:
            alternate = expected(base, source, explicit)
            assert source["alternate"] == alternate, (source, alternate)
            target = urlsplit(alternate).path
            control(base, "flush")
            for method in ["POST", "OPTIONS", "PUT"]:
                downstream(alternate + "/", method)
            for slashes in ["/", "///"]:
                control(base, "flush")
                redirect(alternate + slashes, target)
                destination(urljoin(base, target), source)
                redirect(alternate + slashes, target)
                redirect(alternate + slashes, target, "HEAD")
            for method in ["POST", "OPTIONS", "PUT"]:
                downstream(alternate + "///", method)
            # Pretty path identity needs no tracking/query suffix in a redirect.
            redirect(alternate + "/?utm_source=fixture&lang=" + ("sv" if source["locale"] == "sv_SE" else "en"), target)
            # A stale plain selector cannot be silently redirected to a path source.
            downstream(alternate + "/?page_id=" + str(source["id"]))
            print("PASS " + mode + " " + source["role"] + " " + source["locale"] + ": cold/warm/HEAD exact Location, one hop, non-read fall-through", flush=True)

        for path in ["/ABOUT.MD/", "/about.Md///", "/sv/sv/about.md/"]:
            downstream(base + path)
        for path in ["//external.test/about.md/", "/%2fexternal.test/about.md/", "/%5cexternal.test/about.md/", "/about%0d%0aX-Injected%3ayes.md/"]:
            status, fields, _ = fetch(base + path + "?slash_fixture_probe=fixture-only")
            assert fields.get("X-Injected") is None, (path, fields)
            location = fields.get("Location")
            if location is not None:
                resolved = urlsplit(urljoin(base, location))
                assert resolved.netloc == urlsplit(base).netloc and not location.startswith("//"), (path, status, location)
                if base.endswith("/sub"):
                    assert resolved.path.startswith("/sub/") and not resolved.path.startswith("/sub/sub/"), (path, location)
        print("PASS encoded/control/scheme-relative paths cannot redirect externally", flush=True)

    control(base, "explicit")
    state = control(base, "plain")
    for source in state["sources"]:
        native = source["alternate"]
        literal = expected(base, source, True, True)
        actual, wanted = urlsplit(native), urlsplit(literal)
        assert (actual.scheme, actual.netloc, actual.path, parse_qs(actual.query)) == (wanted.scheme, wanted.netloc, wanted.path, parse_qs(wanted.query)), (source, literal)
        destination(native, source)
        for path in ["/index.md/", "/plain/" + str(source["id"]) + ".md///"]:
            downstream(base + path + "?page_id=" + str(source["id"]) + "&lang=sv&format=markdown")
        downstream(base + "/?page_id=" + str(source["id"]) + "%2F&format=markdown")
    print("PASS native plain query alternates remain usable; synthetic suffixes and query-value slashes receive no plugin redirect", flush=True)
    print("Trailing slash HTTP: 0 failures", flush=True)


def run(subpath):
    """Record the worker immediately and always stop its entire process group."""
    root = Path(os.environ.get("KNTNT_SLASH_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    port = os.environ.get("KNTNT_SLASH_PORT", "9444")
    base = "http://127.0.0.1:" + port + subpath
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            "--port=" + port, "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/trailing-slash-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if tracker:
                subprocess.run(["uv", "run", tracker, "add", "pid", str(worker.pid), "issue 24 trailing-slash Playground"], check=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during redirect fixture setup")
                try:
                    if len(control(base, "state").get("sources", [])) == 10:
                        break
                except (URLError, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Redirect fixture could not become ready; raise the runtime obstacle")
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
    for installation in json.loads(os.environ.get("KNTNT_SLASH_INSTALLATIONS", '["", "/sub"]')):
        run(installation)
