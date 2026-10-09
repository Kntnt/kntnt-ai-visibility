"""Compare Markdown destinations with browser URI semantics on PHP 8.4.

KNTNT_REFERENCES_PORT selects the disposable worker (default 9448).
KNTNT_REFERENCES_PLUGIN_ROOT can mount the accepted pre-fix checkout for RED.
Both root and subdirectory installations exercise translated sources.
"""

from html.parser import HTMLParser
from http.client import RemoteDisconnected
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit
from urllib.request import Request, urlopen

from playground_process import stop_worker


def fetch(url, headers=None):
    """Keep real response status, headers and UTF-8 bytes."""
    try:
        response = urlopen(Request(url, headers=headers or {}), timeout=30)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read()


class References(HTMLParser):
    """Read destinations actually present in the source's canonical HTML."""
    def __init__(self):
        super().__init__()
        self.references = {}
        self.href = None

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag == "a":
            self.href = values.get("href")
        if tag == "img" and values.get("alt", "").startswith("image-"):
            self.references["!" + values["alt"]] = values["src"]

    def handle_data(self, data):
        if self.href is not None and re.fullmatch(r"(?:child|parent|query|fragment|root|absolute|protocol|mail)-[0-9]+", data):
            self.references[data] = self.href

    def handle_endtag(self, tag):
        if tag == "a":
            self.href = None


def canonical(base, mode, source):
    """Independent literal WordPress/Bogo path and selector expectations."""
    role, locale = source["role"], source["locale"]
    if mode == "plain":
        query = {} if role == "front" else {"p" if role == "post" else "page_id": source["id"]}
        if locale == "sv_SE":
            query["lang"] = "sv"
        return base + "/" + ("?" + urlencode(query) if query else "")
    prefix = "/sv" if locale == "sv_SE" else ""
    path = {"front": "", "nested": "index/index", "post": "2026/10/08/relative-story" if mode == "dated" else "relative-story"}[role]
    suffix = "/" if role == "front" or mode != "no-slash" else ""
    return base + prefix + "/" + path + (suffix if path else "")


def verify(base):
    """Check every form and source-less full build against canonical HTML."""
    def control(action):
        status, _, body = fetch(base + "/?relative_fixture_token=fixture-only&action=" + action)
        assert status == 200, (action, status, body[:1200])
        return json.loads(body)

    state = control("state")
    assert re.fullmatch(r"8\.4\.[0-9]+", state["php"]), state
    print("Actual Playground PHP " + state["php"] + " home " + base, flush=True)
    for mode in ["pretty", "no-slash", "dated", "plain"]:
        state = control(mode)
        all_expected = {}
        for source in state["sources"]:
            expected_url = canonical(base, mode, source)
            actual, expected = urlsplit(source["canonical"]), urlsplit(expected_url)
            assert (actual.scheme, actual.netloc, actual.path, parse_qs(actual.query)) == (expected.scheme, expected.netloc, expected.path, parse_qs(expected.query)), (mode, source, expected_url)
            status, fields, body = fetch(source["canonical"])
            assert status == 200 and fields.get_content_type() == "text/html", (source, status, body[:1200])
            parser = References()
            parser.feed(body.decode("utf-8"))
            source_refs = {label: value for label, value in parser.references.items() if label.endswith("-" + str(source["id"]))}
            assert len(source_refs) == 9, (source, source_refs)
            # Python's URI resolver represents browser reference semantics; no converter is used for expectations.
            destinations = {label: urljoin(source["canonical"], value) for label, value in source_refs.items()}
            all_expected.update(destinations)
            forms = [("alternate", source["alternate"], {})]
            if mode != "plain":
                forms.append(("query", source["canonical"] + "?format=markdown", {}))
            forms.append(("Accept", source["canonical"], {"Accept": "text/markdown"}))
            for form, url, headers in forms:
                control("flush")
                for temperature in ["cold", "warm"]:
                    status, fields, body = fetch(url, headers)
                    assert status == 200 and fields.get_content_type() == "text/markdown", (mode, source, form, status, body[:1200])
                    document(mode + " " + source["role"] + " " + source["locale"] + " " + form + " " + temperature, body, destinations)
        control("flush")
        for temperature in ["cold", "warm"]:
            status, fields, body = fetch(base + "/llms-full.txt")
            assert status == 200 and fields.get_content_type() == "text/plain", (mode, status, body[:1200])
            document(mode + " full " + temperature, body, all_expected)
    print("Relative references HTTP: 0 failures", flush=True)


def document(label, body, destinations):
    """Compare exact emitted link/image destinations, including retained queries."""
    text = body.decode("utf-8")
    for name, destination in destinations.items():
        prefix, name = ("!", name[1:]) if name.startswith("!") else ("", name)
        expected = prefix + "[" + name + "](" + destination + ")"
        assert expected in text, (label, expected, text[:2000])
    print("PASS " + label + ": " + str(len(destinations)) + " source-aware destinations", flush=True)


def run(subpath):
    """Record one isolated worker immediately and always stop its whole group."""
    root = Path(os.environ.get("KNTNT_REFERENCES_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    port = os.environ.get("KNTNT_REFERENCES_PORT", "9448")
    base = "http://127.0.0.1:" + port + subpath
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            "--port=" + port, "--site-url=" + base,
            "--mount=" + str(root) + ":/wordpress/wp-content/plugins/kntnt-ai-visibility",
            "--blueprint=" + str(root / "tests/Integration/relative-references-blueprint.json"),
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            cleanup = os.environ.get("KNTNT_SESSION_CLEANUP_SCRIPT")
            if cleanup:
                subprocess.run(["uv", "run", cleanup, "add", "pid", str(worker.pid), "issue 18 source-relative references Playground"], check=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during reference fixture setup")
                try:
                    status, fields, body = fetch(base + "/?relative_fixture_token=fixture-only&action=state")
                    ready = status == 200 and fields.get_content_type() == "application/json" and len(json.loads(body).get("sources", [])) == 6
                except (URLError, RemoteDisconnected, TimeoutError, json.JSONDecodeError):
                    ready = False
                if ready:
                    verify(base)
                    return
                time.sleep(2)
            raise RuntimeError("Relative-reference fixture did not become ready; raise the runtime obstacle")
        except BaseException:
            log.seek(0)
            print(log.read().decode(errors="replace"), flush=True)
            raise
        finally:
            stop_worker(worker, grace_seconds=10)


if __name__ == "__main__":
    for installation in ["", "/sub"]:
        run(installation)
