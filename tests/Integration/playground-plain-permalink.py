"""Verify plain-query identities and pretty/plain transitions over real HTTP.

KNTNT_PLAIN_PORT selects the port; KNTNT_PLAIN_PLUGIN_ROOT can mount an isolated
historical checkout for RED evidence. Servers own tracked process groups.
"""

import json
from html import unescape
import os
from pathlib import Path
import re
import signal
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, Request, build_opener
from urllib.parse import parse_qs, urlencode, urlsplit


class NoRedirect(HTTPRedirectHandler):
    """Keep unsupported aliases visible instead of following canonical redirects."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Expose the first response for independent address assertions."""
        return None


def request(base, path, headers=None):
    """Read the actual response, normalising Markdown's escaped underscores."""
    try:
        response = build_opener(NoRedirect).open(Request(base + path, headers=headers or {}), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read().replace(b"\\_", b"_")


def control(base, action):
    """Exercise real permalink/home option lifecycle in the disposable site."""
    status, _, body = request(base, f"/?plain_permalink_token=fixture-only&plain_permalink_action={action}")
    assert status == 200, (action, status, body[:1200])
    return json.loads(body)


def markdown(base, path, role, locale, headers=None):
    """Require one source-specific body and exclude every other seeded source."""
    status, fields, body = request(base, path, headers)
    assert status == 200 and fields.get_content_type() == "text/markdown", (path, status, fields, body[:1200])
    marker = f"BODY-{role}-{locale}".encode()
    assert body.count(marker) == 1, (path, marker, body)
    for other_locale in ["en_GB", "sv_SE"]:
        for other_role in ["front", "index", "nested", "post"]:
            other = f"BODY-{other_role}-{other_locale}".encode()
            if other != marker:
                assert other not in body, (path, other, body)
    return fields, body


def addresses(ids, pretty, static, explicit, locale):
    """Build literal expectations from WordPress's documented native selectors."""
    canonicals, alternates = {}, {}
    for role in ["front", "index", "nested", "post"]:
        if pretty:
            prefix = "/sv" if locale == "sv_SE" else "/en" if explicit else ""
            suffix = {"front": "/" if static else "/ordinary/", "index": "/index/", "nested": "/index/index/", "post": "/plain-story/"}[role]
            canonical = prefix + suffix
            alternate = prefix + {"front": "/index.md" if static else "/ordinary.md", "index": "/index/index.md", "nested": "/index/index/index.md", "post": "/plain-story.md"}[role]
        else:
            query = {} if role == "front" and static else {"p" if role == "post" else "page_id": ids[locale][role]}
            if locale == "sv_SE" or explicit:
                query["lang"] = "sv" if locale == "sv_SE" else "en"
            canonical = "/" + ("?" + urlencode(query) if query else "")
            alternate = "/?" + urlencode({**query, "format": "markdown"})
        canonicals[role], alternates[role] = canonical, alternate
    return canonicals, alternates


def same_url(left, right):
    """Compare URL identity without depending on query parameter ordering."""
    a, b = urlsplit(left), urlsplit(right)
    return (a.scheme, a.netloc, a.path, parse_qs(a.query)) == (b.scheme, b.netloc, b.path, parse_qs(b.query))


def metadata(body, base, canonical, role, locale):
    """Validate the source-specific public title and canonical front matter."""
    assert body.count(f'title: "{role} {locale}"'.encode()) == 1, (role, locale, body)
    matches = re.findall(rb'^canonical_url: "([^"]+)"$', body, re.M)
    assert len(matches) == 1 and same_url(matches[0].decode(), base + canonical), (canonical, matches, body)


def unsupported(base, path):
    """An unsupported alias cannot return a different source as Markdown."""
    status, fields, body = request(base, path, {"Accept": "text/markdown"})
    assert fields.get_content_type() != "text/markdown", (path, status, body[:1200])


def verify(base, ids):
    """Verify all supported forms, discovery, aggregates and native transitions."""
    status, _, runtime = request(base, "/wp-content/plugins/kntnt-ai-visibility/tests/Integration/php-version.php")
    assert status == 200 and re.fullmatch(rb"8\.4\.[0-9]+", runtime), runtime
    print(f"Playground actual PHP runtime: {runtime.decode()} (8.4 asserted); home {base}.", flush=True)
    markdown(base, f"/?page_id={ids['en_GB']['index']}&format=markdown", "index", "en_GB")
    for explicit in [False, True]:
        control(base, "explicit" if explicit else "implicit")
        for static in [True, False]:
            control(base, "static" if static else "blog")
            # Each option transition follows a fully warm previous mode. No
            # fixture purge masks the native permalink lifecycle at this point.
            for pretty in [False, True, False]:
                control(base, "pretty" if pretty else "plain")
                expected = {locale: addresses(ids, pretty, static, explicit, locale) for locale in ["en_GB", "sv_SE"]}
                for locale, (canonicals, alternates) in expected.items():
                    for order in [("front", "index", "nested", "post"), ("index", "post", "nested", "front")]:
                        control(base, "flush")
                        for temperature in ["cold", "warm"]:
                            for role in order:
                                _, body = markdown(base, alternates[role], role, locale)
                                metadata(body, base, canonicals[role], role, locale)
                            for role in order:
                                query = canonicals[role] + ("&" if "?" in canonicals[role] else "?") + "format=markdown"
                                _, body = markdown(base, query, role, locale)
                                metadata(body, base, canonicals[role], role, locale)
                                markdown(base, canonicals[role], role, locale, {"Accept": "text/markdown"})
                    for role in ["front", "index", "nested", "post"]:
                        status, fields, body = request(base, canonicals[role])
                        assert status == 200 and fields.get_content_type() == "text/html", (canonicals[role], status, body[:1200])
                        links = " ".join(fields.get_all("Link", []))
                        advertised = re.findall(r'<([^>]+)>; rel="alternate"; type="text/markdown"', links)
                        assert len(advertised) == 1 and same_url(advertised[0], base + alternates[role]), (role, links)
                        html_links = re.findall(r'<link\b[^>]*>', unescape(body.decode()), re.I)
                        assert any('type="text/markdown"' in link and any(same_url(url, base + alternates[role]) for url in re.findall(r'href="([^"]+)"', link)) for link in html_links), (role, html_links)
                        markdown(base, advertised[0][len(base):], role, locale)
                    if not static:
                        home = ("/sv/" if locale == "sv_SE" else "/en/" if explicit else "/") if pretty else ("/?lang=sv" if locale == "sv_SE" else "/?lang=en" if explicit else "/")
                        unsupported(base, home + ("&" if "?" in home else "?") + "format=markdown")
                        unsupported(base, home)
                    print(f"PASS: {'pretty' if pretty else 'plain'} {'static' if static else 'blog'} {locale} {'explicit' if explicit else 'implicit'}: both warm orders, supported forms, metadata and discovery.", flush=True)

                # Build aggregate-first after purging only for the order test.
                control(base, "flush")
                for temperature in ["cold", "warm"]:
                    status, fields, full = request(base, "/llms-full.txt")
                    assert status == 200 and fields.get_content_type() == "text/plain", (status, full[:1200])
                    for locale, (canonicals, alternates) in expected.items():
                        for role in ["front", "index", "nested", "post"]:
                            marker = f"BODY-{role}-{locale}".encode()
                            assert full.count(marker) == 1, (marker, full)
                            blocks = [block for block in full.split(b"\n---\n") if marker in block]
                            # Full_Builder separates whole front-matter documents
                            # with an HTML marker; locate each source independently.
                            start = full.rfind(b'---\ntitle:', 0, full.index(marker))
                            end = full.find(b'---\ntitle:', full.index(marker))
                            document = full[start:end if end != -1 else len(full)]
                            metadata(document, base, canonicals[role], role, locale)
                    status, fields, index = request(base, "/llms.txt")
                    assert status == 200 and fields.get_content_type() == "text/plain", (status, index[:1200])
                    urls = [url.decode() for url in re.findall(rb'\]\((https?://[^)]+)\)', index)]
                    for locale, (_, alternates) in expected.items():
                        for role, alternate in alternates.items():
                            matching = [url for url in urls if same_url(url, base + alternate)]
                            assert len(matching) == 1, (alternate, urls)
                            markdown(base, matching[0][len(base):], role, locale)
                    print(f"PASS: {temperature} {'pretty' if pretty else 'plain'} aggregates: all eight bodies/metadata exactly once; all advertised URLs resolve.", flush=True)

                if pretty:
                    unsupported(base, f"/?page_id={ids['en_GB']['index']}&format=markdown")
                    unsupported(base, f"/?p={ids['en_GB']['post']}&format=markdown")
                else:
                    for alias in ["/index.md", "/index/index.md", "/sv/index.md", f"/plain/{ids['en_GB']['index']}.md", f"/plain/{ids['sv_SE']['post']}.md"]:
                        unsupported(base, alias)
                    for query in ["page_id=0", "page_id=0007", "page_id=-7", "page_id[]=7", "page_id=99999999999999999999999", f"page_id={ids['en_GB']['index']}&p={ids['en_GB']['post']}", f"page_id={ids['en_GB']['index']}&lang=sv", f"page_id={ids['en_GB']['index']}&extra=unsupported"]:
                        unsupported(base, "/?" + query + "&format=markdown")
                    if base.endswith("/sub"):
                        outside = base[:-4]
                        unsupported(outside, f"/?page_id={ids['en_GB']['index']}&format=markdown")
    print("Plain permalink HTTP: 0 failures", flush=True)


def run(subpath):
    """Boot one worker and stop its whole group even after an assertion fails."""
    root = Path(os.environ.get("KNTNT_PLAIN_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    port = os.environ.get("KNTNT_PLAIN_PORT", "9431")
    base = f"http://127.0.0.1:{port}{subpath}"
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            f"--port={port}", f"--site-url={base}", f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
            f"--blueprint={root}/tests/Integration/plain-permalink-blueprint.json",
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
            if tracker.exists():
                subprocess.run(["uv", "run", str(tracker), "add", "pid", str(worker.pid), "#11 plain permalink Playground"], check=True, stdout=subprocess.DEVNULL)
            print(f"Playground process group: {worker.pid}", flush=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during setup")
                try:
                    ids = control(base, "state")
                    if "post" in ids.get("sv_SE", {}):
                        break
                except (URLError, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Plain permalink fixtures never became ready")
            verify(base, ids)
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
