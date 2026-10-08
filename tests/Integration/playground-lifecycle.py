"""Verify sequential old-address invalidation through native WordPress HTTP.

KNTNT_LIFECYCLE_PORT selects the port; KNTNT_LIFECYCLE_PLUGIN_ROOT can mount an
isolated historical checkout for RED evidence. KNTNT_LIFECYCLE_CASES selects a
tracer slice; KNTNT_LIFECYCLE_ROOT_ONLY limits historical setup to one base.
Worker groups are always stopped.
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
    """Expose stale addresses rather than following WordPress's guessed URL."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        """Return the original response for independent identity assertions."""
        return None


def request(base, path, headers=None):
    """Read actual status, headers and source bytes from the public endpoint."""
    try:
        response = build_opener(NoRedirect).open(Request(base + path, headers=headers or {}), timeout=20)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read().replace(b"\\_", b"_")


def control(base, action):
    """Complete the real mutation without any fixture cache purge."""
    status, _, body = request(base, f"/?lifecycle_token=fixture-only&lifecycle_action={action}")
    assert status == 200, (action, status, body[:1200])
    return json.loads(body)


def markdown(base, path, marker):
    """Require the source-specific Markdown body at its supported address."""
    status, fields, body = request(base, path)
    assert status == 200 and fields.get_content_type() == "text/markdown", (path, status, body[:1200])
    assert body.count(marker) == 1, (path, marker, body)
    return fields, body


def aggregate(base, path):
    """Read a live aggregate with its declared public media type."""
    status, fields, body = request(base, path)
    assert status == 200 and fields.get_content_type() == "text/plain", (path, status, body[:1200])
    return body


def withdrawal(base):
    """Warm all public forms before native withdrawal and permanent deletion."""
    for role in ["draft", "private", "trash", "protect", "delete"]:
        path = f"/life-{role}.md"
        marker = f"LIFECYCLE-{role}".encode()
        markdown(base, path, marker)
        markdown(base, path, marker)
        assert (base + path).encode() in aggregate(base, "/llms.txt"), role
        assert aggregate(base, "/llms-full.txt").count(marker) == 1, role
        control(base, role)
        status, _, body = request(base, path)
        expected_status = 403 if role == "protect" else 404
        assert status == expected_status and marker not in body, ("withdrawn URL", role, status, body[:1200])
        assert (base + path).encode() not in aggregate(base, "/llms.txt"), role
        assert marker not in aggregate(base, "/llms-full.txt"), role
        print(f"Native {role}: old URL and both aggregates withdrawn", flush=True)


def absent(base, path, marker=None, exact_status=None):
    """Reject stale dedicated bytes or unsupported plain canonical aliases."""
    status, fields, body = request(base, path)
    assert fields.get_content_type() != "text/markdown", (path, status, body[:1200])
    if exact_status is not None:
        assert status == exact_status, (path, status, body[:1200])
    if marker is not None:
        assert marker not in body, (path, status, body[:1200])


def context_path(mode, ids, role, pretty, page=False, swedish=False, book=False):
    """Compose the ticket's supported query form from independent source IDs."""
    if mode == "pretty":
        return pretty
    selector = "page_id" if page else "p"
    fields = f"post_type=kntnt_lifecycle_book&{selector}" if book else selector
    language = "&lang=sv" if swedish else ""
    return f"/?{fields}={ids[role]}{language}&format=markdown"


def context_changes(base, all_ids, mode):
    """Keep descendants and indirect permalink contexts current in each mode."""
    all_ids = control(base, "context-" + mode)
    ids = all_ids["context"]
    prefix = f"{mode}-ctx"

    # Parent rename changes both source paths; language prefixes stay intact.
    for swedish in [False, True]:
        language = "/sv" if swedish else ""
        parent = "sv_parent" if swedish else "parent"
        child = "sv_child" if swedish else "child"
        parent_path = context_path(mode, ids, parent, f"{language}/{prefix}-parent.md", True, swedish)
        child_path = context_path(mode, ids, child, f"{language}/{prefix}-parent/{prefix}-child.md", True, swedish)
        marker = f"CONTEXT-{mode}-{child}".encode()
        markdown(base, parent_path, f"CONTEXT-{mode}-{parent}".encode())
        markdown(base, child_path, marker)
        markdown(base, child_path, marker)
        control(base, "sv-parent-rename" if swedish else "parent-rename")
        new_parent = context_path(mode, ids, parent, f"{language}/{prefix}-renamed-parent.md", True, swedish)
        new_child = context_path(
            mode, ids, child, f"{language}/{prefix}-renamed-parent/{prefix}-child.md", True, swedish,
        )
        if mode == "pretty":
            absent(base, parent_path, exact_status=404)
            absent(base, child_path, marker, 404)
        markdown(base, new_parent, f"CONTEXT-{mode}-{parent}".encode())
        markdown(base, new_child, marker)

    # Reparenting and permanent parent deletion move ordinary descendant URLs.
    child_path = context_path(mode, ids, "child", f"/{prefix}-renamed-parent/{prefix}-child.md", True)
    control(base, "child-reparent")
    if mode == "pretty":
        absent(base, child_path, exact_status=404)
    markdown(
        base, context_path(mode, ids, "child", f"/{prefix}-spare/{prefix}-child.md", True),
        f"CONTEXT-{mode}-child".encode(),
    )
    old_orphan = context_path(mode, ids, "orphan", f"/{prefix}-remove/{prefix}-orphan.md", True)
    markdown(base, old_orphan, f"CONTEXT-{mode}-orphan".encode())
    control(base, "parent-delete")
    if mode == "pretty":
        absent(base, old_orphan, exact_status=404)
    markdown(base, context_path(mode, ids, "orphan", f"/{prefix}-orphan.md", True), f"CONTEXT-{mode}-orphan".encode())
    print(f"{mode}: parent rename, reparent and deletion; English/Swedish descendants current", flush=True)

    # A custom post type changes its canonical path or query, including metadata.
    old_type = context_path(mode, ids, "type", f"/{prefix}-type.md")
    markdown(base, old_type, f"CONTEXT-{mode}-type".encode())
    control(base, "post-type")
    absent(base, old_type, exact_status=404 if mode == "pretty" else None)
    new_type = context_path(mode, ids, "type", f"/books/{prefix}-type.md", book=True)
    _, body = markdown(base, new_type, f"CONTEXT-{mode}-type".encode())
    canonical = base + (
        f"/books/{prefix}-type/" if mode == "pretty" else f"/?post_type=kntnt_lifecycle_book&p={ids['type']}"
    )
    assert f'canonical_url: "{canonical}"'.encode() in body, body

    # Date, category assignment and term lifecycle affect pretty stored paths.
    if mode == "pretty":
        control(base, "date-structure")
        path = f"/pretty-category-old/2020/01/02/{prefix}-dated.md"
        markdown(base, path, b"CONTEXT-pretty-dated")
        for action, next_path in [
            ("post-date", f"/pretty-category-old/2021/03/04/{prefix}-dated.md"),
            ("post-category", f"/pretty-category-new/2021/03/04/{prefix}-dated.md"),
            ("term-rename", f"/pretty-category-renamed/2021/03/04/{prefix}-dated.md"),
            ("term-delete", f"/uncategorized/2021/03/04/{prefix}-dated.md"),
        ]:
            control(base, action)
            absent(base, path, exact_status=404)
            _, body = markdown(base, next_path, b"CONTEXT-pretty-dated")
            assert b"date: 2021-03-04" in body, body
            path = next_path
        control(base, "simple")
    else:
        path = context_path(mode, ids, "dated", "")
        markdown(base, path, b"CONTEXT-plain-dated")
        control(base, "post-date")
        control(base, "post-category")
        control(base, "term-rename")
        _, body = markdown(base, path, b"CONTEXT-plain-dated")
        assert b"date: 2021-03-04" in body and b"plain-category-renamed" in body, body
        control(base, "term-delete")
        _, body = markdown(base, path, b"CONTEXT-plain-dated")
        assert b"Uncategorized" in body and b"plain-category-renamed" not in body, body
    print(f"{mode}: post type, date and category/term lifecycle current", flush=True)

    # The home switches source; an actual index page stays distinct when ordinary.
    for language, locale in [("", "en_GB"), ("/sv", "sv_SE")]:
        home = f"{language}/index.md" if mode == "pretty" else (
            "/?lang=sv&format=markdown" if language else "/?format=markdown"
        )
        index = f"{language}/index/index.md" if mode == "pretty" else (
            f"/?page_id={all_ids[locale]['index']}" + ("&lang=sv" if language else "") + "&format=markdown"
        )
        markdown(base, home, f"BODY-front-{locale}".encode())
        markdown(base, index, f"BODY-index-{locale}".encode())
    control(base, "front-index")
    for language, locale in [("", "en_GB"), ("/sv", "sv_SE")]:
        home = f"{language}/index.md" if mode == "pretty" else (
            "/?lang=sv&format=markdown" if language else "/?format=markdown"
        )
        _, body = markdown(base, home, f"BODY-index-{locale}".encode())
        assert f"BODY-front-{locale}".encode() not in body, body
        old_index = f"{language}/index/index.md" if mode == "pretty" else (
            f"/?page_id={all_ids[locale]['index']}" + ("&lang=sv" if language else "") + "&format=markdown"
        )
        absent(base, old_index, exact_status=404 if mode == "pretty" else None)
        ordinary = f"{language}/ordinary.md" if mode == "pretty" else (
            f"/?page_id={all_ids[locale]['front']}" + ("&lang=sv" if language else "") + "&format=markdown"
        )
        markdown(base, ordinary, f"BODY-front-{locale}".encode())
    control(base, "blog")
    absent(
        base, "/index.md" if mode == "pretty" else "/?format=markdown",
        exact_status=404 if mode == "pretty" else None,
    )
    control(base, "front-ordinary")
    print(f"{mode}: translated static-front, actual index and blog home turnover current", flush=True)
    return all_ids


def mode_mutations(base, all_ids, mode):
    """A stable plain ID must still turn over its bytes and aggregate membership."""
    ids = all_ids["context"]
    old = context_path(mode, ids, "rename", f"/{mode}-ctx-rename.md")
    markdown(base, old, f"CONTEXT-{mode}-rename".encode())
    markdown(base, old, f"CONTEXT-{mode}-rename".encode())
    control(base, "ctx-rename")
    if mode == "pretty":
        absent(base, old, exact_status=404)
    new = context_path(mode, ids, "rename", f"/{mode}-ctx-renamed.md")
    _, body = markdown(base, new, f"CURRENT-{mode}-source".encode())
    assert f"CONTEXT-{mode}-rename".encode() not in body, body
    canonical = new[:-3] + "/" if mode == "pretty" else new.replace("&format=markdown", "")
    assert f'canonical_url: "{base + canonical}"'.encode() in body, body
    query = canonical + ("?format=markdown" if mode == "pretty" else "&format=markdown")
    markdown(base, query, f"CURRENT-{mode}-source".encode())
    status, fields, body = request(base, canonical, {"Accept": "text/markdown"})
    assert status == 200 and fields.get_content_type() == "text/markdown", (canonical, status, body)
    assert body.count(f"CURRENT-{mode}-source".encode()) == 1, body
    index = aggregate(base, "/llms.txt")
    assert (base + new).encode() in index, index
    if mode == "pretty":
        assert (base + old).encode() not in index, index
    full = aggregate(base, "/llms-full.txt")
    assert full.count(f"CURRENT-{mode}-source".encode()) == 1, full
    assert f"CONTEXT-{mode}-rename".encode() not in full, full

    # Use the actual configured supported addresses, never a synthetic plain path.
    for role in ["draft", "private", "trash", "protect", "delete"]:
        path = context_path(mode, ids, role, f"/{mode}-ctx-{role}.md")
        marker = f"CONTEXT-{mode}-{role}".encode()
        markdown(base, path, marker)
        markdown(base, path, marker)
        index = aggregate(base, "/llms.txt")
        assert (base + path).encode() in index, (role, index)
        assert aggregate(base, "/llms-full.txt").count(marker) == 1, role
        control(base, "ctx-" + role)
        absent(base, path, marker, (403 if role == "protect" else 404) if mode == "pretty" else None)
        canonical = path[:-3] + "/" if mode == "pretty" else path.replace("&format=markdown", "")
        query = canonical + ("?format=markdown" if mode == "pretty" else "&format=markdown")
        absent(base, query, marker)
        status, fields, body = request(base, canonical, {"Accept": "text/markdown"})
        assert fields.get_content_type() != "text/markdown" and marker not in body, (role, status, body[:1200])
        assert (base + path).encode() not in aggregate(base, "/llms.txt"), role
        assert marker not in aggregate(base, "/llms-full.txt"), role
    print(f"{mode}: rename, draft/private/trash/password/delete; no stale body or aggregate member", flush=True)


def ignored_revisions(base, all_ids, mode):
    """Native revision and autosave writes preserve warmed public artifacts."""
    ids = all_ids["context"]
    control(base, "revision-prepare")
    path = context_path(mode, ids, "revision", f"/{mode}-ctx-revision.md")
    _, page = markdown(base, path, f"CONTEXT-{mode}-revision".encode())
    assert b"REVISION-RENDER-" in page, page
    paths = [path, "/llms.txt", "/llms-full.txt"]
    snapshots = {}
    for target in paths:
        status, fields, body = request(base, target)
        assert status == 200, (target, status)
        snapshots[target] = (fields.get("ETag"), body)
    for action in ["revision", "autosave", "autosave-update"]:
        observed = control(base, action)
        if action == "revision":
            assert observed.get("revision_id", 0) > 0, observed
        else:
            assert observed.get("autosave_id", 0) > 0, observed
            assert observed.get("autosave_parent") == ids["revision"], observed
        for target in paths:
            status, fields, body = request(base, target)
            assert status == 200 and (fields.get("ETag"), body) == snapshots[target], (action, target, body)
            assert b"UNPUBLISHED-REVISION" not in body and b"UNPUBLISHED-AUTOSAVE" not in body, body
    print(f"{mode}: native revision and autosave insert/update preserve all warm bodies and ETags", flush=True)


def verify(base, ids):
    """Assert sequential source, descendant and aggregate lifecycle contracts."""
    status, _, runtime = request(base, "/wp-content/plugins/kntnt-ai-visibility/tests/Integration/php-version.php")
    assert status == 200 and re.fullmatch(rb"8\.4\.[0-9]+", runtime), runtime
    print(f"Actual PHP runtime: {runtime.decode()} (8.4 asserted); home {base}.", flush=True)
    if os.environ.get("KNTNT_LIFECYCLE_CASES", "all") in ["all", "rename"]:
        markdown(base, "/life-rename.md", b"LIFECYCLE-rename")
        markdown(base, "/life-rename.md", b"LIFECYCLE-rename")
        control(base, "rename")
        status, _, body = request(base, "/life-rename.md")
        assert status == 404 and b"LIFECYCLE-rename" not in body, ("old renamed URL", status, body)
        markdown(base, "/life-renamed.md", b"LIFECYCLE-rename")
    if os.environ.get("KNTNT_LIFECYCLE_CASES", "all") in ["all", "withdrawal"]:
        withdrawal(base)
    if os.environ.get("KNTNT_LIFECYCLE_CASES", "all") in ["all", "context"]:
        for mode in ["pretty", "plain"]:
            context_ids = context_changes(base, ids, mode)
            mode_mutations(base, context_ids, mode)
            ignored_revisions(base, context_ids, mode)
    print("Lifecycle HTTP: 0 failures", flush=True)


def run(subpath):
    """Run one disposable installation and stop its recorded process group."""
    root = Path(os.environ.get("KNTNT_LIFECYCLE_PLUGIN_ROOT", Path(__file__).resolve().parents[2]))
    port = os.environ.get("KNTNT_LIFECYCLE_PORT", "9430")
    base = f"http://127.0.0.1:{port}{subpath}"
    with tempfile.TemporaryFile() as log:
        worker = subprocess.Popen([
            "npx", "--yes", "@wp-playground/cli@3.1.36", "server", "--php=8.4", "--wp=latest", "--workers=1",
            f"--port={port}", f"--site-url={base}", f"--mount={root}:/wordpress/wp-content/plugins/kntnt-ai-visibility",
            f"--blueprint={root}/tests/Integration/lifecycle-blueprint.json",
        ], stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            tracker = Path.home() / ".agents/skills/kntnt/features/session-cleanup/scripts/session_cleanup.py"
            if tracker.exists():
                subprocess.run(
                    ["uv", "run", str(tracker), "add", "pid", str(worker.pid), "#10 sequential lifecycle Playground"],
                    check=True, stdout=subprocess.DEVNULL,
                )
            print(f"Playground process group: {worker.pid}", flush=True)
            for _ in range(90):
                if worker.poll() is not None:
                    raise RuntimeError("Playground exited during setup")
                try:
                    ids = control(base, "state")
                    if "rename" in ids.get("posts", {}):
                        break
                except (URLError, TimeoutError, AssertionError, json.JSONDecodeError):
                    pass
                time.sleep(2)
            else:
                raise RuntimeError("Lifecycle fixtures never became ready")
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
    installations = [""] if os.environ.get("KNTNT_LIFECYCLE_ROOT_ONLY") == "1" else ["", "/sub"]
    for installation in installations:
        run(installation)
