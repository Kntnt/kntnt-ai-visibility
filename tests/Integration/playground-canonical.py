"""Assert full canonical paths and Bogo identities over real Playground HTTP.

KNTNT_CANONICAL_PORT chooses the port; KNTNT_CANONICAL_PLUGIN_ROOT supports an
isolated historical checkout for RED evidence. Each server owns a process group
and is registered immediately with the user's session-cleanup tracker.
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
from urllib.request import HTTPRedirectHandler, Request, build_opener

from playground_process import stop_worker


class NoRedirect(HTTPRedirectHandler):
    """Keep aliases visible rather than following them into real artifacts."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Return the original response for canonical redirects."""
        return None


def request(base, path, headers=None):
    """Read actual bytes and headers, including deliberate HTTP errors."""
    try:
        response = build_opener(NoRedirect).open(Request(base + path, headers=headers or {}), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read().replace(b"\\_", b"_")


def control(base, action, audit=False):
    """Mutate fixtures through real WordPress actions, never direct cache edits."""
    prefix = "audit" if audit else "canonical"
    status, _, body = request(base, f"/?{prefix}_token=fixture-only&{prefix}_action={action}")
    assert status == 200, (action, status, body)
    return json.loads(body)


def markdown(base, path, expected, forbidden=None, headers=None, source=None):
    """Require the source-specific public bytes rather than status alone."""
    status, fields, body = request(base, path, headers)
    assert status == 200 and fields.get_content_type() == "text/markdown", (path, status, fields, body[:1200])
    assert expected.encode() in body, (path, expected, body)
    if forbidden:
        assert forbidden.encode() not in body, (path, forbidden, body)
    if source:
        assert f"CONTEXT-{source}-".encode() in body, (path, source, body)
    return fields, body


def absent(base, path, marker):
    """An invented or obsolete path must not serve another page's artifact."""
    status, headers, body = request(base, path, {"Accept": "text/markdown"})
    assert status == 404 and marker.encode() not in body and headers.get_content_type() != "text/markdown", (path, status, body[:1200])


def translations(base, prefix, ids, front=False):
    """Verify both languages across dedicated, negotiated and discovery seams."""
    for locale, language_path, other in [("en_GB", prefix, "sv_SE"), ("sv_SE", "/sv", "en_GB")]:
        html = language_path + ("/" if front else "/same-slug/")
        artifact = language_path + ("/index.md" if front else "/same-slug.md")
        for form, path, headers in [
            ("suffix", artifact, {}),
            ("Accept", html, {"Accept": "text/markdown"}),
            ("query", html + "?format=markdown", {}),
        ]:
            control(base, "flush", audit=True)
            for temperature in ["cold", "warm"]:
                markdown(base, path, f"BODY-{locale}", f"BODY-{other}", headers, ids[locale])
                print(f"PASS: {temperature} {locale} {form} {path} contains its own source body.", flush=True)
        status, headers, body = request(base, html)
        assert status == 200 and headers.get_content_type() == "text/html", (html, status, body[:1200])
        assert (base + artifact).encode() in body and f"<{base + artifact}>" in headers.get("Link", ""), (html, headers, body)
        assert f"<{base}/llms.txt>" in " ".join(headers.get_all("Link", [])), headers
        assert f"<{base}/llms-full.txt>" in " ".join(headers.get_all("Link", [])), headers
        print(f"PASS: HTML and HTTP discovery for {html} use the source language; singletons use installation root.", flush=True)
    control(base, "flush", audit=True)
    for temperature in ["cold", "warm"]:
        status, _, index = request(base, "/llms.txt")
        assert status == 200, status
        for language_path in [prefix, "/sv"]:
            suffix = "/index.md" if front else "/same-slug.md"
            assert (base + language_path + suffix).encode() in index, index
        status, _, full = request(base, "/llms-full.txt")
        assert status == 200, status
        for locale in ["en_GB", "sv_SE"]:
            assert f"BODY-{locale}".encode() in full and f"CONTEXT-{ids[locale]}-{locale}".encode() in full, full
        print(f"PASS: {temperature} aggregates contain both source-language URLs and distinct actual bodies.", flush=True)


def verify(base, subpath):
    """Exercise routing and identity lifecycle on actual PHP 8.4 and Bogo 3.9.3."""
    status, _, runtime = request(base, "/wp-content/plugins/kntnt-ai-visibility/tests/Integration/php-version.php")
    assert status == 200 and re.fullmatch(rb"8\.4\.[0-9]+", runtime), runtime
    print(f"Playground actual PHP runtime: {runtime.decode()} (8.4 asserted); home {base}.", flush=True)
    ids = control(base, "state")
    # Changing the site's default language changes both shared-slug identities.
    markdown(base, "/same-slug.md", "BODY-en_GB", "BODY-sv_SE")
    markdown(base, "/sv/same-slug.md", "BODY-sv_SE", "BODY-en_GB")
    request(base, "/llms.txt")
    request(base, "/llms-full.txt")
    control(base, "default-swedish")
    markdown(base, "/same-slug.md", "BODY-sv_SE", "BODY-en_GB")
    markdown(base, "/en/same-slug.md", "BODY-en_GB", "BODY-sv_SE")
    absent(base, "/sv/same-slug.md", "BODY-sv_SE")
    index = request(base, "/llms.txt")[2]
    assert (base + "/en/same-slug.md").encode() in index and (base + "/sv/same-slug.md").encode() not in index, index
    full = request(base, "/llms-full.txt")[2]
    assert b"BODY-en_GB" in full and b"BODY-sv_SE" in full, full
    control(base, "default-english")
    markdown(base, "/same-slug.md", "BODY-en_GB", "BODY-sv_SE")
    markdown(base, "/sv/same-slug.md", "BODY-sv_SE", "BODY-en_GB")
    print("PASS: changing the site's default language invalidates warm shared-slug identities and both aggregates.", flush=True)
    translations(base, "", ids)
    for temperature in ["cold", "warm"]:
        markdown(base, "/parent/child.md", "CANONICAL-nested")
        markdown(base, "/l%C3%A4sa.md", "CANONICAL-encoded")
        markdown(base, "/ordinary.md", "CANONICAL-ordinary")
        for path, marker in [
            ("/invented/path/ordinary.md", "CANONICAL-ordinary"),
            ("/invented/child.md", "CANONICAL-nested"),
            ("/sv/ordinary.md", "CANONICAL-ordinary"),
            ("/sv/parent/child.md", "CANONICAL-nested"),
        ]:
            absent(base, path, marker)
        if subpath:
            origin = base.removesuffix(subpath)
            absent(origin, "/ordinary.md", "CANONICAL-ordinary")
            absent(origin, "/same-slug.md", "BODY-en_GB")
            absent(origin, subpath + "-extra/ordinary.md", "CANONICAL-ordinary")
        print(f"PASS: {temperature} nested, encoded and ordinary paths resolve; invented, wrong-language and out-of-home paths refuse.", flush=True)

    # No flush control follows these writes: the application owns invalidation.
    markdown(base, "/language-change.md", "CANONICAL-language")
    request(base, "/llms.txt")
    request(base, "/llms-full.txt")
    control(base, "language")
    for _ in range(2):
        absent(base, "/language-change.md", "CANONICAL-language")
        markdown(base, "/sv/language-change.md", "CANONICAL-language")
    index = request(base, "/llms.txt")[2]
    assert (base + "/language-change.md").encode() not in index and (base + "/sv/language-change.md").encode() in index, index
    assert b"CANONICAL-language" in request(base, "/llms-full.txt")[2]
    print("PASS: changing _locale invalidates the warm old language identity and both aggregates.", flush=True)

    markdown(base, "/ordinary.md", "CANONICAL-ordinary")
    control(base, "dated")
    for _ in range(2):
        absent(base, "/ordinary.md", "CANONICAL-ordinary")
        markdown(base, "/2026/10/08/ordinary.md", "CANONICAL-ordinary")
        markdown(base, "/parent/child.md", "CANONICAL-nested")
    index = request(base, "/llms.txt")[2]
    assert (base + "/ordinary.md").encode() not in index and (base + "/2026/10/08/ordinary.md").encode() in index, index
    full = request(base, "/llms-full.txt")[2]
    assert b"CANONICAL-ordinary" in full, full
    print("PASS: permalink policy invalidates the old warm address; dated posts and nested pages retain actual bodies.", flush=True)

    control(base, "flat")
    control(base, "explicit")
    control(base, "flush", audit=True)
    translations(base, "/en", ids)
    control(base, "front")
    translations(base, "/en", ids, front=True)
    control(base, "implicit")
    control(base, "flush", audit=True)
    translations(base, "", ids, front=True)
    print("Canonical paths e2e: 0 failed", flush=True)


def run(subpath):
    """Start one isolated server and always stop its entire process group."""
    root = Path(os.environ.get("KNTNT_CANONICAL_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    port = os.environ.get("KNTNT_CANONICAL_PORT", "9442")
    base = f"http://127.0.0.1:{port}{subpath}"
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            f"--port={port}", f"--site-url={base}",
            f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
            f"--blueprint={root}/tests/Integration/canonical-blueprint.json",
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
        try:
            if tracker.exists():
                subprocess.run(["uv", "run", str(tracker), "add", "pid", str(server.pid), "#22 canonical Playground HTTP verification"], check=True, stdout=subprocess.DEVNULL)
            print(f"Playground process group: {server.pid}", flush=True)
            for _ in range(90):
                if server.poll() is not None:
                    raise RuntimeError("Playground exited before its fixture was ready")
                try:
                    if "ordinary" in control(base, "state"):
                        break
                except (URLError, RemoteDisconnected, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Playground fixtures did not become ready")
            verify(base, subpath)
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            # Stop descendants even if the npx wrapper has already exited.
            stop_worker(server)


if __name__ == "__main__":
    for installation_path in ["", "/sub"]:
        run(installation_path)
