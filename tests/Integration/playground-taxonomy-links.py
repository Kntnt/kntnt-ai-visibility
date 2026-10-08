"""Follow emitted taxonomy metadata links in real PHP 8.4 WordPress.

Run with python3; KNTNT_TAXONOMY_PORT selects the worker (default 9445).
KNTNT_TAXONOMY_PLUGIN_ROOT can mount an exact historical checkout for RED.
Every own server process group is recorded immediately and stopped on exit.
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
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


def fetch(url, headers=None):
    """Preserve the actual status, response headers and emitted bytes."""
    try:
        response = urlopen(Request(url, headers=headers or {}), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


def terms(frontmatter):
    """Decode the builder's JSON-compatible double-quoted YAML scalar subset."""
    entries = {}
    key = None
    for line in frontmatter.splitlines():
        if line in ["categories:", "tags:"]:
            key = line[:-1]
            entries[key] = []
        elif line.startswith("  - name: "):
            entries[key].append({"name": json.loads(line.removeprefix("  - name: "))})
        elif line.startswith("    url: "):
            entries[key][-1]["url"] = json.loads(line.removeprefix("    url: "))
    return entries


def verify(base):
    """Follow exact category/tag links and exercise real WP_Error results."""
    def control(action):
        status, _, body = fetch(base + "/?taxonomy_fixture=" + action)
        assert status == 200, (action, status, body)
        return json.loads(body)

    state = control("state")
    assert re.fullmatch(r"8\.4\.[0-9]+", state["php"]), state
    print("Actual Playground PHP " + state["php"], flush=True)
    assert state["terms"]["category"]["name"] == 'Nyheter "Å, Ä &amp; Ö"', state
    assert state["terms"]["post_tag"]["name"] == "Råd &amp; tips 😀", state
    for temperature in ["cold", "warm"]:
        status, headers, body = fetch(base + "/taxonomy-source.md")
        assert status == 200 and headers.get_content_type() == "text/markdown", (status, headers, body)
        text = body.decode("utf-8")
        assert text.startswith("---\n") and "TAXONOMY-ARCHIVE-FIXTURE-BODY" in text, text
        metadata = terms(text.split("---", 2)[1])
        for key, taxonomy in [("categories", "category"), ("tags", "post_tag")]:
            assert len(metadata[key]) == 1, metadata
            emitted = metadata[key][0]
            expected = state["terms"][taxonomy]
            print(temperature + " emitted " + taxonomy + ": " + emitted["url"], flush=True)
            archive_status, fields, archive = fetch(emitted["url"])
            assert archive_status == 200 and fields.get_content_type() == "text/html", (emitted, archive_status, fields, archive[:1000])
            assert fields.get("X-Kntnt-Taxonomy-Archive") == taxonomy, fields
            assert fields.get("X-Kntnt-Taxonomy-Term") == str(expected["id"]), fields
            assert b"TAXONOMY-ARCHIVE-FIXTURE-BODY" in archive, archive[:1000]
            assert emitted == {"name": expected["name"], "url": expected["url"]}, (emitted, expected)
            assert not urlsplit(emitted["url"]).path.endswith(".md"), emitted
        assert urlsplit(metadata["tags"][0]["url"]).query == "label=%22%C3%85%22&path=%5Carkiv%5C", metadata
        print("PASS " + temperature + " Unicode YAML term links reach their actual HTML archives with exact URL escapes", flush=True)

    errors = control("link-errors")
    assert errors["link_error"] is True, errors
    metadata = terms(errors["frontmatter"])
    assert metadata == {
        "categories": [{"name": 'Nyheter "Å, Ä &amp; Ö"', "url": ""}],
        "tags": [{"name": "Råd &amp; tips 😀", "url": ""}],
    }, metadata
    assert ".md" not in errors["frontmatter"], errors
    print("PASS real get_term_link WP_Error preserves names with an empty URL and no invented link", flush=True)
    errors = control("term-errors")
    assert errors["terms_error"] is True and terms(errors["frontmatter"]) == {}, errors
    assert 'title: "Taxonomy source"' in errors["frontmatter"], errors
    print("PASS real get_the_terms WP_Error omits unavailable taxonomy fields without losing the document", flush=True)
    print("Taxonomy links HTTP: 0 failures", flush=True)


def main():
    """Boot one recorded disposable worker and always stop its whole group."""
    root = Path(os.environ.get("KNTNT_TAXONOMY_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    base = "http://127.0.0.1:" + os.environ.get("KNTNT_TAXONOMY_PORT", "9445")
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest",
            "--workers=1", "--port=" + base.rsplit(":", 1)[1], "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/taxonomy-links-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if cleanup:
                subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 25 taxonomy archive Playground regression"], check=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited before taxonomy fixtures became ready")
                try:
                    if fetch(base + "/?taxonomy_fixture=state")[0] == 200:
                        verify(base)
                        return
                except (URLError, TimeoutError):
                    pass
                time.sleep(2)
            raise RuntimeError("Playground taxonomy fixture did not become ready; raise the runtime obstacle")
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
    main()
